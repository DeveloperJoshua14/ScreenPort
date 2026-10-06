<?php
declare(strict_types=1);
namespace ScreenPort;

final class Jellyfin
{
    public function __construct(private Settings $settings,private Db $db) {}
    public function find(array $media): ?array
    {
        if(!$this->settings->get('JELLYFIN_URL') || !$this->settings->get('JELLYFIN_API_KEY')) return null;
        $key='jellyfin:'.$media['type'].':'.$media['id'];
        return $this->db->cached($key,120,function() use($media) {
            $url=Http::baseUrl($this->settings->get('JELLYFIN_URL'));
            $p=['Recursive'=>'true','IncludeItemTypes'=>$media['type']==='movie' ? 'Movie' : 'Series','SearchTerm'=>$media['title'],'Fields'=>'ProviderIds','Limit'=>30];
            $r=Http::json('GET',$url.'/Items?'.http_build_query($p),['X-Emby-Token: '.$this->settings->get('JELLYFIN_API_KEY')]);
            foreach($r['Items'] ?? [] as $item) {
                if((string)($item['ProviderIds']['Tmdb'] ?? '')===(string)$media['id'] || (!empty($media['imdb_id']) && ($item['ProviderIds']['Imdb'] ?? '')===$media['imdb_id'])) {
                    return ['in_library'=>true,'name'=>$item['Name'],'type'=>$media['type']];
                }
            }
            return null;
        });
    }
}
