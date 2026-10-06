<?php
declare(strict_types=1);
namespace ScreenPort;

final class Qbit
{
    private ?\CurlHandle $handle=null;
    private string $base;
    private string $origin;
    public function __construct(private Settings $settings)
    {
        $this->base=Http::baseUrl($settings->get('QBITTORRENT_URL'));
        $p=parse_url($this->base);
        $this->origin=$p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
    }
    private function login(): void
    {
        if($this->handle) return;
        $this->handle=curl_init(); curl_setopt($this->handle,CURLOPT_COOKIEFILE,'');
        $r=Http::request('POST',$this->base.'/api/v2/auth/login',$this->headers(true),http_build_query([
            'username'=>$this->settings->get('QBITTORRENT_USERNAME'),'password'=>$this->settings->get('QBITTORRENT_PASSWORD')]),$this->handle);
        if($r['status']!==200 || trim($r['body'])!=='Ok.') { $this->handle=null; throw new \RuntimeException('qBittorrent login failed. Check its credentials and Web UI access rules.'); }
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
        if($r['status']<200 || $r['status']>=300) throw new \RuntimeException('qBittorrent '.$path.' failed (HTTP '.$r['status'].').');
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
