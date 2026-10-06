<?php
declare(strict_types=1);
// No live downloads, SMTP, or paid model requests: every external request is intercepted.
use ScreenPort\{ApiError,Auth,Catalog,Config,Db,Http,Mailer,MediaRules,Requests,Settings,TorrentPolicy,Worker};
$root=dirname(__DIR__);
$dir=$root.'/.test-output/unit-'.bin2hex(random_bytes(4)); mkdir($dir,0700,true);
putenv('APP_ENV=local'); putenv('APP_URL=http://127.0.0.1:8098'); putenv('APP_KEY='.base64_encode(random_bytes(32))); putenv('STORAGE_PATH='.$dir);
putenv('SCREENPORT_ENV_FILE='.$dir.'/.env');
file_put_contents($dir.'/.env',"TMDB_READ_ACCESS_TOKEN=fake\nQBITTORRENT_URL=http://127.0.0.1:9999\nQBITTORRENT_USERNAME=fake\nQBITTORRENT_PASSWORD=fake\nOPENAI_API_KEY=fake\nSMTP_HOST=fake.example\nMAIL_FROM_ADDRESS=screenport@example.test\nDOWNLOAD_MANAGER_EMAIL=manager@example.test\n");
require $root.'/app/bootstrap.php';
$tests=0;
function check(bool $ok,string $label): void { global $tests; $tests++; if(!$ok) throw new RuntimeException('FAIL: '.$label); }
function rejects(callable $fn,string $label): void { try { $fn(); } catch(Throwable $e) { check(true,$label); return; } check(false,$label); }
function movie(int $id=101): array { return ['id'=>$id,'title'=>'Sample Movie','original_title'=>'Sample Movie','original_language'=>'en','release_date'=>'2025-01-02','runtime'=>120,'vote_average'=>8,'genres'=>[['name'=>'Science Fiction']],
    'external_ids'=>['imdb_id'=>'tt123'],'release_dates'=>['results'=>[['iso_3166_1'=>'US','release_dates'=>[['type'=>3,'release_date'=>'2025-01-02T00:00:00Z','certification'=>'PG-13'],['type'=>4,'release_date'=>'2025-03-02T00:00:00Z','certification'=>'PG-13']]]]]]; }
function show(int $id=202): array { return ['id'=>$id,'name'=>'Sample Show','original_name'=>'Sample Show','original_language'=>'en','first_air_date'=>'2024-01-01','status'=>'Ended','episode_run_time'=>[45],
    'genres'=>[['name'=>'Drama']],'content_ratings'=>['results'=>[['iso_3166_1'=>'US','rating'=>'TV-MA']]],'seasons'=>[['season_number'=>1,'air_date'=>'2024-01-01'],['season_number'=>2,'air_date'=>'2025-01-01']]]; }
function choice(string $id,array $seasons=[],string $pack='movie'): array { return ['candidate_id'=>$id,'seasons'=>$seasons,'pack'=>$pack,'correct_title'=>true,'language_ok'=>true,'theater_recording'=>false,'quality'=>'1080p','confidence'=>.95,'reason'=>'Matches title, language, and size.']; }
try {
    putenv('qBittorrent_URL=http://docker-qbit:8080');
    $dockerConfig=new Config($dir.'/nonexistent.env');
    check($dockerConfig->get('QBITTORRENT_URL')==='http://docker-qbit:8080','legacy names supported as Docker environment variables');
    putenv('qBittorrent_URL');
    $theatrical=movie(); $theatrical['release_dates']['results'][0]['release_dates']=[$theatrical['release_dates']['results'][0]['release_dates'][0]];
    check(!MediaRules::availability($theatrical,'movie','US','2026-01-01')['available'],'theatrical-only movies blocked');
    check(!MediaRules::availability(movie(),'movie','US','2025-02-01')['available'],'future home releases blocked');
    check(MediaRules::availability(movie(),'movie','US','2025-03-02')['available'],'release day allowed');
    check(!MediaRules::availability(movie(),'movie','GB','2026-01-01')['available'],'other-region release does not bypass gate');
    check(!MediaRules::completedSeason(['episodes'=>[['air_date'=>'2026-01-01'],['air_date'=>null]]],'2026-10-01'),'unknown episode dates blocked');
    check(!MediaRules::completedSeason(['episodes'=>[['air_date'=>'2026-11-01']]],'2026-10-01'),'future episodes blocked');
    check(MediaRules::completedSeason(['episodes'=>[['air_date'=>'2025-01-01']]],'2026-01-01'),'fully aired seasons allowed');
    check(MediaRules::folder('../../Odd/Show: Name')==='Odd Show Name','folder traversal stripped');
    $m=['type'=>'movie','title'=>'Sample Movie','genres'=>['Science Fiction'],'rating'=>'PG-13','year'=>'2025'];
    check(MediaRules::path($m,false,$settings)==='/media/Movies/Science Fiction/','genre movie path');
    $tv=['type'=>'tv','title'=>'Sample/Show','original_title'=>'Sample Show','rating'=>'TV-MA'];
    check(MediaRules::path($tv,false,$settings)==='/media/Mature TV Shows/','mature all seasons path');
    check(MediaRules::path($tv,true,$settings)==='/media/Mature TV Shows/Sample Show/','mature per season path');
    $tv['rating']='Unrated'; check(str_contains(MediaRules::path($tv,false,$settings),'Mature'),'unrated shows mature');
    $tv['rating']='TV-PG'; check(MediaRules::path($tv,true,$settings)==='/media/TV Shows/Sample Show/','normal per season path');
    $settings->save(['OPENAI_API_KEY'=>'protected-secret','MOVIE_GENRE_MAP'=>'{"Science Fiction":"Sci-Fi"}']);
    check($settings->get('OPENAI_API_KEY')==='protected-secret','encrypted settings round trip');
    check(!str_contains($db->one("SELECT value FROM settings WHERE key='OPENAI_API_KEY'")['value'],'protected-secret'),'secret not stored in plaintext');
    check(!str_contains(json_encode($settings->display()),'protected-secret'),'secret never returned to browser');
    $settings->save(['OPENAI_API_KEY'=>'']); check($settings->get('OPENAI_API_KEY')==='protected-secret','blank secret preserves existing');
    check(MediaRules::path($m,false,$settings)==='/media/Movies/Sci-Fi/','genre folder mapping');
    rejects(fn()=>$settings->save(['MOVIE_ROOT'=>'/media/../etc']),'unsafe root rejected');
    rejects(fn()=>$settings->save(['SMTP_ENCRYPTION'=>'none']),'plaintext email disallowed');
    rejects(fn()=>Http::baseUrl('https://user:password@example.com'),'embedded URL credentials disallowed');
    $hash=str_repeat('a',40); $magnet='magnet:?xt=urn:btih:'.$hash;
    check(TorrentPolicy::magnetHash($magnet)===$hash,'hex magnet hash');
    check(TorrentPolicy::magnetHash('magnet:?xt=urn:btih:'.str_repeat('A',32))===str_repeat('0',40),'base32 magnet hash');
    check(TorrentPolicy::urlAllowed($magnet,''),'valid magnet allowed');
    check(!TorrentPolicy::urlAllowed($magnet.'&xs=http://127.0.0.1/private',''),'magnet web-source injection blocked');
    check(!TorrentPolicy::urlAllowed($magnet.'&tr='.urlencode('http://127.0.0.1/announce'),''),'private magnet trackers blocked');
    check(!TorrentPolicy::urlAllowed('http://127.0.0.1/private','127.0.0.1'),'private torrent fetch rejected');
    check(!TorrentPolicy::urlAllowed('https://example.com/file',''),'non-allowlisted torrent host rejected');
    $c=TorrentPolicy::candidates([
        ['fileName'=>'Sample Movie 2025 1080p ENG','fileSize'=>2*1024**3,'nbSeeders'=>50,'fileUrl'=>$magnet],
        ['fileName'=>'Sample Movie 2025 HDCAM','fileSize'=>2*1024**3,'nbSeeders'=>500,'fileUrl'=>'magnet:?xt=urn:btih:'.str_repeat('b',40)],
        ['fileName'=>'Sample Movie 2025 1080p ENG','fileSize'=>2*1024**3,'nbSeeders'=>2,'fileUrl'=>$magnet],
    ],15,$settings);
    check(count($c)===1,'CAM excluded and magnets deduplicated');
    $many=[]; for($i=0;$i<40;$i++) $many[]=['fileName'=>'Sample Movie 2025 1080p ENG','fileSize'=>2*1024**3,'nbSeeders'=>40-$i,'fileUrl'=>'magnet:?xt=urn:btih:'.str_pad(dechex($i+1),40,'0',STR_PAD_LEFT)];
    check(count(TorrentPolicy::candidates($many,15,$settings))===15,'movies capped at 15 candidates');
    check(count(TorrentPolicy::candidates($many,30,$settings))===30,'TV capped at 30 candidates');
    $selection=['selections'=>[choice($c[0]['id'])]];
    check(count(TorrentPolicy::validate($selection,$c,$m,[],$settings,false))===1,'movie selection passes');
    $bad=$selection; $bad['selections'][0]['candidate_id']='invented'; rejects(fn()=>TorrentPolicy::validate($bad,$c,$m,[],$settings,false),'invented candidate rejected');
    $bad=$selection; $bad['selections'][0]['language_ok']=false; rejects(fn()=>TorrentPolicy::validate($bad,$c,$m,[],$settings,false),'wrong language rejected');
    $bad=$selection; $bad['selections'][0]['confidence']=.7; rejects(fn()=>TorrentPolicy::validate($bad,$c,$m,[],$settings,false),'low confidence rejected');
    $wrong=$c; $wrong[0]['name']='Other Movie 2025 1080p ENG'; rejects(fn()=>TorrentPolicy::validate($selection,$wrong,$m,[],$settings,false),'wrong title rejected despite model confidence');
    $c1=['id'=>'one','name'=>'Sample Show S01 1080p ENG Complete','url'=>$magnet,'hash'=>$hash,'size_bytes'=>5*1024**3];
    $c2=['id'=>'two','name'=>'Sample Show S02 1080p ENG Complete','url'=>'magnet:?xt=urn:btih:'.str_repeat('b',40),'hash'=>str_repeat('b',40),'size_bytes'=>5*1024**3];
    $p=['selections'=>[choice('one',[1],'season'),choice('two',[2],'season')]];
    check(count(TorrentPolicy::validate($p,[$c1,$c2],$tv,[1,2],$settings,false))===2,'separate seasons complete coverage');
    $bad=['selections'=>[choice('one',[2],'season')]]; rejects(fn()=>TorrentPolicy::validate($bad,[$c1],$tv,[2],$settings,false),'false filename season claim rejected');
    $bad=$p; $bad['selections'][1]['seasons']=[1]; rejects(fn()=>TorrentPolicy::validate($bad,[$c1,$c2],$tv,[1,2],$settings,false),'overlapping coverage rejected');
    $bad=['selections'=>[choice('one',[1],'season')]]; rejects(fn()=>TorrentPolicy::validate($bad,[$c1],$tv,[1,2],$settings,false),'partial coverage rejected');
    $bad=['selections'=>[choice('one',[3],'season')]]; rejects(fn()=>TorrentPolicy::validate($bad,[$c1],$tv,[1,2],$settings,false),'unreleased seasons rejected');
    $bad=['selections'=>[choice('one',[1],'episode')]]; rejects(fn()=>TorrentPolicy::validate($bad,[$c1],$tv,[1],$settings,false),'episode pack rejected');
    $a=$auth->create('admin','manager@example.test','A-strong-test-password','admin','approved');
    $u=$auth->create('member','user@example.test','A-strong-test-password','user','approved');
    rejects(fn()=>$auth->create('member','other@example.test','A-strong-test-password'),'duplicate username rejected');
    rejects(fn()=>$auth->create('weakuser','weak@example.test','short'),'weak password rejected');
    $deliveries=[]; $adds=[]; $searches=[]; $fakeTorrents=[]; $scenario='movie'; $nextSearch=1;
    Http::setTestTransport(function($method,$url,$headers,$body) use (&$adds,&$searches,&$fakeTorrents,&$nextSearch,&$scenario,$magnet,$hash) {
        $path=parse_url($url,PHP_URL_PATH); parse_str((string)parse_url($url,PHP_URL_QUERY),$query); parse_str((string)$body,$form);
        $result=null;
        if(str_contains($url,'api.themoviedb.org')) {
            if($path==='/3/movie/101') $result=movie();
            elseif(preg_match('~^/3/tv/(202|203)$~',$path,$match)) $result=show((int)$match[1]);
            elseif(preg_match('~^/3/tv/(202|203)/season/(\d+)$~',$path,$m)) $result=['episodes'=>array_fill(0,8,['air_date'=>'2025-01-01','runtime'=>45])];
            else throw new RuntimeException('Unexpected catalog call: '.$path);
        } elseif(str_contains($url,'api.openai.com')) {
            $request=json_decode($body,true); $input=json_decode($request['input'],true); $items=$input['candidates'];
            check(count($items)<=($scenario==='movie' ? 15 : 30),'model candidate cap enforced');
            check(!str_contains(json_encode($input),'protected-secret'),'credentials never sent to model');
            check(!isset($items[0]['url']),'torrent URL not sent to model');
            $picks=[];
            if($items && !($scenario==='separate' && $input['all_only'])) $picks=[choice($items[0]['id'],array_column($input['wanted_seasons'],'number'),$scenario==='movie' ? 'movie' : ($input['all_only'] ? 'all_seasons' : 'season'))];
            $result=['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode(['selections'=>$picks,'summary'=>'Verified'])]]]]];
        } elseif(str_contains($url,'127.0.0.1:9999')) {
            $action=substr($path,8);
            if($action==='auth/login') return ['status'=>200,'body'=>'Ok.'];
            if($action==='search/start') { $n=$nextSearch++; $searches[$n]=$form['pattern']; $result=['id'=>$n]; }
            elseif($action==='search/results') {
                $pattern=$searches[(int)$query['id']]; $title='Sample Movie 2025 1080p ENG'; $h=$hash;
                if($scenario!=='movie') { $title=str_contains($pattern,'S02') ? 'Sample Show S02 Complete 1080p ENG' : (str_contains($pattern,'S01') ? 'Sample Show S01 Complete 1080p ENG' : 'Sample Show S01-S02 Complete 1080p ENG'); $h=str_contains($pattern,'S02') ? str_repeat('b',40) : $hash; }
                $result=['status'=>'Stopped','results'=>[['fileName'=>$title,'fileSize'=>2*1024**3,'nbSeeders'=>30,'nbLeechers'=>5,'fileUrl'=>'magnet:?xt=urn:btih:'.$h]]];
            } elseif(in_array($action,['search/delete','search/stop','torrents/createCategory','torrents/addTags'],true)) return ['status'=>200,'body'=>''];
            elseif($action==='torrents/categories') $result=['Movies'=>[],'TV Shows'=>[]];
            elseif($action==='torrents/info') {
                $result=array_values(array_filter($fakeTorrents,fn($t)=>isset($query['tag']) ? str_contains($t['tags'],$query['tag']) : in_array($t['hash'],explode('|',$query['hashes'] ?? ''),true)));
            } elseif($action==='torrents/add') {
                $adds[]=$form; $h=TorrentPolicy::magnetHash($form['urls']);
                $fakeTorrents[$h]=['hash'=>$h,'name'=>'Chosen torrent','tags'=>$form['tags'],'progress'=>.3,'size'=>2*1024**3,'eta'=>300,'dlspeed'=>1000000,'num_seeds'=>20,'num_leechs'=>3,'state'=>'downloading'];
                return ['status'=>200,'body'=>'Ok.'];
            } else throw new RuntimeException('Unexpected downloader action '.$action);
        } else throw new RuntimeException('Unexpected outbound network request blocked.');
        return ['status'=>200,'body'=>json_encode($result,JSON_THROW_ON_ERROR)];
    });
    $worker=new Worker($config,$db,$settings,$catalog,function($email,$content) use (&$deliveries) { $deliveries[$email]=$content; });
    $requests=new Requests($config,$db,$settings,$catalog);
    $user=['id'=>$u,'role'=>'user']; $admin=['id'=>$a,'role'=>'admin'];
    $r=$requests->enqueue($user,'movie',101); $requests->enqueue($admin,'movie',101);
    check((int)$db->one('SELECT COUNT(*) AS n FROM requests')['n']===1,'same media deduplicates across users');
    for($i=0;$i<5;$i++) { $db->run('UPDATE jobs SET due_at=? WHERE kind=?',[time()-1,'acquire']); $worker->tick(); }
    check(count($adds)===1,'movie adds once');
    check($adds[0]['savepath']==='/media/Movies/Sci-Fi/','download destination sent correctly');
    check(str_contains($adds[0]['tags'],'Movie,Added by ScreenPort'),'required movie tags');
    check($adds[0]['autoTMM']==='false','auto torrent management cannot override path');
    check(!$deliveries,'notifications not sent before one minute');
    $db->run('UPDATE jobs SET due_at=? WHERE kind IN (?,?)',[time()-1,'notify','monitor']); $worker->tick();
    check(count($deliveries)===2,'user and manager both notified, manager subscriber deduplicated');
    check(str_contains($deliveries['manager@example.test']['text'],'Seeders:'),'manager email includes torrent metadata');
    check(!str_contains($deliveries['user@example.test']['text'],'Seeders:'),'user email omits manager torrent metadata');
    check(str_contains($deliveries['user@example.test']['text'],'5 minutes'),'email contains actual ETA');
    $worker->tick(); check(count($deliveries)===2,'emails not duplicated after successful delivery');
    $scenario='separate'; $fakeTorrents=[];
    $r2=$requests->enqueue($user,'tv',202);
    for($i=0;$i<10;$i++) { $db->run('UPDATE jobs SET due_at=? WHERE request_id=? AND kind=?',[time()-1,$r2['id'],'acquire']); $worker->tick(); }
    check(count($adds)===3,'separate season fallback downloads one pack per season');
    check($adds[1]['savepath']==='/media/Mature TV Shows/Sample Show/','separate show mature folder');
    check(str_contains($adds[1]['tags'],'TV Show,Added by ScreenPort'),'required TV tags');
    $listed=$requests->list($user); check(count($listed)===2,'user sees subscribed downloads');
    check(!isset($listed[0]['torrents'][0]['seeders']),'manager-only details protected for normal users');
    $onlyUser=$auth->create('outsider','outsider@example.test','A-strong-test-password','user','approved');
    check($requests->list(['id'=>$onlyUser,'role'=>'user'])===[],'users cannot see other users requests');
    $fakeTorrents=array_map(function($t) { $t['progress']=1; $t['eta']=0; return $t; },$fakeTorrents);
    $db->run("UPDATE jobs SET due_at=? WHERE request_id=? AND kind='monitor'",[time()-1,$r2['id']]); $worker->tick();
    check($db->one('SELECT status FROM requests WHERE id=?',[$r2['id']])['status']==='complete','all torrents completed updates request');
    $scenario='all_seasons'; $fakeTorrents=[];
    $r3=$requests->enqueue($user,'tv',203);
    for($i=0;$i<5;$i++) { $db->run('UPDATE jobs SET due_at=? WHERE request_id=? AND kind=?',[time()-1,$r3['id'],'acquire']); $worker->tick(); }
    check(count($adds)===4,'all-season workflow selects one pack');
    check($adds[3]['savepath']==='/media/Mature TV Shows/','all-season pack uses TV root without show folder');
    check(count(array_filter($searches,fn($s)=>str_contains($s,'S01')))===1,'all-season success skips separate season searches');
    $content=Mailer::content(['title'=>'<script>alert(1)</script>','type'=>'movie','year'=>'2025','rating'=>'PG','release_date'=>'2025-01-01','overview'=>'<img onerror=x>'],[],true);
    check(!str_contains($content['html'],'<script>'),'email HTML escaped');
    check(Mailer::eta(null)==='Not available yet','unknown ETA not fabricated');
    echo "PASS: $tests assertions. External downloads, paid API calls, and SMTP deliveries were simulated.\n";
} catch(Throwable $e) { echo $e->getMessage()."\n"; exit(1); }
finally { Http::setTestTransport(null); }
