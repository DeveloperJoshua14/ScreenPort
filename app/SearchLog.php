<?php
declare(strict_types=1);
namespace ScreenPort;

final class SearchLog
{
    public function __construct(private Db $db,private Settings $settings) {}
    public function redact(mixed $value): mixed
    {
        if(is_array($value)) return array_map(fn($v)=>$this->redact($v),$value);
        if(!is_string($value)) return $value;
        // Whitelisted reports contain no URLs. Also redact untrusted names/model text.
        foreach(Settings::FIELDS as $key=>$field) {
            if(($field['secret'] ?? false) || in_array($key,['QBITTORRENT_URL','JELLYFIN_URL','SMTP_HOST','MAIL_FROM_ADDRESS','DOWNLOAD_MANAGER_EMAIL','ACCOUNT_MANAGER_EMAIL'],true)) {
                $secret=$this->settings->get($key);
                if($secret!=='') $value=str_replace(array_unique([$secret,rawurlencode($secret),urlencode($secret)]),'[redacted]',$value);
            }
        }
        $key=$this->settings->get('APP_KEY');
        if($key!=='') $value=str_replace($key,'[redacted]',$value);
        $value=preg_replace('~\b(?:https?://|magnet:\?|udp://)[^\s<>"\']+~i','[link redacted]',$value) ?? '';
        $value=preg_replace('~\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b~i','[email redacted]',$value) ?? '';
        $value=preg_replace('~\b(?:sk-(?:proj-|svcacct-)?[A-Za-z0-9_-]{20,}|eyJ[A-Za-z0-9_-]{15,}\.[A-Za-z0-9_-]{15,}\.[A-Za-z0-9_-]{10,})~','[token redacted]',$value) ?? '';
        return mb_substr(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/','',$value) ?? '',0,1500);
    }
    public function begin(int $request,array $report): int
    {
        $this->db->run('INSERT INTO search_logs(request_id,created_at,updated_at,report) VALUES(?,?,?,?)',[$request,time(),time(),json_encode($this->redact($report),JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)]);
        $id=$this->db->id();
        $this->db->run('DELETE FROM search_logs WHERE request_id=? AND id NOT IN (SELECT id FROM search_logs WHERE request_id=? ORDER BY id DESC LIMIT 10)',[$request,$request]);
        $this->db->run('DELETE FROM search_logs WHERE created_at<? OR id NOT IN (SELECT id FROM search_logs ORDER BY id DESC LIMIT 500)',[time()-30*86400]);
        return $id;
    }
    public function update(int $id,array $changes): void
    {
        $row=$this->db->one('SELECT report FROM search_logs WHERE id=?',[$id]);
        if(!$row) return;
        $report=array_merge(json_decode($row['report'],true,512,JSON_THROW_ON_ERROR),$changes);
        $this->db->run('UPDATE search_logs SET updated_at=?,report=? WHERE id=?',[time(),json_encode($this->redact($report),JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),$id]);
    }
    public function forRequest(int $request): array
    {
        return array_map(fn($row)=>['id'=>(int)$row['id'],'created_at'=>(int)$row['created_at'],'updated_at'=>(int)$row['updated_at'],'report'=>$this->redact(json_decode($row['report'],true,512,JSON_THROW_ON_ERROR))],
            $this->db->all('SELECT * FROM search_logs WHERE request_id=? ORDER BY id DESC LIMIT 10',[$request]));
    }
}
