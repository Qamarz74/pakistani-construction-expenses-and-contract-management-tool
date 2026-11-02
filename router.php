<?php
// Local development only: php -S 127.0.0.1:8080 router.php
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/');
if($path==='/' || $path==='/index.php') { require __DIR__.'/index.php'; return true; }
if(preg_match('~^/assets/[a-zA-Z0-9_-]+\.(css|js)$~D',$path) && is_file(__DIR__.$path))return false;
http_response_code(404); echo 'Not found.';
