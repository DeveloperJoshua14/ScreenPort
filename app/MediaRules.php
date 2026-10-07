<?php
declare(strict_types=1);
namespace ScreenPort;

final class MediaRules
{
    public static function availability(array $detail,string $type,string $region,string $today): array
    {
        if($type==='movie') {
            $dates=[]; $rating='';
            foreach($detail['release_dates']['results'] ?? [] as $country) {
                if(($country['iso_3166_1'] ?? '')!==$region) continue;
                foreach($country['release_dates'] ?? [] as $r) {
                    if(!empty($r['certification'])) $rating=$r['certification'];
                    if(in_array((int)($r['type'] ?? 0),[4,5,6],true) && !empty($r['release_date'])) $dates[]=substr($r['release_date'],0,10);
                }
            }
            sort($dates); $date=$dates[0] ?? null;
            return ['available'=>$date!==null && $date<=$today,'home_release'=>$date,'rating'=>$rating ?: 'Unrated',
                'availability_note'=>$date ? ($date<=$today ? 'Released for home viewing' : 'Home release scheduled for '.$date) : 'Home release not confirmed in '.$region];
        }
        $date=$detail['first_air_date'] ?? null; $rating='';
        foreach($detail['content_ratings']['results'] ?? [] as $r) if(($r['iso_3166_1'] ?? '')===$region) $rating=$r['rating'] ?? '';
        return ['available'=>!empty($date) && $date<=$today,'home_release'=>$date ?: null,'rating'=>$rating ?: 'Unrated',
            'availability_note'=>!empty($date) && $date<=$today ? 'Aired on TV or streaming · completed seasons only' : 'First broadcast not confirmed'];
    }
    public static function completedSeason(array $season,string $today): bool
    {
        $episodes=$season['episodes'] ?? [];
        if(!$episodes) return false;
        foreach($episodes as $episode) if(empty($episode['air_date']) || $episode['air_date']>$today) return false;
        return true;
    }
    public static function folder(string $value): string
    {
        $s=preg_replace('/[\x00-\x1f\x7f\\\\\/:*?"<>|.]/u',' ', $value) ?? '';
        $s=preg_replace('/\s+/u',' ',trim($s)) ?? '';
        return mb_substr($s!=='' ? $s : 'Unknown',0,100);
    }
    public static function path(array $media,bool $separate,Settings $settings): string
    {
        if($media['type']==='movie') {
            return rtrim($settings->get('MOVIE_ROOT'),'/').'/'.MovieFolders::folder($media,$settings).'/';
        }
        $matureRatings=array_map('trim',explode(',',strtoupper($settings->get('MATURE_RATINGS'))));
        $rating=strtoupper($media['rating'] ?? 'Unrated');
        $mature=in_array($rating,$matureRatings,true) || ($settings->bool('UNKNOWN_RATING_MATURE') && in_array($rating,['','UNRATED','NR'],true));
        $root=rtrim($settings->get($mature ? 'MATURE_TV_ROOT' : 'TV_ROOT'),'/');
        return $root.'/'.($separate ? self::folder($media['title']).'/' : '');
    }
}
