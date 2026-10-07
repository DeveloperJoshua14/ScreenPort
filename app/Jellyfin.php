<?php
declare(strict_types=1);
namespace ScreenPort;

final class Jellyfin
{
    public function __construct(private Settings $settings,private Db $db) {}
    public function find(array $media,bool $fresh=false): ?array
    {
        if(!$this->settings->get('JELLYFIN_URL') || !$this->settings->get('JELLYFIN_API_KEY')) return null;
        $scope=hash('sha256',$this->settings->get('JELLYFIN_URL').'|'.$this->settings->get('JELLYFIN_API_KEY'));
        $key='jellyfin:v2:'.$scope.':'.$media['type'].':'.$media['id'];
        $load=function() use($media) {
            $url=Http::baseUrl($this->settings->get('JELLYFIN_URL'));
            $p=['Recursive'=>'true','IncludeItemTypes'=>$media['type']==='movie' ? 'Movie' : 'Series','SearchTerm'=>$media['title'],'Fields'=>'ProviderIds','Limit'=>30];
            $r=Http::json('GET',$url.'/Items?'.http_build_query($p),['X-Emby-Token: '.$this->settings->get('JELLYFIN_API_KEY')]);
            foreach($r['Items'] ?? [] as $item) {
                if(($item['Type'] ?? $p['IncludeItemTypes'])!==$p['IncludeItemTypes']) continue;
                if((string)($item['ProviderIds']['Tmdb'] ?? '')===(string)$media['id'] || (!empty($media['imdb_id']) && ($item['ProviderIds']['Imdb'] ?? '')===$media['imdb_id'])) {
                    return ['in_library'=>true,'name'=>$item['Name'] ?? $media['title'],'type'=>$media['type'],'item_id'=>$item['Id'] ?? '', 'server_id'=>$item['ServerId'] ?? ''];
                }
            }
            return null;
        };
        if($fresh) $this->db->run('DELETE FROM cache WHERE key=?',[$key]);
        $match=$this->db->cached($key,120,$load);
        if(!$match) return null;
        // Construct navigation links locally; API credentials never appear in them.
        $match['url']=$this->itemUrl((string)$match['item_id'],(string)$match['server_id'],$scope);
        unset($match['server_id']);
        return $match;
    }
    private function itemUrl(string $item,string $server,string $scope): ?string
    {
        $guid=static fn($value)=>preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})$/i',$value)===1;
        if(!$guid($item)) return null;
        if(!$guid($server)) {
            try {
                $server=(string)$this->db->cached('jellyfin:server:'.$scope,3600,fn()=>Http::json('GET',Http::baseUrl($this->settings->get('JELLYFIN_URL')).'/System/Info/Public',[],null,8)['Id'] ?? '');
            } catch(\Throwable $e) { return null; }
        }
        if(!$guid($server)) return null;
        $base=Http::baseUrl(trim($this->settings->get('JELLYFIN_PUBLIC_URL')) ?: $this->settings->get('JELLYFIN_URL'));
        if(preg_match('/[\x00-\x20\x7f]/',$base)) return null;
        return $base.'/web/#/details?'.http_build_query(['id'=>str_replace('-','',$item),'serverId'=>str_replace('-','',$server)],'','&',PHP_QUERY_RFC3986);
    }
    public function annotate(array $items): array
    {
        $reachable=true;
        foreach($items as &$media) {
            $media['library']=null;
            if(!$reachable) continue;
            try { $media['library']=$this->find($media); }
            catch(\Throwable $e) { $reachable=false; } // A library outage must not block browsing or repeat twenty timeouts.
        }
        unset($media);
        return $items;
    }
}
