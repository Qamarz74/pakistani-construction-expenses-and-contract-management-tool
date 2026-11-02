<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$page=(string)($_GET['page']??'dashboard');
$error=null;
try {
    if($page==='login' && $_SERVER['REQUEST_METHOD']==='POST') {
        require_post_csrf();
        $user=one('SELECT * FROM users WHERE username=?',[(string)($_POST['username']??'')]);
        if(!$user || !password_verify((string)($_POST['password']??''),$user['password_hash']))throw new InvalidArgumentException('Invalid username or password.');
        session_regenerate_id(true); $_SESSION=['user_id'=>(int)$user['id'],'csrf'=>bin2hex(random_bytes(32))]; redirect('index.php');
    }
    if(empty($_SESSION['user_id']) && $page!=='login')redirect('index.php?page=login');
    if(!empty($_SESSION['user_id']) && $page==='login')redirect('index.php');
    if($page==='logout') { require_post_csrf(); $_SESSION=[]; session_destroy(); setcookie(session_name(),'',time()-42000,'/'); redirect('index.php?page=login'); }
    if($page==='switch') { require_post_csrf(); $p=one('SELECT id FROM projects WHERE id=? AND active=1',[(int)($_POST['project_id']??0)]); if(!$p)throw new InvalidArgumentException('Select an active project.'); $_SESSION['project_id']=(int)$p['id']; redirect('index.php'); }
    if($page!=='login') {
        $project=one('SELECT * FROM projects WHERE id=? AND active=1',[project_id()]);
        if(!$project) { $project=one('SELECT * FROM projects WHERE active=1 ORDER BY id LIMIT 1'); $_SESSION['project_id']=(int)($project['id']??0); }
    }
    if($page==='attachment') {
        $a=one('SELECT a.* FROM attachments a LEFT JOIN expenses e ON e.id=a.expense_id LEFT JOIN payments p ON p.id=a.payment_id WHERE a.id=? AND COALESCE(e.project_id,p.project_id)=?',[(int)($_GET['id']??0),project_id()]);
        if(!$a) { http_response_code(404); throw new InvalidArgumentException('Attachment not found in this project.'); }
        $path=$config['upload_dir'].'/'.$a['stored_name'];
        if($_SERVER['REQUEST_METHOD']==='POST') { require_post_csrf(); db()->beginTransaction(); project_lock(); run('DELETE FROM attachments WHERE id=?',[$a['id']]); db()->commit(); @unlink($path); flash('Attachment removed.'); redirect('index.php?page='.($a['expense_id']?'expenses&id='.$a['expense_id']:'payments&id='.$a['payment_id'])); }
        if(!is_file($path)) { http_response_code(404); exit('File unavailable.'); }
        header('Content-Type: '.$a['mime']); header('Content-Length: '.filesize($path)); header("Content-Security-Policy: default-src 'none'; sandbox");
        header("Content-Disposition: inline; filename*=UTF-8''".rawurlencode($a['original_name'])); readfile($path); exit;
    }
} catch(Throwable $ex) { try { if(db()->inTransaction())db()->rollBack(); } catch(Throwable) {} cleanup_failed_uploads(); error_log((string)$ex); $error=$ex instanceof PDOException?'Database operation failed. Check configuration and server logs.':$ex->getMessage(); }
$titles=['dashboard'=>'Dashboard','expenses'=>'Expenses','payments'=>'Payments','contracts'=>'Thekas','contacts'=>'Suppliers / Contractors','reports'=>'Reports','settings'=>'Settings','login'=>'Login'];
if(!isset($titles[$page])) { http_response_code(404); $page='dashboard'; $error='Page not found.'; }
$content='';
if(!$error && $page!=='login' && !empty($_SESSION['user_id'])) {
    ob_start();
    try {
        if(!project_id() && $page!=='settings') echo '<div class="alert alert-info">Create or activate a project in <a href="index.php?page=settings&tab=projects">Settings</a>.</div>';
        elseif(is_file(__DIR__.'/pages/'.$page.'.php')) require __DIR__.'/pages/'.$page.'.php';
        else echo '<p>This module is being built. See tasks/todo.md.</p>';
        $content=ob_get_clean();
    } catch(Throwable $ex) { ob_end_clean(); try { if(db()->inTransaction())db()->rollBack(); } catch(Throwable) {} cleanup_failed_uploads(); error_log((string)$ex); $error=$ex instanceof PDOException?'Database operation failed. No financial changes were saved.':$ex->getMessage(); $content='<a class="btn btn-outline-secondary" href="'.e($_SERVER['REQUEST_URI']).'">Reload page</a>'; }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?=e($titles[$page])?> · Home Construction</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/app.css" rel="stylesheet"></head><body class="bg-light">
<?php if(!empty($_SESSION['user_id'])): ?>
<nav class="navbar navbar-dark bg-dark navbar-expand-lg"><div class="container-fluid"><a class="navbar-brand" href="index.php">Home Construction</a><button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button><div class="collapse navbar-collapse" id="nav"><ul class="navbar-nav me-auto"><?php foreach($titles as $key=>$title): if($key==='login')continue; ?><li class="nav-item"><a class="nav-link <?=$page===$key?'active':''?>" href="index.php?page=<?=e($key)?>"><?=e($title)?></a></li><?php endforeach; ?></ul><form method="post" action="index.php?page=logout"><?=csrf()?><button class="btn btn-outline-light btn-sm">Logout</button></form></div></div></nav>
<?php endif; ?>
<main class="container-xl py-3">
<?php if(!empty($_SESSION['user_id'])): ?><div class="d-flex flex-wrap justify-content-between gap-3 align-items-center mb-3"><h1 class="h3 m-0"><?=e($titles[$page])?></h1><form method="post" action="index.php?page=switch" class="d-flex gap-2 align-items-center"><?=csrf()?><label for="project-switch">Project</label><select id="project-switch" name="project_id" class="form-select" style="max-width:230px"><?=options($error?[]:rows('SELECT id,name FROM projects WHERE active=1 ORDER BY name'),project_id())?></select><button class="btn btn-outline-primary">Select</button></form></div><?php endif; ?>
<?php foreach($_SESSION['flash']??[] as [$message,$type]): ?><div class="alert alert-<?=e(in_array($type,['success','warning','danger','info'],true)?$type:'info')?>" role="status"><?=e($message)?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?=e($error)?></div><?php endif; ?>
<?php if($page==='login'): ?><div class="card mx-auto mt-4" style="max-width:420px"><div class="card-body"><h1 class="h4">Home Construction</h1><p class="text-muted">Private expense tracker</p><form method="post" action="index.php?page=login"><?=csrf()?><label for="username" class="form-label">Username</label><input id="username" class="form-control mb-3" name="username" autocomplete="username" required autofocus><label for="password" class="form-label">Password</label><input id="password" type="password" class="form-control mb-3" name="password" autocomplete="current-password" required><button class="btn btn-primary w-100">Log in</button></form></div></div><?php else: ?><?=$content?><?php endif; ?>
</main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html>
