<?php
declare(strict_types=1);
namespace ScreenPort;

final class Db
{
    public \PDO $pdo;
    public function __construct(Config $config)
    {
        $dir=$config->storage();
        if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new \RuntimeException('Storage is unavailable.');
        $this->pdo=new \PDO('sqlite:'.$dir.'/screenport.sqlite',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=10000; PRAGMA journal_mode=WAL;');
        $this->migrate();
    }
    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
 id INTEGER PRIMARY KEY, username TEXT NOT NULL COLLATE NOCASE UNIQUE, email TEXT NOT NULL COLLATE NOCASE UNIQUE,
 password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'user', status TEXT NOT NULL DEFAULT 'pending',
 auth_version INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL, secret INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS cache (key TEXT PRIMARY KEY, value TEXT NOT NULL, expires_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS rate_limits (key TEXT PRIMARY KEY, hits INTEGER NOT NULL, expires_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS requests (
 id INTEGER PRIMARY KEY, media_type TEXT NOT NULL, media_id INTEGER NOT NULL, media TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'queued', message TEXT NOT NULL DEFAULT 'Waiting for the download worker',
 plan TEXT, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 UNIQUE(media_type,media_id)
);
CREATE TABLE IF NOT EXISTS subscribers (
 request_id INTEGER NOT NULL REFERENCES requests(id), user_id INTEGER NOT NULL REFERENCES users(id),
 created_at INTEGER NOT NULL, PRIMARY KEY(request_id,user_id)
);
CREATE TABLE IF NOT EXISTS jobs (
 id INTEGER PRIMARY KEY, request_id INTEGER NOT NULL REFERENCES requests(id), kind TEXT NOT NULL,
 payload TEXT NOT NULL DEFAULT '{}', due_at INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
 status TEXT NOT NULL DEFAULT 'pending', last_error TEXT, UNIQUE(request_id,kind)
);
CREATE TABLE IF NOT EXISTS torrents (
 id INTEGER PRIMARY KEY, request_id INTEGER NOT NULL REFERENCES requests(id), candidate_id TEXT NOT NULL,
 name TEXT NOT NULL, url TEXT NOT NULL, hash TEXT, tag TEXT NOT NULL, save_path TEXT NOT NULL,
 seasons TEXT NOT NULL DEFAULT '[]', state TEXT NOT NULL DEFAULT 'planned', added_at INTEGER,
 snapshot TEXT NOT NULL DEFAULT '{}', UNIQUE(request_id,candidate_id)
);
CREATE TABLE IF NOT EXISTS emails (
 id INTEGER PRIMARY KEY, request_id INTEGER NOT NULL REFERENCES requests(id), recipient TEXT NOT NULL,
 audience TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0,
 due_at INTEGER NOT NULL, UNIQUE(request_id,recipient)
);
CREATE TABLE IF NOT EXISTS audit (
 id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT NOT NULL, detail TEXT NOT NULL, created_at INTEGER NOT NULL
);
SQL);
    }
    public function run(string $sql, array $params=[]): \PDOStatement { $s=$this->pdo->prepare($sql); $s->execute($params); return $s; }
    public function one(string $sql,array $params=[]): ?array { return $this->run($sql,$params)->fetch() ?: null; }
    public function all(string $sql,array $params=[]): array { return $this->run($sql,$params)->fetchAll(); }
    public function id(): int { return (int)$this->pdo->lastInsertId(); }
    public function transaction(callable $fn): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try { $v=$fn(); $this->pdo->exec('COMMIT'); return $v; }
        catch (\Throwable $e) { $this->pdo->exec('ROLLBACK'); throw $e; }
    }
    public function cached(string $key,int $ttl,callable $fn): mixed
    {
        $row=$this->one('SELECT value FROM cache WHERE key=? AND expires_at>?',[$key,time()]);
        if ($row) return json_decode($row['value'],true,512,JSON_THROW_ON_ERROR);
        $v=$fn();
        $this->run('INSERT INTO cache VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,expires_at=excluded.expires_at',[$key,json_encode($v,JSON_THROW_ON_ERROR),time()+$ttl]);
        return $v;
    }
    public function limit(string $key,int $max,int $seconds): void
    {
        $hits=$this->transaction(function() use($key,$seconds) {
            $row=$this->one('SELECT * FROM rate_limits WHERE key=?',[$key]);
            $hits=$row && (int)$row['expires_at']>time() ? (int)$row['hits']+1 : 1;
            $expires=$hits===1 ? time()+$seconds : (int)$row['expires_at'];
            $this->run('INSERT INTO rate_limits VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET hits=excluded.hits,expires_at=excluded.expires_at',[$key,$hits,$expires]);
            return $hits;
        });
        if ($hits>$max) throw new ApiError('Too many attempts. Please try again later.',429);
    }
    public function audit(?int $user,string $action,string $detail=''): void { $this->run('INSERT INTO audit(user_id,action,detail,created_at) VALUES(?,?,?,?)',[$user,$action,mb_substr($detail,0,300),time()]); }
}
