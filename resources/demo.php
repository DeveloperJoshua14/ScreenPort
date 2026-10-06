<?php
// Explicit local preview only. Never inserted into production catalog or download queue.
return array_map(static function(array $m): array {
    return $m+['original_title'=>$m['title'],'original_language'=>'en','overview'=>'A new story to discover, a world to get lost in, and a good reason to stay in tonight. This is sample catalog data for the ScreenPort design preview.',
        'release_date'=>$m['year'].'-03-01','home_release'=>$m['available'] ? $m['year'].'-05-01' : null,
        'poster'=>null,'backdrop'=>null,'runtime'=>120,'imdb_id'=>null,'status'=>'Released',
        'availability_note'=>$m['available'] ? 'Released for home viewing' : 'Home release not confirmed',
        'seasons'=>$m['type']==='tv' ? [['season_number'=>1,'episode_count'=>8]] : []];
},[
    ['id'=>693134,'type'=>'movie','title'=>'Dune: Part Two','year'=>'2024','rating'=>'PG-13','genres'=>['Science Fiction','Adventure'],'score'=>8.1,'available'=>true],
    ['id'=>1184918,'type'=>'movie','title'=>'The Wild Robot','year'=>'2024','rating'=>'PG','genres'=>['Animation','Adventure'],'score'=>8.4,'available'=>true],
    ['id'=>950387,'type'=>'movie','title'=>'A Minecraft Movie','year'=>'2025','rating'=>'PG','genres'=>['Adventure','Comedy'],'score'=>6.2,'available'=>true],
    ['id'=>872585,'type'=>'movie','title'=>'Oppenheimer','year'=>'2023','rating'=>'R','genres'=>['Drama','History'],'score'=>8.0,'available'=>true],
    ['id'=>933260,'type'=>'movie','title'=>'The Substance','year'=>'2024','rating'=>'R','genres'=>['Horror','Drama'],'score'=>7.2,'available'=>true],
    ['id'=>999001,'type'=>'movie','title'=>'Beyond the Horizon','year'=>'2026','rating'=>'Unrated','genres'=>['Adventure'],'score'=>0.0,'available'=>false],
    ['id'=>414906,'type'=>'movie','title'=>'The Batman','year'=>'2022','rating'=>'PG-13','genres'=>['Crime','Mystery'],'score'=>7.7,'available'=>true],
    ['id'=>1022789,'type'=>'movie','title'=>'Inside Out 2','year'=>'2024','rating'=>'PG','genres'=>['Animation','Family'],'score'=>7.6,'available'=>true],
    ['id'=>95396,'type'=>'tv','title'=>'Severance','year'=>'2022','rating'=>'TV-MA','genres'=>['Drama','Mystery'],'score'=>8.7,'available'=>true],
    ['id'=>126308,'type'=>'tv','title'=>'Shōgun','year'=>'2024','rating'=>'TV-MA','genres'=>['Drama'],'score'=>8.5,'available'=>true],
    ['id'=>125988,'type'=>'tv','title'=>'Silo','year'=>'2023','rating'=>'TV-MA','genres'=>['Science Fiction','Drama'],'score'=>8.2,'available'=>true],
    ['id'=>100088,'type'=>'tv','title'=>'The Last of Us','year'=>'2023','rating'=>'TV-MA','genres'=>['Drama'],'score'=>8.6,'available'=>true],
    ['id'=>94997,'type'=>'tv','title'=>'House of the Dragon','year'=>'2022','rating'=>'TV-MA','genres'=>['Fantasy','Drama'],'score'=>8.4,'available'=>true],
    ['id'=>999002,'type'=>'tv','title'=>'The Next Chapter','year'=>'2027','rating'=>'Unrated','genres'=>['Drama'],'score'=>0.0,'available'=>false],
]);
