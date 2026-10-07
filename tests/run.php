<?php
declare(strict_types=1);
// No live downloads, SMTP, or paid model requests: every external request is intercepted.
use ScreenPort\{ApiError,Auth,Catalog,Config,Db,FolderAlerts,Http,Mailer,MediaRules,MovieFolders,Requests,SearchLog,Settings,TorrentPolicy,Worker};
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
    'adult'=>false,'belongs_to_collection'=>['id'=>987654,'name'=>'Sample Collection','poster_path'=>'/sample.jpg'],
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
    $m=['type'=>'movie','title'=>'Sample Movie','original_language'=>'en','genres'=>['Science Fiction'],'rating'=>'PG-13','year'=>'2025'];
    check(MediaRules::path($m,false,$settings)==='/media/Movies/Other/','unmapped genre uses existing Other folder');
    check(count(MovieFolders::existing($settings))===20,'all twenty supplied movie folders configured');
    $genres=[['Family','Animation'],['Romance','Comedy'],['Romance','Drama'],['Horror','Mystery'],['Mystery','Drama'],['Crime'],['Action','Adventure'],['Adventure','Drama'],['Drama'],['Documentary'],['Comedy']];
    $destinations=['Kids','RomCom','Romance','Horror','Murder Mysteries','Murder Mysteries','Action','Adventure','Drama','Other','Other'];
    foreach($genres as $i=>$genre) check(MovieFolders::folder(array_replace($m,['genres'=>$genre,'rating'=>'PG']),$settings)===$destinations[$i],'movie genre destination '.$destinations[$i]);
    check(MovieFolders::folder(array_replace($m,['genres'=>['Animation'],'rating'=>'R']),$settings)==='Other','adult animation is not routed to Kids');
    check(MovieFolders::folder(array_replace($m,['genres'=>['Action'],'rating'=>'R']),$settings)==='Action','R-rated action remains in Action');
    check(MovieFolders::folder(array_replace($m,['genres'=>['Action'],'rating'=>'NC-17']),$settings)==='Adults','NC-17 routes to Adults');
    check(MovieFolders::folder(array_replace($m,['adult'=>true]),$settings)==='Adults','adult metadata routes to Adults');
    $collections=['Divergent Collection'=>'Divergent','Harry Potter Collection'=>'Harry Potter','The Hunger Games Collection'=>'Hunger Games','James Bond Collection'=>'James Bond','The Maze Runner Collection'=>'Maze Runner','Pirates of the Caribbean Collection'=>'Pirates of the Caribbean','Star Wars Collection'=>'Star Wars','Twilight Collection'=>'Twilight','X-Men Collection'=>'X-Men','The Wolverine Collection'=>'X-Men','Deadpool Collection'=>'X-Men','Spider-Man (MCU) Collection'=>'Marvel Cinematic Universe','The Avengers Collection'=>'Marvel Cinematic Universe'];
    foreach($collections as $name=>$folder) check(MovieFolders::folder(array_replace($m,['collection'=>['name'=>$name],'genres'=>['Romance','Comedy']]),$settings)===$folder,'franchise has priority: '.$folder);
    check(MovieFolders::folder(array_replace($m,['collection'=>['id'=>1241,'name'=>'Localized name']]),$settings)==='Harry Potter','collection identity works independently of name');
    check(MovieFolders::folder(array_replace($m,['title'=>'Twilight Zone','genres'=>['Horror']]),$settings)==='Horror','unrelated title does not imply franchise membership');
    foreach([330459=>'Star Wars',348350=>'Star Wars',533535=>'Marvel Cinematic Universe',566525=>'Marvel Cinematic Universe'] as $id=>$folder) check(MovieFolders::folder(array_replace($m,['id'=>$id]),$settings)===$folder,'standalone franchise identity '.$id);
    $tv=['type'=>'tv','title'=>'Sample/Show','original_title'=>'Sample Show','original_language'=>'en','rating'=>'TV-MA'];
    check(MediaRules::path($tv,false,$settings)==='/media/Mature TV Shows/','mature all seasons path');
    check(MediaRules::path($tv,true,$settings)==='/media/Mature TV Shows/Sample Show/','mature per season path');
    $tv['rating']='Unrated'; check(str_contains(MediaRules::path($tv,false,$settings),'Mature'),'unrated shows mature');
    $tv['rating']='TV-PG'; check(MediaRules::path($tv,true,$settings)==='/media/TV Shows/Sample Show/','normal per season path');
    $settings->save(['OPENAI_API_KEY'=>'protected-secret','MOVIE_GENRE_MAP'=>'{"Science Fiction":"Adventure"}']);
    check($settings->get('OPENAI_API_KEY')==='protected-secret','encrypted settings round trip');
    check(!str_contains($db->one("SELECT value FROM settings WHERE key='OPENAI_API_KEY'")['value'],'protected-secret'),'secret not stored in plaintext');
    check(!str_contains(json_encode($settings->display()),'protected-secret'),'secret never returned to browser');
    $settings->save(['OPENAI_API_KEY'=>'']); check($settings->get('OPENAI_API_KEY')==='protected-secret','blank secret preserves existing');
    check(MediaRules::path($m,false,$settings)==='/media/Movies/Adventure/','genre maps to existing folder');
    $settings->save(['MOVIE_GENRE_MAP'=>'{"Science Fiction":"Sci-Fi"}']);
    check(MediaRules::path($m,false,$settings)==='/media/Movies/Other/','legacy unknown genre override cannot create new folder by default');
    $settings->save(['MOVIE_GENRE_MAP'=>'{"Science Fiction":"Adventure"}']);
    $settings->save(['MOVIE_FOLDER_OVERRIDES'=>'{"101":"RomCom"}']);
    check(MovieFolders::folder($m+['id'=>101],$settings)==='RomCom','explicit movie override takes priority');
    $settings->save(['MOVIE_FOLDER_OVERRIDES'=>'{}']);
    rejects(fn()=>$settings->save(['MOVIE_FOLDER_OVERRIDES'=>'{"101":"../new"}']),'movie override traversal rejected');
    rejects(fn()=>$settings->save(['MOVIE_FOLDER_OVERRIDES'=>'{"title":"Action"}']),'movie override requires numeric identity');
    rejects(fn()=>$settings->save(['MOVIE_EXISTING_FOLDERS'=>'Action,Drama']),'existing movie folders require Other fallback');
    rejects(fn()=>$settings->save(['MOVIE_EXISTING_FOLDERS'=>'Other,../private']),'existing folder traversal rejected');
    rejects(fn()=>$settings->save(['ALLOW_NEW_MOVIE_FOLDERS'=>'yes']),'invalid new folder switch rejected');
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
    $deliveries=[]; $adds=[]; $searches=[]; $fakeTorrents=[]; $scenario='movie'; $nextSearch=1; $lostSearches=0; $loseAllSearches=false;
    Http::setTestTransport(function($method,$url,$headers,$body) use (&$adds,&$searches,&$fakeTorrents,&$nextSearch,&$scenario,&$lostSearches,&$loseAllSearches,$magnet,$hash) {
        $path=parse_url($url,PHP_URL_PATH); parse_str((string)parse_url($url,PHP_URL_QUERY),$query); parse_str((string)$body,$form);
        $result=null;
        if(str_contains($url,'api.themoviedb.org')) {
            if(preg_match('~^/3/movie/(101|104|105|106|107|108)$~',$path,$match)) $result=movie((int)$match[1]);
            elseif(preg_match('~^/3/tv/(202|203)$~',$path,$match)) $result=show((int)$match[1]);
            elseif(preg_match('~^/3/tv/(202|203)/season/(\d+)$~',$path,$m)) $result=['episodes'=>array_fill(0,8,['air_date'=>'2025-01-01','runtime'=>45])];
            else throw new RuntimeException('Unexpected catalog call: '.$path);
        } elseif(str_contains($url,'api.openai.com')) {
            $request=json_decode($body,true); $input=json_decode($request['input'],true); $items=$input['candidates'];
            check(count($items)<=($scenario==='movie' ? 15 : 30),'model candidate cap enforced');
            check(!str_contains(json_encode($input),'protected-secret'),'credentials never sent to model');
            check(!isset($items[0]['url']),'torrent URL not sent to model');
            $picks=[];
            if($items && $scenario!=='no_selection' && !($scenario==='separate' && $input['all_only'])) $picks=[choice($items[0]['id'],array_column($input['wanted_seasons'],'number'),$scenario==='movie' ? 'movie' : ($input['all_only'] ? 'all_seasons' : 'season'))];
            $evaluations=array_map(fn($c)=>['candidate_id'=>$c['id'],'verdict'=>in_array($c['id'],array_column($picks,'candidate_id'),true) ? 'selected' : 'rejected','reason'=>'Simulated policy verdict.'],$items);
            $result=['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode(['selections'=>$picks,'summary'=>'Verified','evaluations'=>$evaluations])]]]]];
        } elseif(str_contains($url,'127.0.0.1:9999')) {
            $action=substr($path,8);
            if($action==='auth/login') return ['status'=>200,'body'=>'Ok.'];
            if($action==='app/version') return ['status'=>200,'body'=>'v5.1.2'];
            if($action==='search/start') { $n=$nextSearch++; $searches[$n]=$form['pattern']; $result=['id'=>$n]; }
            elseif($action==='search/results') {
                if($loseAllSearches || $lostSearches>0) { if($lostSearches>0) $lostSearches--; return ['status'=>404,'body'=>'Not Found']; }
                $pattern=$searches[(int)$query['id']]; $title='Sample Movie 2025 1080p ENG'; $h=$hash;
                if(in_array($scenario,['separate','all_seasons'],true)) { $title=str_contains($pattern,'S02') ? 'Sample Show S02 Complete 1080p ENG' : (str_contains($pattern,'S01') ? 'Sample Show S01 Complete 1080p ENG' : 'Sample Show S01-S02 Complete 1080p ENG'); $h=str_contains($pattern,'S02') ? str_repeat('b',40) : $hash; }
                $result=['status'=>'Stopped','results'=>[['fileName'=>$title,'fileSize'=>2*1024**3,'nbSeeders'=>30,'nbLeechers'=>5,'fileUrl'=>'magnet:?xt=urn:btih:'.$h]]];
            } elseif(in_array($action,['search/delete','search/stop','torrents/createCategory','torrents/addTags'],true)) return ['status'=>200,'body'=>''];
            elseif($action==='torrents/categories') $result=['Movies'=>[],'TV Shows'=>[]];
            elseif($action==='torrents/info') {
                $result=array_values(array_filter($fakeTorrents,fn($t)=>isset($query['tag']) ? str_contains($t['tags'],$query['tag']) : in_array($t['hash'],explode('|',$query['hashes'] ?? ''),true)));
            } elseif($action==='torrents/add') {
                $adds[]=$form; $h=TorrentPolicy::magnetHash($form['urls']);
                $fakeTorrents[$h]=['hash'=>$h,'name'=>'Chosen torrent','tags'=>$form['tags'],'save_path'=>$form['savepath'],'progress'=>.3,'size'=>2*1024**3,'eta'=>300,'dlspeed'=>1000000,'num_seeds'=>20,'num_leechs'=>3,'state'=>'downloading'];
                return ['status'=>200,'body'=>'Ok.'];
            } else throw new RuntimeException('Unexpected downloader action '.$action);
        } else throw new RuntimeException('Unexpected outbound network request blocked.');
        return ['status'=>200,'body'=>json_encode($result,JSON_THROW_ON_ERROR)];
    });
    $worker=new Worker($config,$db,$settings,$catalog,function($email,$content) use (&$deliveries) { $deliveries[$email]=$content; });
    $requests=new Requests($config,$db,$settings,$catalog);
    $user=['id'=>$u,'role'=>'user']; $admin=['id'=>$a,'role'=>'admin'];
    $r=$requests->enqueue($user,'movie',101); $requests->enqueue($admin,'movie',101);
    $normalized=json_decode($db->one('SELECT media FROM requests WHERE id=?',[$r['id']])['media'],true);
    check($normalized['collection']===['id'=>987654,'name'=>'Sample Collection'] && $normalized['adult']===false,'catalog retains normalized movie routing metadata');
    check((int)$db->one('SELECT COUNT(*) AS n FROM requests')['n']===1,'same media deduplicates across users');
    $lostSearches=1;
    for($i=0;$i<2;$i++) { $db->run('UPDATE jobs SET due_at=? WHERE kind=?',[time()-1,'acquire']); $worker->tick(); }
    $lostJob=$db->one("SELECT * FROM jobs WHERE request_id=? AND kind='acquire'",[$r['id']]);
    check(!isset(json_decode($lostJob['payload'],true)['search_id']),'lost search ID cleared before retry');
    check($lostJob['status']==='pending' && (int)$lostJob['attempts']===0,'missing search schedules recovery without failing request');
    check(!$adds,'missing search never adds a torrent');
    for($i=0;$i<5;$i++) { $db->run('UPDATE jobs SET due_at=? WHERE kind=?',[time()-1,'acquire']); $worker->tick(); }
    check(count($adds)===1,'movie adds once');
    check($adds[0]['savepath']==='/media/Movies/Adventure/','existing movie destination sent correctly');
    check(!$db->one('SELECT path FROM folder_alerts'),'existing movie folder does not trigger alert');
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
    $scenario='movie'; $loseAllSearches=true; $searchCount=count($searches);
    $lost=$requests->enqueue($user,'movie',104);
    for($i=0;$i<10;$i++) { $db->run('UPDATE jobs SET due_at=? WHERE request_id=? AND kind=?',[time()-1,$lost['id'],'acquire']); $worker->tick(); }
    check(count($searches)===$searchCount+3,'repeated lost searches have a bounded restart count');
    check($db->one('SELECT status FROM requests WHERE id=?',[$lost['id']])['status']==='failed','persistent search loss stops for admin review');
    $failureAlerts=$db->all('SELECT * FROM manager_alerts WHERE request_id=?',[$lost['id']]);
    check(count($failureAlerts)===2,'background failure queues initial and final manager alerts only');
    check(count(array_filter($failureAlerts,fn($e)=>$e['status']==='sent' && $e['recipient']==='manager@example.test'))===2,'worker delivers acquisition failures only to download manager');
    check(str_contains($deliveries['manager@example.test']['text'],'Automatic retries have stopped'),'final acquisition email explains admin review');
    check(count($adds)===4,'persistent search loss starts no download');
    $loseAllSearches=false; $requests->retry((int)$lost['id'],$a);
    check((int)$db->one('SELECT generation FROM manager_alert_epochs WHERE request_id=?',[$lost['id']])['generation']===1,'admin retry starts a fresh notification cycle');
    $retryJob=$db->one("SELECT payload FROM jobs WHERE request_id=? AND kind='acquire'",[$lost['id']]);
    check(!isset(json_decode($retryJob['payload'],true)['search_restarts']),'admin retry resets search recovery limit');
    for($i=0;$i<5;$i++) { $db->run('UPDATE jobs SET due_at=? WHERE request_id=? AND kind=?',[time()-1,$lost['id'],'acquire']); $worker->tick(); }
    check($db->one('SELECT status FROM requests WHERE id=?',[$lost['id']])['status']==='downloading','admin retry recovers with a fresh search');
    $scenario='no_selection'; $searchCount=count($searches);
    $noSelection=$requests->enqueue($user,'movie',105);
    for($i=0;$i<10;$i++) { $db->run('UPDATE jobs SET due_at=? WHERE request_id=? AND kind=?',[time()-1,$noSelection['id'],'acquire']); $worker->tick(); }
    check($db->one('SELECT status FROM requests WHERE id=?',[$noSelection['id']])['status']==='failed','empty model decisions stop after three failures');
    check((int)$db->one('SELECT COUNT(*) AS n FROM manager_alerts WHERE request_id=?',[$noSelection['id']])['n']===2,'no eligible model selection produces manager notifications');
    check(count($searches)===$searchCount+3,'successful polling cannot reset repeated acquire failures indefinitely');
    $emptyReport=(new SearchLog($db,$settings))->forRequest((int)$noSelection['id']);
    check($emptyReport[0]['report']['outcome']==='no_selection' && $emptyReport[0]['report']['decision']['model_called'],'empty model choice has a distinct logged outcome');
    $reports=(new SearchLog($db,$settings))->forRequest((int)$r['id']);
    check($reports[0]['report']['outcome']==='selected','search report persists after successful selection and search cleanup');
    check($reports[0]['report']['decision']['summary']==='Verified','model explanation recorded');
    check($reports[0]['report']['filters']['sent_to_model']===1,'model candidate count recorded');
    check($reports[1]['report']['outcome']==='expired','lost search recorded separately');
    check(!str_contains(json_encode($reports),'magnet:'),'torrent links excluded from reports');
    $trackerCache=[];
    $normal=TorrentPolicy::normalizeMagnet($magnet.'&tr='.rawurlencode('udp://127.0.0.1:6969/announce').'&xs='.rawurlencode('http://127.0.0.1/private'),$trackerCache);
    check($normal && $normal['url']===$magnet,'unsafe optional trackers and sources removed while retaining valid hash');
    check(str_contains($normal['note'],'Removed 1') && str_contains($normal['note'],'source'),'magnet cleanup explained in search report');
    check(TorrentPolicy::urlAllowed($normal['url'],''),'sanitized magnet passes existing network policy');
    $publicTracker='udp://8.8.8.8:6969/announce';
    $withPublic=TorrentPolicy::normalizeMagnet($magnet.'&tr='.rawurlencode($publicTracker),$trackerCache);
    check($withPublic['url']===$magnet.'&tr='.rawurlencode($publicTracker),'valid public trackers retained');
    check(TorrentPolicy::normalizeMagnet('magnet:?xt=urn:btih:not-a-hash',$trackerCache)===null,'invalid hashes still rejected');
    check(TorrentPolicy::normalizeMagnet($magnet."\n",$trackerCache)===null,'magnet control characters still rejected');
    $filterReport=[];
    $fixture=['fileName'=>'Sample Movie 2025 1080p ENG','fileSize'=>2*1024**3,'nbSeeders'=>30,'fileUrl'=>$magnet];
    $candidateFixtures=[$fixture+['engineName'=>'Local test'],array_merge($fixture,['fileName'=>'Sample Movie 2025 CAM','fileUrl'=>'magnet:?xt=urn:btih:'.str_repeat('c',40)]),array_merge($fixture,['nbSeeders'=>0,'fileUrl'=>'magnet:?xt=urn:btih:'.str_repeat('d',40)]),$fixture];
    $filtered=TorrentPolicy::candidates($candidateFixtures,15,$settings,$filterReport);
    check(count($filtered)===1 && count($filterReport['rows'])===4,'every fetched candidate receives a filter outcome');
    check(($filterReport['reasons']['Theater recording, screener, or workprint'] ?? 0)===1,'CAM rejection reason recorded');
    check(($filterReport['reasons']['No reported seeders'] ?? 0)===1,'zero seeder rejection reason recorded');
    check(($filterReport['reasons']['Duplicate torrent'] ?? 0)===1,'duplicate rejection reason recorded');
    $normalizedFixtures=[array_merge($fixture,['fileUrl'=>$magnet.'&tr='.rawurlencode('udp://127.0.0.1:6969/announce')])];
    $filtered=TorrentPolicy::candidates($normalizedFixtures,15,$settings,$filterReport);
    check(count($filtered)===1 && $filtered[0]['url']===$magnet,'candidate with bad optional tracker becomes a safe hash-only magnet');
    check(!empty($filterReport['rows'][0]['note']),'candidate log reports tracker cleanup');
    $many=[]; for($n=0;$n<20;$n++) $many[]=array_merge($fixture,['fileUrl'=>'magnet:?xt=urn:btih:'.str_pad(dechex($n+1),40,'0',STR_PAD_LEFT)]);
    $filtered=TorrentPolicy::candidates($many,15,$settings,$filterReport);
    check(count($filtered)===15 && count($filterReport['rows'])===20,'logging preserves movie model limit while accounting for remaining results');
    check(($filterReport['reasons']['Not reviewed: model candidate limit reached'] ?? 0)===5,'candidate cap is explained');
    $logs=new SearchLog($db,$settings);
    $secretLog=$logs->begin((int)$r['id'],['query'=>'protected-secret https://example.test/?token=hidden user@example.test','outcome'=>'test',
        'decision'=>['summary'=>'protected-secret '.rawurlencode('protected-secret').' magnet:?xt=urn:btih:'.$hash]]);
    $stored=$db->one('SELECT report FROM search_logs WHERE id=?',[$secretLog])['report'];
    check(!str_contains($stored,'protected-secret') && !str_contains($stored,'token=hidden'),'secrets and URLs redacted before database writes');
    check(!str_contains($stored,'user@example.test') && !str_contains($stored,'magnet:?'),'emails and magnets redacted before database writes');
    for($n=0;$n<12;$n++) $logs->begin((int)$r['id'],['query'=>'Retention fixture '.$n,'outcome'=>'test']);
    check(count($logs->forRequest((int)$r['id']))===10,'per-request search history bounded to ten reports');
    $db->run('UPDATE search_logs SET created_at=? WHERE request_id=?',[time()-31*86400,$r['id']]);
    $logs->begin((int)$r['id'],['query'=>'New report','outcome'=>'test']);
    check(count($logs->forRequest((int)$r['id']))===1,'expired search reports removed');
    $content=Mailer::content(['title'=>'<script>alert(1)</script>','type'=>'movie','year'=>'2025','rating'=>'PG','release_date'=>'2025-01-01','overview'=>'<img onerror=x>'],[],true);
    check(!str_contains($content['html'],'<script>'),'email HTML escaped');
    $scenario='movie'; $fakeTorrents=[]; $folderDeliveries=[];
    $settings->save(['ALLOW_NEW_MOVIE_FOLDERS'=>'true','MOVIE_FOLDER_OVERRIDES'=>'{"106":"Documentaries","107":"Documentaries","108":"Unused Folder"}']);
    $folderWorker=new Worker($config,$db,$settings,$catalog,function($email,$content) use (&$folderDeliveries) {
        if($content['subject']==='ScreenPort: new movie folder — check Jellyfin') $folderDeliveries[]=[$email,$content];
    });
    $newFolder=$requests->enqueue($user,'movie',106);
    for($i=0;$i<2;$i++) { $db->run('UPDATE jobs SET due_at=? WHERE request_id=? AND kind=?',[time()-1,$newFolder['id'],'acquire']); $folderWorker->tick(); }
    $persisted=json_decode($db->one('SELECT plan FROM requests WHERE id=?',[$newFolder['id']])['plan'],true);
    $pick=$persisted['picks'][0];
    $db->run('INSERT INTO torrents(request_id,candidate_id,name,url,hash,tag,save_path) VALUES(?,?,?,?,?,?,?)',[$newFolder['id'],$pick['id'],$pick['name'],$pick['url'],$pick['hash'],'ScreenPort-'.$newFolder['id'].'-'.$pick['id'],'/media/Movies/Legacy Genre/']);
    $db->run('UPDATE jobs SET due_at=? WHERE request_id=? AND kind=?',[time()-1,$newFolder['id'],'acquire']); $folderWorker->tick();
    check($adds[array_key_last($adds)]['savepath']==='/media/Movies/Documentaries/','explicit new folder submitted when allowed');
    check(count($folderDeliveries)===1 && $folderDeliveries[0][0]==='manager@example.test','new folder email sent only to download manager');
    check(str_contains($folderDeliveries[0][1]['text'],'/media/Movies/Documentaries/') && str_contains($folderDeliveries[0][1]['text'],'Jellyfin'),'new folder email includes path and Jellyfin action');
    check(!$db->one('SELECT path FROM folder_alerts WHERE path=?',['/media/Movies/Legacy Genre/']),'unsubmitted legacy path is replaced before add and never alerted');
    check(!$db->one('SELECT id FROM emails WHERE request_id=?',[$newFolder['id']]),'folder alert does not replace delayed download email');
    $folderWorker->tick(); check(count($folderDeliveries)===1,'new folder alert is not repeated each tick');
    $secondFolder=$requests->enqueue($user,'movie',107);
    for($i=0;$i<4;$i++) { $db->run('UPDATE jobs SET due_at=? WHERE request_id=? AND kind=?',[time()-1,$secondFolder['id'],'acquire']); $folderWorker->tick(); }
    check(count($folderDeliveries)===1,'subsequent movie in same new folder does not duplicate alert');
    $unused=$requests->enqueue($user,'movie',108);
    $before=count($adds);
    for($i=0;$i<4;$i++) { $db->run('UPDATE jobs SET due_at=? WHERE request_id=? AND kind=?',[time()-1,$unused['id'],'acquire']); $folderWorker->tick(); }
    check(count($adds)===$before,'existing torrent reused without creating unused destination');
    check(!$db->one('SELECT path FROM folder_alerts WHERE path=?',['/media/Movies/Unused Folder/']),'reused torrent does not announce unused new folder');
    check($db->one('SELECT save_path FROM torrents WHERE request_id=?',[$unused['id']])['save_path']==='/media/Movies/Documentaries/','reused torrent records its actual destination');
    // Simulate a previous add reaching qBittorrent, followed by a worker crash before its database commit.
    $fakeTorrents[$hash]['save_path']='/media/Movies/Recovered Folder/';
    $db->run("UPDATE torrents SET state='adding',save_path=? WHERE request_id=?",['/media/Movies/Recovered Folder/',$unused['id']]);
    $db->run("UPDATE jobs SET status='pending',due_at=? WHERE request_id=? AND kind='acquire'",[time()-1,$unused['id']]);
    $folderWorker->tick();
    check(count($adds)===$before,'crashed add reconciles without duplicate torrent submission');
    check(count($folderDeliveries)===2 && str_contains($folderDeliveries[1][1]['text'],'Recovered Folder'),'crashed add still queues durable alert for actual destination');
    $alerts=new FolderAlerts($config,$db,$settings,fn()=>throw new RuntimeException('fake secret SMTP failure'));
    $alerts->queue((int)$newFolder['id'],['type'=>'tv'],'/media/Movies/TV Test/');
    check(!$db->one('SELECT path FROM folder_alerts WHERE path=?',['/media/Movies/TV Test/']),'TV requests never generate movie folder alert');
    $alerts->queue((int)$newFolder['id'],['type'=>'movie'],'/media/Movies/Retry Test/');
    for($i=0;$i<5;$i++) { $db->run("UPDATE folder_alerts SET due_at=? WHERE path=?",[time()-1,'/media/Movies/Retry Test/']); $alerts->deliver(); }
    $failed=$db->one('SELECT * FROM folder_alerts WHERE path=?',['/media/Movies/Retry Test/']);
    check($failed['status']==='failed' && (int)$failed['attempts']===5,'folder emails retry and fail after five attempts');
    check(!str_contains(json_encode($db->all('SELECT detail FROM audit')),'fake secret'),'folder alert errors do not log SMTP exception secrets');
    $db->run("UPDATE folder_alerts SET status='pending',attempts=0,due_at=? WHERE status='failed'",[time()]);
    (new FolderAlerts($config,$db,$settings,function($email,$content) use (&$folderDeliveries) { $folderDeliveries[]=[$email,$content]; }))->deliver();
    check($db->one('SELECT status FROM folder_alerts WHERE path=?',['/media/Movies/Retry Test/'])['status']==='sent','folder email can recover after admin retry');
    $escapedFolder=Mailer::folderContent(['title'=>'<script>alert(1)</script>'],'/media/Movies/<bad>/');
    check(!str_contains($escapedFolder['html'],'<script>') && !str_contains($escapedFolder['html'],'<bad>'),'folder email escapes title and path');
    check(Mailer::eta(null)==='Not available yet','unknown ETA not fabricated');
    echo "PASS: $tests assertions. External downloads, paid API calls, and SMTP deliveries were simulated.\n";
} catch(Throwable $e) { echo $e->getMessage()."\n"; exit(1); }
finally { Http::setTestTransport(null); }
