<?php
declare(strict_types=1);
namespace ScreenPort;

umask(0077);
ini_set('display_errors','0'); ini_set('log_errors','0');
date_default_timezone_set('UTC');
require_once __DIR__.'/Auth.php';
spl_autoload_register(static function(string $class): void {
    $prefix=__NAMESPACE__.'\\';
    if(str_starts_with($class,$prefix)) {
        $path=__DIR__.'/'.substr($class,strlen($prefix)).'.php';
        if(is_file($path)) require_once $path;
    }
});
if(is_file(dirname(__DIR__).'/vendor/autoload.php')) require_once dirname(__DIR__).'/vendor/autoload.php';
$config=new Config(getenv('SCREENPORT_ENV_FILE') ?: dirname(__DIR__).'/.env');
$db=new Db($config);
$settings=new Settings($config,$db);
$auth=new Auth($db,$config,$settings);
$catalog=new Catalog($config,$db,$settings);
