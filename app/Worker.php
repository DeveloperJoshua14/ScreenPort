<?php
declare(strict_types=1);
namespace ScreenPort;

final class Worker
{
    private Qbit $qbit;
    public function __construct(private Config $config,private Db $db,private Settings $settings,private Catalog $catalog,private ?\Closure $testDelivery=null)
    {
        if($testDelivery && PHP_SAPI!=='cli') throw new \LogicException('Test deliveries are CLI-only.');
    }
    public function tick(): int
    {
        if($this->config->demo()) return 0;
        $this->db->run("INSERT INTO cache VALUES('worker_heartbeat',?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,expires_at=excluded.expires_at",[json_encode(time()),time()+300]);
        $this->db->run("UPDATE jobs SET status='pending' WHERE status='running'");
        $this->settings->reload(); $count=0;
        // Deliver account/intake alerts before potentially slow acquisition jobs.
        (new ManagerAlerts($this->config,$this->db,$this->settings,$this->testDelivery))->deliver();
        $jobs=$this->db->all("SELECT * FROM jobs WHERE status='pending' AND due_at<=? ORDER BY CASE kind WHEN 'notify' THEN 0 WHEN 'monitor' THEN 1 ELSE 2 END,due_at LIMIT 25",[time()]);
        foreach($jobs as $job) {
            $this->db->run("UPDATE jobs SET status='running' WHERE id=?",[$job['id']]);
            try {
                $this->qbit=new Qbit($this->settings,$this->config);
                match($job['kind']) {
                    'acquire'=>$this->acquire($job), 'notify'=>$this->notify($job), 'monitor'=>$this->monitor($job),
                    default=>throw new \RuntimeException('Unknown background job.')
                };
            } catch(\Throwable $e) {
                $attempts=(int)$job['attempts']+1;
                $message=$e instanceof \JsonException ? 'A service returned invalid data.' : $this->safeMessage($e);
                $terminal=$attempts>=3;
                $this->db->transaction(function() use($job,$terminal,$attempts,$message) {
                    $this->db->run('UPDATE jobs SET status=?,attempts=?,last_error=?,due_at=? WHERE id=?',[$terminal ? 'failed' : 'pending',$attempts,$message,time()+min(900,30*2**$attempts),$job['id']]);
                    if($job['kind']==='acquire') {
                        $this->state((int)$job['request_id'],$terminal ? 'failed' : 'queued',$message.($terminal ? ' Admin review is required.' : ' Retrying shortly.'));
                        (new ManagerAlerts($this->config,$this->db,$this->settings))->acquisition((int)$job['request_id'],$message,$terminal,$attempts);
                    }
                    if($job['kind']==='monitor') (new ManagerAlerts($this->config,$this->db,$this->settings))->monitoring((int)$job['request_id'],'Could not verify qBittorrent download status: '.$message);
                    $this->db->audit(null,'worker_error',$job['kind'].' request '.$job['request_id'].': '.$message);
                });
            }
            $count++;
        }
        $this->sendPendingEmails();
        (new FolderAlerts($this->config,$this->db,$this->settings,$this->testDelivery))->deliver();
        (new ManagerAlerts($this->config,$this->db,$this->settings,$this->testDelivery))->deliver();
        $this->db->run('DELETE FROM rate_limits WHERE expires_at<?',[time()]);
        $this->db->run('DELETE FROM cache WHERE expires_at<?',[time()-86400]);
        return $count;
    }
    private function safeMessage(\Throwable $e): string
    {
        // Only our deliberately authored errors are surfaced; third-party exception text can contain secrets.
        if($e instanceof \RuntimeException && str_starts_with($e->getFile(),__DIR__) && !($e instanceof \PDOException)) return mb_substr($e->getMessage(),0,300);
        return 'The background task encountered an error. Check configuration and service connectivity.';
    }
    private function state(int $rid,string $status,string $message): void { $this->db->run('UPDATE requests SET status=?,message=?,updated_at=? WHERE id=?',[$status,$message,time(),$rid]); }
    private function pending(array $job,array $payload,int $delay=0): void { $this->db->run("UPDATE jobs SET status='pending',payload=?,due_at=?,attempts=CASE WHEN kind='acquire' THEN attempts ELSE 0 END,last_error=NULL WHERE id=?",[json_encode($payload,JSON_THROW_ON_ERROR),time()+$delay,$job['id']]); }
    private function done(array $job): void { $this->db->run("UPDATE jobs SET status='done',last_error=NULL WHERE id=?",[$job['id']]); }
    private function schedule(int $rid,string $kind,int $due): void
    {
        $this->db->run("INSERT INTO jobs(request_id,kind,due_at) VALUES(?,?,?) ON CONFLICT(request_id,kind) DO UPDATE SET status='pending',due_at=excluded.due_at",[$rid,$kind,$due]);
    }
    private function acquire(array $job): void
    {
        $rid=(int)$job['request_id']; $p=json_decode($job['payload'],true) ?: [];
        $logs=new SearchLog($this->db,$this->settings);
        $r=$this->db->one('SELECT * FROM requests WHERE id=?',[$rid]);
        if(!$r) { $this->done($job); return; }
        if(!$this->settings->bool('DOWNLOADS_ENABLED')) { $this->pending($job,$p,60); return; }
        if(!empty($r['plan'])) { $this->addPlan($job,$r,json_decode($r['plan'],true,512,JSON_THROW_ON_ERROR)); return; }
        if(!$p) {
            $media=$this->catalog->detail($r['media_type'],(int)$r['media_id'],true);
            if(!$media['available']) throw new \RuntimeException('Not out Yet. Release information changed; no downloads were started.');
            $seasons=$media['type']==='tv' ? $this->catalog->completedSeasons($media) : [];
            if($media['type']==='tv' && !$seasons) throw new \RuntimeException('No complete, fully aired seasons are available yet.');
            if(count($seasons)>50) throw new \RuntimeException('This show exceeds the 50-season request limit.');
            $p=['media'=>$media,'seasons'=>$seasons,'phase'=>$media['type']==='movie' ? 'movie' : 'series','index'=>0,'picks'=>[]];
            $this->db->run('UPDATE requests SET media=? WHERE id=?',[json_encode($media,JSON_THROW_ON_ERROR),$rid]);
        }
        if(!empty($p['restart_search'])) {
            if(!empty($p['search_log_id'])) $logs->update((int)$p['search_log_id'],['outcome'=>'restarted','message'=>'Search restarted by admin retry.']);
            try { $this->qbit->stopSearch((int)$p['search_id']); $this->qbit->deleteSearch((int)$p['search_id']); } catch(\Throwable $e) {}
            unset($p['search_id'],$p['started_at'],$p['restart_search'],$p['search_restarts'],$p['search_log_id']);
        }
        $pattern=$p['media']['title'];
        if($p['phase']==='movie') $pattern.=' '.$p['media']['year'];
        elseif($p['phase']==='series') $pattern.=' complete';
        else $pattern.=' S'.str_pad((string)$p['seasons'][$p['index']]['number'],2,'0',STR_PAD_LEFT).' complete';
        $wanted=$p['phase']==='season' ? [$p['seasons'][$p['index']]] : $p['seasons'];
        $logInfo=['query'=>$pattern,'phase'=>$p['phase'],'plugins'=>$this->settings->get('QBITTORRENT_SEARCH_PLUGINS'),
            'media'=>array_intersect_key($p['media'],array_flip(['title','original_title','type','year','original_language','runtime'])),
            'wanted_seasons'=>array_column($wanted,'number'),'outcome'=>'searching','message'=>'Waiting for qBittorrent results.'];
        if(empty($p['search_id'])) {
            if((int)($p['search_restarts'] ?? 0)>2) throw new \RuntimeException('qBittorrent repeatedly lost its search job. Check server restarts, session timeout, and proxy routing before retrying.');
            $p['search_id']=$this->qbit->startSearch($pattern); $p['started_at']=time();
            $p['search_log_id']=$logs->begin($rid,$logInfo+['search_id'=>$p['search_id']]);
            $this->state($rid,'searching',$p['phase']==='season' ? 'Searching for complete season '.$p['seasons'][$p['index']]['number'] : 'Searching available torrents');
            $this->pending($job,$p,5); return;
        }
        if(empty($p['search_log_id'])) {
            $p['search_log_id']=$logs->begin($rid,$logInfo+['search_id'=>$p['search_id']]);
            $this->db->run('UPDATE jobs SET payload=? WHERE id=?',[json_encode($p,JSON_THROW_ON_ERROR),$job['id']]);
        }
        try {
            $result=$this->qbit->results((int)$p['search_id']);
            if(($result['status'] ?? '')==='Running' && time()-$p['started_at']>=(int)$this->settings->get('SEARCH_TIMEOUT')) {
                $this->qbit->stopSearch((int)$p['search_id']);
                $result=$this->qbit->results((int)$p['search_id']);
            }
        } catch(\RuntimeException $e) {
            $logs->update((int)$p['search_log_id'],['outcome'=>$e->getCode()===404 ? 'expired' : 'error','message'=>$this->safeMessage($e)]);
            if($e->getCode()!==404) throw $e;
            $p['search_restarts']=(int)($p['search_restarts'] ?? 0)+1;
            unset($p['search_id'],$p['started_at'],$p['search_log_id']);
            // Persist cleanup even on terminal failure; retry must never poll a lost ID.
            $this->db->run('UPDATE jobs SET payload=? WHERE id=?',[json_encode($p,JSON_THROW_ON_ERROR),$job['id']]);
            if($p['search_restarts']>2) throw new \RuntimeException('qBittorrent repeatedly lost its search job. Check server restarts, session timeout, and proxy routing before retrying.');
            $this->state($rid,'searching','qBittorrent search expired or disappeared; starting a fresh search');
            $this->pending($job,$p,5); return;
        }
        $logs->update((int)$p['search_log_id'],['search_status'=>$result['status'] ?? 'Unknown','total_found'=>(int)($result['total'] ?? count($result['results'] ?? [])),
            'elapsed_seconds'=>max(0,time()-(int)$p['started_at'])]);
        if(($result['status'] ?? '')==='Running' && time()-$p['started_at']<(int)$this->settings->get('SEARCH_TIMEOUT')) { $this->pending($job,$p,5); return; }
        $filters=[];
        $candidates=TorrentPolicy::candidates($result['results'] ?? [],$p['media']['type']==='movie' ? 15 : 30,$this->settings,$filters,$p['media'],$wanted,$p['phase']==='series');
        $logs->update((int)$p['search_log_id'],['filters'=>$filters,'outcome'=>'reviewing','message'=>'Evaluating eligible candidates.']);
        $this->state($rid,'selecting','Comparing identity, language, quality, and size');
        $decision=[];
        try {
            $picked=(new Selector($this->settings))->select($p['media'],$candidates,$wanted,$p['phase']==='series',$decision);
        } catch(\Throwable $e) {
            $logs->update((int)$p['search_log_id'],['decision'=>$decision,'outcome'=>'error','message'=>$this->safeMessage($e)]);
            throw $e;
        }
        $logs->update((int)$p['search_log_id'],['decision'=>$decision,'outcome'=>$picked ? 'selected' : ($candidates ? 'no_selection' : 'no_candidates'),
            'message'=>$picked ? 'Selection passed server validation.' : ($candidates ? 'The model returned no selections.' : 'All results were excluded before model review.')]);
        try { $this->qbit->deleteSearch((int)$p['search_id']); } catch(\Throwable $e) {}
        unset($p['search_id'],$p['started_at'],$p['search_restarts'],$p['search_log_id']);
        // Save cleanup before any policy failure so an admin retry never polls a deleted search ID.
        $this->db->run('UPDATE jobs SET payload=? WHERE id=?',[json_encode($p,JSON_THROW_ON_ERROR),$job['id']]);
        if($p['phase']==='series' && !$picked) { $p['phase']='season'; $this->pending($job,$p); return; }
        if(!$picked) throw new \RuntimeException($candidates ? 'The model did not select a torrent. Admins can open the Search log in Downloads to see why.' : 'No search results passed the preliminary filters. Admins can open the Search log in Downloads to see why.');
        $p['picks']=array_merge($p['picks'],$picked);
        if($p['phase']==='season' && ++$p['index']<count($p['seasons'])) { $this->pending($job,$p); return; }
        $ids=array_column($p['picks'],'id');
        if(count($ids)!==count(array_unique($ids))) throw new \RuntimeException('The same torrent appeared in multiple season searches. Review required.');
        $plan=['media'=>$p['media'],'picks'=>$p['picks'],'separate'=>$p['phase']==='season'];
        $this->db->run('UPDATE requests SET plan=? WHERE id=?',[json_encode($plan,JSON_THROW_ON_ERROR),$rid]);
        // Persist the full plan before any external side effect.
        $this->pending($job,['stage'=>'add']);
    }
    private function addPlan(array $job,array $request,array $plan): void
    {
        $rid=(int)$request['id'];
        $fresh=$this->catalog->detail($plan['media']['type'],(int)$plan['media']['id'],true);
        if(!$fresh['available']) throw new \RuntimeException('Release verification failed before adding torrents.');
        $path=MediaRules::path($fresh,(bool)$plan['separate'],$this->settings);
        foreach($plan['picks'] as $c) {
            if(!TorrentPolicy::urlAllowed($c['url'],$this->settings->get('TORRENT_ALLOWED_HOSTS'))) throw new \RuntimeException('A selected torrent URL no longer meets the allowed-host policy.');
            $tag='ScreenPort-'.$rid.'-'.$c['id'];
            $this->db->run('INSERT OR IGNORE INTO torrents(request_id,candidate_id,name,url,hash,tag,save_path,seasons) VALUES(?,?,?,?,?,?,?,?)',[$rid,$c['id'],$c['name'],$c['url'],$c['hash'],$tag,$path,json_encode($c['seasons'])]);
            $t=$this->db->one('SELECT * FROM torrents WHERE request_id=? AND candidate_id=?',[$rid,$c['id']]);
            if($t['state']==='added') continue;
            $known=$this->qbit->tagged($tag);
            if(!$known && $c['hash']) {
                $known=$this->qbit->hashes([$c['hash']]);
                if($known) $this->qbit->tagExisting($known[0]['hash'],$tag,$fresh['type']);
            }
            if($known) {
                // Reused torrents keep their actual location; they do not create our requested folder.
                $actualPath=$known[0]['save_path'] ?? $t['save_path'];
                $this->db->transaction(function() use($known,$t,$actualPath,$fresh,$rid) {
                    $this->db->run("UPDATE torrents SET state='added',hash=?,save_path=?,added_at=COALESCE(added_at,?),snapshot=? WHERE id=?",[$known[0]['hash'],$actualPath,time(),json_encode($this->snapshot($known[0])),$t['id']]);
                    if(isset($known[0]['save_path']) || $t['state']==='adding') (new FolderAlerts($this->config,$this->db,$this->settings))->queue($rid,$fresh,$actualPath);
                });
                continue;
            }
            // An ambiguous previous add is reconciled first. Never repeat a URL add that might have succeeded.
            if($t['state']==='adding') throw new \RuntimeException('A previous torrent add could not be confirmed. Inspect qBittorrent before retrying.');
            // Re-evaluate a persisted but unsubmitted plan against current folder rules.
            $t['save_path']=$path;
            $this->db->run("UPDATE torrents SET state='adding',save_path=?,added_at=? WHERE id=?",[$path,time(),$t['id']]);
            $this->qbit->add($t,$fresh['type']);
            $this->db->transaction(function() use($t,$fresh,$rid) {
                $this->db->run("UPDATE torrents SET state='added' WHERE id=?",[$t['id']]);
                (new FolderAlerts($this->config,$this->db,$this->settings))->queue($rid,$fresh,$t['save_path']);
            });
        }
        $this->state($rid,'downloading','Downloads started · first email update in about a minute');
        $this->schedule($rid,'notify',time()+60); $this->schedule($rid,'monitor',time()+60); $this->done($job);
    }
    private function snapshot(array $t): array { return array_intersect_key($t,array_flip(['hash','name','state','progress','size','total_size','eta','dlspeed','num_seeds','num_leechs','downloaded'])); }
    private function refresh(int $rid): array
    {
        $rows=$this->db->all('SELECT * FROM torrents WHERE request_id=?',[$rid]);
        foreach($rows as &$t) {
            $infos=$t['hash'] ? $this->qbit->hashes([$t['hash']]) : $this->qbit->tagged($t['tag']);
            if($infos) {
                $s=$this->snapshot($infos[0]);
                $t['snapshot']=json_encode($s,JSON_THROW_ON_ERROR); $t['hash']=$s['hash'];
                $this->db->run('UPDATE torrents SET hash=?,snapshot=? WHERE id=?',[$t['hash'],$t['snapshot'],$t['id']]);
            } else {
                $t['snapshot']='{}';
                $this->db->run("UPDATE torrents SET snapshot='{}' WHERE id=?",[$t['id']]);
            }
        }
        return $rows;
    }
    private function monitor(array $job): void
    {
        $rid=(int)$job['request_id']; $torrents=$this->refresh($rid); $complete=(bool)$torrents; $error=false; $missing=false; $notStarted=false;
        foreach($torrents as $t) {
            $s=json_decode($t['snapshot'],true) ?: [];
            if(empty($s)) $missing=true;
            if(($s['progress'] ?? 0)<1) $complete=false;
            if(in_array($s['state'] ?? '',['error','missingFiles'],true)) $error=true;
            if(($s['progress'] ?? 0)<=0 && ($s['downloaded'] ?? 0)<=0 && !empty($t['added_at']) && time()-(int)$t['added_at']>=(int)$this->settings->get('DOWNLOAD_START_TIMEOUT_MINUTES')*60) $notStarted=true;
        }
        if($missing || $error || $notStarted) (new ManagerAlerts($this->config,$this->db,$this->settings))->monitoring($rid,
            $missing ? 'One or more requested torrents are missing from qBittorrent.' : ($error ? 'qBittorrent reported a file or disk error for a requested torrent.' : 'One or more torrents have transferred no confirmed data within '.$this->settings->get('DOWNLOAD_START_TIMEOUT_MINUTES').' minutes of submission.'));
        $this->state($rid,$complete ? 'complete' : 'downloading',$complete ? 'Ready in your media folder' : ($missing ? 'A torrent is missing from qBittorrent. Admin review needed.' : ($error ? 'qBittorrent reported a file or disk error. Admin review needed.' : ($notStarted ? 'A torrent has not started transferring data within the configured timeout. Admin review needed.' : 'Downloading · estimates update as peers connect'))));
        if($complete) $this->done($job); else $this->pending($job,[],60);
    }
    private function notify(array $job): void
    {
        $rid=(int)$job['request_id']; $this->refresh($rid);
        $manager=strtolower(trim($this->settings->get('DOWNLOAD_MANAGER_EMAIL')));
        $recipients=[$manager=>'manager'];
        foreach($this->db->all("SELECT u.email FROM users u JOIN subscribers s ON s.user_id=u.id WHERE s.request_id=? AND u.status='approved'",[$rid]) as $u) {
            $email=strtolower(trim($u['email'])); if($email!==$manager) $recipients[$email]='user';
        }
        foreach($recipients as $email=>$audience) $this->db->run('INSERT OR IGNORE INTO emails(request_id,recipient,audience,due_at) VALUES(?,?,?,?)',[$rid,$email,$audience,time()]);
        $this->done($job);
    }
    private function sendPendingEmails(): void
    {
        foreach($this->db->all("SELECT * FROM emails WHERE status='pending' AND due_at<=? LIMIT 25",[time()]) as $e) {
            try {
                $r=$this->db->one('SELECT media FROM requests WHERE id=?',[$e['request_id']]);
                $torrents=$this->db->all('SELECT * FROM torrents WHERE request_id=?',[$e['request_id']]);
                $content=Mailer::content(json_decode($r['media'],true),$torrents,$e['audience']==='manager');
                if($this->testDelivery) ($this->testDelivery)($e['recipient'],$content);
                else (new Mailer($this->config,$this->settings))->send($e['recipient'],$content);
                $this->db->run("UPDATE emails SET status='sent' WHERE id=?",[$e['id']]);
            } catch(\Throwable $ex) {
                $tries=(int)$e['attempts']+1;
                $this->db->run('UPDATE emails SET status=?,attempts=?,due_at=? WHERE id=?',[$tries>=5 ? 'failed' : 'pending',$tries,time()+min(3600,60*2**$tries),$e['id']]);
                $this->db->audit(null,'email_delivery_failed','Request '.$e['request_id'].'; attempt '.$tries);
            }
        }
    }
}
