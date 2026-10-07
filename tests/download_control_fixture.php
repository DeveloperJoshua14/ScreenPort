<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$auth->create('controladmin','admin@example.test','FakeAdminPassword2026!','admin','approved');
$member=$auth->create('controlmember','member@example.test','FakeMemberPassword2026!','user','approved');
foreach([901=>'failed',902=>'queued'] as $id=>$status) {
    $db->run('INSERT INTO requests(media_type,media_id,media,status,created_at,updated_at) VALUES(?,?,?,?,?,?)',['movie',$id,json_encode(['id'=>$id,'type'=>'movie','title'=>'HTTP Control '.$id,'year'=>'2025','rating'=>'PG']),$status,time(),time()]);
    $rid=$db->id();
    $db->run('INSERT INTO jobs(request_id,kind,status,due_at) VALUES(?,?,?,?)',[$rid,'acquire',$status==='failed' ? 'failed' : 'pending',time()+3600]);
    $db->run('INSERT INTO subscribers VALUES(?,?,?)',[$rid,$member,time()]);
}
echo "Isolated control fixtures initialized.\n";
