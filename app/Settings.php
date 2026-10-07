<?php
declare(strict_types=1);
namespace ScreenPort;

final class Settings
{
    public const FIELDS=[
        'TMDB_READ_ACCESS_TOKEN'=>['label'=>'TMDB read access token','secret'=>true,'default'=>''],
        'QBITTORRENT_URL'=>['label'=>'qBittorrent Web UI URL','default'=>''],
        'QBITTORRENT_USERNAME'=>['label'=>'qBittorrent username','secret'=>true,'default'=>''],
        'QBITTORRENT_PASSWORD'=>['label'=>'qBittorrent password','secret'=>true,'default'=>''],
        'QBITTORRENT_SEARCH_PLUGINS'=>['label'=>'Search plugins (enabled or names separated by |)','default'=>'enabled'],
        'TORRENT_ALLOWED_HOSTS'=>['label'=>'Allowed torrent file hosts (comma separated; magnets always supported)','default'=>''],
        'JELLYFIN_URL'=>['label'=>'Jellyfin URL','default'=>''],
        'JELLYFIN_API_KEY'=>['label'=>'Jellyfin API key','secret'=>true,'default'=>''],
        'OPENAI_API_KEY'=>['label'=>'OpenAI API key','secret'=>true,'default'=>''],
        'OPENAI_MODEL'=>['label'=>'OpenAI model with Structured Outputs','default'=>'gpt-4o-mini'],
        'REGION'=>['label'=>'Release and rating country','default'=>'US'],
        'TIMEZONE'=>['label'=>'Timezone','default'=>'America/New_York'],
        'MATURE_RATINGS'=>['label'=>'Mature TV ratings (comma separated)','default'=>'TV-MA,R,NC-17,18,18+'],
        'UNKNOWN_RATING_MATURE'=>['label'=>'Route unrated TV to mature folder','default'=>'true'],
        'MOVIE_ROOT'=>['label'=>'Movie download root','default'=>'/media/Movies'],
        'TV_ROOT'=>['label'=>'TV download root','default'=>'/media/TV Shows'],
        'MATURE_TV_ROOT'=>['label'=>'Mature TV download root','default'=>'/media/Mature TV Shows'],
        'DOWNLOAD_MANAGER_EMAIL'=>['label'=>'Download manager email','default'=>''],
        'ACCOUNT_MANAGER_EMAIL'=>['label'=>'Account manager email (blank uses download manager)','default'=>''],
        'SMTP_HOST'=>['label'=>'SMTP host','default'=>''],
        'SMTP_PORT'=>['label'=>'SMTP port','default'=>'587'],
        'SMTP_USERNAME'=>['label'=>'SMTP username','secret'=>true,'default'=>''],
        'SMTP_PASSWORD'=>['label'=>'SMTP password','secret'=>true,'default'=>''],
        'SMTP_ENCRYPTION'=>['label'=>'SMTP encryption (tls or ssl)','default'=>'tls'],
        'MAIL_FROM_ADDRESS'=>['label'=>'Sender email address','default'=>''],
        'MAIL_FROM_NAME'=>['label'=>'Sender name','default'=>'ScreenPort'],
        'REGISTRATION_OPEN'=>['label'=>'Allow account requests (approval required)','default'=>'true'],
        'DOWNLOADS_ENABLED'=>['label'=>'Enable new downloads','default'=>'true'],
        'DOWNLOAD_START_TIMEOUT_MINUTES'=>['label'=>'Minutes without download progress before manager alert','default'=>'10'],
        'REQUEST_LIMIT_PER_DAY'=>['label'=>'Requests per user per day','default'=>'10'],
        'SELECTION_MIN_CONFIDENCE'=>['label'=>'Minimum selection confidence','default'=>'0.85'],
        'ASSUME_ORIGINAL_AUDIO'=>['label'=>'Assume original audio when no other audio is advertised','default'=>'true'],
        'MAX_TORRENT_GB'=>['label'=>'Hard maximum size of one torrent in GB','default'=>'250'],
        'TV_MAX_GIB_PER_HOUR'=>['label'=>'Maximum TV torrent GiB per hour of episodes','default'=>'2'],
        'SEARCH_TIMEOUT'=>['label'=>'Torrent search timeout in seconds','default'=>'45'],
        'MOVIE_EXISTING_FOLDERS'=>['label'=>'Existing movie folders (comma separated; keep Other as fallback)','default'=>MovieFolders::EXISTING],
        'ALLOW_NEW_MOVIE_FOLDERS'=>['label'=>'Allow custom new movie folders (emails download manager)','default'=>'false'],
        'MOVIE_FOLDER_OVERRIDES'=>['label'=>'Movie folder overrides by TMDB ID (JSON, e.g. {"1032863":"RomCom"})','default'=>'{}'],
        'MOVIE_GENRE_MAP'=>['label'=>'Movie genre folder overrides (JSON, e.g. {"Science Fiction":"Adventure"})','default'=>'{}'],
    ];
    private array $rows=[];
    public function __construct(private Config $config,private Db $db) { $this->reload(); }
    public function reload(): void { $this->rows=[]; foreach($this->db->all('SELECT * FROM settings') as $r) $this->rows[$r['key']]=$r; }
    public function get(string $key): string
    {
        if(isset($this->rows[$key])) {
            $r=$this->rows[$key];
            return $r['secret'] ? $this->decrypt($r['value']) : $r['value'];
        }
        return $this->config->get($key,self::FIELDS[$key]['default'] ?? '');
    }
    public function bool(string $key): bool { return $this->get($key)==='true'; }
    public function ready(): bool
    {
        foreach(['TMDB_READ_ACCESS_TOKEN','QBITTORRENT_URL','QBITTORRENT_USERNAME','QBITTORRENT_PASSWORD','OPENAI_API_KEY','SMTP_HOST','MAIL_FROM_ADDRESS','DOWNLOAD_MANAGER_EMAIL'] as $k) if(!$this->get($k)) return false;
        return true;
    }
    public function display(): array
    {
        $out=[];
        foreach(self::FIELDS as $k=>$f) $out[]=['key'=>$k,'label'=>$f['label'],'secret'=>$f['secret'] ?? false,'configured'=>$this->get($k)!=='','value'=>($f['secret'] ?? false) ? '' : $this->get($k)];
        return $out;
    }
    public function save(array $values): void
    {
        foreach($values as $k=>$v) {
            if(!isset(self::FIELDS[$k]) || !is_string($v) || strlen($v)>5000 || preg_match('/[\r\n\x00]/',$v)) throw new ApiError('Invalid settings.',422);
            if((self::FIELDS[$k]['secret'] ?? false) && $v==='') continue;
            self::validate($k,$v);
        }
        $this->db->transaction(function() use($values) {
            foreach($values as $k=>$v) {
                $secret=self::FIELDS[$k]['secret'] ?? false;
                if($secret && $v==='') continue;
                $this->db->run('INSERT INTO settings VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,secret=excluded.secret',[$k,$secret ? $this->encrypt($v) : $v,(int)$secret]);
            }
            $this->db->run('DELETE FROM cache');
        });
        $this->reload();
    }
    public static function validate(string $k,string $v): void
    {
        if(str_ends_with($k,'_URL') && $v!=='') Http::baseUrl($v);
        if(in_array($k,['MOVIE_ROOT','TV_ROOT','MATURE_TV_ROOT'],true) && (!str_starts_with($v,'/media/') || str_contains($v,'..') || str_contains($v,'\\') || strlen($v)>180)) throw new ApiError('Download roots must be safe absolute paths under /media/.',422);
        if(in_array($k,['DOWNLOAD_MANAGER_EMAIL','ACCOUNT_MANAGER_EMAIL','MAIL_FROM_ADDRESS'],true) && $v!=='' && !filter_var($v,FILTER_VALIDATE_EMAIL)) throw new ApiError('Enter a valid email address.',422);
        if($k==='REGION' && !preg_match('/^[A-Z]{2}$/',$v)) throw new ApiError('Use a two-letter country code.',422);
        if($k==='TIMEZONE' && !in_array($v,\DateTimeZone::listIdentifiers(),true)) throw new ApiError('Invalid timezone.',422);
        if($k==='SMTP_ENCRYPTION' && !in_array($v,['tls','ssl'],true)) throw new ApiError('SMTP must use tls or ssl.',422);
        if(in_array($k,['REGISTRATION_OPEN','DOWNLOADS_ENABLED','UNKNOWN_RATING_MATURE','ALLOW_NEW_MOVIE_FOLDERS','ASSUME_ORIGINAL_AUDIO'],true) && !in_array($v,['true','false'],true)) throw new ApiError('Use true or false.',422);
        $ranges=['SMTP_PORT'=>[1,65535],'REQUEST_LIMIT_PER_DAY'=>[1,100],'SELECTION_MIN_CONFIDENCE'=>[0.5,1],'MAX_TORRENT_GB'=>[1,2000],'TV_MAX_GIB_PER_HOUR'=>[0.2,20],'DOWNLOAD_START_TIMEOUT_MINUTES'=>[1,1440],'SEARCH_TIMEOUT'=>[5,120]];
        if(isset($ranges[$k]) && (!is_numeric($v) || (float)$v<$ranges[$k][0] || (float)$v>$ranges[$k][1])) throw new ApiError('Setting is outside its allowed range.',422);
        if($k==='MOVIE_EXISTING_FOLDERS') {
            $folders=array_map('trim',explode(',',$v));
            if(count($folders)>100 || !in_array('Other',$folders,true)) throw new ApiError('List existing folders, including Other for the fallback.',422);
            foreach($folders as $folder) if(!preg_match('/^[\pL\pN _-]{1,60}$/u',$folder)) throw new ApiError('Use simple movie folder names, separated by commas.',422);
        }
        if(in_array($k,['MOVIE_GENRE_MAP','MOVIE_FOLDER_OVERRIDES'],true)) {
            $map=json_decode($v,true);
            if(!is_array($map) || !str_starts_with(ltrim($v),'{')) throw new ApiError('Folder overrides must be a JSON object.',422);
            foreach($map as $name=>$folder) {
                if(!is_string($folder) || !preg_match('/^[\pL\pN _-]{1,60}$/u',$folder)) throw new ApiError('Use simple folder names for overrides.',422);
                if($k==='MOVIE_FOLDER_OVERRIDES' && !preg_match('/^[1-9][0-9]{0,9}$/',(string)$name)) throw new ApiError('Movie overrides must use TMDB movie IDs as keys.',422);
            }
        }
        if($k==='OPENAI_MODEL' && !preg_match('/^[a-zA-Z0-9._:-]{1,100}$/',$v)) throw new ApiError('Invalid model name.',422);
        if($k==='QBITTORRENT_SEARCH_PLUGINS' && !preg_match('/^[a-zA-Z0-9_|-]{1,250}$/',$v)) throw new ApiError('Invalid plugin list.',422);
        if($k==='SMTP_HOST' && $v!=='' && !preg_match('/^[a-zA-Z0-9.:-]{1,250}$/',$v)) throw new ApiError('Invalid SMTP host.',422);
    }
    private function encrypt(string $value): string
    {
        $iv=random_bytes(12); $tag='';
        $data=openssl_encrypt($value,'aes-256-gcm',$this->config->key(),OPENSSL_RAW_DATA,$iv,$tag,'screenport',16);
        if($data===false) throw new \RuntimeException('Could not encrypt settings.');
        return base64_encode($iv.$tag.$data);
    }
    private function decrypt(string $value): string
    {
        $raw=base64_decode($value,true);
        if($raw===false || strlen($raw)<28) throw new \RuntimeException('Invalid encrypted setting.');
        $v=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->config->key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'screenport');
        if($v===false) throw new \RuntimeException('Could not decrypt settings.');
        return $v;
    }
}
