<?php
// Local development only: serve known public files and refuse traversal, dotfiles, and source files.
declare(strict_types=1);
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/');
if($path==='/' || $path==='/index.php') { require __DIR__.'/index.php'; return true; }
if($path==='/api.php') { require __DIR__.'/api.php'; return true; }
if(preg_match('~^/assets/[A-Za-z0-9_-]+\.(css|js|svg|png|jpg|woff2)$~',$path) && is_file(__DIR__.$path)) return false;
http_response_code(404); header('Content-Type: text/plain'); echo 'Not found'; return true;
