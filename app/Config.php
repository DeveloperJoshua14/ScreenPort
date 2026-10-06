<?php
declare(strict_types=1);
namespace ScreenPort;

final class Config
{
    private const ALIASES=['QBITTORRENT_URL'=>'qBittorrent_URL','QBITTORRENT_USERNAME'=>'qBittorrent_Username',
        'QBITTORRENT_PASSWORD'=>'qBittorrent_Password','JELLYFIN_URL'=>'JellyFin_URL',
        'JELLYFIN_API_KEY'=>'JellyFin_APIKEY','OPENAI_API_KEY'=>'OpenAI_APIKEY'];
    private array $values;
    public function __construct(string $file)
    {
        $this->values = self::readEnv($file);
        foreach (self::ALIASES as $key=>$alias) {
            if (empty($this->values[$key]) && isset($this->values[$alias])) $this->values[$key]=$this->values[$alias];
        }
    }
    public static function readEnv(string $file): array
    {
        if (!is_file($file)) return [];
        $out=[];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (!preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) continue;
            $v=trim($m[2]);
            if (strlen($v)>=2 && (($v[0]==='"' && str_ends_with($v,'"')) || ($v[0]==="'" && str_ends_with($v,"'")))) {
                $v=substr($v,1,-1);
            } else $v=preg_replace('/\s+#.*$/','',$v) ?? $v;
            $out[$m[1]]=$v; // Never evaluate or interpolate credential contents.
        }
        return $out;
    }
    public function get(string $key, string $default=''): string
    {
        $v=getenv($key);
        if($v===false && isset(self::ALIASES[$key])) $v=getenv(self::ALIASES[$key]);
        return $v!==false ? $v : ($this->values[$key] ?? $default);
    }
    public function local(): bool { return $this->get('APP_ENV','production')==='local'; }
    public function demo(): bool { return $this->local() && $this->get('DEMO_MODE')==='true'; }
    public function storage(): string { return $this->get('STORAGE_PATH') ?: dirname(__DIR__).'/storage'; }
    public function secureRequest(): bool
    {
        if(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') return true;
        if((int)($_SERVER['SERVER_PORT'] ?? 0)===443) return true;
        $trusted=array_filter(array_map('trim',explode(',',$this->get('TRUSTED_PROXY_IPS'))));
        return in_array($_SERVER['REMOTE_ADDR'] ?? '',$trusted,true) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')==='https';
    }
    public function key(): string
    {
        $key=base64_decode($this->get('APP_KEY'),true);
        if ($key===false || strlen($key)!==32) throw new \RuntimeException('Run the ScreenPort initialization command first.');
        return $key;
    }
}
