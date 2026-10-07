<?php
declare(strict_types=1);
use ScreenPort\{ApiError,Auth,Http,Jellyfin,Qbit,Requests,SearchLog,Settings};

require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
try {
    $config->key();
    if(!$config->local() && !$config->secureRequest()) {
        // Proxy deployments must set HTTPS=on at the trusted web server, never trust arbitrary forwarded headers.
        throw new ApiError('HTTPS is required. Configure TLS on the web server.',503);
    }
    $auth->start();
    $action=$_GET['action'] ?? 'session';
    if(!is_string($action)) throw new ApiError('Invalid action.',422);
    $method=$_SERVER['REQUEST_METHOD'];
    $read=['session','catalog','media','downloads','admin','admin-search-log'];
    if(!in_array($method,['GET','POST'],true) || ($method==='GET' && !in_array($action,$read,true)) || ($method==='POST' && in_array($action,$read,true))) throw new ApiError('Method not allowed.',405);
    $body=[];
    if($method==='POST') {
        $auth->csrf();
        if((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>40000) throw new ApiError('Request is too large.',413);
        if(!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '','application/json')) throw new ApiError('Use a JSON request.',415);
        $raw=file_get_contents('php://input',false,null,0,40001);
        if(strlen($raw)>40000) throw new ApiError('Request is too large.',413);
        $body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($body) || !preg_match('/^\s*\{/',$raw)) throw new ApiError('Invalid request body.',422);
    }
    $string=static function(array $source,string $key,string $default='',int $max=500): string {
        $v=$source[$key] ?? $default;
        if(!is_string($v) || strlen($v)>$max) throw new ApiError('Invalid '.$key.'.',422);
        return $v;
    };
    $integer=static function(array $source,string $key,int $default=0): int {
        $v=$source[$key] ?? $default;
        if(filter_var($v,FILTER_VALIDATE_INT)===false || (int)$v<0) throw new ApiError('Invalid '.$key.'.',422);
        return (int)$v;
    };
    $requests=new Requests($config,$db,$settings,$catalog);
    switch($action) {
        case 'session':
            $user=$auth->user(false);
            $result=['user'=>$user,'csrf'=>$_SESSION['csrf'],'registration_open'=>$settings->bool('REGISTRATION_OPEN'),'demo'=>$config->demo(),
                'setup_required'=>(int)$db->one('SELECT COUNT(*) AS n FROM users')['n']===0,
                'catalog_ready'=>$settings->get('TMDB_READ_ACCESS_TOKEN')!=='' || $config->demo()];
            break;
        case 'login':
            $user=$auth->login($string($body,'username','',40),$string($body,'password','',72));
            $result=['user'=>$user,'csrf'=>$_SESSION['csrf']]; break;
        case 'logout': $auth->logout(); $result=['csrf'=>$_SESSION['csrf']]; break;
        case 'register':
            if(!$settings->bool('REGISTRATION_OPEN') || $config->demo()) throw new ApiError('Account requests are currently closed.',403);
            $db->limit('register:'.$auth->ip(),5,3600);
            $auth->create($string($body,'username','',40),$string($body,'email','',254),$string($body,'password','',72));
            $db->audit(null,'account_requested'); $result=['message'=>'Account requested. An admin must approve it before you can sign in.']; break;
        case 'catalog':
            $user=$auth->user(); $db->limit('catalog:'.$user['id'],100,60);
            $type=$string($_GET,'type','movie',5); $q=trim($string($_GET,'q','',120)); $page=max(1,min(500,$integer($_GET,'page',1)));
            if(!in_array($type,['movie','tv','all'],true) || ($q==='' && $type==='all')) throw new ApiError('Invalid catalog type.',422);
            // Release the session lock before making external catalog requests.
            session_write_close();
            $result=$q!=='' ? $catalog->search($q,$type,$page) : $catalog->browse($type,$page); break;
        case 'media':
            $user=$auth->user(); $db->limit('detail:'.$user['id'],60,60); session_write_close();
            $media=$catalog->detail($string($_GET,'type','movie',5),$integer($_GET,'id'));
            $library=null; $libraryChecked=false;
            if(!$config->demo()) try { $library=(new Jellyfin($settings,$db))->find($media); $libraryChecked=$settings->get('JELLYFIN_URL')!==''; } catch(Throwable $e) {}
            $result=$media+['library'=>$library,'library_checked'=>$libraryChecked]; break;
        case 'request':
            $user=$auth->user(); session_write_close();
            $result=$requests->enqueue($user,$string($body,'type','',5),$integer($body,'id')); break;
        case 'downloads': $result=['requests'=>$requests->list($auth->user())]; break;
        case 'profile':
            $user=$auth->user(); $old=$string($body,'current_password','',72); $new=$string($body,'new_password','',72);
            $row=$db->one('SELECT password_hash FROM users WHERE id=?',[$user['id']]);
            $db->limit('password:'.$user['id'],10,900);
            if(!password_verify($old,$row['password_hash'])) throw new ApiError('Current password is incorrect.',422);
            Auth::validatePassword($new);
            $db->run('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),$user['id']]);
            $auth->logout(); $result=['message'=>'Password changed. Please sign in again.','csrf'=>$_SESSION['csrf']]; break;
        case 'admin':
            $admin=$auth->admin();
            $heartbeat=$db->one("SELECT value FROM cache WHERE key='worker_heartbeat'");
            $result=['users'=>$db->all('SELECT id,username,email,role,status,created_at FROM users ORDER BY created_at DESC'),
                'settings'=>$settings->display(),'ready'=>$settings->ready(),
                'worker_seen'=>$heartbeat ? json_decode($heartbeat['value'],true) : null,
                'audit'=>$db->all('SELECT a.action,a.detail,a.created_at,u.username FROM audit a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 30'),
                'email_failures'=>(int)$db->one("SELECT (SELECT COUNT(*) FROM emails WHERE status='failed')+(SELECT COUNT(*) FROM folder_alerts WHERE status='failed')+(SELECT COUNT(*) FROM manager_alerts WHERE status='failed') AS n")['n']]; break;
        case 'admin-search-log':
            $admin=$auth->admin(); $id=$integer($_GET,'id');
            $db->limit('search-log:'.$admin['id'],60,60);
            if(!$db->one('SELECT id FROM requests WHERE id=?',[$id])) throw new ApiError('Request not found.',404);
            $result=['request_id'=>$id,'logs'=>(new SearchLog($db,$settings))->forRequest($id)]; break;
        case 'admin-user-create':
            $admin=$auth->admin();
            $id=$auth->create($string($body,'username','',40),$string($body,'email','',254),$string($body,'password','',72),$string($body,'role','user',10),'approved');
            $db->audit((int)$admin['id'],'created_account',(string)$id); $result=['message'=>'Account created and approved.']; break;
        case 'admin-user-update':
            $admin=$auth->admin(); $id=$integer($body,'id'); $role=$string($body,'role','user',10); $status=$string($body,'status','approved',10); $password=$string($body,'password','',72);
            if(!in_array($role,['user','admin'],true) || !in_array($status,['pending','approved','disabled'],true)) throw new ApiError('Invalid account status or role.',422);
            $db->transaction(function() use($id,$role,$status,$password,$admin,$db) {
                $u=$db->one('SELECT * FROM users WHERE id=?',[$id]); if(!$u) throw new ApiError('Account not found.',404);
                if($u['id']===$admin['id'] && ($role!=='admin' || $status!=='approved')) throw new ApiError('Keep your own admin account active.',422);
                if($u['role']==='admin' && $u['status']==='approved' && ($role!=='admin' || $status!=='approved') && (int)$db->one("SELECT COUNT(*) AS n FROM users WHERE role='admin' AND status='approved'")['n']<2) throw new ApiError('The last active admin cannot be disabled.',422);
                $db->run('UPDATE users SET role=?,status=?,auth_version=auth_version+1 WHERE id=?',[$role,$status,$id]);
                if($password!=='') { Auth::validatePassword($password); $db->run('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$id]); }
                if($id===$admin['id']) $_SESSION['auth_version']++;
                $db->audit((int)$admin['id'],'updated_account',(string)$id);
            }); $result=['message'=>'Account updated.']; break;
        case 'admin-settings':
            $admin=$auth->admin();
            if(!isset($body['settings']) || !is_array($body['settings'])) throw new ApiError('Invalid settings.',422);
            $settings->save($body['settings']); $db->audit((int)$admin['id'],'updated_settings'); $result=['message'=>'Settings saved. Blank secret fields keep existing values.']; break;
        case 'admin-retry': $admin=$auth->admin(); $requests->retry($integer($body,'id'),(int)$admin['id']); $result=['message'=>'Retry scheduled.']; break;
        case 'admin-email-retry':
            $admin=$auth->admin(); $db->run("UPDATE emails SET status='pending',attempts=0,due_at=? WHERE status='failed'",[time()]);
            $db->run("UPDATE folder_alerts SET status='pending',attempts=0,due_at=? WHERE status='failed'",[time()]);
            $db->run("UPDATE manager_alerts SET status='pending',attempts=0,due_at=?,recipient=CASE WHEN kind='account_requested' THEN ? ELSE ? END WHERE status='failed'",[time(),trim($settings->get('ACCOUNT_MANAGER_EMAIL')) ?: $settings->get('DOWNLOAD_MANAGER_EMAIL'),$settings->get('DOWNLOAD_MANAGER_EMAIL')]);
            $db->audit((int)$admin['id'],'retried_emails'); $result=['message'=>'Failed emails scheduled for retry.']; break;
        case 'admin-connections':
            $admin=$auth->admin(); $db->limit('connections:'.$admin['id'],5,300); session_write_close();
            $checks=[];
            foreach(['catalog','qBittorrent','Jellyfin','OpenAI','SMTP'] as $service) {
                try {
                    if($config->demo()) { $checks[$service]=['ok'=>null,'message'=>'Disabled in preview']; continue; }
                    switch($service) {
                        case 'catalog': $catalog->detail('movie',550,true); break;
                        case 'qBittorrent': $q=new Qbit($settings); $version=$q->call('app/version',[],false,false); $plugins=$q->call('search/plugins');
                            if(!array_filter($plugins,fn($p)=>$p['enabled'] ?? false)) throw new RuntimeException('Enable at least one search plugin in qBittorrent.'); break;
                        case 'Jellyfin': Http::json('GET',Http::baseUrl($settings->get('JELLYFIN_URL')).'/System/Info',['X-Emby-Token: '.$settings->get('JELLYFIN_API_KEY')]); break;
                        case 'OpenAI': Http::json('GET','https://api.openai.com/v1/models/'.rawurlencode($settings->get('OPENAI_MODEL')),['Authorization: Bearer '.$settings->get('OPENAI_API_KEY')]); break;
                        case 'SMTP':
                            if(!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) throw new RuntimeException('Mail dependency is missing.');
                            $smtp=new \PHPMailer\PHPMailer\SMTP(); $smtp->do_debug=0;
                            $prefix=$settings->get('SMTP_ENCRYPTION')==='ssl' ? 'ssl://' : '';
                            if(!$smtp->connect($prefix.$settings->get('SMTP_HOST'),(int)$settings->get('SMTP_PORT'),10) || !$smtp->hello('screenport.local')) throw new RuntimeException('SMTP connection failed.');
                            if($settings->get('SMTP_ENCRYPTION')==='tls' && (!$smtp->startTLS() || !$smtp->hello('screenport.local'))) throw new RuntimeException('SMTP TLS failed.');
                            if($settings->get('SMTP_USERNAME')!=='' && !$smtp->authenticate($settings->get('SMTP_USERNAME'),$settings->get('SMTP_PASSWORD'))) throw new RuntimeException('SMTP authentication failed.');
                            $smtp->quit(); $smtp->close(); break;
                    }
                    $checks[$service]=['ok'=>true,'message'=>'Connected'];
                } catch(Throwable $e) { $checks[$service]=['ok'=>false,'message'=>$service==='qBittorrent' && str_contains($e->getMessage(),'plugin') ? 'Enable qBittorrent search plugins.' : 'Check credentials, network access, and TLS.']; }
            }
            $result=['checks'=>$checks]; break;
        default: throw new ApiError('Action not found.',404);
    }
    echo json_encode(['ok'=>true,'data'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
} catch(ApiError $e) { http_response_code($e->status); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
catch(JsonException $e) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Invalid JSON data.']); }
catch(Throwable $e) { http_response_code(503); echo json_encode(['ok'=>false,'error'=>'ScreenPort could not complete this action. Check setup and service connectivity.']); }
