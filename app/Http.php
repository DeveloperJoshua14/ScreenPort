<?php
declare(strict_types=1);
namespace ScreenPort;

final class Http
{
    private static ?\Closure $testTransport=null;
    public static function setTestTransport(?\Closure $transport): void
    {
        if(PHP_SAPI!=='cli') throw new \LogicException('Test transports are CLI-only.');
        self::$testTransport=$transport;
    }
    public static function baseUrl(string $url): string
    {
        $p=parse_url($url);
        if(!$p || !in_array($p['scheme'] ?? '',['http','https'],true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || preg_match('/[\r\n\x00]/',$url)) throw new ApiError('Invalid server URL. Use an http or https URL without embedded credentials.',422);
        return rtrim($url,'/');
    }
    public static function request(string $method,string $url,array $headers=[],mixed $body=null,?\CurlHandle $handle=null,int $timeout=25): array
    {
        if(self::$testTransport) return (self::$testTransport)($method,$url,$headers,$body);
        $own=$handle===null; $c=$handle ?? curl_init();
        $buffer=''; $tooLarge=false;
        curl_setopt_array($c,[CURLOPT_URL=>$url,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'ScreenPort/1.0',CURLOPT_POSTFIELDS=>$body,
            CURLOPT_WRITEFUNCTION=>static function($ch,string $data) use (&$buffer,&$tooLarge): int {
                if(strlen($buffer)+strlen($data)>8*1024*1024) { $tooLarge=true; return 0; }
                $buffer.=$data; return strlen($data);
            }]);
        $ok=curl_exec($c); $status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);
        if($own) curl_close($c);
        if($ok===false) throw new \RuntimeException($tooLarge ? 'Service response exceeded its limit.' : 'Unable to reach a configured service. Check connectivity and TLS.');
        return ['status'=>$status,'body'=>$buffer];
    }
    public static function json(string $method,string $url,array $headers=[],?array $body=null,int $timeout=25): array
    {
        $r=self::request($method,$url,array_merge(['Accept: application/json','Content-Type: application/json'],$headers),$body===null ? null : json_encode($body,JSON_THROW_ON_ERROR),null,$timeout);
        if($r['status']<200 || $r['status']>=300) throw new \RuntimeException('Configured service returned HTTP '.$r['status'].'.');
        $v=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($v)) throw new \RuntimeException('Invalid service response.');
        return $v;
    }
}
