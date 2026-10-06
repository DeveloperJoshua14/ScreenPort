<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
umask(0077);
$command=$argv[1] ?? 'help';
$root=dirname(__DIR__);
if($command==='init') {
    require $root.'/app/Config.php';
    $file=getenv('SCREENPORT_ENV_FILE') ?: $root.'/.env';
    if(!is_file($file) && !getenv('APP_KEY')) copy($root.'/.env.example',$file);
    $config=new ScreenPort\Config($file);
    if($config->get('APP_KEY')==='') file_put_contents($file,"\nAPP_KEY=".base64_encode(random_bytes(32))."\n",FILE_APPEND|LOCK_EX);
    if(is_file($file)) chmod($file,0600);
    echo "Private encryption key configured. Keep a secure backup of .env and storage.\n";
    require $root.'/app/bootstrap.php';
    echo "Database initialized. Next create your admin account.\n";
    exit(0);
}
if($command==='help') {
    echo "ScreenPort PHP 8.2 console\n\n";
    echo "  init                    Initialize database and private encryption key\n";
    echo "  create-admin            Create the first admin (interactive or environment inputs)\n";
    echo "  reset-password USER     Reset an existing account's password\n";
    echo "  worker [--once]         Process the queue (continuous or one cron run)\n";
    echo "  check                   Read-only service connectivity checks\n";
    exit(0);
}
try {
    require $root.'/app/bootstrap.php';
    $config->key();
    $password=static function(): string {
        $value=getenv('SCREENPORT_ADMIN_PASSWORD');
        if($value!==false && $value!=='') return $value;
        echo "Password (12–72 bytes; input hidden on Linux): ";
        $hidden=PHP_OS_FAMILY!=='Windows' && function_exists('shell_exec') && stream_isatty(STDIN);
        if($hidden) shell_exec('stty -echo');
        try { $value=rtrim(fgets(STDIN) ?: '',"\r\n"); } finally { if($hidden) shell_exec('stty echo'); echo "\n"; }
        return $value;
    };
    if($command==='create-admin') {
        $username=getenv('SCREENPORT_ADMIN_USERNAME'); $email=getenv('SCREENPORT_ADMIN_EMAIL');
        if(!$username) { echo 'Admin username: '; $username=trim(fgets(STDIN) ?: ''); }
        if(!$email) { echo 'Admin email: '; $email=trim(fgets(STDIN) ?: ''); }
        $id=$auth->create($username,$email,$password(),'admin','approved');
        $db->audit($id,'created_admin_cli'); echo "Admin account created. No credentials were printed.\n";
    } elseif($command==='reset-password') {
        $user=$db->one('SELECT id FROM users WHERE username=?',[$argv[2] ?? '']);
        if(!$user) throw new RuntimeException('Account not found.');
        $new=$password(); ScreenPort\Auth::validatePassword($new);
        $db->run('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),$user['id']]);
        $db->audit((int)$user['id'],'password_reset_cli'); echo "Password changed. Existing sessions revoked.\n";
    } elseif($command==='worker') {
        if($config->demo()) throw new RuntimeException('Background downloads are disabled in preview mode.');
        $lock=fopen($config->storage().'/worker.lock','c');
        if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { echo "Another worker is active.\n"; exit(0); }
        $worker=new ScreenPort\Worker($config,$db,$settings,$catalog);
        $once=in_array('--once',$argv,true);
        do { $n=$worker->tick(); if($n) echo date('c')." Processed $n background jobs.\n"; if(!$once) sleep(3); } while(!$once);
        flock($lock,LOCK_UN); fclose($lock);
    } elseif($command==='check') {
        foreach(['Catalog','qBittorrent','Jellyfin','OpenAI','SMTP'] as $service) {
            try {
                switch($service) {
                    case 'Catalog': $catalog->detail('movie',550,true); break;
                    case 'qBittorrent': $q=new ScreenPort\Qbit($settings); $q->call('app/version',[],false,false); $plugins=$q->call('search/plugins');
                        if(!array_filter($plugins,fn($p)=>$p['enabled'] ?? false)) throw new RuntimeException('No enabled search plugins.'); break;
                    case 'Jellyfin': ScreenPort\Http::json('GET',ScreenPort\Http::baseUrl($settings->get('JELLYFIN_URL')).'/System/Info',['X-Emby-Token: '.$settings->get('JELLYFIN_API_KEY')]); break;
                    case 'OpenAI': ScreenPort\Http::json('GET','https://api.openai.com/v1/models/'.rawurlencode($settings->get('OPENAI_MODEL')),['Authorization: Bearer '.$settings->get('OPENAI_API_KEY')]); break;
                    case 'SMTP':
                        $smtp=new PHPMailer\PHPMailer\SMTP(); $smtp->do_debug=0;
                        $host=($settings->get('SMTP_ENCRYPTION')==='ssl' ? 'ssl://' : '').$settings->get('SMTP_HOST');
                        if(!$smtp->connect($host,(int)$settings->get('SMTP_PORT'),10) || !$smtp->hello('screenport.local')) throw new RuntimeException('Connection failed.');
                        if($settings->get('SMTP_ENCRYPTION')==='tls' && (!$smtp->startTLS() || !$smtp->hello('screenport.local'))) throw new RuntimeException('TLS failed.');
                        if($settings->get('SMTP_USERNAME')!=='' && !$smtp->authenticate($settings->get('SMTP_USERNAME'),$settings->get('SMTP_PASSWORD'))) throw new RuntimeException('Authentication failed.');
                        $smtp->quit(); $smtp->close(); break;
                }
                echo "$service: connected\n";
            } catch(Throwable $e) { echo "$service: unavailable; check credentials, network access, TLS, and required plugins\n"; }
        }
        echo "No downloads started and no email sent.\n";
    } else throw new RuntimeException('Unknown console command.');
} catch(Throwable $e) {
    // Do not dump exceptions or third-party responses: they may contain credentials.
    echo 'ScreenPort could not complete the command. '.($e instanceof ScreenPort\ApiError ? $e->getMessage() : 'Check configuration and prerequisites.')."\n";
    exit(1);
}
