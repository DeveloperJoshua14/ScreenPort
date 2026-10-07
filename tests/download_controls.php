<?php
declare(strict_types=1);
// Real worker/control workflow against isolated SQLite, simulated qBittorrent and SMTP.
use ScreenPort\{ApiError,Config,Db,FolderAlerts,Http,ManagerAlerts,Qbit,RequestControls,Requests,Worker};
$root=dirname(__DIR__); $private=$root.'/.test-output/controls-'.bin2hex(random_bytes(4)); mkdir($private,0700,true);
putenv('SCREENPORT_ENV_FILE='.$private.'/.env'); putenv('APP_ENV=local'); putenv('APP_URL=http://127.0.0.1:8098');
putenv('APP_KEY='.base64_encode(random_bytes(32))); putenv('STORAGE_PATH='.$private);
file_put_contents($private.'/.env',"TMDB_READ_ACCESS_TOKEN=fake\nQBITTORRENT_URL=http://qbit.example.test\nQBITTORRENT_USERNAME=fake\nQBITTORRENT_PASSWORD=fake\nOPENAI_API_KEY=fake\nDOWNLOAD_MANAGER_EMAIL=manager@example.test\nSMTP_HOST=fake.example\nMAIL_FROM_ADDRESS=from@example.test\n");
require $root.'/app/bootstrap.php'; $checks=0;
function verify(bool $ok,string $label): void { global $checks; $checks++; if(!$ok) throw new RuntimeException('FAIL: '.$label); }
function blocked(callable $fn,int $status,string $label): void { try { $fn(); } catch(ApiError $e) { verify($e->status===$status,$label); return; } verify(false,$label); }
function seed(string $status='queued',bool $torrent=false): int {
    global $db,$fake;
    $id=1000+(int)$db->one('SELECT COUNT(*) n FROM requests')['n'];
    $media=['id'=>$id,'type'=>'movie','title'=>'Control Test '.$id,'year'=>'2025','rating'=>'PG','overview'=>'Test','release_date'=>'2025-01-01','available'=>true];
    $db->run('INSERT INTO requests(media_type,media_id,media,status,created_at,updated_at) VALUES(?,?,?,?,?,?)',['movie',$id,json_encode($media),$status,time(),time()]); $rid=$db->id();
    $db->run('INSERT INTO jobs(request_id,kind,status,due_at) VALUES(?,?,?,?)',[$rid,'acquire',$status==='failed' ? 'failed' : ($torrent ? 'done' : 'pending'),time()+3600]);
    if($torrent) {
        $hash=sha1('control-'.$rid); $tag='ScreenPort-'.$rid.'-t_test';
        $fake[$hash]=['hash'=>$hash,'name'=>$media['title'],'tags'=>'Movie,Added by ScreenPort,'.$tag,'state'=>'downloading','progress'=>.1,'size'=>1000,'downloaded'=>100,'save_path'=>'/media/Movies/Other/'];
        $db->run("INSERT INTO torrents(request_id,candidate_id,name,url,hash,tag,save_path,state,added_at) VALUES(?,'t_test',?,?,?,?,?,'added',?)",[$rid,$media['title'],'magnet:?xt=urn:btih:'.$hash,$hash,$tag,'/media/Movies/Other/',time()]);
        $db->run("INSERT INTO jobs(request_id,kind,status,due_at) VALUES(?,'monitor','pending',?)",[$rid,time()+3600]);
    }
    return $rid;
}
try {
    $aid=$auth->create('admin','manager@example.test','FakePassword!2026','admin','approved');
    $uid=$auth->create('member','member@example.test','FakePassword!2026','user','approved');
    $admin=['id'=>$aid,'role'=>'admin']; $member=['id'=>$uid,'role'=>'user'];
    $controls=new RequestControls($config,$db,$settings); $requests=new Requests($config,$db,$settings,$catalog);
    $fake=[]; $calls=[]; $legacy=false; $failStop=false; $deleteUncertain=false; $onAdd=null; $onLookup=null; $onInfo=null; $failSearch=null;
    Http::setTestTransport(function($method,$url,$headers,$body) use(&$fake,&$calls,&$legacy,&$failStop,&$deleteUncertain,&$onAdd,&$onLookup,&$onInfo,&$failSearch) {
        parse_str(parse_url($url,PHP_URL_QUERY) ?? '',$params); if(is_string($body)) parse_str($body,$params);
        $path=parse_url($url,PHP_URL_PATH); $calls[]=[$path,$params];
        if(str_contains($url,'api.themoviedb.org')) {
            if($onLookup) { $fn=$onLookup; $onLookup=null; $fn(); }
            preg_match('~/movie/(\d+)$~',$path,$m); $id=(int)$m[1];
            return ['status'=>200,'body'=>json_encode(['id'=>$id,'title'=>'Control Test '.$id,'release_date'=>'2025-01-01','original_language'=>'en','genres'=>[], 'release_dates'=>['results'=>[['iso_3166_1'=>'US','release_dates'=>[['type'=>4,'release_date'=>'2025-02-01T00:00:00Z','certification'=>'PG']]]]]])];
        }
        if(str_ends_with($path,'auth/login')) return ['status'=>200,'body'=>'Ok.'];
        if(str_ends_with($path,'app/version')) return ['status'=>200,'body'=>'5.1.2'];
        if(str_ends_with($path,'search/start') && $failSearch) { $fn=$failSearch; $failSearch=null; $fn(); throw new RuntimeException('Simulated search outage'); }
        if(str_contains($path,'/search/')) return ['status'=>200,'body'=>''];
        if(str_ends_with($path,'torrents/categories')) return ['status'=>200,'body'=>'{"Movies":{}}'];
        if(str_ends_with($path,'torrents/info')) {
            if($onInfo) { $fn=$onInfo; $onInfo=null; $fn(); }
            $rows=array_values(array_filter($fake,fn($t)=>isset($params['hashes']) ? in_array($t['hash'],explode('|',$params['hashes']),true) : in_array($params['tag'] ?? '',explode(',',$t['tags']),true)));
            return ['status'=>200,'body'=>json_encode($rows)];
        }
        if(str_ends_with($path,'torrents/add')) {
            preg_match('/btih:([a-f0-9]{40})/',$params['urls'],$m); $hash=$m[1];
            $fake[$hash]=['hash'=>$hash,'name'=>'New control torrent','tags'=>$params['tags'],'state'=>'downloading','progress'=>0,'size'=>1000,'downloaded'=>0];
            if($onAdd) { $fn=$onAdd; $onAdd=null; $fn(); }
            return ['status'=>200,'body'=>'Ok.'];
        }
        if(str_ends_with($path,'torrents/stop') || str_ends_with($path,'torrents/pause')) {
            if($failStop) return ['status'=>503,'body'=>'Private service response'];
            if($legacy && str_ends_with($path,'/stop')) return ['status'=>404,'body'=>''];
            foreach(explode('|',$params['hashes']) as $hash) if(isset($fake[$hash])) $fake[$hash]['state']='stoppedDL';
            return ['status'=>200,'body'=>''];
        }
        if(str_ends_with($path,'torrents/start') || str_ends_with($path,'torrents/resume')) {
            if($legacy && str_ends_with($path,'/start')) return ['status'=>404,'body'=>''];
            foreach(explode('|',$params['hashes']) as $hash) if(isset($fake[$hash])) $fake[$hash]['state']='downloading';
            return ['status'=>200,'body'=>''];
        }
        if(str_ends_with($path,'torrents/delete')) {
            if(($params['deleteFiles'] ?? '')!=='false') throw new RuntimeException('Files must never be deleted');
            foreach(explode('|',$params['hashes']) as $hash) unset($fake[$hash]);
            if($deleteUncertain) { $deleteUncertain=false; throw new RuntimeException('Simulated lost delete response'); }
            return ['status'=>200,'body'=>''];
        }
        if(str_ends_with($path,'torrents/removeTags')) {
            $hash=$params['hashes']; $fake[$hash]['tags']=implode(',',array_diff(explode(',',$fake[$hash]['tags']),[$params['tags']]));
            return ['status'=>200,'body'=>''];
        }
        throw new RuntimeException('Unexpected simulated integration');
    });
    $messages=[]; $worker=new Worker($config,$db,$settings,$catalog,function($email,$content) use(&$messages) { $messages[]=[$email,$content]; });
    $failed=seed('failed');
    blocked(fn()=>$controls->command($failed,'remove',$member),403,'member cannot control download');
    blocked(fn()=>$controls->command(9999,'remove',$admin),404,'missing request validated');
    blocked(fn()=>$controls->command($failed,'delete-files',$admin),422,'unsupported destructive command rejected');
    $db->run("INSERT INTO emails(request_id,recipient,audience,status,due_at) VALUES(?,'member@example.test','user','pending',?)",[$failed,time()]);
    $controls->command($failed,'remove',$admin); verify($db->one('SELECT status FROM requests WHERE id=?',[$failed])['status']==='removing','removal intent persisted immediately');
    $before=count($calls); $worker->tick(); verify(count($calls)===$before,'failed request with no search/torrents needs no qBittorrent connection');
    verify($db->one('SELECT status FROM requests WHERE id=?',[$failed])['status']==='removed','failed request removed');
    verify($db->one('SELECT status FROM jobs WHERE request_id=?',[$failed])['status']==='cancelled','removed request cannot retry automatically');
    verify($db->one('SELECT status FROM emails WHERE request_id=?',[$failed])['status']==='cancelled' && !$messages,'obsolete email cancelled without sending');
    verify(!$requests->list($admin) && count($requests->list($admin,true))===1,'removed requests hidden except admin history filter');
    blocked(fn()=>$requests->list($member,true),403,'member cannot view removed history');
    $mid=(int)$db->one('SELECT media_id FROM requests WHERE id=?',[$failed])['media_id'];
    blocked(fn()=>$requests->enqueue($member,'movie',$mid),409,'member cannot silently recreate removed request');
    verify(!$db->one('SELECT id FROM manager_alerts'),'intentional blocked request generates no failure alert');
    blocked(fn()=>$requests->retry($failed,$aid),409,'removed request cannot bypass restore with retry');
    $controls->command($failed,'restore',$admin); verify($db->one('SELECT status FROM requests WHERE id=?',[$failed])['status']==='failed','restore requires explicit admin retry');
    $worker->tick(); verify(count($calls)===$before,'restoration does not immediately start a download');
    $queued=seed(); $controls->command($queued,'suspend',$admin); $worker->tick();
    verify($db->one('SELECT status FROM requests WHERE id=?',[$queued])['status']==='suspended','queued request suspended before integrations');
    $db->run('UPDATE jobs SET due_at=? WHERE request_id=?',[time()-1,$queued]); $worker->tick(); verify(count($calls)===$before,'due jobs remain on hold while suspended');
    $controls->command($queued,'resume',$admin); $controls->process(); verify(!$db->one('SELECT request_id FROM request_controls WHERE request_id=?',[$queued]),'resume releases durable hold');
    $db->run('UPDATE jobs SET due_at=? WHERE request_id=?',[time()+3600,$queued]);
    $active=seed('downloading',true); $hash=sha1('control-'.$active); $controls->command($active,'suspend',$admin); $worker->tick();
    verify($fake[$hash]['state']==='stoppedDL','active torrent stopped through qBittorrent');
    verify($db->one('SELECT status FROM requests WHERE id=?',[$active])['status']==='suspended','UI suspended only after remote stop succeeds');
    $controls->command($active,'resume',$admin); $worker->tick(); verify($fake[$hash]['state']==='downloading','resume restarts torrent stopped by ScreenPort');
    $external=seed('downloading',true); $eh=sha1('control-'.$external); $fake[$eh]['state']='stoppedDL';
    $controls->command($external,'suspend',$admin); $worker->tick(); $controls->command($external,'resume',$admin); $worker->tick();
    verify($fake[$eh]['state']==='stoppedDL','resume does not undo a preexisting external pause');
    $shared=seed('downloading',true); $sh=sha1('control-'.$shared); $fake[$sh]['tags'].=',ScreenPort-999-t_other';
    $controls->command($shared,'suspend',$admin); $worker->tick(); verify($fake[$sh]['state']==='downloading','shared torrent continues for other requests');
    $controls->command($shared,'remove',$admin); $worker->tick(); verify(isset($fake[$sh]) && !str_contains($fake[$sh]['tags'],'ScreenPort-'.$shared.'-'),'shared torrent detached without deleting other request torrent');
    $controls->command($active,'remove',$admin); $worker->tick(); verify(!isset($fake[$hash]),'unshared linked torrent entry removed');
    verify($db->one('SELECT state FROM torrents WHERE request_id=?',[$active])['state']==='removed','removed torrent record retained for safe restore');
    $legacy=true; $old=seed('downloading',true); $oh=sha1('control-'.$old); $controls->command($old,'suspend',$admin); $worker->tick(); $controls->command($old,'resume',$admin); $worker->tick();
    verify($fake[$oh]['state']==='downloading' && array_filter($calls,fn($c)=>str_ends_with($c[0],'torrents/pause')) && array_filter($calls,fn($c)=>str_ends_with($c[0],'torrents/resume')),'qBittorrent 4.x pause/resume fallback supported'); $legacy=false;
    $offline=seed('downloading',true); $failStop=true; $controls->command($offline,'suspend',$admin); $worker->tick();
    $c=$db->one('SELECT * FROM request_controls WHERE request_id=?',[$offline]);
    verify($c['status']==='pending' && (int)$c['attempts']===1,'unreachable remote control remains pending with retry');
    verify(!str_contains($c['last_error'],'Private service response'),'control errors expose no raw responses');
    verify($db->one('SELECT status FROM requests WHERE id=?',[$offline])['status']==='suspending','failure does not falsely claim remote pause succeeded');
    $failStop=false; $db->run('UPDATE request_controls SET due_at=? WHERE request_id=?',[time()-1,$offline]); $worker->tick();
    verify($db->one('SELECT status FROM requests WHERE id=?',[$offline])['status']==='suspended','pending stop recovers on later worker tick');
    $uncertain=seed('downloading',true); $deleteUncertain=true; $controls->command($uncertain,'remove',$admin); $worker->tick();
    verify($db->one('SELECT status FROM requests WHERE id=?',[$uncertain])['status']==='removing','uncertain deletion remains blocked');
    $db->run('UPDATE request_controls SET due_at=? WHERE request_id=?',[time()-1,$uncertain]); $worker->tick(); verify($db->one('SELECT status FROM requests WHERE id=?',[$uncertain])['status']==='removed','lost delete response reconciled without restoring work');
    $racing=seed(); $rmid=(int)$db->one('SELECT media_id FROM requests WHERE id=?',[$racing])['media_id']; $rh=sha1('race');
    $plan=['media'=>['id'=>$rmid,'type'=>'movie'],'separate'=>false,'picks'=>[['id'=>'t_race','name'=>'Test race','url'=>'magnet:?xt=urn:btih:'.$rh,'hash'=>$rh,'seasons'=>[]]]];
    $db->run('UPDATE requests SET plan=? WHERE id=?',[json_encode($plan),$racing]); $db->run('UPDATE jobs SET due_at=? WHERE request_id=?',[time()-1,$racing]);
    $onAdd=fn()=>$controls->command($racing,'remove',$admin); $worker->tick();
    verify(!isset($fake[$rh]) && $db->one('SELECT status FROM requests WHERE id=?',[$racing])['status']==='removed','remove arriving during an add cleans up the accepted torrent');
    verify(!$db->one("SELECT id FROM jobs WHERE request_id=? AND status='pending'",[$racing]),'in-flight add cannot reactivate removed jobs');
    $guarded=seed(); $gmid=(int)$db->one('SELECT media_id FROM requests WHERE id=?',[$guarded])['media_id']; $plan['media']['id']=$gmid;
    $db->run('UPDATE requests SET plan=? WHERE id=?',[json_encode($plan),$guarded]); $db->run('UPDATE jobs SET due_at=? WHERE request_id=?',[time()-1,$guarded]);
    $addCount=count(array_filter($calls,fn($c)=>str_ends_with($c[0],'torrents/add'))); $onLookup=fn()=>$controls->command($guarded,'suspend',$admin); $worker->tick();
    verify(count(array_filter($calls,fn($c)=>str_ends_with($c[0],'torrents/add')))===$addCount,'suspend before add side effect prevents torrent submission');
    verify($db->one('SELECT status FROM requests WHERE id=?',[$guarded])['status']==='suspended','worker cancellation does not overwrite suspended status');
    $searching=seed(); $db->run('UPDATE jobs SET due_at=? WHERE request_id=?',[time()-1,$searching]); $failSearch=fn()=>$controls->command($searching,'remove',$admin); $worker->tick();
    verify($db->one('SELECT status FROM requests WHERE id=?',[$searching])['status']==='removed','admin remove wins over concurrent worker failure');
    verify(!$db->one('SELECT id FROM manager_alerts WHERE request_id=?',[$searching]),'concurrent controlled error does not create failure-email spam');
    $savedSearch=seed('searching');
    $db->run('UPDATE jobs SET payload=? WHERE request_id=?',[json_encode(['phase'=>'movie','search_id'=>77,'started_at'=>time(),'picks'=>[]]),$savedSearch]);
    $controls->command($savedSearch,'suspend',$admin); (new RequestControls($config,new Db($config),$settings))->process();
    $payload=json_decode($db->one('SELECT payload FROM jobs WHERE request_id=?',[$savedSearch])['payload'],true);
    verify(!isset($payload['search_id']) && $payload['phase']==='movie','stopped search ID cleared while continuation phase retained');
    verify(array_filter($calls,fn($c)=>str_ends_with($c[0],'search/stop') && ($c[1]['id'] ?? '')==='77') && array_filter($calls,fn($c)=>str_ends_with($c[0],'search/delete') && ($c[1]['id'] ?? '')==='77'),'active search stopped and deleted through saved worker session');
    verify($db->one('SELECT status FROM requests WHERE id=?',[$savedSearch])['status']==='suspended','control survives new database connection and worker restart');
    $newer=seed('downloading',true); $nh=sha1('control-'.$newer); $controls->command($newer,'suspend',$admin);
    $onInfo=fn()=>$controls->command($newer,'remove',$admin); $controls->process();
    verify($db->one('SELECT status FROM requests WHERE id=?',[$newer])['status']==='removing' && $fake[$nh]['state']==='downloading','new remove command supersedes stale suspend before remote mutation');
    $controls->process(); verify(!isset($fake[$nh]) && $db->one('SELECT status FROM requests WHERE id=?',[$newer])['status']==='removed','superseding command eventually applied');
    $held=seed('failed');
    $db->run("INSERT INTO emails(request_id,recipient,audience,due_at) VALUES(?,'member@example.test','user',?)",[$held,time()]);
    (new ManagerAlerts($config,$db,$settings))->acquisition($held,'No suitable candidates',true,3);
    (new FolderAlerts($config,$db,$settings))->queue($held,['type'=>'movie'],'/media/Movies/Control Test New/');
    $controls->command($held,'suspend',$admin); $beforeMessages=count($messages); $worker->tick();
    verify(count($messages)===$beforeMessages,'suspension holds progress, failure and folder emails');
    verify($db->one('SELECT status FROM manager_alerts WHERE request_id=?',[$held])['status']==='pending','held failure alert retained for later resume');
    $controls->command($held,'remove',$admin); $worker->tick();
    verify($db->one('SELECT status FROM manager_alerts WHERE request_id=?',[$held])['status']==='cancelled' && $db->one('SELECT status FROM folder_alerts WHERE request_id=?',[$held])['status']==='cancelled','removal cancels request failure and folder alerts');
    $q=new Qbit($settings); $before=count($calls); try { $q->remove(['all']); verify(false,'wildcard removal rejected'); } catch(RuntimeException $e) { verify(count($calls)===$before,'wildcard removal rejected before network'); }
    verify(!array_filter($calls,fn($c)=>isset($c[1]['deleteFiles']) && $c[1]['deleteFiles']!=='false'),'every torrent deletion preserves downloaded files');
    $demoFile=$private.'/demo.env'; file_put_contents($demoFile,"DEMO_MODE=true\nAPP_KEY=".$config->get('APP_KEY')."\nSTORAGE_PATH=".$private."\n");
    blocked(fn()=>(new RequestControls(new Config($demoFile),$db,$settings))->command($queued,'remove',$admin),403,'demo controls cannot mutate downloads');
    echo "PASS: $checks admin download-control checks. qBittorrent and SMTP simulated; no live changes.\n";
} catch(Throwable $e) { echo $e->getMessage()."\n"; exit(1); }
finally { Http::setTestTransport(null); }
