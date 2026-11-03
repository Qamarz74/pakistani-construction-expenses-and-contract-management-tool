<?php

declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$page = (string)($_GET['page'] ?? 'dashboard');
$error = null;
try {
    if (!empty($_SESSION['user_id']) && $page === 'login') redirect('index.php');
    if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_post_csrf();
        $user = one('SELECT * FROM users WHERE username=?', [(string)($_POST['username'] ?? '')]);
        if (!$user || !password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) throw new InvalidArgumentException('Invalid username or password.');
        session_regenerate_id(true);
        $_SESSION = ['user_id' => (int)$user['id'], 'csrf' => bin2hex(random_bytes(32))];
        redirect('index.php');
    }
    if (empty($_SESSION['user_id']) && $page !== 'login') redirect('index.php?page=login');
    if ($page === 'logout') {
        require_post_csrf();
        $_SESSION = [];
        session_destroy();
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $cookie['path'], 'domain' => $cookie['domain'], 'secure' => $cookie['secure'], 'httponly' => $cookie['httponly'], 'samesite' => $cookie['samesite']]);
        redirect('index.php?page=login');
    }
    if ($page === 'switch') {
        require_post_csrf();
        $p = one('SELECT id FROM projects WHERE id=? AND active=1', [(int)($_POST['project_id'] ?? 0)]);
        if (!$p) throw new InvalidArgumentException('Select an active project.');
        $_SESSION['project_id'] = (int)$p['id'];
        redirect('index.php');
    }
    if ($page !== 'login') {
        $project = one('SELECT * FROM projects WHERE id=? AND active=1', [project_id()]);
        if (!$project) {
            $project = one('SELECT * FROM projects WHERE active=1 ORDER BY id LIMIT 1');
            $_SESSION['project_id'] = (int)($project['id'] ?? 0);
        }
    }
    if ($page === 'attachment') {
        $a = one('SELECT a.* FROM attachments a LEFT JOIN expenses e ON e.id=a.expense_id LEFT JOIN payments p ON p.id=a.payment_id WHERE a.id=? AND COALESCE(e.project_id,p.project_id)=?', [(int)($_GET['id'] ?? 0), project_id()]);
        if (!$a) {
            http_response_code(404);
            throw new InvalidArgumentException('Attachment not found in this project.');
        }
        $path = $config['upload_dir'] . '/' . $a['stored_name'];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_post_csrf();
            db()->beginTransaction();
            project_lock();
            run('DELETE FROM attachments WHERE id=?', [$a['id']]);
            db()->commit();
            @unlink($path);
            flash('Attachment removed.');
            redirect('index.php?page=' . ($a['expense_id'] ? 'expenses&id=' . $a['expense_id'] : 'payments&id=' . $a['payment_id']));
        }
        if (!is_file($path)) {
            http_response_code(404);
            exit('File unavailable.');
        }
        header('Content-Type: ' . $a['mime']);
        header('Content-Length: ' . filesize($path));
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($a['original_name']));
        readfile($path);
        exit;
    }
} catch (Throwable $ex) {
    try {
        if (db()->inTransaction()) db()->rollBack();
    } catch (Throwable) {
    }
    cleanup_failed_uploads();
    error_log((string)$ex);
    $error = $ex instanceof PDOException ? 'Database operation failed. Check configuration and server logs.' : $ex->getMessage();
}
$titles = ['dashboard' => 'Dashboard', 'expenses' => 'Expenses', 'payments' => 'Payments', 'contracts' => 'Thekas', 'contacts' => 'Contacts', 'reports' => 'Reports', 'settings' => 'Settings', 'login' => 'Login'];
if (!isset($titles[$page])) {
    http_response_code(404);
    $page = 'dashboard';
    $error = 'Page not found.';
}
$content = '';
if (!$error && $page !== 'login' && !empty($_SESSION['user_id'])) {
    ob_start();
    try {
        if (!project_id() && $page !== 'settings') echo '<div class="alert alert-info">Create or activate a project in <a href="index.php?page=settings&tab=projects">Settings</a>.</div>';
        elseif (is_file(__DIR__ . '/pages/' . $page . '.php')) require __DIR__ . '/pages/' . $page . '.php';
        else echo '<p>This module is being built. See tasks/todo.md.</p>';
        $content = ob_get_clean();
    } catch (Throwable $ex) {
        ob_end_clean();
        try {
            if (db()->inTransaction()) db()->rollBack();
        } catch (Throwable) {
        }
        cleanup_failed_uploads();
        error_log((string)$ex);
        $error = $ex instanceof PDOException ? 'Database operation failed. No financial changes were saved.' : $ex->getMessage();
        $content = '<a class="btn btn-outline-secondary" href="' . e($_SERVER['REQUEST_URI']) . '">Reload page</a>';
    }
}
$authenticated = !empty($_SESSION['user_id']);
$expenseForm = $page === 'expenses' && (isset($_GET['add']) || isset($_GET['edit']) || ($_POST['action'] ?? '') === 'save');
$heading = $expenseForm ? ((int)($_GET['edit'] ?? $_POST['id'] ?? 0) ? 'Edit expense' : 'Add expense') : $titles[$page];
$introductions = ['dashboard' => 'Your home, one clear picture.', 'expenses' => $expenseForm ? 'What did you buy? Enter the quantity and the price on your bill.' : 'Your purchases, payments and bills in one place.'];
$projects = $authenticated && !$error ? rows('SELECT id,name FROM projects WHERE active=1 ORDER BY name') : [];
$projectName = $project['name'] ?? 'Select a project';
function ui_icon(string $name): string {
    $paths = [
        'home' => '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1Z"/><path d="M9 21v-8h6v8"/>',
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'expenses' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2Z"/><path d="M9 7h6M9 11h6M9 15h3"/>',
        'payments' => '<rect x="3" y="5" width="18" height="15" rx="2"/><path d="M3 9h18M7 15h4"/>',
        'contracts' => '<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V3h8v4M3 12h18M10 12v3h4v-3"/>',
        'contacts' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6M21 21v-3a6 6 0 0 0-3-5"/>',
        'reports' => '<path d="M4 3v18h17M9 17v-4M14 17V8M19 17V4"/>',
        'settings' => '<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3" fill="currentColor"/><circle cx="15" cy="17" r="3" fill="currentColor"/>',
        'logout' => '<path d="M9 4H4v16h5M9 12h12m-4-4 4 4-4 4"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'back' => '<path d="m10 5-7 7 7 7M3 12h18"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.($paths[$name] ?? $paths['home']).'</svg>';
}
$navLabels = ['dashboard'=>'Overview','expenses'=>'Expenses','payments'=>'Payments','contracts'=>'Thekas','contacts'=>'People','reports'=>'Reports','settings'=>'Settings'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#17634d">
    <title><?=e($heading)?> · Home Construction</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/tom-select.css" rel="stylesheet">
    <link href="assets/app.css?v=<?=filemtime(__DIR__.'/assets/app.css')?>" rel="stylesheet">
    <?php if(in_array($page,['dashboard','expenses','reports'],true) && is_file(__DIR__.'/assets/'.$page.'.css')): ?><link href="assets/<?=e($page)?>.css?v=<?=filemtime(__DIR__.'/assets/'.$page.'.css')?>" rel="stylesheet"><?php endif; ?>
    <link href="assets/searchable-selects.css?v=<?=filemtime(__DIR__.'/assets/searchable-selects.css')?>" rel="stylesheet">
    <?php if($page==='reports'): ?><script src="assets/reports.js?v=<?=filemtime(__DIR__.'/assets/reports.js')?>" defer></script><?php endif; ?>
    <script src="assets/tom-select.js" defer></script>
    <script src="assets/searchable-selects.js?v=<?=filemtime(__DIR__.'/assets/searchable-selects.js')?>" defer></script>
</head>
<body class="app-body page-<?=e($page)?>">
<a class="skip-link" href="#main-content">Skip to content</a>
<?php if($authenticated): ?>
<div class="workspace-shell">
    <aside class="workspace-sidebar" aria-label="Main navigation">
        <a class="app-brand" href="index.php"><span class="brand-mark"><?=ui_icon('home')?></span><span>Home<span class="brand-subtitle">CONSTRUCTION</span></span></a>
        <span class="nav-caption">YOUR WORKSPACE</span>
        <nav class="workspace-nav" aria-label="Main"><?php foreach($navLabels as $key=>$label): ?><a class="nav-destination <?=$page===$key?'is-active':''?>" href="index.php?page=<?=e($key)?>" <?=$page===$key?'aria-current="page"':''?>><?=ui_icon($key)?><span><?=e($label)?></span></a><?php endforeach; ?></nav>
        <div class="sidebar-bottom"><div class="private-note"><span class="private-dot"></span> Your private construction ledger</div><form method="post" action="index.php?page=logout"><?=csrf()?><button class="sidebar-logout"><?=ui_icon('logout')?>Log out</button></form></div>
    </aside>
    <div class="workspace-main">
        <header class="workspace-topbar">
            <a class="mobile-brand" href="index.php"><span class="brand-mark"><?=ui_icon('home')?></span><span>Home Construction</span></a>
            <details class="project-picker"><summary><span class="project-dot"></span><span class="project-picker-name"><?=e($projectName)?></span><?=ui_icon('chevron')?></summary><div class="project-popover"><form method="post" action="index.php?page=switch"><?=csrf()?><label for="project-switch">Working on</label><select id="project-switch" name="project_id" class="form-select"><?=options($projects,project_id())?></select><button class="btn btn-primary w-100 mt-3">Use this project</button></form><a href="index.php?page=settings&tab=projects">Manage projects</a></div></details>
            <span class="topbar-date"><?=e(date('l, j F Y'))?></span>
            <details class="mobile-menu"><summary aria-label="Open navigation"><?=ui_icon('menu')?></summary><nav class="mobile-menu-panel" aria-label="More navigation"><?php foreach($navLabels as $key=>$label): ?><a class="nav-destination <?=$page===$key?'is-active':''?>" href="index.php?page=<?=e($key)?>" <?=$page===$key?'aria-current="page"':''?>><?=ui_icon($key)?><?=e($label)?></a><?php endforeach; ?><form method="post" action="index.php?page=logout"><?=csrf()?><button class="sidebar-logout"><?=ui_icon('logout')?>Log out</button></form></nav></details>
        </header>
        <main id="main-content" class="workspace-content" tabindex="-1">
            <div class="page-heading"><?php if($expenseForm): ?><a class="back-link" href="index.php?page=expenses"><?=ui_icon('back')?>All expenses</a><?php else: ?><p class="page-eyebrow"><?=e($projectName)?></p><?php endif; ?><h1><?=e($heading)?></h1><?php if(isset($introductions[$page])): ?><p class="page-intro"><?=e($introductions[$page])?></p><?php endif; ?></div>
<?php else: ?>
<main id="main-content" class="login-layout" tabindex="-1">
<?php endif; ?>
<?php foreach($_SESSION['flash']??[] as [$message,$type]): ?><div class="alert alert-<?=e(in_array($type,['success','warning','danger','info'],true)?$type:'info')?>" role="status"><?=e($message)?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?=e($error)?></div><?php endif; ?>
<?php if($page==='login'): ?>
    <div class="login-panel"><span class="brand-mark"><?=ui_icon('home')?></span><h1>Welcome home.</h1><p class="text-muted">Your construction expenses, all in one place.</p><form method="post" action="index.php?page=login"><?=csrf()?><label for="username" class="form-label">Username</label><input id="username" class="form-control mb-3" name="username" autocomplete="username" required autofocus><label for="password" class="form-label">Password</label><input id="password" type="password" class="form-control mb-4" name="password" autocomplete="current-password" required><button class="btn btn-primary w-100">Log in</button></form></div>
<?php else: ?><?=$content?><?php endif; ?>
</main>
<?php if($authenticated): ?>
    </div>
</div>
<nav class="mobile-shortcuts" aria-label="Quick navigation"><?php foreach(['dashboard'=>['Overview','dashboard'],'expenses'=>['Add expense','plus'],'payments'=>['Payments','payments'],'reports'=>['Reports','reports']] as $key=>[$label,$icon]): $active=$page===$key && ($key!=='expenses'||$expenseForm); ?><a href="index.php?page=<?=e($key)?><?=$key==='expenses'?'&add=1':''?>" class="<?=$active?'is-active':''?>" <?=$active?'aria-current="page"':''?>><?=ui_icon($icon)?><span><?=e($label)?></span></a><?php endforeach; ?></nav>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
