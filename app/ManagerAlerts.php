<?php
declare(strict_types=1);
namespace ScreenPort;

final class ManagerAlerts
{
    public function __construct(private Config $config,private Db $db,private Settings $settings,private ?\Closure $testDelivery=null)
    {
        if($testDelivery && PHP_SAPI!=='cli') throw new \LogicException('Test deliveries are CLI-only.');
    }
    public static function reason(\Throwable $error): string
    {
        // Never forward third-party exception messages or raw service responses.
        if($error instanceof \RuntimeException && !($error instanceof \PDOException) && str_starts_with($error->getFile(),__DIR__)) return mb_substr($error->getMessage(),0,1000);
        return 'ScreenPort could not start the download. Check configuration and service connectivity.';
    }
    private function queue(string $key,string $kind,string $recipient,array $payload,?int $user=null,?int $request=null): void
    {
        if($this->config->demo()) return;
        $this->db->run('INSERT OR IGNORE INTO manager_alerts(event_key,kind,recipient,payload,user_id,request_id,created_at,due_at) VALUES(?,?,?,?,?,?,?,?)',
            [$key,$kind,strtolower(trim($recipient)),json_encode($payload,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),$user,$request,time(),time()]);
    }
    public function account(int $user): void
    {
        $recipient=trim($this->settings->get('ACCOUNT_MANAGER_EMAIL')) ?: $this->settings->get('DOWNLOAD_MANAGER_EMAIL');
        // Store only the user reference, never the submitted password or request body.
        $this->queue('account:'.$user,'account_requested',$recipient,[],$user);
    }
    public function submission(int $user,string $type,int $id,?array $media,\Throwable $error): void
    {
        $payload=(new SearchLog($this->db,$this->settings))->redact([
            'type'=>$type,'media_id'=>$id,'title'=>$media['title'] ?? ucfirst($type).' (TMDB #'.$id.')',
            'reason'=>self::reason($error),'stage'=>'Request submission','terminal'=>true]);
        // Repeat submissions of the same media by the same user get one alert per day.
        $this->queue('submission:'.$user.':'.$type.':'.$id.':'.gmdate('Y-m-d'),'download_submission_failed',$this->settings->get('DOWNLOAD_MANAGER_EMAIL'),$payload,$user);
    }
    public function acquisition(int $request,string $reason,bool $terminal,int $attempt): void
    {
        $row=$this->db->one('SELECT media FROM requests WHERE id=?',[$request]);
        if(!$row) return;
        $media=json_decode($row['media'],true,512,JSON_THROW_ON_ERROR);
        $cycle=(int)($this->db->one('SELECT generation FROM manager_alert_epochs WHERE request_id=?',[$request])['generation'] ?? 0);
        $payload=(new SearchLog($this->db,$this->settings))->redact([
            'type'=>$media['type'] ?? '', 'media_id'=>$media['id'] ?? 0,'title'=>$media['title'] ?? 'Requested media',
            'reason'=>$reason,'stage'=>'Background acquisition','terminal'=>$terminal,'attempt'=>$attempt]);
        $this->queue('acquisition:'.$request.':'.$cycle.':'.($terminal ? 'final' : 'initial'),'download_start_failed',$this->settings->get('DOWNLOAD_MANAGER_EMAIL'),$payload,null,$request);
    }
    public function monitoring(int $request,string $reason): void
    {
        $row=$this->db->one('SELECT media FROM requests WHERE id=?',[$request]);
        if(!$row) return;
        $media=json_decode($row['media'],true,512,JSON_THROW_ON_ERROR);
        $cycle=(int)($this->db->one('SELECT generation FROM manager_alert_epochs WHERE request_id=?',[$request])['generation'] ?? 0);
        $payload=(new SearchLog($this->db,$this->settings))->redact(['type'=>$media['type'] ?? '', 'media_id'=>$media['id'] ?? 0,'title'=>$media['title'] ?? 'Requested media',
            'reason'=>$reason,'stage'=>'qBittorrent download monitoring','terminal'=>true,'monitoring'=>true]);
        $this->queue('monitoring:'.$request.':'.$cycle,'download_start_failed',$this->settings->get('DOWNLOAD_MANAGER_EMAIL'),$payload,null,$request);
    }
    public function deliver(): void
    {
        if($this->config->demo()) return;
        foreach($this->db->all("SELECT * FROM manager_alerts WHERE status='pending' AND due_at<=? ORDER BY id LIMIT 25",[time()]) as $alert) {
            try {
                $payload=json_decode($alert['payload'],true,512,JSON_THROW_ON_ERROR);
                $user=$alert['user_id'] ? $this->db->one('SELECT username,email,created_at FROM users WHERE id=?',[$alert['user_id']]) : null;
                $content=$alert['kind']==='account_requested'
                    ? Mailer::accountContent($user,$this->config->get('APP_URL'))
                    : Mailer::failureContent((new SearchLog($this->db,$this->settings))->redact($payload),$alert['request_id'] ? (int)$alert['request_id'] : null,$user['username'] ?? '',$this->config->get('APP_URL'));
                if(trim($alert['recipient'])==='') {
                    $alert['recipient']=$alert['kind']==='account_requested'
                        ? (trim($this->settings->get('ACCOUNT_MANAGER_EMAIL')) ?: $this->settings->get('DOWNLOAD_MANAGER_EMAIL')) : $this->settings->get('DOWNLOAD_MANAGER_EMAIL');
                    $this->db->run('UPDATE manager_alerts SET recipient=? WHERE id=?',[$alert['recipient'],$alert['id']]);
                }
                if(!filter_var($alert['recipient'],FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('Manager email is missing or invalid.');
                if($this->testDelivery) ($this->testDelivery)($alert['recipient'],$content);
                else (new Mailer($this->config,$this->settings))->send($alert['recipient'],$content);
                $this->db->run("UPDATE manager_alerts SET status='sent' WHERE id=?",[$alert['id']]);
            } catch(\Throwable $error) {
                $tries=(int)$alert['attempts']+1;
                $this->db->run('UPDATE manager_alerts SET status=?,attempts=?,due_at=? WHERE id=?',[$tries>=5 ? 'failed' : 'pending',$tries,time()+min(3600,60*2**$tries),$alert['id']]);
                $this->db->audit(null,'manager_email_delivery_failed','Alert '.$alert['id'].'; attempt '.$tries);
            }
        }
    }
}
