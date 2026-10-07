<?php
declare(strict_types=1);
namespace ScreenPort;

final class Qbit
{
    private ?\CurlHandle $handle=null;
    private string $base;
    private string $origin;
    private ?string $cookieFile=null;
    public function __construct(private Settings $settings,?Config $config=null)
    {
        $this->base=Http::baseUrl($settings->get('QBITTORRENT_URL'));
        $p=parse_url($this->base);
        $this->origin=$p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        if($config) {
            // Only the locked worker persists sessions. Connection checks use isolated logins.
            $dir=$config->storage().'/qbit';
            if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new \RuntimeException('Private qBittorrent session storage is unavailable.');
            chmod($dir,0700);
            $identity=json_encode([$this->base,$settings->get('QBITTORRENT_USERNAME'),$settings->get('QBITTORRENT_PASSWORD')],JSON_THROW_ON_ERROR);
            $this->cookieFile=$dir.'/'.hash_hmac('sha256',$identity,$config->key()).'.cookies';
        }
    }
    private function login(): void
    {
        if($this->handle) return;
        $this->handle=curl_init(); curl_setopt($this->handle,CURLOPT_COOKIEFILE,$this->cookieFile ?? '');
        if($this->cookieFile) {
            $saved=is_file($this->cookieFile) && filesize($this->cookieFile)>0;
            $file=fopen($this->cookieFile,'c');
            if(!$file) throw new \RuntimeException('Private qBittorrent session storage is not writable.');
            fclose($file); chmod($this->cookieFile,0600);
            curl_setopt($this->handle,CURLOPT_COOKIEJAR,$this->cookieFile);
            if($saved) {
                $probe=Http::request('GET',$this->base.'/api/v2/app/version',$this->headers(),null,$this->handle);
                if($probe['status']===200) { $this->saveCookies(); return; }
                if(!in_array($probe['status'],[401,403],true)) throw new \RuntimeException('qBittorrent session check failed (HTTP '.$probe['status'].').');
            }
            curl_setopt($this->handle,CURLOPT_COOKIELIST,'ALL');
        }
        $r=Http::request('POST',$this->base.'/api/v2/auth/login',$this->headers(true),http_build_query([
            'username'=>$this->settings->get('QBITTORRENT_USERNAME'),'password'=>$this->settings->get('QBITTORRENT_PASSWORD')]),$this->handle);
        if($r['status']!==200 || trim($r['body'])!=='Ok.') { $this->handle=null; throw new \RuntimeException('qBittorrent login failed. Check its credentials and Web UI access rules.'); }
        $this->saveCookies();
    }
    private function saveCookies(): void
    {
        if($this->cookieFile && $this->handle) {
            curl_setopt($this->handle,CURLOPT_COOKIELIST,'FLUSH');
            chmod($this->cookieFile,0600);
        }
    }
    private function headers(bool $form=false): array
    {
        return array_merge(['Referer: '.$this->base.'/','Origin: '.$this->origin],$form ? ['Content-Type: application/x-www-form-urlencoded'] : []);
    }
    public function call(string $path,array $params=[],bool $post=false,bool $json=true): mixed
    {
        $this->login(); $url=$this->base.'/api/v2/'.$path;
        if(!$post && $params) $url.='?'.http_build_query($params);
        $r=Http::request($post ? 'POST' : 'GET',$url,$this->headers($post),$post ? http_build_query($params) : null,$this->handle);
        if(in_array($r['status'],[401,403],true)) {
            // Authentication rejection cannot have performed the requested operation.
            curl_setopt($this->handle,CURLOPT_COOKIELIST,'ALL');
            $this->handle=null;
            $this->login();
            $r=Http::request($post ? 'POST' : 'GET',$url,$this->headers($post),$post ? http_build_query($params) : null,$this->handle);
        }
        $this->saveCookies();
        if($r['status']<200 || $r['status']>=300) throw new \RuntimeException('qBittorrent '.$path.' failed (HTTP '.$r['status'].').',$r['status']);
        if(!$json) return trim($r['body']);
        return json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);
    }
    public function startSearch(string $pattern): int
    {
        $r=$this->call('search/start',['pattern'=>$pattern,'plugins'=>$this->settings->get('QBITTORRENT_SEARCH_PLUGINS'),'category'=>'all'],true);
        if(empty($r['id'])) throw new \RuntimeException('qBittorrent did not create a search job.');
        return (int)$r['id'];
    }
    public function results(int $id): array { return $this->call('search/results',['id'=>$id,'limit'=>500,'offset'=>0]); }
    public function stopSearch(int $id): void { $this->call('search/stop',['id'=>$id],true,false); }
    public function deleteSearch(int $id): void { $this->call('search/delete',['id'=>$id],true,false); }
    public function tagged(string $tag): array { return $this->call('torrents/info',['tag'=>$tag]); }
    public function hashes(array $hashes): array { return $hashes ? $this->call('torrents/info',['hashes'=>implode('|',$hashes)]) : []; }
    private function controlHashes(array $hashes): string
    {
        foreach($hashes as $hash) if(!is_string($hash) || !preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/i',$hash)) throw new \RuntimeException('Invalid torrent identity for download control.');
        return implode('|',array_unique($hashes));
    }
    public function stop(array $hashes): void
    {
        if(!$hashes) return;
        $ids=$this->controlHashes($hashes);
        try { $this->call('torrents/stop',['hashes'=>$ids],true,false); }
        catch(\RuntimeException $e) { if($e->getCode()!==404) throw $e; $this->call('torrents/pause',['hashes'=>$ids],true,false); }
    }
    public function start(array $hashes): void
    {
        if(!$hashes) return;
        $ids=$this->controlHashes($hashes);
        try { $this->call('torrents/start',['hashes'=>$ids],true,false); }
        catch(\RuntimeException $e) { if($e->getCode()!==404) throw $e; $this->call('torrents/resume',['hashes'=>$ids],true,false); }
    }
    public function remove(array $hashes): void
    {
        if($hashes) $this->call('torrents/delete',['hashes'=>$this->controlHashes($hashes),'deleteFiles'=>'false'],true,false);
    }
    public function untag(string $hash,string $tag): void
    {
        $this->call('torrents/removeTags',['hashes'=>$this->controlHashes([$hash]),'tags'=>$tag],true,false);
    }
    public function add(array $torrent,string $type): void
    {
        // Exact save path wins over category rules because automatic management is disabled.
        $category=$type==='movie' ? 'Movies' : 'TV Shows';
        $cats=$this->call('torrents/categories');
        if(!isset($cats[$category])) $this->call('torrents/createCategory',['category'=>$category,'savePath'=>''],true,false);
        $r=$this->call('torrents/add',['urls'=>$torrent['url'],'savepath'=>$torrent['save_path'],'category'=>$category,
            'tags'=>($type==='movie' ? 'Movie' : 'TV Show').',Added by ScreenPort,'.$torrent['tag'],
            'autoTMM'=>'false','paused'=>'false','stopped'=>'false','skip_checking'=>'false'],true,false);
        if($r!=='Ok.') throw new \RuntimeException('qBittorrent rejected the selected torrent.');
    }
    public function tagExisting(string $hash,string $tag,string $type): void
    {
        $this->call('torrents/addTags',['hashes'=>$hash,'tags'=>($type==='movie' ? 'Movie' : 'TV Show').',Added by ScreenPort,'.$tag],true,false);
    }
}
