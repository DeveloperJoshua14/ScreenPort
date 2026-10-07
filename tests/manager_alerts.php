<?php
declare(strict_types=1);
// Isolated database, fake integrations and simulated SMTP; no live messages are sent.
use ScreenPort\{ApiError,Config,Db,Http,Mailer,ManagerAlerts,Requests,Settings,Worker};
$root=dirname(__DIR__); $private=$root.'/.test-output/manager-'.bin2hex(random_bytes(4)); mkdir($private,0700,true);
putenv('SCREENPORT_ENV_FILE='.$private.'/.env'); putenv('APP_ENV=local'); putenv('APP_URL=http://127.0.0.1:8098');
putenv('APP_KEY='.base64_encode(random_bytes(32))); putenv('STORAGE_PATH='.$private);
file_put_contents($private.'/.env',"DOWNLOAD_MANAGER_EMAIL=downloads@example.test\nMAIL_FROM_ADDRESS=from@example.test\nSMTP_HOST=fake.example\nTMDB_READ_ACCESS_TOKEN=fake\nQBITTORRENT_URL=http://127.0.0.1:9999\nQBITTORRENT_USERNAME=fake\nQBITTORRENT_PASSWORD=fake\nOPENAI_API_KEY=private-credential-example\n");
require $root.'/app/bootstrap.php'; $checks=0;
function verify(bool $ok,string $message): void { global $checks; $checks++; if(!$ok) throw new RuntimeException('FAIL: '.$message); }
function blocked(callable $fn,string $message): void { try { $fn(); } catch(Throwable $e) { verify(true,$message); return; } verify(false,$message); }
try {
    $approved=$auth->create('admin','admin@example.test','ValidPassword!2026','admin','approved');
    verify(!$db->one('SELECT id FROM manager_alerts'),'approved admin creation does not generate approval email');
    $pending=$auth->create('newperson','newperson@example.test','UniqueSignupPassword!2026');
    $alert=$db->one('SELECT * FROM manager_alerts');
    verify($alert['kind']==='account_requested' && $alert['recipient']==='downloads@example.test','account manager defaults to download manager');
    verify($db->one('SELECT status FROM users WHERE id=?',[$pending])['status']==='pending','notification does not approve account');
    verify(!str_contains($alert['payload'],'UniqueSignupPassword') && !str_contains($alert['payload'],'password_hash'),'queued notification contains no password material');
    blocked(fn()=>$auth->create('newperson','newperson@example.test','UniqueSignupPassword!2026'),'duplicate signup rejected');
    blocked(fn()=>$auth->create('invalid','invalid@example.test','short'),'invalid signup rejected');
    verify((int)$db->one('SELECT COUNT(*) AS n FROM manager_alerts')['n']===1,'duplicate and invalid signups do not generate notification spam');
    $settings->save(['ACCOUNT_MANAGER_EMAIL'=>'accounts@example.test']);
    $other=$auth->create('anotherperson','another@example.test','UniqueSignupPassword!2026');
    verify($db->one('SELECT recipient FROM manager_alerts WHERE user_id=?',[$other])['recipient']==='accounts@example.test','explicit account manager receives new account alerts');
    blocked(fn()=>$settings->save(['ACCOUNT_MANAGER_EMAIL'=>'bad address']),'invalid account manager email rejected');
    $settings->save(['ACCOUNT_MANAGER_EMAIL'=>'']);
    $last=$auth->create('thirdperson','third@example.test','UniqueSignupPassword!2026');
    verify($db->one('SELECT recipient FROM manager_alerts WHERE user_id=?',[$last])['recipient']==='downloads@example.test','blank account manager restores fallback');
    $messages=[];
    $alerts=new ManagerAlerts($config,$db,$settings,function($email,$content) use (&$messages) { $messages[]=[$email,$content]; });
    $alerts->deliver();
    verify(count($messages)===3,'account notifications delivered separately to configured managers');
    verify(str_contains($messages[0][1]['text'],'newperson@example.test') && str_contains($messages[0][1]['text'],'Administration'),'account message includes identity and approval instructions');
    verify(!str_contains(json_encode($messages),'UniqueSignupPassword'),'account password never appears in email');
    (new ManagerAlerts($config,new Db($config),$settings,function($email,$content) use (&$messages) { $messages[]=[$email,$content]; }))->deliver();
    verify(count($messages)===3,'sent alerts do not repeat after process/database restart');
    $requests=new Requests($config,$db,$settings,$catalog); $user=['id'=>$approved,'role'=>'admin'];
    $settings->save(['DOWNLOADS_ENABLED'=>'false']);
    blocked(fn()=>$requests->enqueue($user,'movie',123),'paused download is rejected');
    blocked(fn()=>$requests->enqueue($user,'movie',123),'repeat paused download is rejected');
    $submission=$db->one("SELECT * FROM manager_alerts WHERE kind='download_submission_failed'");
    verify($submission['recipient']==='downloads@example.test' && !$submission['request_id'],'intake failure notifies download manager before a request row exists');
    verify((int)$db->one("SELECT COUNT(*) AS n FROM manager_alerts WHERE kind='download_submission_failed'")['n']===1,'repeat intake failure deduplicated for same user/media/day');
    blocked(fn()=>$requests->enqueue($user,'other',0),'invalid media input rejected');
    verify((int)$db->one("SELECT COUNT(*) AS n FROM manager_alerts WHERE kind='download_submission_failed'")['n']===1,'invalid input does not trigger manager alerts');
    $alerts->deliver();
    verify(str_contains($messages[3][1]['text'],'paused') && str_contains($messages[3][1]['text'],'TMDB #123'),'submission email includes reason and media reference');
    $settings->save(['DOWNLOADS_ENABLED'=>'true']);
    $outbound=0;
    Http::setTestTransport(function() use (&$outbound) { $outbound++; throw new RuntimeException('raw third-party private-credential-example https://private.example.test/?key=secret'); });
    blocked(fn()=>$requests->enqueue($user,'movie',456),'catalog outage prevents submission');
    $outage=$db->one("SELECT payload FROM manager_alerts WHERE event_key LIKE 'submission:%:movie:456:%'")['payload'];
    verify(!str_contains($outage,'private-credential-example') && !str_contains($outage,'private.example.test'),'third-party exception text never stored or emailed');
    $alerts->deliver();
    $db->run('INSERT INTO requests(media_type,media_id,media,created_at,updated_at) VALUES(?,?,?,?,?)',['movie',789,json_encode(['type'=>'movie','id'=>789,'title'=>'<script>Sample</script>']),time(),time()]);
    $rid=$db->id();
    $alerts->acquisition($rid,'private-credential-example https://private.example.test/?key=secret user@example.test',false,1);
    $alerts->acquisition($rid,'Another failure',false,2);
    $alerts->acquisition($rid,'Final failure',true,3);
    verify((int)$db->one('SELECT COUNT(*) AS n FROM manager_alerts WHERE request_id=?',[$rid])['n']===2,'only initial and final failure alerts queued per retry cycle');
    $payloads=json_encode($db->all('SELECT payload FROM manager_alerts WHERE request_id=?',[$rid]));
    verify(!str_contains($payloads,'private-credential-example') && !str_contains($payloads,'key=secret') && !str_contains($payloads,'user@example.test'),'authored failure messages are redacted before storage');
    $alerts->deliver();
    verify(str_contains($messages[5][1]['text'],'retry automatically'),'initial failure says retries continue');
    verify(str_contains($messages[6][1]['text'],'Automatic retries have stopped'),'terminal failure includes review/retry instructions');
    verify(!str_contains($messages[6][1]['html'],'<script>'),'untrusted media titles escaped in failure email');
    $db->run("UPDATE requests SET status='failed' WHERE id=?",[$rid]);
    $db->run("INSERT INTO jobs(request_id,kind,due_at,status) VALUES(?,'acquire',?,'failed')",[$rid,time()]);
    $requests->retry($rid,$approved); $alerts->acquisition($rid,'New retry failure',false,1);
    verify((int)$db->one('SELECT COUNT(*) AS n FROM manager_alerts WHERE request_id=?',[$rid])['n']===3,'admin retry permits a fresh initial failure notification');
    $failureDelivery=new ManagerAlerts($config,$db,$settings,fn()=>throw new RuntimeException('SMTP password must never be logged'));
    for($i=0;$i<5;$i++) { $db->run("UPDATE manager_alerts SET due_at=? WHERE status='pending'",[time()-1]); $failureDelivery->deliver(); }
    verify($db->one("SELECT status FROM manager_alerts WHERE event_key=?",['acquisition:'.$rid.':1:initial'])['status']==='failed','SMTP delivery retries five times then appears failed');
    verify(!str_contains(json_encode($db->all('SELECT detail FROM audit')),'SMTP password'),'SMTP exception details stay out of activity log');
    $db->run("UPDATE manager_alerts SET status='pending',attempts=0,due_at=? WHERE status='failed'",[time()]); $alerts->deliver();
    verify($db->one("SELECT status FROM manager_alerts WHERE event_key=?",['acquisition:'.$rid.':1:initial'])['status']==='sent','failed manager email recovers after retry');
    // A qBittorrent-accepted torrent that never transfers data is also reported.
    $monitorMode='stalled';
    Http::setTestTransport(function($method,$url,$headers,$body) use (&$monitorMode) {
        $path=parse_url($url,PHP_URL_PATH);
        if(str_ends_with($path,'auth/login')) return ['status'=>200,'body'=>'Ok.'];
        if(str_ends_with($path,'app/version')) return ['status'=>200,'body'=>'v5.1.2'];
        if(str_ends_with($path,'torrents/info')) {
            if($monitorMode==='outage') return ['status'=>500,'body'=>'private-credential-example'];
            if($monitorMode==='missing') return ['status'=>200,'body'=>'[]'];
            return ['status'=>200,'body'=>json_encode([['hash'=>str_repeat('a',40),'name'=>'Sample','state'=>$monitorMode==='error' ? 'error' : 'stalledDL','progress'=>0,'downloaded'=>0,'size'=>2*1024**3]])];
        }
        throw new RuntimeException('Unexpected outbound call blocked.');
    });
    $db->run("UPDATE jobs SET status='done' WHERE request_id=?",[$rid]);
    $db->run("UPDATE requests SET status='downloading' WHERE id=?",[$rid]);
    $db->run("INSERT INTO torrents(request_id,candidate_id,name,url,hash,tag,save_path,state,added_at) VALUES(?,?,?,?,?,?,?,'added',?)",[$rid,'one','Sample','magnet:?xt=urn:btih:'.str_repeat('a',40),str_repeat('a',40),'tag','/media/Movies/Other/',time()-601]);
    $db->run("INSERT INTO jobs(request_id,kind,due_at) VALUES(?,'monitor',?)",[$rid,time()-1]);
    $worker=new Worker($config,$db,$settings,$catalog,function($email,$content) use (&$messages) { $messages[]=[$email,$content]; });
    $worker->tick();
    $monitor=$db->one("SELECT * FROM manager_alerts WHERE event_key=?",['monitoring:'.$rid.':1']);
    verify($monitor && $monitor['status']==='sent','accepted but stalled torrent triggers manager alert after timeout');
    verify(str_contains($messages[array_key_last($messages)][1]['text'],'no confirmed data') && !str_contains($messages[array_key_last($messages)][1]['text'],'Automatic retries have stopped'),'stalled alert explains monitoring accurately');
    $count=count($messages); $db->run("UPDATE jobs SET due_at=? WHERE request_id=? AND kind='monitor'",[time()-1,$rid]); $worker->tick();
    verify(count($messages)===$count,'repeated stalled monitoring does not spam manager');
    foreach(['error'=>'file or disk error','missing'=>'missing from qBittorrent','outage'=>'Could not verify'] as $mode=>$reason) {
        $monitorMode=$mode;
        $db->run('INSERT INTO requests(media_type,media_id,media,status,created_at,updated_at) VALUES(?,?,?,?,?,?)',['tv',800+count($messages),json_encode(['type'=>'tv','id'=>800+count($messages),'title'=>'Generic Test Series']),'downloading',time(),time()]);
        $caseId=$db->id();
        $db->run("INSERT INTO torrents(request_id,candidate_id,name,url,hash,tag,save_path,state,added_at) VALUES(?,?,?,?,?,?,?,'added',?)",[$caseId,'one','Generic Test Series','magnet:?xt=urn:btih:'.str_repeat('a',40),str_repeat('a',40),'tag-'.$caseId,'/media/TV Shows/',time()]);
        $db->run("INSERT INTO jobs(request_id,kind,due_at) VALUES(?,'monitor',?)",[$caseId,time()-1]);
        $worker->tick();
        $caseAlert=$db->one("SELECT * FROM manager_alerts WHERE event_key=?",['monitoring:'.$caseId.':0']);
        verify($caseAlert && $caseAlert['status']==='sent' && str_contains($caseAlert['payload'],$reason),'monitoring alerts cover '.$mode.' before progress timeout');
        verify(!str_contains($caseAlert['payload'],'private-credential-example'),'monitoring errors do not leak service responses: '.$mode);
    }
    $settings->save(['DOWNLOAD_START_TIMEOUT_MINUTES'=>'20']);
    blocked(fn()=>$settings->save(['DOWNLOAD_START_TIMEOUT_MINUTES'=>'0']),'invalid start timeout rejected');
    $demoFile=$private.'/demo.env'; file_put_contents($demoFile,"APP_ENV=local\nDEMO_MODE=true\nAPP_KEY=".$config->get('APP_KEY')."\nSTORAGE_PATH=".$private."\n");
    $demo=new Config($demoFile); $before=(int)$db->one('SELECT COUNT(*) AS n FROM manager_alerts')['n'];
    (new ManagerAlerts($demo,$db,$settings))->account($approved);
    verify((int)$db->one('SELECT COUNT(*) AS n FROM manager_alerts')['n']===$before,'preview mode does not queue emails');
    echo "PASS: $checks manager notification checks. No live downloads, model requests, or email.\n";
} catch(Throwable $e) { echo $e->getMessage()."\n"; exit(1); }
finally { Http::setTestTransport(null); }
