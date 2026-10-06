<?php
declare(strict_types=1);
namespace ScreenPort;

final class Catalog
{
    public function __construct(private Config $config,private Db $db,private Settings $settings) {}
    private function today(): string { return (new \DateTimeImmutable('now',new \DateTimeZone($this->settings->get('TIMEZONE'))))->format('Y-m-d'); }
    private function tmdb(string $path,array $params=[],int $ttl=1800): array
    {
        $token=$this->settings->get('TMDB_READ_ACCESS_TOKEN');
        if(!$token) throw new ApiError('The catalog needs a TMDB access token. Ask an admin to finish setup.',503);
        $params=array_merge(['language'=>'en-US'],$params);
        $key='tmdb:'.$path.':'.hash('sha256',json_encode($params));
        $load=fn()=>Http::json('GET','https://api.themoviedb.org/3'.$path.'?'.http_build_query($params),['Authorization: Bearer '.$token]);
        return $ttl>0 ? $this->db->cached($key,$ttl,$load) : $load();
    }
    public function browse(string $type,int $page=1): array
    {
        if($this->config->demo()) return $this->demoList($type);
        $today=$this->today(); $start=date('Y-m-d',strtotime($today.' -120 days'));
        $params=['page'=>$page,'include_adult'=>'false','sort_by'=>$type==='movie' ? 'primary_release_date.desc' : 'first_air_date.desc','vote_count.gte'=>10];
        if($type==='movie') $params+=['region'=>$this->settings->get('REGION'),'release_date.gte'=>$start,'release_date.lte'=>$today,'with_release_type'=>'2|3|4|5|6'];
        else $params+=['first_air_date.gte'=>$start,'first_air_date.lte'=>$today];
        $r=$this->tmdb('/discover/'.$type,$params,1800);
        return ['results'=>$this->hydrate(array_slice($r['results'] ?? [],0,20),$type),'page'=>$page,'total_pages'=>min((int)($r['total_pages'] ?? 1),500)];
    }
    public function search(string $query,string $type,int $page=1): array
    {
        if($this->config->demo()) {
            $items=array_merge($this->demoList('movie')['results'],$this->demoList('tv')['results']);
            return ['results'=>array_values(array_filter($items,fn($m)=>($type==='all' || $m['type']===$type) && str_contains(strtolower($m['title']),strtolower($query)))),'page'=>1,'total_pages'=>1];
        }
        $endpoint=$type==='all' ? 'multi' : $type;
        $r=$this->tmdb('/search/'.$endpoint,['query'=>$query,'page'=>$page,'include_adult'=>'false','region'=>$this->settings->get('REGION')],300);
        $items=array_filter($r['results'] ?? [],fn($m)=>in_array($m['media_type'] ?? $type,['movie','tv'],true));
        return ['results'=>$this->hydrate(array_slice(array_values($items),0,20),$type),'page'=>$page,'total_pages'=>min((int)($r['total_pages'] ?? 1),500)];
    }
    private function hydrate(array $items,string $defaultType): array
    {
        $out=[];
        foreach($items as $item) {
            $type=$item['media_type'] ?? $defaultType;
            try { $out[]=$this->detail($type,(int)$item['id']); }
            catch(\Throwable $e) { $m=$this->normalize($item,$type); $m['available']=false; $m['availability_note']='Release information could not be verified'; $out[]=$m; }
        }
        return $out;
    }
    public function detail(string $type,int $id,bool $fresh=false): array
    {
        if(!in_array($type,['movie','tv'],true) || $id<1) throw new ApiError('Invalid title.',422);
        if($this->config->demo()) {
            foreach($this->demoList($type)['results'] as $m) if($m['id']===$id) return $m;
            throw new ApiError('Title not found.',404);
        }
        $append=$type==='movie' ? 'release_dates,external_ids' : 'content_ratings,external_ids';
        $d=$this->tmdb('/'.$type.'/'.$id,['append_to_response'=>$append],$fresh ? 0 : 1800);
        return $this->normalize($d,$type);
    }
    private function normalize(array $d,string $type): array
    {
        $date=$type==='movie' ? ($d['release_date'] ?? '') : ($d['first_air_date'] ?? '');
        $poster=$d['poster_path'] ?? ''; $backdrop=$d['backdrop_path'] ?? '';
        $img=static fn($path,$size)=>is_string($path) && preg_match('~^/[A-Za-z0-9._-]+$~',$path) ? 'https://image.tmdb.org/t/p/'.$size.$path : null;
        return ['id'=>(int)$d['id'],'type'=>$type,'title'=>$d['title'] ?? $d['name'] ?? 'Untitled',
            'original_title'=>$d['original_title'] ?? $d['original_name'] ?? '', 'original_language'=>$d['original_language'] ?? 'en',
            'overview'=>$d['overview'] ?? '', 'release_date'=>$date,'year'=>substr($date,0,4),'poster'=>$img($poster,'w500'),
            'backdrop'=>$img($backdrop,'w1280'),'score'=>round((float)($d['vote_average'] ?? 0),1),
            'genres'=>array_column($d['genres'] ?? [],'name'),'runtime'=>(int)($d['runtime'] ?? ($d['episode_run_time'][0] ?? 45)),
            'imdb_id'=>$d['external_ids']['imdb_id'] ?? $d['imdb_id'] ?? null,'status'=>$d['status'] ?? '',
            'seasons'=>$d['seasons'] ?? []]+MediaRules::availability($d,$type,$this->settings->get('REGION'),$this->today());
    }
    public function completedSeasons(array $media,bool $fresh=true): array
    {
        if($this->config->demo()) return [['number'=>1,'episodes'=>8,'runtime'=>45,'last_air_date'=>'2025-03-01']];
        $eligible=[];
        foreach($media['seasons'] as $s) {
            $number=(int)($s['season_number'] ?? 0);
            if($number<1 || empty($s['air_date']) || $s['air_date']>$this->today()) continue;
            $season=$this->tmdb('/tv/'.$media['id'].'/season/'.$number,[],$fresh ? 0 : 1800);
            if(!MediaRules::completedSeason($season,$this->today())) continue;
            $episodes=$season['episodes']; $runtimes=array_filter(array_column($episodes,'runtime'),fn($r)=>$r>0);
            $eligible[]=['number'=>$number,'episodes'=>count($episodes),'runtime'=>$runtimes ? (int)round(array_sum($runtimes)/count($runtimes)) : $media['runtime'],
                'last_air_date'=>max(array_column($episodes,'air_date'))];
        }
        return $eligible;
    }
    private function demoList(string $type): array
    {
        $data=require dirname(__DIR__).'/resources/demo.php';
        return ['results'=>array_values(array_filter($data,fn($m)=>$m['type']===$type)),'page'=>1,'total_pages'=>1];
    }
}
