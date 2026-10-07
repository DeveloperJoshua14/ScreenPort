<?php
declare(strict_types=1);
namespace ScreenPort;

final class Requests
{
    public function __construct(private Config $config,private Db $db,private Settings $settings,private Catalog $catalog) {}
    public function enqueue(array $user,string $type,int $id): array
    {
        if(!in_array($type,['movie','tv'],true) || $id<1) throw new ApiError('Invalid media request.',422);
        if($this->config->demo()) throw new ApiError('Preview mode cannot start real downloads.',403);
        $this->checkControl($type,$id);
        $media=null;
        try { return $this->submit($user,$type,$id,$media); }
        catch(\Throwable $error) {
            if(!$this->controlled($type,$id)) (new ManagerAlerts($this->config,$this->db,$this->settings))->submission((int)$user['id'],$type,$id,$media,$error);
            throw $error;
        }
    }
    private function submit(array $user,string $type,int $id,?array &$media): array
    {
        if($this->config->demo()) throw new ApiError('Preview mode cannot start real downloads.',403);
        if(!$this->settings->bool('DOWNLOADS_ENABLED')) throw new ApiError('New downloads are paused by an admin.',409);
        if(!$this->settings->ready()) throw new ApiError('An admin needs to complete the catalog, downloader, AI, and email settings first.',503);
        $this->db->limit('requests:'.$user['id'],(int)$this->settings->get('REQUEST_LIMIT_PER_DAY'),86400);
        $media=$this->catalog->detail($type,$id,true);
        if(!$media['available']) throw new ApiError('Not out Yet. '.$media['availability_note'],422);
        try {
            $library=(new Jellyfin($this->settings,$this->db))->find($media);
            if($library) throw new ApiError('This title is on Jellyfin. Open it there or request a manual review.',409);
        } catch(ApiError $e) { throw $e; } catch(\Throwable $e) { /* Library lookup is advisory; requests still deduplicate in SQLite. */ }
        return $this->db->transaction(function() use($user,$type,$id,$media) {
            $this->checkControl($type,$id);
            $r=$this->db->one('SELECT * FROM requests WHERE media_type=? AND media_id=?',[$type,$id]);
            if(!$r) {
                $this->db->run('INSERT INTO requests(media_type,media_id,media,created_at,updated_at) VALUES(?,?,?,?,?)',[$type,$id,json_encode($media,JSON_THROW_ON_ERROR),time(),time()]);
                $rid=$this->db->id();
                $this->db->run('INSERT INTO jobs(request_id,kind,due_at) VALUES(?,?,?)',[$rid,'acquire',time()]);
            } else {
                $rid=(int)$r['id'];
                if($r['status']==='failed') throw new ApiError('This request needs an admin to review and retry it.',409);
            }
            $this->db->run('INSERT OR IGNORE INTO subscribers VALUES(?,?,?)',[$rid,$user['id'],time()]);
            if($r && in_array($r['status'],['downloading','complete'],true) && $this->db->one("SELECT id FROM jobs WHERE request_id=? AND kind='notify'",[$rid])) {
                $this->db->run("UPDATE jobs SET status='pending',due_at=? WHERE request_id=? AND kind='notify'",[time(),$rid]);
            }
            $this->db->audit((int)$user['id'],'requested_media',$type.':'.$id);
            return ['id'=>$rid,'status'=>$r['status'] ?? 'queued','message'=>$r ? 'You are following this request. Duplicate downloads are prevented.' : 'Request added. ScreenPort will find the best available download.'];
        });
    }
    private function controlled(string $type,int $id): ?array
    {
        return $this->db->one('SELECT c.action FROM request_controls c JOIN requests r ON r.id=c.request_id WHERE r.media_type=? AND r.media_id=?',[$type,$id]);
    }
    private function checkControl(string $type,int $id): void
    {
        $control=$this->controlled($type,$id);
        if($control) throw new ApiError($control['action']==='remove' ? 'This request was removed by an admin. Contact the download manager for review.' : 'This request is suspended or awaiting an admin action. Contact the download manager for review.',409);
    }
    public function list(array $user,bool $includeRemoved=false): array
    {
        if($includeRemoved && $user['role']!=='admin') throw new ApiError('Admin access required.',403);
        $filter=$includeRemoved ? '1=1' : "r.status<>'removed'";
        $sql=$user['role']==='admin' ? "SELECT r.* FROM requests r WHERE $filter ORDER BY r.created_at DESC LIMIT 100" : "SELECT r.* FROM requests r JOIN subscribers s ON s.request_id=r.id WHERE s.user_id=? AND $filter ORDER BY r.created_at DESC LIMIT 100";
        $rows=$this->db->all($sql,$user['role']==='admin' ? [] : [$user['id']]);
        $out=[];
        foreach($rows as $r) {
            $torrents=$this->db->all('SELECT name,state,save_path,seasons,snapshot FROM torrents WHERE request_id=?',[$r['id']]);
            $progress=0; $total=0; $done=0; $eta=0; $unknown=false; $speed=0;
            foreach($torrents as &$t) {
                $s=json_decode($t['snapshot'],true) ?: [];
                $t=['name'=>$t['name'],'state'=>$s['state'] ?? $t['state'],'seasons'=>json_decode($t['seasons'],true),'progress'=>(float)($s['progress'] ?? 0)];
                if($user['role']==='admin') $t+=['size'=>(int)($s['size'] ?? 0),'seeders'=>(int)($s['num_seeds'] ?? 0),'peers'=>(int)($s['num_leechs'] ?? 0)];
                $bytes=(int)($s['size'] ?? 0); $total+=$bytes; $done+=(int)round($bytes*(float)($s['progress'] ?? 0)); $speed+=(int)($s['dlspeed'] ?? 0);
                if(($s['progress'] ?? 0)<1 && (empty($s['eta']) || $s['eta']>=8640000)) $unknown=true;
                else $eta=max($eta,(int)($s['eta'] ?? 0));
            }
            unset($t);
            if(in_array($r['status'],['suspended','suspending','resuming','removing','removed'],true)) { $speed=0; $unknown=true; }
            $progress=$total>0 ? $done/$total : ($r['status']==='complete' ? 1 : 0);
            $m=json_decode($r['media'],true);
            $out[]=['id'=>(int)$r['id'],'media'=>$m,'status'=>$r['status'],'message'=>$r['message'],'created_at'=>(int)$r['created_at'],
                'progress'=>$progress,'eta'=>$unknown ? null : $eta,'speed'=>$speed,'torrents'=>$torrents,
                'email_status'=>$this->db->all('SELECT audience,status FROM emails WHERE request_id=?',[$r['id']])];
        }
        return $out;
    }
    public function retry(int $id,int $admin): void
    {
        $this->db->transaction(function() use($id,$admin) {
            $r=$this->db->one('SELECT * FROM requests WHERE id=?',[$id]);
            if($this->db->one('SELECT request_id FROM request_controls WHERE request_id=?',[$id])) throw new ApiError('Resume or restore this request before retrying.',409);
            if(!$r || $r['status']!=='failed') throw new ApiError('Only failed requests can be retried.',409);
            // Retain the original plan and torrent records: retry reconciles uncertain adds before trying again.
            $job=$this->db->one("SELECT * FROM jobs WHERE request_id=? AND kind='acquire'",[$id]);
            $payload=json_decode($job['payload'] ?? '{}',true) ?: [];
            unset($payload['search_restarts']);
            if(!empty($payload['search_id'])) $payload['restart_search']=true;
            $this->db->run('INSERT INTO manager_alert_epochs(request_id,generation) VALUES(?,1) ON CONFLICT(request_id) DO UPDATE SET generation=generation+1',[$id]);
            $this->db->run("UPDATE requests SET status='queued',message='Retry scheduled by admin',updated_at=? WHERE id=?",[time(),$id]);
            $this->db->run("UPDATE jobs SET status='pending',attempts=0,last_error=NULL,due_at=?,payload=? WHERE id=?",[time(),json_encode($payload,JSON_THROW_ON_ERROR),$job['id']]);
            $this->db->audit($admin,'retried_request',(string)$id);
        });
    }
}
