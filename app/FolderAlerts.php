<?php
declare(strict_types=1);
namespace ScreenPort;

final class FolderAlerts
{
    public function __construct(private Config $config,private Db $db,private Settings $settings,private ?\Closure $testDelivery=null)
    {
        if($testDelivery && PHP_SAPI!=='cli') throw new \LogicException('Test deliveries are CLI-only.');
    }
    public function queue(int $requestId,array $media,string $path): void
    {
        if(($media['type'] ?? '')!=='movie' || !MovieFolders::newPath($path,$this->settings)) return;
        $path=rtrim($path,'/').'/';
        $insert=$this->db->run('INSERT OR IGNORE INTO folder_alerts(path,request_id,recipient,due_at) VALUES(?,?,?,?)',
            [$path,$requestId,strtolower(trim($this->settings->get('DOWNLOAD_MANAGER_EMAIL'))),time()]);
        if($insert->rowCount()) $this->db->audit(null,'new_movie_folder','Request '.$requestId.': '.$path);
    }
    public function deliver(): void
    {
        if($this->config->demo()) return;
        foreach($this->db->all("SELECT * FROM folder_alerts WHERE status='pending' AND due_at<=? LIMIT 25",[time()]) as $alert) {
            try {
                $request=$this->db->one('SELECT media FROM requests WHERE id=?',[$alert['request_id']]);
                $content=Mailer::folderContent(json_decode($request['media'],true,512,JSON_THROW_ON_ERROR),$alert['path']);
                if(!filter_var($alert['recipient'],FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('Invalid manager email.');
                if($this->testDelivery) ($this->testDelivery)($alert['recipient'],$content);
                else (new Mailer($this->config,$this->settings))->send($alert['recipient'],$content);
                $this->db->run("UPDATE folder_alerts SET status='sent' WHERE path=?",[$alert['path']]);
            } catch(\Throwable $e) {
                $tries=(int)$alert['attempts']+1;
                $this->db->run('UPDATE folder_alerts SET status=?,attempts=?,due_at=? WHERE path=?',
                    [$tries>=5 ? 'failed' : 'pending',$tries,time()+min(3600,60*2**$tries),$alert['path']]);
                $this->db->audit(null,'folder_email_delivery_failed','Request '.$alert['request_id'].'; attempt '.$tries);
            }
        }
    }
}
