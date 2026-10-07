<?php
declare(strict_types=1);
namespace ScreenPort;

final class RequestControls
{
    public function __construct(private Config $config,private Db $db,private Settings $settings) {}
    public function command(int $id,string $action,array $admin): array
    {
        if(($admin['role'] ?? '')!=='admin') throw new ApiError('Admin access required.',403);
        if($this->config->demo()) throw new ApiError('Download controls are disabled in preview.',403);
        if($id<1 || !in_array($action,['suspend','resume','remove','restore'],true)) throw new ApiError('Invalid download control.',422);
        return $this->db->transaction(function() use($id,$action,$admin) {
            $r=$this->db->one('SELECT * FROM requests WHERE id=?',[$id]);
            if(!$r) throw new ApiError('Request not found.',404);
            $c=$this->db->one('SELECT * FROM request_controls WHERE request_id=?',[$id]);
            if($action==='restore') {
                if(!$c || $c['action']!=='remove' || $c['status']!=='done') throw new ApiError('Only removed requests can be restored.',409);
                $this->db->run('DELETE FROM request_controls WHERE request_id=?',[$id]);
                $this->db->run("UPDATE torrents SET state='planned',snapshot='{}',added_at=NULL WHERE request_id=? AND state='removed'",[$id]);
                $this->db->run("UPDATE jobs SET status='failed',attempts=0,last_error=NULL WHERE request_id=? AND kind='acquire'",[$id]);
                $this->db->run("UPDATE requests SET status='failed',message='Restored by admin. Review and retry when a suitable torrent is available.',updated_at=? WHERE id=?",[time(),$id]);
                $this->db->audit((int)$admin['id'],'restored_request',(string)$id);
                return ['message'=>'Request restored for admin review. Use Retry when ready.'];
            }
            if($c && $c['action']===$action) return ['message'=>$c['status']==='done' ? ($action==='remove' ? 'Request already removed.' : 'Request already suspended.') : 'This action is already queued.'];
            if($action==='resume' && (!$c || $c['action']!=='suspend' || $c['status']!=='done')) throw new ApiError('Only suspended requests can be resumed.',409);
            if($c && $c['action']==='remove') throw new ApiError('Restore this removed request before trying again.',409);
            if($action==='suspend' && ($c || $r['status']==='complete')) throw new ApiError('This request cannot be suspended right now.',409);
            $previous=$c['previous_status'] ?? $r['status'];
            $payload=$c['payload'] ?? '{}';
            $this->db->run("INSERT INTO request_controls(request_id,action,previous_status,payload,due_at) VALUES(?,?,?,?,?) ON CONFLICT(request_id) DO UPDATE SET action=excluded.action,status='pending',payload=excluded.payload,generation=generation+1,attempts=0,due_at=excluded.due_at,last_error=NULL",[$id,$action,$previous,$payload,time()]);
            $status=['suspend'=>'suspending','resume'=>'resuming','remove'=>'removing'][$action];
            $this->db->run('UPDATE requests SET status=?,message=?,updated_at=? WHERE id=?',[$status,'Admin '.$action.' queued. Waiting for the worker to finish any current operation.',time(),$id]);
            $this->db->audit((int)$admin['id'],$action.'_requested',(string)$id);
            return ['message'=>ucfirst($action).' queued. The worker will apply it shortly.'];
        });
    }
    public function assertActive(int $id): void
    {
        if($this->db->one('SELECT request_id FROM request_controls WHERE request_id=?',[$id])) throw new RequestInterrupted('Request controlled by admin.');
    }
    public function process(): void
    {
        foreach($this->db->all("SELECT * FROM request_controls WHERE status='pending' AND due_at<=? ORDER BY due_at LIMIT 25",[time()]) as $control) {
            try { $this->apply($control); }
            catch(\Throwable $e) {
                $tries=(int)$control['attempts']+1;
                $message='Could not apply the admin action. Check qBittorrent connectivity and the request torrent tags; the worker will retry.';
                $this->db->transaction(function() use($control,$tries,$message) {
                    $s=$this->db->run('UPDATE request_controls SET attempts=?,due_at=?,last_error=? WHERE request_id=? AND generation=?',[$tries,time()+min(900,30*2**min($tries,5)),$message,$control['request_id'],$control['generation']]);
                    if($s->rowCount()) $this->db->run('UPDATE requests SET message=?,updated_at=? WHERE id=?',[$message,time(),$control['request_id']]);
                });
            }
        }
    }
    private function apply(array $control): void
    {
        $id=(int)$control['request_id']; $action=$control['action'];
        $current=$this->db->one('SELECT generation FROM request_controls WHERE request_id=?',[$id]);
        if(!$current || (int)$current['generation']!==(int)$control['generation']) return;
        $payload=json_decode($control['payload'],true,512,JSON_THROW_ON_ERROR); $payload['paused_hashes'] ??= [];
        $rows=$this->db->all('SELECT * FROM torrents WHERE request_id=?',[$id]);
        $job=$this->db->one("SELECT * FROM jobs WHERE request_id=? AND kind='acquire'",[$id]);
        $search=json_decode($job['payload'] ?? '{}',true,512,JSON_THROW_ON_ERROR);
        $q=null;
        if(!empty($search['search_id'])) {
            $q=new Qbit($this->settings,$this->config);
            foreach(['stopSearch','deleteSearch'] as $method) try { $q->$method((int)$search['search_id']); } catch(\RuntimeException $e) { if(!in_array($e->getCode(),[400,404],true)) throw $e; }
            unset($search['search_id'],$search['started_at'],$search['restart_search'],$search['search_restarts'],$search['search_log_id']);
            $this->db->run('UPDATE jobs SET payload=? WHERE id=?',[json_encode($search,JSON_THROW_ON_ERROR),$job['id']]);
        }
        foreach($rows as $torrent) {
            if(in_array($torrent['state'],['planned','removed'],true)) continue;
            $q ??= new Qbit($this->settings,$this->config);
            $infos=$torrent['hash'] ? $q->hashes([$torrent['hash']]) : $q->tagged($torrent['tag']);
            if(!$infos && $torrent['state']==='adding' && !$torrent['hash']) throw new \RuntimeException('Unconfirmed torrent add needs inspection.');
            foreach($infos as $info) {
                $current=$this->db->one('SELECT generation FROM request_controls WHERE request_id=?',[$id]);
                if(!$current || (int)$current['generation']!==(int)$control['generation']) return;
                $tags=array_filter(array_map('trim',explode(',',(string)($info['tags'] ?? ''))));
                if(!in_array($torrent['tag'],$tags,true)) {
                    if($action==='remove') continue; // Already detached, or the external torrent no longer belongs to this request.
                    throw new \RuntimeException('The request tag is missing from a linked torrent.');
                }
                $hash=(string)$info['hash'];
                $shared=(bool)array_filter($tags,fn($tag)=>preg_match('/^ScreenPort-[0-9]+-/',$tag) && !str_starts_with($tag,'ScreenPort-'.$id.'-'));
                if($shared) {
                    if($action==='remove') $q->untag($hash,$torrent['tag']);
                    continue;
                }
                if($action==='suspend') {
                    if(!in_array($info['state'] ?? '',['pausedDL','pausedUP','stoppedDL','stoppedUP'],true)) {
                        $payload['paused_hashes']=array_values(array_unique(array_merge($payload['paused_hashes'],[$hash])));
                        // Persist intent before a remote stop so an uncertain response remains resumable.
                        $this->db->run('UPDATE request_controls SET payload=? WHERE request_id=? AND generation=?',[json_encode($payload,JSON_THROW_ON_ERROR),$id,$control['generation']]);
                        $q->stop([$hash]);
                    }
                } elseif($action==='resume') {
                    if(in_array($hash,$payload['paused_hashes'],true)) $q->start([$hash]);
                } else $q->remove([$hash]); // Files are always preserved.
            }
        }
        $this->db->transaction(function() use($control,$id,$action) {
            $current=$this->db->one('SELECT generation FROM request_controls WHERE request_id=?',[$id]);
            if(!$current || (int)$current['generation']!==(int)$control['generation']) return; // A newer admin command wins.
            if($action==='resume') {
                $status=in_array($control['previous_status'],['failed','downloading','complete'],true) ? $control['previous_status'] : 'queued';
                $this->db->run('DELETE FROM request_controls WHERE request_id=?',[$id]);
                $this->db->run("UPDATE jobs SET status='pending',attempts=0,due_at=?,last_error=NULL WHERE request_id=? AND (status='running' OR (kind<>'acquire' AND status='failed'))",[time(),$id]);
                $message=$status==='failed' ? 'Resumed by admin. This failed request still requires Retry.' : 'Resumed by admin.';
            } else {
                $status=$action==='remove' ? 'removed' : 'suspended';
                $message=$action==='remove' ? 'Removed by admin. Downloaded files were kept.' : 'Suspended by admin. Automatic work is on hold; shared torrents may continue for other requests.';
                $this->db->run("UPDATE request_controls SET status='done',last_error=NULL WHERE request_id=?",[$id]);
                if($action==='remove') {
                    $this->db->run("UPDATE torrents SET state='removed' WHERE request_id=?",[$id]);
                    $this->db->run("UPDATE jobs SET status='cancelled' WHERE request_id=?",[$id]);
                    foreach(['emails','folder_alerts','manager_alerts'] as $table) $this->db->run("UPDATE $table SET status='cancelled' WHERE request_id=? AND status<>'sent'",[$id]);
                }
            }
            $this->db->run('UPDATE requests SET status=?,message=?,updated_at=? WHERE id=?',[$status,$message,time(),$id]);
            $this->db->audit(null,'admin_'.$action.'_applied',(string)$id);
        });
    }
}
