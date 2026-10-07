<?php
declare(strict_types=1);
// Used only by the isolated localhost HTTP test.
require dirname(__DIR__).'/app/bootstrap.php';
$auth->create('reviewadmin','manager@example.test','FakeAdminPassword2026!','admin','approved');
$auth->create('reviewmember','member@example.test','FakeMemberPassword2026!','user','approved');
foreach(['movie'=>901,'tv'=>902] as $type=>$id) {
    $raw=['id'=>$id,'title'=>'HTTP Sample Movie','name'=>'HTTP Sample Show','original_language'=>'en','release_date'=>'2025-01-01','first_air_date'=>'2024-01-01','genres'=>[],'seasons'=>[['season_number'=>1]],
        'release_dates'=>['results'=>[['iso_3166_1'=>'US','release_dates'=>[['type'=>4,'release_date'=>'2025-02-01T00:00:00Z','certification'=>'PG']]]]],'content_ratings'=>['results'=>[['iso_3166_1'=>'US','rating'=>'TV-MA']]]];
    if($type==='tv') unset($raw['title']);
    $params=['language'=>'en-US','append_to_response'=>$type==='movie' ? 'release_dates,external_ids' : 'content_ratings,external_ids'];
    $key='tmdb:/'.$type.'/'.$id.':'.hash('sha256',json_encode($params));
    $db->run('INSERT INTO cache VALUES(?,?,?)',[$key,json_encode($raw),time()+3600]);
}
$params=['language'=>'en-US','query'=>'HTTP Sample','page'=>1,'include_adult'=>'false','region'=>'US'];
$key='tmdb:/search/multi:'.hash('sha256',json_encode($params));
$db->run('INSERT INTO cache VALUES(?,?,?)',[$key,json_encode(['results'=>[['id'=>901,'media_type'=>'movie'],['id'=>902,'media_type'=>'tv']],'total_pages'=>1]),time()+3600]);
echo "Isolated fake users and cached media initialized.\n";
