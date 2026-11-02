<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if(!extension_loaded('pdo_mysql') || !extension_loaded('bcmath') || !extension_loaded('fileinfo') || !extension_loaded('mbstring'))exit("Enable PDO MySQL, BCMath, Fileinfo and Mbstring.\n");
$file=__DIR__.'/config.php'; if(!is_file($file))exit("Copy config.example.php to config.php and configure the database first.\n");
$c=require $file; $pdo=new PDO($c['dsn'],$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$username=$argv[1]??'admin';
if($pdo->query('SELECT COUNT(*) FROM users')->fetchColumn())exit("Admin already exists. No change made.\n");
fwrite(STDOUT,"Password (minimum 12 characters): ");
$tty=function_exists('shell_exec') ? shell_exec('stty -g 2>/dev/null') : null;
if($tty) shell_exec('stty -echo');
try { $password=rtrim(fgets(STDIN)?:'',"\r\n"); } finally { if($tty) shell_exec('stty '.escapeshellarg(trim($tty))); fwrite(STDOUT,"\n"); }
if(strlen($password)<12)exit("Password must contain at least 12 characters.\n");
$pdo->prepare('INSERT INTO users(username,password_hash) VALUES(?,?)')->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);
fwrite(STDOUT,"Admin created. Open index.php to log in.\n");
