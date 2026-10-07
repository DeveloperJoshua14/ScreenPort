<?php
declare(strict_types=1);
namespace ScreenPort;

final class ApiError extends \RuntimeException
{
    public function __construct(string $message,public int $status=400) { parent::__construct($message); }
}
final class Auth
{
    public function __construct(private Db $db,private Config $config,private Settings $settings) {}
    public function start(): void
    {
        $dir=$this->config->storage().'/sessions';
        if(!is_dir($dir)) mkdir($dir,0700,true);
        ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
        session_save_path($dir); session_name('screenport_session');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!$this->config->local(),'httponly'=>true,'samesite'=>'Strict']);
        session_start();
        if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
    }
    public function user(bool $required=true): ?array
    {
        $id=$_SESSION['user_id'] ?? null;
        $u=$id ? $this->db->one('SELECT id,username,email,role,status,auth_version FROM users WHERE id=?',[$id]) : null;
        if(!$u || $u['status']!=='approved' || (int)$u['auth_version']!==($_SESSION['auth_version'] ?? 0)
            || time()-($_SESSION['last_seen'] ?? 0)>1800 || time()-($_SESSION['signed_in'] ?? 0)>43200) {
            unset($_SESSION['user_id']);
            if($required) throw new ApiError('Please sign in to continue.',401);
            return null;
        }
        $_SESSION['last_seen']=time(); unset($u['auth_version']); return $u;
    }
    public function admin(): array { $u=$this->user(); if($u['role']!=='admin') throw new ApiError('Admin access required.',403); return $u; }
    public function csrf(): void
    {
        $token=$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if(!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '',$token)) throw new ApiError('Your session changed. Refresh and try again.',403);
        $origin=$_SERVER['HTTP_ORIGIN'] ?? '';
        $expected=$this->config->get('APP_URL');
        if($origin!=='' && rtrim($origin,'/')!==rtrim($expected,'/')) throw new ApiError('Invalid request origin.',403);
    }
    public function login(string $username,string $password): array
    {
        $this->db->limit('login-ip:'.$this->ip(),30,900);
        $this->db->limit('login-name:'.hash('sha256',strtolower($username)),10,900);
        $u=$this->db->one('SELECT * FROM users WHERE username=?',[trim($username)]);
        $hash=$u['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        $valid=password_verify($password,$hash);
        if(!$u || !$valid) throw new ApiError('Username or password is incorrect.',401);
        if($u['status']!=='approved') throw new ApiError('Your account is waiting for approval or has been disabled.',403);
        if(password_needs_rehash($u['password_hash'],PASSWORD_DEFAULT)) $this->db->run('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$u['id']]);
        session_regenerate_id(true);
        $_SESSION=['user_id'=>(int)$u['id'],'auth_version'=>(int)$u['auth_version'],'last_seen'=>time(),'signed_in'=>time(),'csrf'=>bin2hex(random_bytes(32))];
        $this->db->audit((int)$u['id'],'signed_in');
        return $this->user();
    }
    public function logout(): void
    {
        $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32));
    }
    public function create(string $username,string $email,string $password,string $role='user',string $status='pending'): int
    {
        $username=trim($username); $email=strtolower(trim($email));
        if(!preg_match('/^[A-Za-z0-9_.-]{3,40}$/',$username)) throw new ApiError('Username must be 3–40 letters, numbers, dots, underscores, or dashes.',422);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254) throw new ApiError('Enter a valid email address.',422);
        self::validatePassword($password);
        if(!in_array($role,['user','admin'],true) || !in_array($status,['pending','approved','disabled'],true)) throw new ApiError('Invalid account role or status.',422);
        try {
            return $this->db->transaction(function() use($username,$email,$password,$role,$status) {
                $this->db->run('INSERT INTO users(username,email,password_hash,role,status,created_at) VALUES(?,?,?,?,?,?)',[$username,$email,password_hash($password,PASSWORD_DEFAULT),$role,$status,time()]);
                $id=$this->db->id();
                if($status==='pending') (new ManagerAlerts($this->config,$this->db,$this->settings))->account($id);
                return $id;
            });
        }
        catch(\PDOException $e) { if((string)$e->getCode()==='23000') throw new ApiError('That username or email is already registered.',409); throw $e; }
    }
    public static function validatePassword(string $password): void
    {
        if(strlen($password)<12 || strlen($password)>72) throw new ApiError('Use a password between 12 and 72 bytes long.',422);
    }
    public function ip(): string { return hash_hmac('sha256',$_SERVER['REMOTE_ADDR'] ?? 'cli',$this->config->key()); }
}
