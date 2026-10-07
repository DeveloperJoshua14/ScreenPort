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
    public static function normalizeMagnet(string $url,array &$trackerChecks=[]): ?array
    {
        if(strlen($url)>6000 || preg_match('/[\x00-\x1f\x7f]/',$url)) return null;
        $hash=self::magnetHash($url);
        if(!$hash) return null;
        parse_str((string)parse_url($url,PHP_URL_QUERY),$parts);
        // Build a fresh link from the verified hash. Optional source URLs are never forwarded.
        $clean='magnet:?xt=urn:btih:'.$hash; $kept=[]; $removed=0;
        $trackers=$parts['tr'] ?? [];
        foreach(is_array($trackers) ? $trackers : [$trackers] as $tracker) {
            if(!is_string($tracker) || strlen($tracker)>2000) { $removed++; continue; }
            if(!array_key_exists($tracker,$trackerChecks)) $trackerChecks[$tracker]=self::urlAllowed('magnet:?xt=urn:btih:'.$hash.'&tr='.rawurlencode($tracker),'');
            if(!$trackerChecks[$tracker]) { $removed++; continue; }
            $kept[$tracker]=true;
        }
        foreach(array_keys($kept) as $tracker) $clean.='&tr='.rawurlencode($tracker);
        $notes=[];
        if($removed) $notes[]='Removed '.$removed.' unsafe or unreachable tracker'.($removed===1 ? '' : 's');
        if(isset($parts['xs']) || isset($parts['as']) || isset($parts['ws'])) $notes[]='Removed optional source URLs';
        if(!$kept && $removed) $notes[]='Valid info hash retained; peers must be found through DHT or peer exchange';
        return ['url'=>$clean,'note'=>implode('. ',$notes)];
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
        $e=CandidateEvidence::seasons($name,$media); sort($claimed);
        return $e['valid'] && !$e['single_episode'] && $e['seasons'] && $e['seasons']===$claimed;
    }
    public static function candidates(array $results,int $limit,Settings $settings,?array &$diagnostics=null,?array $media=null,array $seasons=[],bool $allOnly=false): array
    {
        usort($results,fn($a,$b)=>(int)($b['nbSeeders'] ?? 0)<=>(int)($a['nbSeeders'] ?? 0));
        $out=[]; $seen=[]; $rows=[]; $reasons=[]; $trackerChecks=[];
        foreach($results as $r) {
            $name=mb_substr((string)($r['fileName'] ?? ''),0,400);
            $url=(string)($r['fileUrl'] ?? ''); $hash=self::magnetHash($url); $key=$hash ?? hash('sha256',$url);
            $size=(float)($r['fileSize'] ?? 0);
            $reason=''; $status='filtered'; $note=''; $evidence=$media ? CandidateEvidence::inspect($name,$media,$seasons,$settings) : null;
            if(!$name) $reason='Missing torrent name';
            elseif(isset($seen[$key])) $reason='Duplicate torrent';
            elseif($size<=0) $reason='Missing or invalid torrent size';
            elseif($size>(float)$settings->get('MAX_TORRENT_GB')*1024**3) $reason='Exceeds maximum torrent size';
            elseif((int)($r['nbSeeders'] ?? 0)<1) $reason='No reported seeders';
            elseif(preg_match('/\b(hd[ ._-]?cam|hd[ ._-]?ts|camrip|telesync|telecine|hd[ ._-]?tc|dvdscr|screener|workprint|cam|ts|tc)\b/i',$name)) $reason='Theater recording, screener, or workprint';
            elseif($media && !self::titleMatches($name,$media)) $reason='Title does not match requested media';
            elseif($media && !$evidence['audio']['allowed']) $reason=$evidence['audio']['note'];
            elseif($media && !in_array($evidence['quality'],$media['type']==='movie' ? ['1080p','2160p'] : ['1080p','720p'],true)) $reason='Resolution is missing or outside the allowed quality policy';
            elseif($media && $media['type']==='tv' && !self::provenSeasons($name,$media,array_column($seasons,'number'))) $reason=$evidence['coverage']['single_episode'] ? 'Single-episode torrent, not a complete season pack' : 'Season coverage does not match this search';
            elseif($media && $media['type']==='tv' && $evidence['max_size_gib']!==null && $size/1024**3>$evidence['max_size_gib']) $reason='Exceeds TV size budget for episode count and runtime';
            elseif(count($out)>=$limit) { $reason='Not reviewed: model candidate limit reached'; $status='not_reviewed'; }
            else {
                if(str_starts_with(strtolower($url),'magnet:')) {
                    $magnet=self::normalizeMagnet($url,$trackerChecks);
                    if($magnet) { $url=$magnet['url']; $note=$magnet['note']; }
                    else $reason='Invalid magnet info hash or malformed link';
                }
                if($reason==='' && !self::urlAllowed($url,$settings->get('TORRENT_ALLOWED_HOSTS'))) {
                    $reason=str_starts_with(strtolower($url),'http://') ? 'HTTP torrent link: HTTPS or a valid magnet is required' : 'Torrent-file link is unsupported, not allowlisted, or failed public-network checks';
                }
            }
            $row=['name'=>$name,'size_bytes'=>(int)$size,'seeders'=>max(0,(int)($r['nbSeeders'] ?? 0)),
                'engine'=>mb_substr((string)($r['engineName'] ?? ''),0,80),'link_type'=>str_starts_with(strtolower($url),'magnet:') ? 'Magnet' : (str_starts_with(strtolower($url),'https://') ? 'HTTPS file' : (str_starts_with(strtolower($url),'http://') ? 'HTTP file' : 'Other'))];
            if($reason!=='') { $rows[]=$row+['status'=>$status,'reason'=>$reason]; $reasons[$reason]=($reasons[$reason] ?? 0)+1; continue; }
            $seen[$key]=true;
            if($evidence) {
                $note=trim($note.($note ? '. ' : '').$evidence['audio']['note']);
                if($evidence['episode_count']>0) $evidence['gib_per_episode']=round($size/1024**3/$evidence['episode_count'],3);
            }
            $out[]=['id'=>'t_'.substr(hash('sha256',$key),0,16),'name'=>$name,'size_bytes'=>(int)$size,
                'seeders'=>(int)$r['nbSeeders'],'peers'=>max(0,(int)($r['nbLeechers'] ?? 0)),'url'=>$url,'hash'=>$hash]+($evidence ? ['evidence'=>$evidence] : []);
            $rows[]=$row+['status'=>'model','reason'=>'Sent to model','candidate_id'=>$out[array_key_last($out)]['id'],'note'=>$note];
            if($diagnostics===null && count($out)>=$limit) break;
        }
        $diagnostics=['received'=>count($results),'sent_to_model'=>count($out),'reasons'=>$reasons,'rows'=>$rows];
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
            if(!CandidateEvidence::audio($candidate['name'],$media,$settings)['allowed']) throw new \RuntimeException('Torrent audio language conflicts with the configured policy.');
            if(($s['correct_title'] ?? false)!==true || ($s['language_ok'] ?? false)!==true || ($s['theater_recording'] ?? true)!==false
                || (float)($s['confidence'] ?? 0)<(float)$settings->get('SELECTION_MIN_CONFIDENCE')) throw new \RuntimeException('Torrent selection did not meet the required confidence or language rules.');
            $quality=$s['quality'] ?? '';
            if($media['type']==='tv' && isset($candidate['evidence']['max_size_gib']) && $candidate['size_bytes']/1024**3>$candidate['evidence']['max_size_gib']) throw new \RuntimeException('TV torrent exceeds the size budget for its episodes and runtime.');
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
