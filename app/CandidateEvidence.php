<?php
declare(strict_types=1);
namespace ScreenPort;

final class CandidateEvidence
{
    private static function text(string $name): string
    {
        return str_replace(['.','_','–','—'],[' ',' ','-','-'],$name);
    }
    public static function seasons(string $name,array $media): array
    {
        $name=self::text($name); $found=[]; $invalid=false;
        $episode=preg_match('/\bS\d{1,2}\s*E\d{1,3}\b|\b\d{1,2}x\d{1,3}\b/i',$name)===1;
        if(preg_match_all('/\bS(\d{1,2})\s*(?:-|to)\s*S?(\d{1,2})\b/i',$name,$ranges,PREG_SET_ORDER)) {
            foreach($ranges as $r) {
                if((int)$r[1]<1 || (int)$r[2]<(int)$r[1] || (int)$r[2]-(int)$r[1]>50) $invalid=true;
                else $found=array_merge($found,range((int)$r[1],(int)$r[2]));
            }
        }
        if(preg_match_all('/\bS(\d{1,2})(?!\d|\s*E\d)/i',$name,$matches)) $found=array_merge($found,array_map('intval',$matches[1]));
        if(preg_match_all('/\bSeasons?\s+(\d{1,2}(?:(?:\s*(?:,|&|and|-|to)\s*)\d{1,2})*)/i',$name,$lists,PREG_SET_ORDER)) {
            foreach($lists as $list) {
                if(preg_match('/^(\d{1,2})\s*(?:-|to)\s*(\d{1,2})$/i',$list[1],$r)) {
                    if((int)$r[1]<1 || (int)$r[2]<(int)$r[1] || (int)$r[2]-(int)$r[1]>50) $invalid=true;
                    else $found=array_merge($found,range((int)$r[1],(int)$r[2]));
                } else { preg_match_all('/\d{1,2}/',$list[1],$numbers); $found=array_merge($found,array_map('intval',$numbers[0])); }
            }
        }
        $source=$found ? 'explicit season numbers' : 'unknown';
        if(!$found && in_array($media['status'] ?? '',['Ended','Canceled'],true) && preg_match('/\b(?:complete\s+series|all\s+seasons)\b/i',$name)) {
            foreach($media['seasons'] ?? [] as $s) if(($s['season_number'] ?? 0)>0) $found[]=(int)$s['season_number'];
            if($found) $source='complete series of an ended show';
        }
        if(in_array(0,$found,true)) $invalid=true;
        $found=array_values(array_unique($found)); sort($found);
        return ['seasons'=>$found,'source'=>$source,'valid'=>!$invalid,'single_episode'=>$episode];
    }
    public static function audio(string $name,array $media,Settings $settings): array
    {
        $text=mb_strtolower(self::text($name));
        // A language word in the media title is not an audio label (e.g. French Kiss).
        foreach(array_unique([$media['title'] ?? '',$media['original_title'] ?? '']) as $title) if($title!=='') $text=str_replace(mb_strtolower(self::text($title)),' ',$text);
        $languages=[
            'en'=>'english|eng','fr'=>'french|fre|fra','de'=>'german|ger|deu','es'=>'spanish|spa|castellano|latino',
            'it'=>'italian|ita','ru'=>'russian|rus','hi'=>'hindi|hin','ta'=>'tamil|tam','te'=>'telugu|tel',
            'ja'=>'japanese|jpn','ko'=>'korean|kor','zh'=>'chinese|mandarin|chi|zho','ar'=>'arabic|ara',
            'pt'=>'portuguese|por','pl'=>'polish|pol','tr'=>'turkish|tur','nl'=>'dutch|dut|nld',
            'sv'=>'swedish|swe','da'=>'danish|dan','fi'=>'finnish|fin','no'=>'norwegian|nor','th'=>'thai|tha',
            'cs'=>'czech|cze|ces','hu'=>'hungarian|hun','uk'=>'ukrainian|ukr','he'=>'hebrew|heb',
            'bn'=>'bengali|ben','kn'=>'kannada|kan','ml'=>'malayalam|mal','pa'=>'punjabi|pan','mr'=>'marathi|mar',
            'ur'=>'urdu|urd','vi'=>'vietnamese|vie','id'=>'indonesian|ind','fa'=>'persian|farsi|per|fas',
            'el'=>'greek|gre|ell','ro'=>'romanian|rum|ron','bg'=>'bulgarian|bul','sr'=>'serbian|srp',
        ];
        foreach($languages as $code=>&$names) $names.='|'.$code;
        unset($names);
        $labels=implode('|',array_values($languages));
        // Subtitle languages are not audio languages. Do not infer English audio from ENG SUBS.
        $text=preg_replace('/\b(?:'.$labels.')\s*(?:subs?|subtitles?|subbed)\b|\b(?:subs?|subtitles?)\s*(?:'.$labels.')\b/i',' ',$text) ?? $text;
        $advertised=[];
        foreach($languages as $code=>$names) if(preg_match('/\b(?:'.$names.')\b/i',$text)) $advertised[]=$code;
        $original=$media['original_language'] ?? '';
        $expected=in_array($original,$advertised,true);
        $ambiguous=preg_match('/\b(?:multi(?:ple)?|dual)(?:\s*audio)?\b/i',$text)===1;
        if($expected) return ['allowed'=>true,'basis'=>'explicit','note'=>'Original-language audio advertised ('.$original.').'];
        if($advertised || $ambiguous || preg_match('/\b(?:dubbed|dub)\b/i',$text)) return ['allowed'=>false,'basis'=>'conflicting','note'=>'Different or ambiguous audio advertised; original-language audio not confirmed.'];
        if($original!=='' && $settings->bool('ASSUME_ORIGINAL_AUDIO')) return ['allowed'=>true,'basis'=>'assumed','note'=>'Original audio assumed from media metadata ('.$original.'); filename does not confirm audio.'];
        return ['allowed'=>false,'basis'=>'unknown','note'=>'Audio language is not explicitly advertised.'];
    }
    public static function inspect(string $name,array $media,array $seasons,Settings $settings): array
    {
        $quality='unknown';
        if(preg_match('/(?<!\d)(2160p|4k|1080p|720p)(?!\d)/i',$name,$m)) $quality=strtolower($m[1])==='4k' ? '2160p' : strtolower($m[1]);
        $coverage=self::seasons($name,$media);
        $episodes=0; $minutes=0;
        foreach($seasons as $season) if(in_array((int)$season['number'],$coverage['seasons'],true)) {
            $count=(int)$season['episodes']; $episodes+=$count;
            $minutes+=$count*max(1,(int)($season['runtime'] ?? $media['runtime'] ?? 45));
        }
        return ['quality'=>$quality,'coverage'=>$coverage,'audio'=>self::audio($name,$media,$settings),'episode_count'=>$episodes,
            'runtime_minutes'=>$minutes,'max_size_gib'=>$minutes>0 ? round($minutes/60*(float)$settings->get('TV_MAX_GIB_PER_HOUR'),3) : null];
    }
}
