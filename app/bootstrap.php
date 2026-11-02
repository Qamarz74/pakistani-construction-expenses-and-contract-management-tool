<?php

declare(strict_types=1);
date_default_timezone_set('Asia/Karachi');
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
// Isolate this app from PHPSESSID cookies used by other hosted PHP applications.
session_name('construction_tracker_session');
$sessionPath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/') . '/';
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax', 'path' => $sessionPath, 'domain' => '']);
if (!session_start()) {
    http_response_code(503);
    exit('Unable to start a session. Check PHP session storage permissions.');
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Copy config.example.php to config.php, configure MySQL, import database/schema.sql and database/seed.sql, then run php setup.php admin.');
}
$config = require $configFile;
function db(): PDO
{
    static $pdo;
    global $config;
    return $pdo ??= new PDO($config['dsn'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}
function run(string $sql, array $params = []): PDOStatement
{
    $s = db()->prepare($sql);
    $s->execute($params);
    return $s;
}
function rows(string $sql, array $params = []): array
{
    return run($sql, $params)->fetchAll();
}
function one(string $sql, array $params = []): ?array
{
    return run($sql, $params)->fetch() ?: null;
}
function e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function money(mixed $v): string
{
    return 'Rs. ' . number_format((float)$v, 2);
}
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = [$message, $type];
}
function csrf(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '"><input type="hidden" name="_project_id" value="' . project_id() . '">';
}
function require_post_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? '')) || empty($_SESSION['csrf'])) {
        http_response_code(403);
        throw new RuntimeException(($_GET['page'] ?? '') === 'login' ? 'This login form has expired. Enter your credentials again and submit the refreshed form.' : 'This form has expired. Reload the page before saving again.');
    }
    if (!empty($_SESSION['user_id']) && !in_array($_GET['page'] ?? '', ['switch', 'logout'], true) && (string)($_POST['_project_id'] ?? '') !== (string)project_id()) throw new RuntimeException('The selected project changed in another tab. Reload this form before saving.');
}
function project_id(): int
{
    return (int)($_SESSION['project_id'] ?? 0);
}
function project_lock(): void
{
    if (!db()->inTransaction()) throw new LogicException('Financial write requires a transaction.');
    if (!one('SELECT id FROM projects WHERE id=? FOR UPDATE', [project_id()])) throw new RuntimeException('Select a project first.');
}
function options(array $records, mixed $selected = null, string $label = 'name'): string
{
    $html = '';
    foreach ($records as $r) $html .= '<option value="' . e($r['id']) . '"' . ((string)$selected === (string)$r['id'] ? ' selected' : '') . '>' . e($r[$label]) . '</option>';
    return $html;
}
function decimal(mixed $v, int $scale = 2, bool $positive = false): string
{
    $v = trim((string)$v);
    if (!preg_match('/^\d{1,12}(?:\.\d{1,' . $scale . '})?$/D', $v)) throw new InvalidArgumentException('Enter a valid non-negative number (up to ' . $scale . ' decimal places).');
    $v = bcadd($v, '0', $scale);
    if ($positive && bccomp($v, '0', $scale) <= 0) throw new InvalidArgumentException('Amount or quantity must be greater than zero.');
    return $v;
}
function add(mixed $a, mixed $b): string
{
    return bcadd((string)$a, (string)$b, 2);
}
function sub(mixed $a, mixed $b): string
{
    return bcsub((string)$a, (string)$b, 2);
}
function cmp(mixed $a, mixed $b): int
{
    return bccomp((string)$a, (string)$b, 2);
}
function rounded(string $v, int $scale = 2): string
{
    return bcadd($v, bccomp($v, '0', 12) < 0 ? '-0.' . str_repeat('0', $scale) . '5' : '0.' . str_repeat('0', $scale) . '5', $scale);
}
function line_total(string $quantity, string $rate, string $basis, string $factor): string
{
    if (bccomp($basis, '0', 8) <= 0) throw new InvalidArgumentException('Rate basis must be positive.');
    $v = rounded(bcdiv(bcmul(bcmul($quantity, $factor, 12), $rate, 12), $basis, 12));
    if (bccomp($v, '999999999999.99', 2) > 0) throw new InvalidArgumentException('Line total is too large.');
    return $v;
}
function valid_date(mixed $v): string
{
    $v = (string)$v;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v || $v < '1900-01-01' || $v > '9999-12-31') throw new InvalidArgumentException('Enter a valid date.');
    return $v;
}
function conversion(int $from, int $to): string
{
    if ($from === $to) return '1.00000000';
    $graph = [];
    foreach (rows('SELECT * FROM unit_conversions') as $c) {
        $graph[$c['from_unit_id']][] = [$c['to_unit_id'], $c['factor']];
        $graph[$c['to_unit_id']][] = [$c['from_unit_id'], bcdiv('1', $c['factor'], 12)];
    }
    $queue = [[$from, '1']];
    $seen = [$from => true];
    for ($index = 0; $index < count($queue); $index++) {
        [$u, $factor] = $queue[$index];
        foreach ($graph[$u] ?? [] as [$v, $f]) {
            if (isset($seen[$v])) continue;
            $result = bcmul($factor, $f, 12);
            if ((int)$v === $to) {
                $result = rounded($result, 8);
                if (bccomp($result, '0', 8) <= 0 || bccomp($result, '9999999999.99999999', 8) > 0) throw new InvalidArgumentException('Conversion factor is outside supported precision.');
                return $result;
            }
            $seen[$v] = true;
            $queue[] = [$v, $result];
        }
    }
    throw new InvalidArgumentException('These units have no configured conversion. Use the same quantity/rate unit or configure a conversion.');
}
function create_payment(?int $contactId, string $date, string $amount, string $method, string $notes): int
{
    if ($contactId && !one('SELECT id FROM contacts WHERE id=?', [$contactId])) throw new InvalidArgumentException('Invalid recipient.');
    $date = valid_date($date);
    $amount = decimal($amount, 2, true);
    if (!in_array($method, ['Cash', 'Bank Transfer', 'JazzCash', 'Easypaisa', 'Cheque', 'Other'], true)) throw new InvalidArgumentException('Invalid payment method.');
    run('INSERT INTO payments(project_id,contact_id,payment_date,amount,method,notes) VALUES(?,?,?,?,?,?)', [project_id(), $contactId ?: null, $date, $amount, $method, $notes]);
    return (int)db()->lastInsertId();
}
function allocate_payment(int $paymentId, string $type, int $targetId, string $amount): void
{
    $amount = decimal($amount, 2, true);
    if (!in_array($type, ['expense', 'contract'], true)) throw new InvalidArgumentException('Invalid allocation target.');
    $p = one('SELECT * FROM payments WHERE id=? AND project_id=? FOR UPDATE', [$paymentId, project_id()]);
    $table = $type === 'expense' ? 'expenses' : 'contracts';
    $col = $type . '_id';
    $t = one("SELECT * FROM $table WHERE id=? AND project_id=? FOR UPDATE", [$targetId, project_id()]);
    if (!$p || !$t || $p['status'] !== 'active' || ($type === 'expense' ? $t['status'] !== 'active' : $t['status'] === 'Cancelled')) throw new InvalidArgumentException('Payment or allocation target is unavailable.');
    if ((string)($p['contact_id'] ?? '') !== (string)($t['contact_id'] ?? '')) throw new InvalidArgumentException('Payment and target must belong to the same supplier/contractor.');
    $used = one('SELECT COALESCE(SUM(amount),0) n FROM payment_allocations WHERE payment_id=?', [$paymentId])['n'];
    $targetUsed = one("SELECT COALESCE(SUM(a.amount),0) n FROM payment_allocations a JOIN payments p ON p.id=a.payment_id AND p.status='active' WHERE a.$col=?", [$targetId])['n'];
    $limit = $type === 'expense' ? $t['total'] : $t['agreed_amount'];
    if (cmp($amount, sub($p['amount'], $used)) > 0) throw new InvalidArgumentException('Allocation exceeds the payment’s unallocated balance.');
    if (cmp($amount, sub($limit, $targetUsed)) > 0) throw new InvalidArgumentException('Allocation exceeds the purchase/contract balance.');
    run("INSERT INTO payment_allocations(payment_id,$col,amount) VALUES(?,?,?)", [$paymentId, $targetId, $amount]);
}
function upload_attachment(string $field, ?int $expenseId = null, ?int $paymentId = null): void
{
    global $config;
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return;
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] <= 0 || $f['size'] > $config['max_upload_bytes']) throw new InvalidArgumentException('Upload failed or exceeds the configured 5 MB limit.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
    if (!$ext) throw new InvalidArgumentException('Only JPG, PNG, WebP and PDF files are allowed.');
    if ($mime !== 'application/pdf' && !@getimagesize($f['tmp_name'])) throw new InvalidArgumentException('Invalid image.');
    $dir = $config['upload_dir'];
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Upload directory unavailable.');
    $name = bin2hex(random_bytes(24)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Unable to store upload.');
    $GLOBALS['new_uploads'][] = $dir . '/' . $name;
    run('INSERT INTO attachments(expense_id,payment_id,original_name,stored_name,mime,size) VALUES(?,?,?,?,?,?)', [$expenseId, $paymentId, mb_substr(basename($f['name']), 0, 255), $name, $mime, $f['size']]);
}
function cleanup_failed_uploads(): void
{
    foreach ($GLOBALS['new_uploads'] ?? [] as $path) @unlink($path);
    $GLOBALS['new_uploads'] = [];
}
function attachments_html(?int $expenseId = null, ?int $paymentId = null): string
{
    $key = $expenseId ? 'expense_id' : 'payment_id';
    $html = '';
    foreach (rows("SELECT * FROM attachments WHERE $key=?", [$expenseId ?? $paymentId]) as $a) {
        $html .= '<div class="d-flex align-items-center gap-2 my-2"><a target="_blank" rel="noopener" href="index.php?page=attachment&id=' . (int)$a['id'] . '">' . e($a['original_name']) . '</a><form method="post" action="index.php?page=attachment&id=' . (int)$a['id'] . '">' . csrf() . '<button class="btn btn-sm btn-outline-danger" name="remove" value="1" onclick="return confirm(\'Remove attachment?\')">Remove</button></form></div>';
    }
    return $html;
}
