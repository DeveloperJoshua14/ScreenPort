<?php
declare(strict_types=1);
namespace ScreenPort;

final class TorrentPolicy
{
    public static function magnetHash(string $url): ?string
    {
        if(!str_starts_with(strtolower($url),'magnet:?')) return null;
        parse_str((string)parse_url($url,PHP_URL_QUERY),$parts); $xt=$parts['xt'] ?? '';
        if(!is_string($xt)) return null;
        if(preg_match('/^urn:btih:([0-9a-f]{40})$/i',$xt,$m)) return strtolower($m[1]);
        if(preg_match('/^urn:btih:([a-z2-7]{32})$/i',$xt,$m)) {
            $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits='';
            foreach(str_split(strtoupper($m[1])) as $c) $bits.=str_pad(decbin(strpos($alphabet,$c)),5,'0',STR_PAD_LEFT);
            $bytes=''; foreach(str_split($bits,8) as $b) $bytes.=chr(bindec($b));
            return bin2hex($bytes);
        }
        return null;
    }
    public static function urlAllowed(string $url,string $allowedHosts): bool
    {
        if(strlen($url)>6000 || preg_match('/[\x00-\x20\x7f]/',$url)) return false;
        if(str_starts_with(strtolower($url),'magnet:')) {
            if(self::magnetHash($url)===null) return false;
            parse_str((string)parse_url($url,PHP_URL_QUERY),$parts);
            // Strip source URLs before adding magnets; qBittorrent should only use their info hash and trackers.
            if(isset($parts['xs']) || isset($parts['as']) || isset($parts['ws'])) return false;
            $trackers=$parts['tr'] ?? [];
            foreach(is_array($trackers) ? $trackers : [$trackers] as $tracker) {
                if(!is_string($tracker)) return false;
                $p=parse_url($tracker);
                if(!$p || empty($p['host']) || !in_array($p['scheme'] ?? '',['http','https','udp'],true) || isset($p['user']) || isset($p['pass'])) return false;
                if(!self::publicHost($p['host'])) return false;
            }
            return true;
        }
        $p=parse_url($url);
        if(!$p || ($p['scheme'] ?? '')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || (isset($p['port']) && $p['port']!==443)) return false;
        $host=strtolower($p['host']);
        $allow=array_map('strtolower',array_map('trim',explode(',',$allowedHosts)));
        if(!in_array($host,$allow,true) || filter_var($host,FILTER_VALIDATE_IP) || !str_contains($host,'.')) return false;
        return self::publicHost($host);
    }
    private static function publicHost(string $host): bool
    {
        $host=trim($host,'[]');
        if(filter_var($host,FILTER_VALIDATE_IP)) return (bool)filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);
        if(!str_contains($host,'.') || preg_match('/(^|\.)(localhost|local|internal|home|lan)$/i',$host)) return false;
        $records=dns_get_record($host,DNS_A|DNS_AAAA) ?: [];
        if(!$records) return false;
        foreach($records as $r) {
            $ip=$r['ip'] ?? $r['ipv6'] ?? '';
            if(!$ip || !filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false;
        }
        return true;
    }
    private static function titleMatches(string $name,array $media): bool
    {
        $normalize=static function(string $s): string {
            $s=mb_strtolower($s,'UTF-8');
            $s=str_replace(['&','’',"'"],['and','',''],$s);
            return trim(preg_replace('/[^\pL\pN]+/u',' ',$s) ?? '');
        };
        $name=' '.$normalize($name).' ';
        foreach(array_unique([$media['title'],$media['original_title'] ?? '']) as $title) {
            $t=$normalize($title);
            if($t!=='' && str_contains($name,' '.$t.' ')) return true;
        }
        return false;
    }
    private static function provenSeasons(string $name,array $media,array $claimed): bool
    {
        $found=[];
        if(preg_match_all('/\bS(\d{1,2})\s*[-–]\s*S?(\d{1,2})\b/i',$name,$ranges,PREG_SET_ORDER)) {
            foreach($ranges as $r) { if((int)$r[2]<(int)$r[1] || (int)$r[2]-(int)$r[1]>50) return false; $found=array_merge($found,range((int)$r[1],(int)$r[2])); }
        }
        if(preg_match_all('/\bSeasons?\s+(\d{1,2})\s*[-–]\s*(\d{1,2})\b/i',$name,$ranges,PREG_SET_ORDER)) {
            foreach($ranges as $r) { if((int)$r[2]<(int)$r[1] || (int)$r[2]-(int)$r[1]>50) return false; $found=array_merge($found,range((int)$r[1],(int)$r[2])); }
        }
        if(preg_match_all('/\bS(\d{1,2})(?!\d|E\d)/i',$name,$matches)) $found=array_merge($found,array_map('intval',$matches[1]));
        if(preg_match_all('/\bSeason\s+(\d{1,2})\b/i',$name,$matches)) $found=array_merge($found,array_map('intval',$matches[1]));
        $found=array_values(array_unique($found)); sort($found); sort($claimed);
        if($found) return $found===$claimed;
        // A generic "complete series" claim is accepted only for an ended show and all known numbered seasons.
        if(!in_array($media['status'] ?? '',['Ended','Canceled'],true) || !preg_match('/\b(?:complete\s+series|all\s+seasons)\b/i',$name)) return false;
        $all=[]; foreach($media['seasons'] ?? [] as $s) if(($s['season_number'] ?? 0)>0) $all[]=(int)$s['season_number'];
        sort($all); return $all && $all===$claimed;
    }
    public static function candidates(array $results,int $limit,Settings $settings): array
    {
        usort($results,fn($a,$b)=>(int)($b['nbSeeders'] ?? 0)<=>(int)($a['nbSeeders'] ?? 0));
        $out=[]; $seen=[];
        foreach($results as $r) {
            $name=mb_substr((string)($r['fileName'] ?? ''),0,400);
            $url=(string)($r['fileUrl'] ?? ''); $hash=self::magnetHash($url); $key=$hash ?? hash('sha256',$url);
            $size=(float)($r['fileSize'] ?? 0);
            if(!$name || isset($seen[$key]) || $size<=0 || $size>(float)$settings->get('MAX_TORRENT_GB')*1024**3 || (int)($r['nbSeeders'] ?? 0)<1) continue;
            if(preg_match('/\b(hd[ ._-]?cam|hd[ ._-]?ts|camrip|telesync|telecine|hd[ ._-]?tc|dvdscr|screener|workprint|cam|ts|tc)\b/i',$name)) continue;
            if(!self::urlAllowed($url,$settings->get('TORRENT_ALLOWED_HOSTS'))) continue;
            $seen[$key]=true;
            $out[]=['id'=>'t_'.substr(hash('sha256',$key),0,16),'name'=>$name,'size_bytes'=>(int)$size,
                'seeders'=>(int)$r['nbSeeders'],'peers'=>max(0,(int)($r['nbLeechers'] ?? 0)),'url'=>$url,'hash'=>$hash];
            if(count($out)>=$limit) break;
        }
        return $out;
    }
    public static function validate(array $selection,array $candidates,array $media,array $wanted,Settings $settings,bool $allOnly): array
    {
        $choices=$selection['selections'] ?? null;
        if(!is_array($choices)) throw new \RuntimeException('The model returned an invalid selection.');
        if(!$choices) return [];
        $byId=array_column($candidates,null,'id'); $picked=[]; $coverage=[]; $ids=[];
        foreach($choices as $s) {
            $id=$s['candidate_id'] ?? '';
            if(!isset($byId[$id]) || isset($ids[$id])) throw new \RuntimeException('The model chose an unknown or duplicate torrent.');
            $ids[$id]=true; $candidate=$byId[$id];
            if(!self::titleMatches($candidate['name'],$media)) throw new \RuntimeException('Torrent title does not match the requested movie or show.');
            if(($s['correct_title'] ?? false)!==true || ($s['language_ok'] ?? false)!==true || ($s['theater_recording'] ?? true)!==false
                || (float)($s['confidence'] ?? 0)<(float)$settings->get('SELECTION_MIN_CONFIDENCE')) throw new \RuntimeException('Torrent selection did not meet the required confidence or language rules.');
            $quality=$s['quality'] ?? '';
            if(!in_array($quality,$media['type']==='movie' ? ['1080p','2160p'] : ['1080p','720p'],true)) throw new \RuntimeException('Torrent quality did not meet the configured policy.');
            $title=$candidate['name'];
            if(!preg_match('/(?<!\d)'.($quality==='2160p' ? '(?:2160p|4k)' : preg_quote($quality,'/')).'(?!\d)/i',$title)) throw new \RuntimeException('Torrent title does not support its reported resolution.');
            $seasons=$s['seasons'] ?? [];
            if(!is_array($seasons) || count($seasons)!==count(array_unique($seasons))) throw new \RuntimeException('Invalid season coverage.');
            if($media['type']==='movie') {
                if(count($choices)!==1 || $seasons || ($s['pack'] ?? '')!=='movie') throw new \RuntimeException('Movies require exactly one movie torrent.');
                if(preg_match('/\b(19\d{2}|20\d{2})\b/',$title,$year) && $year[1]!==$media['year']) throw new \RuntimeException('Torrent release year does not match the movie.');
            } else {
                if(!$seasons || !in_array($s['pack'] ?? '',['all_seasons','season'],true)) throw new \RuntimeException('Only complete season packs are allowed.');
                if($allOnly && (($s['pack'] ?? '')!=='all_seasons' || count($choices)!==1)) throw new \RuntimeException('Expected one pack covering all released, complete seasons.');
                if(preg_match('/\bS\d{1,2}E\d{1,3}\b/i',$title)) throw new \RuntimeException('Single-episode torrents are not allowed.');
                if(!self::provenSeasons($title,$media,$seasons)) throw new \RuntimeException('Torrent title does not prove its reported season coverage.');
                foreach($seasons as $season) {
                    if(!is_int($season) || !in_array($season,$wanted,true) || isset($coverage[$season])) throw new \RuntimeException('Season packs overlap or include unreleased seasons.');
                    $coverage[$season]=true;
                }
            }
            // Resolve URLs exclusively from the server-held candidate list, never from generated text.
            $picked[]=$candidate+['seasons'=>$seasons,'reason'=>mb_substr((string)($s['reason'] ?? ''),0,500),'pack'=>$s['pack']];
        }
        if($media['type']==='tv') {
            $got=array_keys($coverage); sort($got); sort($wanted);
            if($got!==$wanted) throw new \RuntimeException('Torrent selection does not cover every requested completed season.');
        }
        return $picked;
    }
}
