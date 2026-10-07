<?php
declare(strict_types=1);
// Isolated fake HTTP integrations and simulated SMTP. No live requests or messages.
use ScreenPort\{ApiError,Config,Db,Http,Jellyfin,LibraryReviews,ManagerAlerts,Requests,Settings};
$root=dirname(__DIR__); $private=$root.'/.test-output/reviews-'.bin2hex(random_bytes(4)); mkdir($private,0700,true);
putenv('SCREENPORT_ENV_FILE='.$private.'/.env'); putenv('APP_ENV=local'); putenv('APP_URL=http://127.0.0.1:8098');
putenv('APP_KEY='.base64_encode(random_bytes(32))); putenv('STORAGE_PATH='.$private);
file_put_contents($private.'/.env',"DOWNLOAD_MANAGER_EMAIL=manager@example.test\nMAIL_FROM_ADDRESS=from@example.test\nSMTP_HOST=fake.example\nTMDB_READ_ACCESS_TOKEN=fake\nQBITTORRENT_URL=http://qbit.example.test\nQBITTORRENT_USERNAME=fake\nQBITTORRENT_PASSWORD=fake\nOPENAI_API_KEY=fake\nJELLYFIN_URL=http://private-jellyfin.example.test:8096/base\nJELLYFIN_PUBLIC_URL=https://watch.example.test/jellyfin\nJELLYFIN_API_KEY=private-jellyfin-key-example\n");
require $root.'/app/bootstrap.php'; $checks=0;
function verify(bool $ok,string $message): void { global $checks; $checks++; if(!$ok) throw new RuntimeException('FAIL: '.$message); }
function blocked(callable $fn,int $code,string $message): void { try { $fn(); } catch(ApiError $e) { verify($e->status===$code,$message); return; } verify(false,$message); }
try {
    $uid=$auth->create('requester','requester@example.test','ValidPassword!2026','user','approved');
    $second=$auth->create('anotheruser','another@example.test','ValidPassword!2026','user','approved');
    $manager=$auth->create('manager','manager@example.test','ValidPassword!2026','admin','approved');
    $user=$db->one('SELECT id,username,email,role FROM users WHERE id=?',[$uid]);
    $mode='found'; $calls=[];
    Http::setTestTransport(function($method,$url,$headers) use(&$mode,&$calls) {
        $calls[]=$url; $path=parse_url($url,PHP_URL_PATH); parse_str(parse_url($url,PHP_URL_QUERY) ?? '',$query);
        if(str_contains($url,'api.themoviedb.org')) {
            preg_match('~/(movie|tv)/(\d+)$~',$path,$match); $tv=($match[1] ?? '')==='tv'; $id=(int)($match[2] ?? 501);
            $data=['id'=>$id,'title'=>'<script>Sample Film</script>','name'=>'Sample Show','overview'=>'A test synopsis','original_language'=>'en','release_date'=>'2025-01-02','first_air_date'=>'2024-01-01','poster_path'=>'/safe.jpg','genres'=>[],'episode_run_time'=>[45],'seasons'=>[['season_number'=>1]],
                'external_ids'=>['imdb_id'=>'tt'.$id],'release_dates'=>['results'=>[['iso_3166_1'=>'US','release_dates'=>[['type'=>4,'release_date'=>'2025-03-02T00:00:00Z','certification'=>'PG-13']]]]],'content_ratings'=>['results'=>[['iso_3166_1'=>'US','rating'=>'TV-MA']]]];
            if($tv) unset($data['title']);
            return ['status'=>200,'body'=>json_encode($data)];
        }
        if(str_ends_with($path,'/System/Info/Public')) return ['status'=>200,'body'=>json_encode(['Id'=>str_repeat('b',32)])];
        if(str_ends_with($path,'/Items')) {
            if($mode==='outage') throw new RuntimeException('Private backend exception');
            $id=str_contains($query['SearchTerm'] ?? '','Show') ? 502 : 501;
            $item=['Id'=>$mode==='bad-id' ? 'javascript:alert(1)' : str_repeat('a',32),'Name'=>'<img src=x> Detected title','Type'=>$query['IncludeItemTypes'],'ProviderIds'=>['Tmdb'=>(string)$id]];
            if($mode==='wrong-type') $item['Type']=$item['Type']==='Series' ? 'Movie' : 'Series';
            return ['status'=>200,'body'=>json_encode(['Items'=>$mode==='missing' ? [] : [$item]])];
        }
        throw new RuntimeException('Unexpected outbound request: no downloader/model/SMTP access is permitted.');
    });
    $movie=$catalog->detail('movie',501); $show=$catalog->detail('tv',502);
    $jellyfin=new Jellyfin($settings,$db);
    $found=$jellyfin->find($movie);
    verify($found['in_library'] && $found['type']==='movie','movie match found by provider ID');
    verify($found['url']==='https://watch.example.test/jellyfin/web/#/details?id='.str_repeat('a',32).'&serverId='.str_repeat('b',32),'item link uses browser URL, correct IDs and base path');
    verify(!str_contains(json_encode($found),'private-jellyfin') && !str_contains($found['url'],'key='),'browser data excludes API credentials and private base URL');
    $before=count($calls); $jellyfin->find($movie); verify(count($calls)===$before,'cached match/link avoids repeated integrations');
    $items=$jellyfin->annotate([$movie,$show]); verify($items[0]['library']['in_library'] && $items[1]['library']['in_library'],'catalog cards are annotated for movies and shows');
    $mode='wrong-type'; verify($jellyfin->find($show,true)===null,'movie and series IDs cannot be confused'); $mode='found';
    $reviews=new LibraryReviews($config,$db,$settings,$catalog);
    $settings->save(['DOWNLOADS_ENABLED'=>'false']);
    $first=$reviews->request($user,'movie',501);
    verify($first['id']>0 && str_contains($first['message'],'Confirmation'),'review accepted while downloads paused');
    verify((int)$db->one('SELECT COUNT(*) n FROM library_reviews')['n']===1,'review persisted privately');
    verify((int)$db->one('SELECT COUNT(*) n FROM requests')['n']===0 && (int)$db->one('SELECT COUNT(*) n FROM jobs')['n']===0,'review starts no automatic download or job');
    $queued=$db->all("SELECT kind,recipient,payload FROM manager_alerts WHERE kind LIKE 'library_review_%' ORDER BY id");
    verify(count($queued)===2 && $queued[0]['recipient']==='manager@example.test' && $queued[1]['recipient']==='requester@example.test','manager and requester receive separate queued emails');
    verify(!str_contains(json_encode($queued),'password') && !str_contains(json_encode($queued),'private-jellyfin-key'),'email queue excludes secret material');
    $before=count($calls); $duplicate=$reviews->request($user,'movie',501);
    verify($duplicate['id']===$first['id'] && count($calls)===$before,'duplicate review returns prior ID without network requests');
    verify((int)$db->one('SELECT COUNT(*) n FROM manager_alerts')['n']===2,'repeated clicks do not repeat either email');
    $messages=[]; $alerts=new ManagerAlerts($config,$db,$settings,function($recipient,$content) use(&$messages) { $messages[]=[$recipient,$content]; });
    $alerts->deliver();
    verify(count($messages)===2,'both review emails delivered through durable queue');
    $managerText=$messages[0][1]['text']; $userText=$messages[1][1]['text'];
    verify(str_contains($managerText,'requester@example.test') && str_contains($managerText,'requester') && str_contains($managerText,'Requester user ID: '.$uid) && str_contains($managerText,'Requester role: user'),'manager email has requester contact details');
    verify(str_contains($managerText,'On Jellyfin!') && str_contains($managerText,'still requesting') && str_contains($managerText,'TMDB ID: 501'),'manager sees detected title and continued review request');
    verify(str_contains($managerText,'Content rating: PG-13') && str_contains($managerText,'Release date: 2025-01-02') && str_contains($messages[0][1]['html'],'/safe.jpg'),'email includes media metadata and safe artwork');
    verify(str_contains($userText,'Your manual review has been requested') && str_contains($userText,'No additional download'),'user confirmation explains requested review');
    verify(!str_contains($messages[0][1]['html'],'<script>') && !str_contains($messages[0][1]['html'],'<img src=x>'),'untrusted titles escaped in HTML mail');
    verify(!str_contains(json_encode($messages),'private-jellyfin') && !str_contains(json_encode($messages),'ValidPassword'),'review messages contain no backend secrets or passwords');
    $alerts->deliver(); verify(count($messages)===2,'sent notifications do not repeat');
    $tv=$reviews->request($user,'tv',502); $alerts->deliver();
    verify(count($messages)===4 && str_contains($messages[2][1]['text'],'every season or episode'),'series review warns that coverage is unconfirmed');
    $other=$db->one('SELECT id,username,email,role FROM users WHERE id=?',[$second]);
    $reviews->request($other,'movie',501); verify((int)$db->one('SELECT COUNT(*) n FROM library_reviews')['n']===3,'different users can report the same title independently');
    $db->run('UPDATE library_reviews SET created_at=? WHERE id=?',[time()-86401,$first['id']]);
    $again=$reviews->request($user,'movie',501); verify($again['id']!==$first['id'],'a review can be requested again after 24 hours');
    $owner=$db->one('SELECT id,username,email,role FROM users WHERE id=?',[$manager]);
    $own=$reviews->request($owner,'movie',501);
    verify((int)$db->one("SELECT COUNT(*) n FROM manager_alerts WHERE event_key LIKE ?",['library-review:'.$own['id'].':%'])['n']===1,'same manager/requester address receives one combined confirmation');
    $mode='missing'; $count=(int)$db->one('SELECT COUNT(*) n FROM library_reviews')['n'];
    blocked(fn()=>$reviews->request($other,'tv',502),409,'fresh lookup prevents forged or stale library review');
    verify((int)$db->one('SELECT COUNT(*) n FROM library_reviews')['n']===$count,'missing title queues no false-positive review emails');
    $mode='outage'; $settings->save(['JELLYFIN_PUBLIC_URL'=>'https://watch.example.test/jellyfin']); $before=count($calls); $items=$jellyfin->annotate([$movie,$show]);
    verify($items[0]['library']===null && $items[1]['library']===null && count($calls)===$before+1,'library outage does not block catalog or repeat timeouts');
    blocked(fn()=>$reviews->request($user,'invalid',0),422,'invalid media cannot queue review');
    $mode='bad-id'; verify($jellyfin->find($show,true)['url']===null,'unsafe item IDs cannot construct navigation links');
    $mode='found'; $settings->save(['JELLYFIN_PUBLIC_URL'=>'','DOWNLOADS_ENABLED'=>'true']);
    verify(str_starts_with($jellyfin->find($movie)['url'],'http://private-jellyfin.example.test:8096/base/web/'),'blank browser URL falls back to configured Jellyfin URL');
    blocked(fn()=>$settings->save(['JELLYFIN_PUBLIC_URL'=>'https://user:password@watch.example.test']),422,'embedded browser URL credentials rejected');
    blocked(fn()=>$settings->save(['JELLYFIN_PUBLIC_URL'=>'javascript:alert(1)']),422,'unsafe browser URL scheme rejected');
    $download=new Requests($config,$db,$settings,$catalog);
    blocked(fn()=>$download->enqueue($user,'movie',501),409,'Jellyfin movie cannot bypass review with download API');
    blocked(fn()=>$download->enqueue($user,'tv',502),409,'Jellyfin series cannot bypass review with download API');
    $failing=new ManagerAlerts($config,$db,$settings,fn()=>throw new RuntimeException('Simulated SMTP error'));
    for($i=0;$i<5;$i++) { $db->run("UPDATE manager_alerts SET due_at=? WHERE status='pending'",[time()-1]); $failing->deliver(); }
    verify($db->one("SELECT status FROM manager_alerts WHERE event_key=?",['library-review:'.$again['id'].':user'])['status']==='failed','user confirmations use five-attempt email retry policy');
    $settings->save(['DOWNLOAD_MANAGER_EMAIL'=>'']);
    blocked(fn()=>$reviews->request($other,'tv',502),503,'unconfigured manager prevents review being falsely accepted');
    $demoFile=$private.'/demo.env'; file_put_contents($demoFile,"DEMO_MODE=true\nAPP_KEY=".$config->get('APP_KEY')."\nSTORAGE_PATH=".$private."\n");
    blocked(fn()=>(new LibraryReviews(new Config($demoFile),$db,$settings,$catalog))->request($user,'tv',502),403,'demo cannot send review emails');
    echo "PASS: $checks Jellyfin link and manual-review checks. All integrations and emails simulated.\n";
} catch(Throwable $e) { echo $e->getMessage()."\n"; exit(1); }
finally { Http::setTestTransport(null); }
