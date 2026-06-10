<?php
ob_start();
session_start();
require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

define('ADMIN_USER', 'admin');
define('ADMIN_PASS', '0okm9ijnsklad');
define('VAPID_SUBJECT',     'mailto:tiholoveni@gmail.com');
define('VAPID_PUBLIC_KEY',  'BJQdGYTWHFlFOsrslhw-nluDXo_xnB34yxogrfx45DuuCKdmZ8NJsK6bNuvjOr5SyjN90We27G5M02vHU8-WmYs');
define('VAPID_PRIVATE_KEY', 'g3_3IXGOjbgEUN8YGJgLJ8AOqWdICi-YsG5dWifIUQs');

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function euro($n): string { return number_format((float)$n, 2, '.', ' ') . ' €'; }
function jsonOut(array $d): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function tableExists(PDO $pdo, string $table): bool {
    try { $s = $pdo->prepare("SHOW TABLES LIKE :t"); $s->execute(['t' => $table]); return (bool)$s->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

$ajax = $_GET['ajax'] ?? '';

/* Без кеширане — иначе браузърът показва стар (логнат) HTML панел, докато
   сесията на сървъра вече е изтекла → AJAX заявките връщат 401 → празно. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* ============================================================
   AUTH GUARD — session login (GDPR security fix)
   ============================================================ */

// Logout
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: papu4koo82.php');
    exit;
}

// Login POST handler
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $u = (string)($_POST['username'] ?? '');
    $pw = (string)($_POST['password'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Rate limit: 5 опита / 10 мин / session
    if (!isset($_SESSION['login_attempts']) || !is_array($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    $_SESSION['login_attempts'] = array_values(array_filter(
        $_SESSION['login_attempts'],
        function($t) { return $t > time() - 600; }
    ));

    if (count($_SESSION['login_attempts']) >= 5) {
        $loginError = 'Твърде много опити. Опитай след 10 минути.';
    } elseif (hash_equals(ADMIN_USER, $u) && hash_equals(ADMIN_PASS, $pw)) {
        session_regenerate_id(true);
        $_SESSION['admin_authed'] = true;
        $_SESSION['admin_ip']     = $ip;
        $_SESSION['admin_ua']     = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
        $_SESSION['login_attempts'] = [];
        header('Location: papu4koo82.php');
        exit;
    } else {
        $_SESSION['login_attempts'][] = time();
        $loginError = 'Грешно потребителско име или парола.';
    }
}

// Session hijack guard: само UA binding.
// IP binding-ът е премахнат — на мобилни мрежи (CGNAT) различните връзки на
// един и същи телефон излизат с различни публични IP-та. Това триеше сесията
// между зареждането на страницата и AJAX заявките → всеки AJAX връщаше 401 →
// таблото/статистиките оставаха вечно на "Зареждане..." (празен панел).
if (!empty($_SESSION['admin_authed'])) {
    $curUa = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
    if (($_SESSION['admin_ua'] ?? '') !== $curUa) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
}

// Not authenticated → AJAX returns 401, иначе login page
if (empty($_SESSION['admin_authed'])) {
    if ($ajax !== '') {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Not authenticated'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ?><!doctype html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Вход — Админ панел</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,Helvetica,sans-serif;background:#111827;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.login-box{background:#1f2937;border:1px solid #374151;border-radius:12px;padding:32px;width:100%;max-width:380px;box-shadow:0 10px 40px rgba(0,0,0,.5)}
.login-box h1{font-size:20px;font-weight:900;color:#E8B800;margin-bottom:4px}
.login-box p{font-size:12px;color:#9CA3AF;margin-bottom:20px}
label{display:block;font-size:12px;font-weight:700;color:#D1D5DB;margin-bottom:6px;margin-top:14px}
input{width:100%;padding:11px 12px;background:#111827;border:1px solid #374151;border-radius:8px;color:#fff;font-size:14px}
input:focus{outline:none;border-color:#E8B800}
button{width:100%;padding:12px;background:#E8B800;border:none;border-radius:8px;color:#111;font-size:14px;font-weight:900;cursor:pointer;margin-top:20px}
button:hover{background:#d4a800}
.error{background:rgba(211,43,43,.15);border:1px solid #D32B2B;color:#fca5a5;padding:10px 12px;border-radius:8px;font-size:13px;margin-top:14px}

/* ══════════════ NEON GLASS — scoped за #page-stats ══════════════ */
#page-stats{
  position:relative;min-height:100vh;padding:24px;margin:-16px;border-radius:20px;
  background:
    radial-gradient(ellipse 80% 50% at 20% 0%, rgba(168,85,247,.25), transparent 50%),
    radial-gradient(ellipse 60% 50% at 80% 0%, rgba(0,240,255,.18), transparent 50%),
    radial-gradient(ellipse 50% 50% at 50% 100%, rgba(255,62,165,.15), transparent 50%),
    #060614;
  color:#fff;
}
#page-stats .page-title{
  font-size:30px;font-weight:900;letter-spacing:-.5px;
  background:linear-gradient(90deg,#fff,rgba(255,255,255,.6));
  -webkit-background-clip:text;background-clip:text;color:transparent;
}
#page-stats .page-sub{color:rgba(255,255,255,.6)}
#page-stats .card-title{color:#fff;font-weight:800}

/* Glass cards */
#page-stats .card{
  background:rgba(255,255,255,.035);border:1px solid rgba(255,255,255,.08);
  border-radius:18px;backdrop-filter:blur(20px) saturate(1.4);
  -webkit-backdrop-filter:blur(20px) saturate(1.4);
  box-shadow:0 4px 24px rgba(0,0,0,.3), inset 0 1px 0 rgba(255,255,255,.08);
  color:#fff;position:relative;overflow:hidden;
}

/* Period buttons (.stat-period) */
#page-stats .stat-period{
  padding:8px 16px;border:1px solid rgba(255,255,255,.1);border-radius:10px;
  background:rgba(255,255,255,.04);color:rgba(255,255,255,.65);font-weight:700;
  transition:all .15s;
}
#page-stats .stat-period:hover{background:rgba(255,255,255,.08);color:#fff}
#page-stats .stat-period.active,
#page-stats .btn-yellow.stat-period{
  background:linear-gradient(135deg,#00f0ff,#a855f7) !important;
  color:#000 !important;border-color:transparent !important;
  box-shadow:0 0 24px rgba(0,240,255,.35), 0 0 44px rgba(168,85,247,.2) !important;
}

/* Stat boxes — glass с conic halo */
#page-stats .stat-box{
  position:relative;background:rgba(255,255,255,.035);
  border:1px solid rgba(255,255,255,.08);border-radius:16px;
  padding:16px 18px;overflow:hidden;
  backdrop-filter:blur(16px) saturate(1.3);
  -webkit-backdrop-filter:blur(16px) saturate(1.3);
  box-shadow:0 2px 12px rgba(0,0,0,.25), inset 0 1px 0 rgba(255,255,255,.05);
  transition:transform .2s;
  border-left:1px solid rgba(255,255,255,.08) !important;  /* reset flat accent */
}
#page-stats .stat-box:hover{transform:translateY(-3px)}
#page-stats .stat-box .sb-label{color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:1px;font-size:10px;font-weight:700;margin-bottom:8px}
#page-stats .stat-box .sb-value{
  font-size:26px;font-weight:900;line-height:1.05;
  background:linear-gradient(180deg,#fff,rgba(255,255,255,.72));
  -webkit-background-clip:text;background-clip:text;color:transparent;
}
#page-stats .stat-box .sb-sub{color:rgba(255,255,255,.4);font-size:11px;margin-top:6px}

/* Shine plash */
#page-stats .stat-box::before{
  content:'';position:absolute;top:0;left:-100%;width:50%;height:100%;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,.06),transparent);
  animation:stats-shine 4s infinite;pointer-events:none;
}
@keyframes stats-shine{
  0%{left:-100%} 60%{left:150%} 100%{left:150%}
}

/* Neon variants */
#page-stats .stat-box.yellow{border-color:rgba(255,184,0,.25) !important}
#page-stats .stat-box.yellow::after{content:'';position:absolute;top:-40%;right:-30%;width:140%;height:140%;
  background:radial-gradient(circle at 70% 30%,rgba(255,184,0,.22),transparent 50%);pointer-events:none;z-index:0}
#page-stats .stat-box.yellow .sb-value{background:linear-gradient(180deg,#ffe08a,#ffb800) !important;-webkit-background-clip:text;background-clip:text;color:transparent !important}

#page-stats .stat-box.blue{border-color:rgba(0,240,255,.25) !important}
#page-stats .stat-box.blue::after{content:'';position:absolute;top:-40%;right:-30%;width:140%;height:140%;
  background:radial-gradient(circle at 70% 30%,rgba(0,240,255,.22),transparent 50%);pointer-events:none;z-index:0}
#page-stats .stat-box.blue .sb-value{background:linear-gradient(180deg,#b4f5ff,#00f0ff) !important;-webkit-background-clip:text;background-clip:text;color:transparent !important}

#page-stats .stat-box.green{border-color:rgba(34,238,136,.25) !important}
#page-stats .stat-box.green::after{content:'';position:absolute;top:-40%;right:-30%;width:140%;height:140%;
  background:radial-gradient(circle at 70% 30%,rgba(34,238,136,.2),transparent 50%);pointer-events:none;z-index:0}
#page-stats .stat-box.green .sb-value{background:linear-gradient(180deg,#b5fbd0,#22ee88) !important;-webkit-background-clip:text;background-clip:text;color:transparent !important}

#page-stats .stat-box.red{border-color:rgba(255,78,78,.25) !important}
#page-stats .stat-box.red::after{content:'';position:absolute;top:-40%;right:-30%;width:140%;height:140%;
  background:radial-gradient(circle at 70% 30%,rgba(255,78,78,.2),transparent 50%);pointer-events:none;z-index:0}
#page-stats .stat-box.red .sb-value{background:linear-gradient(180deg,#ffbbbb,#ff4e4e) !important;-webkit-background-clip:text;background-clip:text;color:transparent !important}

#page-stats .stat-box.purple{border-color:rgba(168,85,247,.25) !important}
#page-stats .stat-box.purple::after{content:'';position:absolute;top:-40%;right:-30%;width:140%;height:140%;
  background:radial-gradient(circle at 70% 30%,rgba(168,85,247,.25),transparent 50%);pointer-events:none;z-index:0}
#page-stats .stat-box.purple .sb-value{background:linear-gradient(180deg,#e6d4ff,#a855f7) !important;-webkit-background-clip:text;background-clip:text;color:transparent !important}

/* Keep value/label over halo */
#page-stats .stat-box .sb-label,
#page-stats .stat-box .sb-value,
#page-stats .stat-box .sb-sub{position:relative;z-index:2}

/* Section titles */
#page-stats .card-title[style*="margin"]{
  font-weight:900;font-size:15px;text-transform:uppercase;letter-spacing:.8px;color:#fff;
}

/* Tables в glass */
#page-stats table{color:#fff}
#page-stats th{background:rgba(255,255,255,.03);color:rgba(255,255,255,.55);
  font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:1px;
  border-bottom:1px solid rgba(255,255,255,.06)}
#page-stats td{border-bottom:1px solid rgba(255,255,255,.06);color:#fff;font-size:13px}
#page-stats tr:hover td{background:rgba(255,255,255,.03)}
#page-stats .badge-gray{background:rgba(255,255,255,.08);color:rgba(255,255,255,.7);padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700}
#page-stats .empty{color:rgba(255,255,255,.4);text-align:center;padding:20px}

/* Period bar wrapper */
#page-stats .stat-period{font-size:13px}
#page-stats [id="statPeriodLabel"]{color:rgba(255,255,255,.4) !important}

/* Hero record (последна карта — #statsBiggestCard) */
#page-stats #statsBiggestCard{
  background:
    radial-gradient(circle at 20% 30%, rgba(255,184,0,.18), transparent 50%),
    radial-gradient(circle at 80% 70%, rgba(255,62,165,.12), transparent 50%),
    rgba(255,255,255,.03) !important;
  border:1px solid rgba(255,184,0,.3) !important;
  box-shadow:0 8px 40px rgba(255,184,0,.12), inset 0 1px 0 rgba(255,255,255,.08);
  padding:24px 28px;
}
#page-stats #statsBiggestCard .sb-label{color:rgba(255,255,255,.4);font-size:10px;letter-spacing:1px;text-transform:uppercase}
#page-stats #statsBiggestCard > div[style*="font-size:28px"]{
  font-size:40px !important;
  background:linear-gradient(180deg,#fff3a8,#ffb800) !important;
  -webkit-background-clip:text;background-clip:text;color:transparent !important;
  filter:drop-shadow(0 0 24px rgba(255,184,0,.5));
}

/* Charts — dark-friendly axis */
#page-stats .chart-wrap canvas{filter:drop-shadow(0 0 8px rgba(0,240,255,.15))}

/* Нормализация — картите от .card вече са glass, но loc breakdown card трябва padding */
#page-stats #statsLocCard{padding:20px}

/* stats-two responsive */
#page-stats .stats-two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:900px){#page-stats .stats-two{grid-template-columns:1fr}}

</style>
</head>
<body>
<form class="login-box" method="POST" autocomplete="off">
<h1>Админ панел</h1>
<p>Лоялна програма — Ени Тихолов</p>
<label>Потребителско име</label>
<input type="text" name="username" required autofocus>
<label>Парола</label>
<input type="password" name="password" required>
<?php if ($loginError): ?><div class="error"><?=h($loginError)?></div><?php endif; ?>
<button type="submit" name="admin_login" value="1">Вход →</button>
</form>
</body>
</html>
<?php
    exit;
}

/* ============================================================
   END AUTH GUARD
   ============================================================ */

/* ── Качване на снимка за банер ── */
if ($ajax === 'banner_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        jsonOut(['ok' => false, 'error' => 'Грешка при качване на файла.']);
    }
    $tmp  = $_FILES['image']['tmp_name'];
    $mime = mime_content_type($tmp);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        jsonOut(['ok' => false, 'error' => 'Разрешени формати: JPG, PNG, WebP, GIF.']);
    }
    $ext = match($mime) {
        'image/png'  => '.png',
        'image/webp' => '.webp',
        'image/gif'  => '.gif',
        default      => '.jpg',
    };
    $dir = __DIR__ . '/uploads/banners';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir)) jsonOut(['ok' => false, 'error' => 'Не може да се създаде папката.']);

    $filename = 'banner_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . $ext;
    $target   = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $target)) {
        jsonOut(['ok' => false, 'error' => 'Неуспешно записване на файла.']);
    }
    $url = '/uploads/banners/' . $filename;
    jsonOut(['ok' => true, 'url' => $url]);
}

/* ── Табло ── */
if ($ajax === 'dashboard') {
    $loc = (int)($_GET['loc'] ?? 0);
    $dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $dateTo   = $_GET['to']   ?? date('Y-m-d');
    $locCond  = $loc > 0 ? 'AND ps.location_id = :loc' : '';
    $params   = ['from' => $dateFrom . ' 00:00:00', 'to' => $dateTo . ' 23:59:59'];
    if ($loc > 0) $params['loc'] = $loc;

    $s = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(amount),0) as total, COALESCE(SUM(discount_amount),0) as disc
        FROM purchase_scans ps WHERE ps.deleted_at IS NULL AND ps.created_at BETWEEN :from AND :to $locCond");
    $s->execute($params); $sales = $s->fetch(PDO::FETCH_ASSOC);

    $s2 = $pdo->prepare("SELECT ps.location_name, COUNT(*) as cnt, COALESCE(SUM(amount),0) as total
        FROM purchase_scans ps WHERE ps.deleted_at IS NULL AND ps.created_at BETWEEN :from AND :to AND ps.location_name IS NOT NULL
        GROUP BY ps.location_id, ps.location_name ORDER BY total DESC");
    $s2->execute(['from' => $dateFrom . ' 00:00:00', 'to' => $dateTo . ' 23:59:59']);
    $byLocation = $s2->fetchAll(PDO::FETCH_ASSOC);

    $s3 = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL AND created_at BETWEEN :from AND :to");
    $s3->execute(['from' => $dateFrom . ' 00:00:00', 'to' => $dateTo . ' 23:59:59']);
    $newCustomers = (int)$s3->fetchColumn();

    $totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();

    $s4 = $pdo->prepare("SELECT DATE(created_at) as day, COALESCE(SUM(amount),0) as total, COUNT(*) as cnt
        FROM purchase_scans ps WHERE deleted_at IS NULL AND created_at BETWEEN :from AND :to $locCond
        GROUP BY DATE(created_at) ORDER BY day ASC");
    $s4->execute($params); $byDay = $s4->fetchAll(PDO::FETCH_ASSOC);

    jsonOut(['ok'=>true,'sales'=>$sales,'byLocation'=>$byLocation,'newCustomers'=>$newCustomers,'totalCustomers'=>$totalCustomers,'byDay'=>$byDay]);
}

/* ── Покупки ── */
if ($ajax === 'purchases') {
    $loc      = (int)($_GET['loc']  ?? 0);
    $search   = trim($_GET['q']     ?? '');
    $dateFrom = $_GET['from']       ?? date('Y-m-d', strtotime('-30 days'));
    $dateTo   = $_GET['to']         ?? date('Y-m-d');
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = 50; $offset = ($page - 1) * $perPage;

    $where  = ['ps.deleted_at IS NULL', 'ps.created_at BETWEEN :from AND :to'];
    $params = ['from' => $dateFrom . ' 00:00:00', 'to' => $dateTo . ' 23:59:59'];
    if ($loc > 0)       { $where[] = 'ps.location_id = :loc'; $params['loc'] = $loc; }
    if ($search !== '') { $where[] = '(lc.card_number LIKE :q OR CONCAT(c.first_name," ",c.last_name) LIKE :q)'; $params['q'] = "%$search%"; }
    $whereStr = implode(' AND ', $where);

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM purchase_scans ps
        LEFT JOIN customers c ON c.id = ps.customer_id
        LEFT JOIN loyalty_cards lc ON lc.customer_id = c.id WHERE $whereStr");
    $cnt->execute($params); $total = (int)$cnt->fetchColumn();

    $s = $pdo->prepare("SELECT ps.id, ps.created_at, ps.amount, ps.discount_amount, ps.location_name,
            lc.card_number, CONCAT(c.first_name,' ',c.last_name) as customer_name
        FROM purchase_scans ps
        LEFT JOIN customers c ON c.id = ps.customer_id
        LEFT JOIN loyalty_cards lc ON lc.customer_id = c.id
        WHERE $whereStr ORDER BY ps.id DESC LIMIT $perPage OFFSET $offset");
    $s->execute($params); $rows = $s->fetchAll(PDO::FETCH_ASSOC);

    jsonOut(['ok'=>true,'rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>ceil($total/$perPage)]);
}

/* ── История за деня ── */
if ($ajax === 'day_history') {
    $locId   = (int)($_GET['loc']  ?? 0);
    $bizDate = trim($_GET['date']  ?? '');
    if (!$bizDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate)) {
        $tz  = new DateTimeZone('Europe/Sofia');
        $now = new DateTime('now', $tz);
        if ((int)$now->format('H') < 19) $now->modify('-1 day');
        $bizDate = $now->format('Y-m-d');
    }

    try {
        $tz    = new DateTimeZone('Europe/Sofia');
        $start = new DateTime($bizDate . ' 19:00:00', $tz);
        $end   = clone $start;
        $end->modify('+1 day')->setTime(18, 59, 59);

        $params  = ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')];
        $locCond = $locId > 0 ? 'AND ps.location_id = :loc' : '';
        if ($locId > 0) $params['loc'] = $locId;

        $hasCp = false;
        try { $pdo->query("SELECT calc_payload FROM purchase_scans LIMIT 0"); $hasCp = true; } catch (Throwable $e) {}
        $cpCol = $hasCp ? 'ps.calc_payload,' : '';

        $stmt = $pdo->prepare("
            SELECT ps.id, ps.created_at, ps.amount, ps.discount_amount,
                   ps.location_id, ps.location_name, {$cpCol}
                   lc.card_number,
                   CONCAT(c.first_name,' ',c.last_name) AS customer_name
            FROM purchase_scans ps
            LEFT JOIN customers c ON c.id = ps.customer_id
            LEFT JOIN loyalty_cards lc ON lc.customer_id = ps.customer_id
            WHERE ps.deleted_at IS NULL AND ps.created_at BETWEEN :start AND :end $locCond
            ORDER BY ps.created_at ASC
        ");
        $stmt->execute($params);
        $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dayItemsAgg = [];
        $dayGross = 0.0; $dayFinal = 0.0; $dayDiscount = 0.0;

        foreach ($purchases as &$p) {
            $dayFinal    += (float)$p['amount'];
            $dayDiscount += (float)$p['discount_amount'];
            $dayGross    += (float)$p['amount'] + (float)$p['discount_amount'];

            $p['parsed_items'] = [];
            $raw = $p['calc_payload'] ?? null;
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $item) {
                        if (!is_array($item)) continue;
                        $code  = trim((string)($item['code']  ?? ''));
                        $model = trim((string)($item['model'] ?? ''));
                        $qty   = max(1, (int)($item['qty']    ?? 1));
                        $price = round((float)($item['price'] ?? 0), 2);
                        if ($price <= 0) continue;

                        $p['parsed_items'][] = ['code'=>$code?:'—','model'=>$model,'qty'=>$qty,'unit_price'=>$price];

                        $key = $code . '||' . number_format($price, 2, '.', '');
                        if (!isset($dayItemsAgg[$key])) {
                            $dayItemsAgg[$key] = ['code'=>$code?:'—','model'=>$model,'qty'=>0,'unit_price'=>$price,'line_base'=>0.0];
                        }
                        $dayItemsAgg[$key]['qty']       += $qty;
                        $dayItemsAgg[$key]['line_base'] += round($qty * $price, 2);
                        if ($model && !$dayItemsAgg[$key]['model']) $dayItemsAgg[$key]['model'] = $model;
                    }
                }
            }
            unset($p['calc_payload']);
        }
        unset($p);

        usort($dayItemsAgg, fn($a,$b) => strcmp((string)$a['code'], (string)$b['code']));

        jsonOut([
            'ok'           => true,
            'purchases'    => $purchases,
            'day_items'    => array_values($dayItemsAgg),
            'day_gross'    => round($dayGross, 2),
            'day_final'    => round($dayFinal, 2),
            'day_discount' => round($dayDiscount, 2),
            'business_date'=> $bizDate,
        ]);
    } catch (Throwable $e) {
        jsonOut(['ok' => false, 'error' => $e->getMessage()]);
    }
}

/* ── Клиенти ── */
if ($ajax === 'customers') {
    $search  = trim($_GET['q'] ?? ''); $page = max(1,(int)($_GET['page']??1)); $perPage=50; $offset=($page-1)*$perPage;
    $where=['1=1']; $params=[];
    if($search!==''){$where[]='(lc.card_number LIKE :q OR CONCAT(c.first_name," ",c.last_name) LIKE :q OR c.phone LIKE :q)';$params['q']="%$search%";}
    $whereStr=implode(' AND ',$where);
    $cnt=$pdo->prepare("SELECT COUNT(*) FROM customers c LEFT JOIN loyalty_cards lc ON lc.customer_id=c.id WHERE $whereStr");$cnt->execute($params);$total=(int)$cnt->fetchColumn();
    $s=$pdo->prepare("SELECT c.id,c.first_name,c.last_name,c.phone,c.total_purchases,c.total_spent,c.cycle_purchases_10,c.cycle_purchases_50,c.cycle_purchases_100,c.cycle_spent_100,c.suspicious,c.created_at,lc.card_number FROM customers c LEFT JOIN loyalty_cards lc ON lc.customer_id=c.id WHERE $whereStr ORDER BY c.id DESC LIMIT $perPage OFFSET $offset");
    $s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    jsonOut(['ok'=>true,'rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>ceil($total/$perPage)]);
}

/* ── Детайли клиент ── */
if ($ajax === 'customer_detail') {
    $id=(int)($_GET['id']??0);
    $c=$pdo->prepare("SELECT c.*,lc.card_number FROM customers c LEFT JOIN loyalty_cards lc ON lc.customer_id=c.id WHERE c.id=:id");$c->execute(['id'=>$id]);$customer=$c->fetch(PDO::FETCH_ASSOC);
    if(!$customer)jsonOut(['ok'=>false,'error'=>'Не е намерен']);
    $v=$pdo->prepare("SELECT * FROM vouchers WHERE customer_id=:id ORDER BY id DESC LIMIT 20");$v->execute(['id'=>$id]);$vouchers=$v->fetchAll(PDO::FETCH_ASSOC);
    $p=$pdo->prepare("SELECT * FROM purchase_scans WHERE customer_id=:id AND deleted_at IS NULL ORDER BY id DESC LIMIT 20");$p->execute(['id'=>$id]);$purchases=$p->fetchAll(PDO::FETCH_ASSOC);
    jsonOut(['ok'=>true,'customer'=>$customer,'vouchers'=>$vouchers,'purchases'=>$purchases]);
}

/* ── Одит лог ── */
if ($ajax === 'audit') {
    $page=max(1,(int)($_GET['page']??1));$perPage=50;$offset=($page-1)*$perPage;$type=trim($_GET['type']??'');
    $where=['1=1'];$params=[];if($type!==''){$where[]='event_type=:type';$params['type']=$type;}
    $whereStr=implode(' AND ',$where);
    $cnt=$pdo->prepare("SELECT COUNT(*) FROM loyalty_audit_log WHERE $whereStr");$cnt->execute($params);$total=(int)$cnt->fetchColumn();
    $s=$pdo->prepare("SELECT * FROM loyalty_audit_log WHERE $whereStr ORDER BY id DESC LIMIT $perPage OFFSET $offset");$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    jsonOut(['ok'=>true,'rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>ceil($total/$perPage)]);
}

/* ── Helper: recompute customer totals (при edit/delete на продажба) ── */
if (!function_exists('loyRecalcCustomer')) {
    function loyRecalcCustomer(PDO $pdo, int $customerId): void {
        if ($customerId <= 0) return;
        $s = $pdo->prepare("SELECT COUNT(*) cnt, COALESCE(SUM(amount),0) tot
            FROM purchase_scans WHERE customer_id=:cid AND deleted_at IS NULL");
        $s->execute(['cid'=>$customerId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("UPDATE customers SET total_spent=:t, total_purchases=:c
            WHERE id=:cid")->execute([
                't'=>$r['tot'] ?? 0, 'c'=>$r['cnt'] ?? 0, 'cid'=>$customerId
            ]);
    }
}

/* ── Покупка: детайли за редакция ── */
if ($ajax === 'purchase_get') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonOut(['ok'=>false,'error'=>'Невалиден id']);
    $s = $pdo->prepare("SELECT ps.*,
        TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) customer_name,
        lc.card_number
        FROM purchase_scans ps
        LEFT JOIN customers c ON c.id=ps.customer_id
        LEFT JOIN loyalty_cards lc ON lc.customer_id=ps.customer_id
        WHERE ps.id=:id");
    $s->execute(['id'=>$id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonOut(['ok'=>false,'error'=>'Не е намерена']);
    jsonOut(['ok'=>true, 'row'=>$row]);
}

/* ── Покупка: редакция ── */
if ($ajax === 'purchase_update' && $_SERVER['REQUEST_METHOD']==='POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if ($id <= 0) jsonOut(['ok'=>false,'error'=>'Невалиден id']);

    /* Вземи стария запис за audit */
    $s = $pdo->prepare("SELECT * FROM purchase_scans WHERE id=:id");
    $s->execute(['id'=>$id]);
    $old = $s->fetch(PDO::FETCH_ASSOC);
    if (!$old) jsonOut(['ok'=>false,'error'=>'Не е намерена']);
    if ($old['deleted_at']) jsonOut(['ok'=>false,'error'=>'Продажбата е изтрита — възстанови я първо']);

    /* Валидация */
    $newAmount   = isset($d['amount'])          ? round((float)$d['amount'], 2)          : (float)$old['amount'];
    $newDiscount = isset($d['discount_amount']) ? round((float)$d['discount_amount'], 2) : (float)$old['discount_amount'];
    $newLocId    = isset($d['location_id'])     ? ((int)$d['location_id'] ?: null)       : $old['location_id'];
    $newPayment  = isset($d['payment_method'])  ? trim((string)$d['payment_method'])     : ($old['payment_method'] ?? 'cash');
    $newGiven    = isset($d['given_amount'])    ? (($d['given_amount']==='' || $d['given_amount']===null) ? null : round((float)$d['given_amount'], 2)) : $old['given_amount'];
    $newChange   = isset($d['change_amount'])   ? (($d['change_amount']==='' || $d['change_amount']===null) ? null : round((float)$d['change_amount'], 2)) : $old['change_amount'];

    if ($newAmount < 0)   jsonOut(['ok'=>false,'error'=>'Сума < 0']);
    if ($newDiscount < 0) jsonOut(['ok'=>false,'error'=>'Отстъпка < 0']);

    /* Location name lookup */
    $newLocName = $old['location_name'];
    if ($newLocId) {
        $l = $pdo->prepare("SELECT name FROM locations WHERE id=:id");
        $l->execute(['id'=>$newLocId]);
        $newLocName = $l->fetchColumn() ?: null;
    } else {
        $newLocName = null;
    }

    /* Update */
    $pdo->prepare("UPDATE purchase_scans SET
        amount=:a, discount_amount=:d, location_id=:lid, location_name=:lname,
        payment_method=:pm, given_amount=:g, change_amount=:c,
        edited_at=NOW(), edited_by=:eb
        WHERE id=:id")
        ->execute([
            'a'=>$newAmount, 'd'=>$newDiscount, 'lid'=>$newLocId, 'lname'=>$newLocName,
            'pm'=>$newPayment, 'g'=>$newGiven, 'c'=>$newChange,
            'eb'=>'admin', 'id'=>$id
        ]);

    /* Recompute customer totals */
    if ($old['customer_id']) loyRecalcCustomer($pdo, (int)$old['customer_id']);

    /* Audit log */
    try {
        $changes = [];
        foreach (['amount','discount_amount','location_id','payment_method','given_amount','change_amount'] as $f) {
            $oldV = $old[$f] ?? null;
            $newV = match($f) {
                'amount' => $newAmount, 'discount_amount' => $newDiscount,
                'location_id' => $newLocId, 'payment_method' => $newPayment,
                'given_amount' => $newGiven, 'change_amount' => $newChange,
                default => null,
            };
            if ((string)$oldV !== (string)$newV) $changes[$f] = ['old'=>$oldV, 'new'=>$newV];
        }
        $pdo->prepare("INSERT INTO loyalty_audit_log (event_type, card_number, ip_address, event_data, created_at)
            VALUES ('purchase_edit', :card, :ip, :data, NOW())")->execute([
                'card' => null,
                'ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
                'data' => json_encode(['id'=>$id, 'changes'=>$changes], JSON_UNESCAPED_UNICODE),
            ]);
    } catch (Throwable $e) {}

    jsonOut(['ok'=>true]);
}

/* ── Покупка: soft delete ── */
if ($ajax === 'purchase_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
    $d  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if ($id <= 0) jsonOut(['ok'=>false,'error'=>'Невалиден id']);

    $s = $pdo->prepare("SELECT * FROM purchase_scans WHERE id=:id");
    $s->execute(['id'=>$id]);
    $old = $s->fetch(PDO::FETCH_ASSOC);
    if (!$old) jsonOut(['ok'=>false,'error'=>'Не е намерена']);
    if ($old['deleted_at']) jsonOut(['ok'=>false,'error'=>'Вече е изтрита']);

    $pdo->prepare("UPDATE purchase_scans SET deleted_at=NOW(), edited_by=:eb WHERE id=:id")
        ->execute(['eb'=>'admin', 'id'=>$id]);

    if ($old['customer_id']) loyRecalcCustomer($pdo, (int)$old['customer_id']);

    try {
        $pdo->prepare("INSERT INTO loyalty_audit_log (event_type, card_number, ip_address, event_data, created_at)
            VALUES ('purchase_delete', :card, :ip, :data, NOW())")->execute([
                'card' => null,
                'ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
                'data' => json_encode(['id'=>$id, 'amount'=>(float)$old['amount'], 'customer_id'=>$old['customer_id']], JSON_UNESCAPED_UNICODE),
            ]);
    } catch (Throwable $e) {}

    jsonOut(['ok'=>true]);
}

/* ── Покупка: възстановяване ── */
if ($ajax === 'purchase_restore' && $_SERVER['REQUEST_METHOD']==='POST') {
    $d  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if ($id <= 0) jsonOut(['ok'=>false,'error'=>'Невалиден id']);

    $s = $pdo->prepare("SELECT * FROM purchase_scans WHERE id=:id");
    $s->execute(['id'=>$id]);
    $old = $s->fetch(PDO::FETCH_ASSOC);
    if (!$old || !$old['deleted_at']) jsonOut(['ok'=>false,'error'=>'Не е изтрита']);

    $pdo->prepare("UPDATE purchase_scans SET deleted_at=NULL WHERE id=:id")->execute(['id'=>$id]);
    if ($old['customer_id']) loyRecalcCustomer($pdo, (int)$old['customer_id']);

    try {
        $pdo->prepare("INSERT INTO loyalty_audit_log (event_type, card_number, ip_address, event_data, created_at)
            VALUES ('purchase_restore', :card, :ip, :data, NOW())")->execute([
                'card' => null,
                'ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
                'data' => json_encode(['id'=>$id], JSON_UNESCAPED_UNICODE),
            ]);
    } catch (Throwable $e) {}

    jsonOut(['ok'=>true]);
}

/* ── Статистики (всичко накуп) ── */
if ($ajax === 'stats_all') {
    $loc    = (int)($_GET['loc'] ?? 0);
    $period = $_GET['period'] ?? '30';
    $daysMap= ['7'=>7,'30'=>30,'90'=>90,'365'=>365,'all'=>3650];
    $days   = $daysMap[$period] ?? 30;
    $dateFrom = date('Y-m-d', strtotime("-$days days")) . ' 00:00:00';
    $dateTo   = date('Y-m-d 23:59:59');
    $locCond  = $loc > 0 ? 'AND location_id = :loc' : '';
    $P = ['from' => $dateFrom, 'to' => $dateTo];
    if ($loc > 0) $P['loc'] = $loc;

    try {
        /* Core: count, sum, discount, avg, max, min */
        $s = $pdo->prepare("SELECT COUNT(*) cnt, COALESCE(SUM(amount),0) total,
            COALESCE(SUM(discount_amount),0) disc, COALESCE(AVG(amount),0) avg_amt,
            COALESCE(MAX(amount),0) max_amt,
            COALESCE(MIN(CASE WHEN amount>0 THEN amount END),0) min_amt
            FROM purchase_scans WHERE deleted_at IS NULL AND created_at BETWEEN :from AND :to $locCond");
        $s->execute($P); $core = $s->fetch(PDO::FETCH_ASSOC);

        /* Медиана (сортирани суми, взимам средния елемент) */
        $s = $pdo->prepare("SELECT amount FROM purchase_scans
            WHERE deleted_at IS NULL AND created_at BETWEEN :from AND :to $locCond AND amount>0 ORDER BY amount ASC");
        $s->execute($P);
        $arr = array_column($s->fetchAll(PDO::FETCH_ASSOC),'amount');
        $n = count($arr);
        $median = $n===0 ? 0 : ($n%2 ? (float)$arr[intdiv($n,2)] : ((float)$arr[$n/2-1]+(float)$arr[$n/2])/2);

        /* Най-голяма покупка + клиент */
        $s = $pdo->prepare("SELECT ps.amount, ps.created_at, ps.location_name,
            TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) name
            FROM purchase_scans ps LEFT JOIN customers c ON c.id=ps.customer_id
            WHERE ps.deleted_at IS NULL AND ps.created_at BETWEEN :from AND :to $locCond
            ORDER BY ps.amount DESC LIMIT 1");
        $s->execute($P); $biggest = $s->fetch(PDO::FETCH_ASSOC) ?: null;

        /* Най-силен ден от седмицата (1=неделя, 2=понеделник...7=събота в MySQL) */
        $s = $pdo->prepare("SELECT DAYOFWEEK(created_at) dow,
            COALESCE(SUM(amount),0) total, COUNT(*) cnt
            FROM purchase_scans WHERE deleted_at IS NULL AND created_at BETWEEN :from AND :to $locCond
            GROUP BY DAYOFWEEK(created_at) ORDER BY dow");
        $s->execute($P); $byDow = $s->fetchAll(PDO::FETCH_ASSOC);

        /* По час */
        $s = $pdo->prepare("SELECT HOUR(created_at) h,
            COALESCE(SUM(amount),0) total, COUNT(*) cnt
            FROM purchase_scans WHERE deleted_at IS NULL AND created_at BETWEEN :from AND :to $locCond
            GROUP BY HOUR(created_at) ORDER BY h");
        $s->execute($P); $byHour = $s->fetchAll(PDO::FETCH_ASSOC);

        /* По ден (line chart) */
        $s = $pdo->prepare("SELECT DATE(created_at) d,
            COALESCE(SUM(amount),0) total, COUNT(*) cnt
            FROM purchase_scans WHERE deleted_at IS NULL AND created_at BETWEEN :from AND :to $locCond
            GROUP BY DATE(created_at) ORDER BY d ASC");
        $s->execute($P); $byDay = $s->fetchAll(PDO::FETCH_ASSOC);

        /* По обект (breakdown без NULL филтър — fix на стария бъг) */
        $s = $pdo->prepare("SELECT COALESCE(NULLIF(location_name,''),'— без обект —') location_name,
            location_id, COALESCE(SUM(amount),0) total, COUNT(*) cnt,
            COALESCE(AVG(amount),0) avg_amt, COALESCE(SUM(discount_amount),0) disc
            FROM purchase_scans WHERE deleted_at IS NULL AND created_at BETWEEN :from AND :to $locCond
            GROUP BY location_id, location_name ORDER BY total DESC");
        $s->execute($P); $byLoc = $s->fetchAll(PDO::FETCH_ASSOC);

        /* ── 💸 Отстъпки в дълбочина ── */
        $ds = $pdo->prepare("SELECT COUNT(*) cnt_disc, COALESCE(AVG(discount_amount),0) avg_on
            FROM purchase_scans WHERE deleted_at IS NULL AND created_at BETWEEN :from AND :to $locCond AND discount_amount > 0");
        $ds->execute($P); $discRow = $ds->fetch(PDO::FETCH_ASSOC);

        /* ── Карта vs без карта ── */
        $cs = $pdo->prepare("SELECT has_card, COUNT(*) cnt, COALESCE(SUM(amount),0) net,
            COALESCE(SUM(discount_amount),0) disc, COALESCE(AVG(amount),0) avg_amt
            FROM purchase_scans WHERE deleted_at IS NULL AND created_at BETWEEN :from AND :to $locCond
            GROUP BY has_card");
        $cs->execute($P); $cardRows = $cs->fetchAll(PDO::FETCH_ASSOC);

        /* ── 🛒 Кошница + топ артикули (от calc_payload JSON) ── */
        $basketSales = 0; $basketQty = 0.0; $basketLines = 0;
        $prodAgg = []; $brandAgg = [];
        try {
            $ps = $pdo->prepare("SELECT calc_payload FROM purchase_scans
                WHERE deleted_at IS NULL AND created_at BETWEEN :from AND :to $locCond
                  AND calc_payload IS NOT NULL AND calc_payload <> ''");
            $ps->execute($P);
            while (($pl = $ps->fetchColumn()) !== false) {
                $items = json_decode((string)$pl, true);
                if (!is_array($items) || !$items) continue;
                $basketSales++;
                foreach ($items as $it) {
                    $code  = trim((string)($it['code']  ?? ''));
                    $brand = trim((string)($it['brand'] ?? ''));
                    $qty   = (float)($it['qty'] ?? 0); if ($qty == 0) $qty = 1;
                    $price = (float)($it['price'] ?? 0);
                    $final = isset($it['final']) ? (float)$it['final'] : $qty * $price;
                    $basketLines++; $basketQty += $qty;
                    if ($code !== '') {
                        if (!isset($prodAgg[$code])) $prodAgg[$code] = ['code'=>$code,'brand'=>$brand,'qty'=>0.0,'rev'=>0.0];
                        $prodAgg[$code]['qty'] += $qty; $prodAgg[$code]['rev'] += $final;
                        if ($brand && !$prodAgg[$code]['brand']) $prodAgg[$code]['brand'] = $brand;
                    }
                    if ($brand !== '') {
                        if (!isset($brandAgg[$brand])) $brandAgg[$brand] = ['brand'=>$brand,'qty'=>0.0,'rev'=>0.0];
                        $brandAgg[$brand]['qty'] += $qty; $brandAgg[$brand]['rev'] += $final;
                    }
                }
            }
        } catch (Throwable $e) {}
        $fmtItems = fn($arr) => array_map(fn($p) => [
            'code'=>$p['code'] ?? '', 'brand'=>$p['brand'] ?? '',
            'qty'=>round($p['qty'],2), 'rev'=>round($p['rev'],2)
        ], $arr);
        $prodByRev = array_values($prodAgg); usort($prodByRev, fn($a,$b)=>$b['rev']<=>$a['rev']);
        $prodByQty = array_values($prodAgg); usort($prodByQty, fn($a,$b)=>$b['qty']<=>$a['qty']);
        $brandByRev= array_values($brandAgg); usort($brandByRev, fn($a,$b)=>$b['rev']<=>$a['rev']);
        $topProdRev = $fmtItems(array_slice($prodByRev, 0, 10));
        $topProdQty = $fmtItems(array_slice($prodByQty, 0, 10));
        $topBrands  = $fmtItems(array_slice($brandByRev, 0, 10));
        $avgBasketQty   = $basketSales > 0 ? round($basketQty / $basketSales, 1) : 0;
        $avgBasketLines = $basketSales > 0 ? round($basketLines / $basketSales, 1) : 0;

        /* ── 📈 Ръст спрямо предходния период (същия брой дни преди него) ── */
        $curStartTs = strtotime($dateFrom);
        $prevStart  = date('Y-m-d 00:00:00', $curStartTs - $days * 86400);
        $prevEnd    = date('Y-m-d 23:59:59', $curStartTs - 86400);
        $pp = ['from'=>$prevStart, 'to'=>$prevEnd]; if ($loc > 0) $pp['loc'] = $loc;
        $pq = $pdo->prepare("SELECT COALESCE(SUM(amount),0) total, COUNT(*) cnt
            FROM purchase_scans WHERE deleted_at IS NULL AND created_at BETWEEN :from AND :to $locCond");
        $pq->execute($pp); $prevSales = $pq->fetch(PDO::FETCH_ASSOC);
        $prq = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL AND created_at BETWEEN :from AND :to");
        $prq->execute(['from'=>$prevStart, 'to'=>$prevEnd]); $prevReg = (int)$prq->fetchColumn();

        /* Регистрации по магазин (класация) — по обекта, в който е направена картата */
        $regByLoc = [];
        try {
            $rs = $pdo->prepare("SELECT COALESCE(NULLIF(reg_location_name,''),'— без магазин —') loc, COUNT(*) cnt
                FROM customers
                WHERE deleted_at IS NULL AND created_at BETWEEN :from AND :to
                GROUP BY reg_location_id, reg_location_name
                ORDER BY cnt DESC");
            $rs->execute(['from'=>$dateFrom,'to'=>$dateTo]);
            $regByLoc = $rs->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $regByLoc = []; }

        /* КЛИЕНТИ */
        $s = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL AND created_at BETWEEN :from AND :to");
        $s->execute(['from'=>$dateFrom,'to'=>$dateTo]); $newCust = (int)$s->fetchColumn();

        $dormant = (int)$pdo->query("SELECT COUNT(*) FROM
            (SELECT customer_id, MAX(created_at) last FROM purchase_scans
             GROUP BY customer_id HAVING last < DATE_SUB(NOW(), INTERVAL 30 DAY)) x")->fetchColumn();

        $dormant60 = (int)$pdo->query("SELECT COUNT(*) FROM
            (SELECT customer_id, MAX(created_at) last FROM purchase_scans
             GROUP BY customer_id HAVING last < DATE_SUB(NOW(), INTERVAL 60 DAY)) x")->fetchColumn();

        $birthdays = (int)$pdo->query("SELECT COUNT(*) FROM customers
            WHERE birth_date IS NOT NULL AND MONTH(birth_date)=MONTH(CURDATE())")->fetchColumn();

        $birthdaysWeek = (int)$pdo->query("SELECT COUNT(*) FROM customers
            WHERE birth_date IS NOT NULL
              AND DATE_ADD(birth_date, INTERVAL YEAR(CURDATE())-YEAR(birth_date) YEAR)
                  BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

        $totalC  = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
        $withP   = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE total_purchases>0")->fetchColumn();
        $oneP    = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE total_purchases=1")->fetchColumn();
        $loyalC  = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE total_purchases>=10")->fetchColumn();
        $loyal50 = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE total_purchases>=50")->fetchColumn();
        $suspC   = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE suspicious=1")->fetchColumn();

        /* Top 10 по оборот (total_spent) */
        $topSpend = $pdo->query("SELECT c.id, c.first_name, c.last_name, c.phone,
            c.total_spent, c.total_purchases, lc.card_number
            FROM customers c LEFT JOIN loyalty_cards lc ON lc.customer_id=c.id
            WHERE c.total_spent>0 ORDER BY c.total_spent DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

        /* Top 10 по брой покупки */
        $topCount = $pdo->query("SELECT c.id, c.first_name, c.last_name, c.phone,
            c.total_spent, c.total_purchases, lc.card_number
            FROM customers c LEFT JOIN loyalty_cards lc ON lc.customer_id=c.id
            WHERE c.total_purchases>0 ORDER BY c.total_purchases DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

        /* ВАУЧЕРИ */
        $vAll    = (int)$pdo->query("SELECT COUNT(*) FROM vouchers")->fetchColumn();
        $vUsed   = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE used=1 OR status='used' OR redeemed_at IS NOT NULL")->fetchColumn();
        $vActive = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE (used=0 OR used IS NULL) AND redeemed_at IS NULL AND (status IS NULL OR status='active')")->fetchColumn();
        $vValue  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM vouchers WHERE used=1 OR status='used' OR redeemed_at IS NOT NULL")->fetchColumn();

        /* КАРТИ */
        $cardsTotal  = (int)$pdo->query("SELECT COUNT(*) FROM loyalty_cards")->fetchColumn();
        $cardsActive = (int)$pdo->query("SELECT COUNT(DISTINCT ps.customer_id) FROM purchase_scans ps
            WHERE ps.deleted_at IS NULL AND ps.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)")->fetchColumn();

        /* Покупки/ден среден */
        $avgPerDay = $days > 0 ? round(((int)$core['cnt']) / $days, 1) : 0;
        $avgRevPerDay = $days > 0 ? round(((float)$core['total']) / $days, 2) : 0;

        /* Gross (преди отстъпка) */
        $gross = round((float)$core['total'] + (float)$core['disc'], 2);
        $discPct = $gross > 0 ? round(((float)$core['disc'] / $gross) * 100, 1) : 0;

        jsonOut([
            'ok'          => true,
            'period'      => $period,
            'days'        => $days,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
            'core'        => $core,
            'gross'       => $gross,
            'disc_pct'    => $discPct,
            'median'      => round($median, 2),
            'avg_per_day' => $avgPerDay,
            'avg_rev_day' => $avgRevPerDay,
            'biggest'     => $biggest,
            'by_dow'      => $byDow,
            'by_hour'     => $byHour,
            'by_day'      => $byDay,
            'by_loc'      => $byLoc,
            'reg_by_loc'  => $regByLoc,
            'discount_deep' => [
                'avg_per_sale'      => (int)$core['cnt'] > 0 ? round((float)$core['disc'] / (int)$core['cnt'], 2) : 0,
                'sales_with_disc'   => (int)$discRow['cnt_disc'],
                'pct_sales_disc'    => (int)$core['cnt'] > 0 ? round((int)$discRow['cnt_disc'] / (int)$core['cnt'] * 100, 1) : 0,
                'avg_on_discounted' => round((float)$discRow['avg_on'], 2),
            ],
            'by_card' => array_map(fn($r) => [
                'has_card'=>(int)$r['has_card'], 'cnt'=>(int)$r['cnt'],
                'net'=>round((float)$r['net'],2), 'disc'=>round((float)$r['disc'],2),
                'avg_amt'=>round((float)$r['avg_amt'],2),
            ], $cardRows),
            'basket' => ['sales'=>$basketSales, 'avg_qty'=>$avgBasketQty, 'avg_lines'=>$avgBasketLines],
            'top_products_rev' => $topProdRev,
            'top_products_qty' => $topProdQty,
            'top_brands'       => $topBrands,
            'growth' => [
                'prev_total'=>round((float)$prevSales['total'],2), 'prev_cnt'=>(int)$prevSales['cnt'], 'prev_reg'=>$prevReg,
                'cur_total'=>round((float)$core['total'],2), 'cur_cnt'=>(int)$core['cnt'], 'cur_reg'=>$newCust,
            ],
            'customers'   => [
                'new'            => $newCust,
                'dormant_30'     => $dormant,
                'dormant_60'     => $dormant60,
                'birthdays'      => $birthdays,
                'birthdays_week' => $birthdaysWeek,
                'total'          => $totalC,
                'with_purchase'  => $withP,
                'one_purchase'   => $oneP,
                'loyal_10'       => $loyalC,
                'loyal_50'       => $loyal50,
                'suspicious'     => $suspC,
            ],
            'top_spend' => $topSpend,
            'top_count' => $topCount,
            'vouchers'  => [
                'total'  => $vAll,
                'used'   => $vUsed,
                'active' => $vActive,
                'value'  => $vValue,
                'redemption_pct' => $vAll > 0 ? round(($vUsed / $vAll) * 100, 1) : 0,
            ],
            'cards' => [
                'total'  => $cardsTotal,
                'active' => $cardsActive,
                'activation_pct' => $cardsTotal > 0 ? round(($cardsActive / $cardsTotal) * 100, 1) : 0,
            ],
        ]);
    } catch (Throwable $e) {
        jsonOut(['ok'=>false,'error'=>$e->getMessage()]);
    }
}

/* ════════════════════════════════════════════════════════════════
   СКЛАДОВО САЛДО (inventory_balance) — по обект, по месец
   Крайно салдо (в продажни цени) =
     начално (авто = крайно от мин. месец, или ръчно за първия)
     + получена стока + увеличение на цени + трансфер вход
     − реален оборот − отстъпки − намаление на цени − трансфер изход
   Реален оборот и отстъпки идват автоматично от purchase_scans.
   ════════════════════════════════════════════════════════════════ */
function invManualRow(PDO $pdo, int $locId, string $period): ?array {
    $s = $pdo->prepare("SELECT * FROM inventory_balance WHERE location_id=:l AND period=:p LIMIT 1");
    $s->execute(['l'=>$locId, 'p'=>$period]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
function invAutoSales(PDO $pdo, int $locId, string $period): array {
    $start = $period . '-01 00:00:00';
    $end   = date('Y-m-t 23:59:59', strtotime($period . '-01'));
    $cond  = $locId > 0 ? 'AND location_id = :l' : '';
    $p = ['s'=>$start, 'e'=>$end];
    if ($locId > 0) $p['l'] = $locId;
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) net, COALESCE(SUM(discount_amount),0) disc, COUNT(*) cnt
        FROM purchase_scans WHERE deleted_at IS NULL AND created_at BETWEEN :s AND :e $cond");
    $s->execute($p);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return ['net'=>(float)$r['net'], 'disc'=>(float)$r['disc'], 'cnt'=>(int)$r['cnt']];
}
function invOpening(PDO $pdo, int $locId, string $period, int $depth = 0): float {
    static $memo = [];
    $key = $locId.'|'.$period;
    if (isset($memo[$key])) return $memo[$key];
    if ($depth > 60) return 0.0;
    $row = invManualRow($pdo, $locId, $period);
    if ($row && (int)$row['opening_manual'] === 1) return $memo[$key] = (float)$row['opening_balance'];
    /* авто-пренос: началото = крайното на предходния месец */
    $prev = date('Y-m', strtotime($period . '-01 -1 month'));
    return $memo[$key] = invClosing($pdo, $locId, $prev, $depth + 1);
}
function invClosing(PDO $pdo, int $locId, string $period, int $depth = 0): float {
    static $memo = [];
    $key = $locId.'|'.$period;
    if (isset($memo[$key])) return $memo[$key];
    $row   = invManualRow($pdo, $locId, $period);
    $sales = invAutoSales($pdo, $locId, $period);
    $opening  = invOpening($pdo, $locId, $period, $depth);
    /* празен месец (без ред и без продажби) надолу по веригата → нищо не се променя */
    if (!$row && $sales['cnt'] === 0 && $depth > 0) return $memo[$key] = $opening;
    $received = (float)($row['goods_received'] ?? 0);
    $markup   = (float)($row['markup_total']   ?? 0);
    $markdown = (float)($row['markdown_total'] ?? 0);
    $tin      = (float)($row['transfer_in']    ?? 0);
    $tout     = (float)($row['transfer_out']   ?? 0);
    return $memo[$key] = round($opening + $received + $markup + $tin
                               - $sales['net'] - $sales['disc'] - $markdown - $tout, 2);
}

if ($ajax === 'inv_get') {
    $locId  = (int)($_GET['loc'] ?? 0);
    $period = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['period'] ?? '')) ? $_GET['period'] : date('Y-m');
    try {
        if (!tableExists($pdo, 'inventory_balance')) {
            jsonOut(['ok'=>false, 'error'=>'Таблицата inventory_balance липсва — пусни migrations/S_inventory_balance.sql']);
        }
        $prevPeriod = date('Y-m', strtotime($period.'-01 -1 month'));

        if ($locId === 0) {
            /* Всички обекти → сбор на салдата по обект (само за четене) */
            $locIds = $pdo->query("SELECT id FROM locations")->fetchAll(PDO::FETCH_COLUMN);
            $sales = invAutoSales($pdo, 0, $period);
            $opening = 0.0; $closing = 0.0;
            $agg = ['goods_received'=>0,'markup_total'=>0,'markdown_total'=>0,'transfer_in'=>0,'transfer_out'=>0];
            foreach ($locIds as $lid) {
                $lid = (int)$lid;
                $opening += invOpening($pdo, $lid, $period);
                $closing += invClosing($pdo, $lid, $period);
                $r = invManualRow($pdo, $lid, $period);
                if ($r) foreach ($agg as $k=>$_) $agg[$k] += (float)($r[$k] ?? 0);
            }
            jsonOut([
                'ok'=>true, 'loc'=>0, 'period'=>$period, 'aggregate'=>true,
                'net'=>round($sales['net'],2), 'disc'=>round($sales['disc'],2),
                'gross'=>round($sales['net']+$sales['disc'],2), 'cnt'=>$sales['cnt'],
                'opening'=>round($opening,2),
                'manual'=>array_map(fn($v)=>round($v,2), $agg) + ['note'=>'', 'opening_manual'=>0, 'opening_balance'=>round($opening,2)],
                'closing'=>round($closing,2), 'saved'=>true, 'prev_has_data'=>true,
            ]);
        }

        $row   = invManualRow($pdo, $locId, $period);
        $sales = invAutoSales($pdo, $locId, $period);
        $opening = invOpening($pdo, $locId, $period);
        $prevRow = invManualRow($pdo, $locId, $prevPeriod);
        $prevHasData = $prevRow || invAutoSales($pdo, $locId, $prevPeriod)['cnt'] > 0;
        $openingManual = $row !== null ? (int)$row['opening_manual'] : ($prevHasData ? 0 : 1);
        $manual = [
            'goods_received' => (float)($row['goods_received'] ?? 0),
            'markup_total'   => (float)($row['markup_total']   ?? 0),
            'markdown_total' => (float)($row['markdown_total'] ?? 0),
            'transfer_in'    => (float)($row['transfer_in']    ?? 0),
            'transfer_out'   => (float)($row['transfer_out']   ?? 0),
            'note'           => (string)($row['note'] ?? ''),
            'opening_manual' => $openingManual,
            'opening_balance'=> round($opening, 2),
        ];
        $closing = round($opening + $manual['goods_received'] + $manual['markup_total'] + $manual['transfer_in']
                         - $sales['net'] - $sales['disc'] - $manual['markdown_total'] - $manual['transfer_out'], 2);
        jsonOut([
            'ok'=>true, 'loc'=>$locId, 'period'=>$period, 'aggregate'=>false,
            'net'=>round($sales['net'],2), 'disc'=>round($sales['disc'],2),
            'gross'=>round($sales['net']+$sales['disc'],2), 'cnt'=>$sales['cnt'],
            'opening'=>round($opening,2), 'opening_carried'=>($openingManual===0),
            'manual'=>$manual, 'closing'=>$closing, 'saved'=>($row !== null),
            'prev_has_data'=>$prevHasData,
        ]);
    } catch (Throwable $e) { jsonOut(['ok'=>false, 'error'=>$e->getMessage()]); }
}

if ($ajax === 'inv_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $locId  = (int)($d['loc'] ?? 0);
    $period = preg_match('/^\d{4}-\d{2}$/', (string)($d['period'] ?? '')) ? $d['period'] : '';
    if ($locId <= 0)  jsonOut(['ok'=>false, 'error'=>'Избери конкретен обект (не „Всички").']);
    if (!$period)     jsonOut(['ok'=>false, 'error'=>'Невалиден месец.']);
    $num = fn($k) => round((float)($d[$k] ?? 0), 2);
    $openingManual  = !empty($d['opening_manual']) ? 1 : 0;
    $openingBalance = round((float)($d['opening_balance'] ?? 0), 2);
    try {
        if (!tableExists($pdo, 'inventory_balance')) {
            jsonOut(['ok'=>false, 'error'=>'Таблицата inventory_balance липсва — пусни migrations/S_inventory_balance.sql']);
        }
        $pdo->prepare("INSERT INTO inventory_balance
            (location_id, period, opening_balance, opening_manual, goods_received, markup_total, markdown_total, transfer_in, transfer_out, note, updated_at)
            VALUES (:l,:p,:ob,:om,:gr,:mu,:md,:ti,:to,:n,NOW())
            ON DUPLICATE KEY UPDATE
              opening_balance=VALUES(opening_balance), opening_manual=VALUES(opening_manual),
              goods_received=VALUES(goods_received), markup_total=VALUES(markup_total),
              markdown_total=VALUES(markdown_total), transfer_in=VALUES(transfer_in),
              transfer_out=VALUES(transfer_out), note=VALUES(note), updated_at=NOW()")
            ->execute([
                'l'=>$locId, 'p'=>$period, 'ob'=>$openingBalance, 'om'=>$openingManual,
                'gr'=>$num('goods_received'), 'mu'=>$num('markup_total'), 'md'=>$num('markdown_total'),
                'ti'=>$num('transfer_in'), 'to'=>$num('transfer_out'),
                'n'=>mb_substr(trim((string)($d['note'] ?? '')), 0, 255),
            ]);
        jsonOut(['ok'=>true]);
    } catch (Throwable $e) { jsonOut(['ok'=>false, 'error'=>$e->getMessage()]); }
}

/* ── Банери ── */
if ($ajax==='banners_list'){try{$rows=$pdo->query("SELECT * FROM banners ORDER BY sort_order ASC,id DESC")->fetchAll(PDO::FETCH_ASSOC);jsonOut(['ok'=>true,'rows'=>$rows]);}catch(Throwable $e){jsonOut(['ok'=>false,'error'=>$e->getMessage()]);}}
if ($ajax==='banner_save'&&$_SERVER['REQUEST_METHOD']==='POST'){$d=json_decode(file_get_contents('php://input'),true)??[];$id=(int)($d['id']??0);$now=date('Y-m-d H:i:s');try{if($id>0){$pdo->prepare("UPDATE banners SET title=:t,body=:b,image_url=:i,link_url=:l,bg_color=:bg,active=:a,sort_order=:s WHERE id=:id")->execute(['t'=>$d['title'],'b'=>$d['body']??'','i'=>$d['image_url']??'','l'=>$d['link_url']??'','bg'=>$d['bg_color']??'#fff8e1','a'=>(int)($d['active']??1),'s'=>(int)($d['sort_order']??0),'id'=>$id]);}else{$pdo->prepare("INSERT INTO banners (title,body,image_url,link_url,bg_color,active,sort_order,created_at) VALUES (:t,:b,:i,:l,:bg,:a,:s,:now)")->execute(['t'=>$d['title'],'b'=>$d['body']??'','i'=>$d['image_url']??'','l'=>$d['link_url']??'','bg'=>$d['bg_color']??'#fff8e1','a'=>(int)($d['active']??1),'s'=>(int)($d['sort_order']??0),'now'=>$now]);}jsonOut(['ok'=>true]);}catch(Throwable $e){jsonOut(['ok'=>false,'error'=>$e->getMessage()]);}};
if ($ajax==='banner_delete'&&$_SERVER['REQUEST_METHOD']==='POST'){$d=json_decode(file_get_contents('php://input'),true)??[];$id=(int)($d['id']??0);if($id>0){$pdo->prepare("DELETE FROM banners WHERE id=:id")->execute(['id'=>$id]);}jsonOut(['ok'=>true]);}

/* ── Push нотификации ── */
if ($ajax === 'push_send' && $_SERVER['REQUEST_METHOD']==='POST') {
    $d=json_decode(file_get_contents('php://input'),true)??[];
    $title=trim($d['title']??'');$body=trim($d['body']??'');$cardNum=trim($d['card']??'');$url=trim($d['url']??'/loyalty/');$icon='/loyalty/icon-192.png';
    if(!$title||!$body)jsonOut(['ok'=>false,'error'=>'Липсва заглавие или текст.']);
    if(!class_exists('Minishlink\WebPush\WebPush'))jsonOut(['ok'=>false,'error'=>'Библиотеката web-push не е инсталирана.']);
    try{if($cardNum!==''){$stmt=$pdo->prepare("SELECT * FROM push_subscriptions WHERE card_number=:card");$stmt->execute(['card'=>$cardNum]);}else{$stmt=$pdo->query("SELECT * FROM push_subscriptions");}$subs=$stmt->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){jsonOut(['ok'=>false,'error'=>'Таблицата push_subscriptions не съществува.']);}
    if(empty($subs))jsonOut(['ok'=>false,'error'=>'Няма абонирани клиенти.']);
    $auth=['VAPID'=>['subject'=>VAPID_SUBJECT,'publicKey'=>VAPID_PUBLIC_KEY,'privateKey'=>VAPID_PRIVATE_KEY]];
    try{$webPush=new WebPush($auth);$webPush->setReuseVAPIDHeaders(true);}catch(Throwable $e){jsonOut(['ok'=>false,'error'=>'Грешка при WebPush: '.$e->getMessage()]);}
    $payload=json_encode(['title'=>$title,'body'=>$body,'icon'=>$icon,'url'=>$url,'badge'=>$icon],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $sent=0;$failed=0;$expired=[];
    foreach($subs as $sub){try{$subscription=Subscription::create(['endpoint'=>$sub['endpoint'],'contentEncoding'=>'aesgcm','keys'=>['p256dh'=>$sub['p256dh'],'auth'=>$sub['auth']]]);$webPush->queueNotification($subscription,$payload);}catch(Throwable $e){$failed++;}}
    foreach($webPush->flush() as $report){if($report->isSuccess())$sent++;else{$failed++;if($report->isSubscriptionExpired())$expired[]=$report->getRequest()->getUri()->__toString();}}
    foreach($expired as $ep){$pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint=:ep")->execute(['ep'=>$ep]);}
    jsonOut(['ok'=>true,'sent'=>$sent,'failed'=>$failed,'expired'=>count($expired),'total'=>count($subs)]);
}

/* ── Обекти ── */
if ($ajax === 'locations') {
    $rows=$pdo->query("SELECT * FROM locations ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    jsonOut(['ok'=>true,'rows'=>$rows]);
}

/* ── Текущ бизнес-ден (за JS) ── */
$tzAdmin  = new DateTimeZone('Europe/Sofia');
$nowAdmin = new DateTime('now', $tzAdmin);
if ((int)$nowAdmin->format('H') < 19) $nowAdmin->modify('-1 day');
$currentBizDate = $nowAdmin->format('Y-m-d');
?>
<!doctype html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Админ панел — Ени Тихолов</title>
<style>
:root{
  --yellow:#E8B800;--yellow-bg:#FFFBEB;--red:#D32B2B;--red-bg:#FFF2F2;
  --green:#16a34a;--green-bg:#f0fdf4;--blue:#2563eb;--blue-bg:#eff6ff;
  --bg:#F3F4F6;--white:#fff;--text:#111827;--text2:#6B7280;--text3:#9CA3AF;
  --border:#E5E7EB;--border2:#D1D5DB;--shadow:0 1px 4px rgba(0,0,0,.08);
  --radius:12px;--sidebar:240px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,Helvetica,sans-serif;background:var(--bg);color:var(--text);font-size:14px}
a{color:inherit;text-decoration:none}
.layout{display:flex;min-height:100vh}
.sidebar{width:var(--sidebar);flex-shrink:0;background:var(--text);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;overflow-y:auto}
.sidebar-logo{padding:20px 16px 14px;border-bottom:1px solid rgba(255,255,255,.08)}
.sidebar-logo h1{font-size:16px;font-weight:900;color:var(--yellow);line-height:1.2}
.sidebar-logo p{font-size:11px;color:rgba(255,255,255,.4);margin-top:3px}
.nav{flex:1;padding:10px 0}
.nav-item{display:flex;align-items:center;gap:10px;padding:11px 16px;color:rgba(255,255,255,.65);font-size:13px;font-weight:700;cursor:pointer;border-left:3px solid transparent;transition:all .15s}
.nav-item:hover{color:#fff;background:rgba(255,255,255,.06)}
.nav-item.active{color:var(--yellow);border-left-color:var(--yellow);background:rgba(232,184,0,.08)}
.nav-item .icon{font-size:16px;width:20px;text-align:center}
.sidebar-bottom{padding:12px 16px;border-top:1px solid rgba(255,255,255,.08)}
.logout-btn{display:block;width:100%;padding:10px;background:rgba(255,255,255,.06);border:none;border-radius:8px;color:rgba(255,255,255,.5);font-size:13px;font-weight:700;cursor:pointer;text-align:center}
.logout-btn:hover{background:rgba(255,255,255,.10);color:#fff}
.main{margin-left:var(--sidebar);flex:1;padding:24px;min-height:100vh}
.page{display:none}.page.active{display:block}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.page-title{font-size:22px;font-weight:900;color:var(--text)}
.page-sub{font-size:13px;color:var(--text2);margin-top:2px}
.card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:18px;box-shadow:var(--shadow)}
.card-title{font-size:14px;font-weight:800;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:20px}
.stat-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:16px;box-shadow:var(--shadow)}
.stat-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--text2);margin-bottom:6px}
.stat-value{font-size:28px;font-weight:900;line-height:1;color:var(--text)}
.stat-sub{margin-top:5px;font-size:12px;color:var(--text3)}
.stat-card.yellow .stat-value{color:var(--yellow)}.stat-card.red .stat-value{color:var(--red)}.stat-card.green .stat-value{color:var(--green)}.stat-card.blue .stat-value{color:var(--blue)}
.loc-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}
.loc-tab{padding:8px 14px;border-radius:999px;border:1px solid var(--border2);background:var(--white);font-size:13px;font-weight:700;color:var(--text2);cursor:pointer;transition:all .15s}
.loc-tab:hover{border-color:var(--yellow);color:var(--text)}.loc-tab.active{background:var(--yellow);border-color:var(--yellow);color:#1a1a1a}
.filters{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;align-items:center}
.filter-input{padding:9px 12px;border:1px solid var(--border2);border-radius:8px;font-size:13px;background:#fff;color:var(--text)}
.filter-input:focus{outline:none;border-color:var(--yellow)}
.btn{padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:800;cursor:pointer}
.btn-yellow{background:var(--yellow);color:#1a1a1a}.btn-red{background:var(--red);color:#fff}.btn-green{background:var(--green);color:#fff}.btn-ghost{background:transparent;border:1px solid var(--border2);color:var(--text2)}
.btn:hover{opacity:.88}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:10px 12px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--text2);border-bottom:2px solid var(--border);white-space:nowrap}
td{padding:10px 12px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
tr:hover td{background:#fafafa}
.badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:800}
.badge-green{background:var(--green-bg);color:var(--green)}.badge-red{background:var(--red-bg);color:var(--red)}.badge-yellow{background:var(--yellow-bg);color:#8a6700}.badge-gray{background:#f3f4f6;color:var(--text2)}
.pagination{display:flex;gap:6px;margin-top:14px;flex-wrap:wrap}
.pg-btn{padding:6px 12px;border:1px solid var(--border2);border-radius:6px;background:#fff;font-size:12px;font-weight:700;cursor:pointer;color:var(--text2)}
.pg-btn.active{background:var(--yellow);border-color:var(--yellow);color:#1a1a1a}.pg-btn:hover:not(.active){background:#f9fafb}
.chart-wrap{height:220px;position:relative;margin-top:8px}
.loc-stat-grid{display:grid;gap:10px;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-top:12px}
.loc-stat-box{background:var(--yellow-bg);border:1px solid rgba(232,184,0,.25);border-radius:10px;padding:12px}
.loc-stat-name{font-size:12px;font-weight:800;color:#8a6700;margin-bottom:5px}.loc-stat-val{font-size:20px;font-weight:900;color:var(--text)}.loc-stat-sub{font-size:11px;color:var(--text2);margin-top:3px}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px}
.modal-overlay.show{display:flex}
.modal{background:#fff;border-radius:16px;padding:22px 20px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15)}
.modal-title{font-size:18px;font-weight:900;margin-bottom:16px}
.form-row{margin-bottom:12px}
.form-label{display:block;font-size:12px;font-weight:800;color:var(--text2);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px}
.form-input{width:100%;padding:10px 12px;border:1px solid var(--border2);border-radius:8px;font-size:14px;color:var(--text);background:#fff}
.form-input:focus{outline:none;border-color:var(--yellow)}
textarea.form-input{resize:vertical;min-height:80px}
.modal-actions{display:flex;gap:10px;margin-top:16px;justify-content:flex-end}
.push-box{background:var(--blue-bg);border:1px solid rgba(37,99,235,.15);border-radius:var(--radius);padding:18px;margin-bottom:16px}
.empty{text-align:center;padding:40px 20px;color:var(--text3);font-size:14px;font-weight:700}
.loading{text-align:center;padding:30px;color:var(--text3);font-size:13px}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}
.detail-box{background:#fafafa;border:1px solid var(--border);border-radius:8px;padding:12px}
.detail-label{font-size:11px;color:var(--text2);font-weight:700;margin-bottom:4px}.detail-value{font-size:18px;font-weight:900}

/* Качване на снимка */
.upload-area{border:2px dashed var(--border2);border-radius:12px;padding:20px;text-align:center;cursor:pointer;transition:all .15s;background:#fafafa;position:relative}
.upload-area:hover{border-color:var(--yellow);background:var(--yellow-bg)}
.upload-area input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.upload-area .ua-icon{font-size:32px;margin-bottom:8px}
.upload-area .ua-text{font-size:13px;font-weight:700;color:var(--text2)}
.upload-area .ua-sub{font-size:11px;color:var(--text3);margin-top:4px}
.img-preview{margin-top:10px;border-radius:10px;overflow:hidden;border:1px solid var(--border);display:none}
.img-preview img{width:100%;max-height:200px;object-fit:cover;display:block}
.img-preview-url{margin-top:6px;font-size:11px;color:var(--text2);word-break:break-all;padding:4px 8px;background:#f3f4f6;border-radius:6px}

/* История за деня */
.dh-loc-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-bottom:20px}
.dh-loc-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow)}
.dh-loc-header{padding:12px 16px;background:var(--yellow-bg);border-bottom:1px solid rgba(232,184,0,.25);display:flex;justify-content:space-between;align-items:center}
.dh-loc-name{font-size:15px;font-weight:900;color:var(--text)}
.dh-loc-stats{display:flex;gap:10px;padding:10px 16px 12px;border-bottom:1px solid var(--border)}
.dh-loc-stat{flex:1;text-align:center}
.dh-loc-stat .k{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--text2);font-weight:700}
.dh-loc-stat .v{font-size:17px;font-weight:900;margin-top:3px}
.dh-loc-expand{padding:8px 16px;font-size:12px;font-weight:700;color:var(--blue);cursor:pointer;text-align:center;border:none;background:none;width:100%}
.dh-loc-expand:hover{background:#f0f4ff}
.dh-loc-detail{display:none;padding:0 12px 12px}
.dh-loc-detail.open{display:block}
.dh-day-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;padding-top:12px}
.dh-day-tab{padding:5px 11px;border-radius:999px;border:1px solid var(--border2);background:#fff;font-size:12px;font-weight:700;color:var(--text2);cursor:pointer;transition:all .12s}
.dh-day-tab:hover{border-color:var(--yellow);color:var(--text)}.dh-day-tab.active{background:var(--yellow);border-color:var(--yellow);color:#1a1a1a}
.dh-mini-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:10px}
.dh-purchases-table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:10px}
.dh-purchases-table th{text-align:left;padding:7px 8px;font-size:10px;font-weight:800;text-transform:uppercase;color:var(--text2);border-bottom:2px solid var(--border);white-space:nowrap}
.dh-purchases-table td{padding:7px 8px;border-bottom:1px solid #f0f0f0;vertical-align:top}
.dh-purchases-table tr:hover td{background:#fafbff}
.dh-items-mini{font-size:11px;color:var(--text2);line-height:1.5;margin-top:2px}
.dh-summary-box{background:#fffaf3;border:1px solid #eadcc6;border-radius:10px;padding:12px;margin-top:6px}
.dh-summary-title{font-size:11px;font-weight:900;color:#6a5b4c;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px}
.dh-summary-tbl{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:8px}
.dh-summary-tbl th{text-align:left;padding:5px 6px;font-size:10px;font-weight:800;text-transform:uppercase;color:#7c6d5d;border-bottom:1px solid #e5d5be}
.dh-summary-tbl td{padding:5px 6px;border-bottom:1px solid #f0e5d5}
.dh-totals-box{background:#fff8ed;border:1px solid #eadcc6;border-radius:8px;padding:8px 10px}
.dh-totals-box table{width:100%;font-size:13px}
.dh-totals-box td{padding:3px 5px}
.dh-totals-box .tl{color:var(--text2);font-weight:700}.dh-totals-box .tv{text-align:right;font-weight:900;color:var(--text)}.dh-totals-box .td{color:var(--red)}
.dh-empty{text-align:center;padding:18px;color:var(--text2);font-size:13px;font-weight:700}

@media(max-width:768px){.sidebar{width:200px}.main{margin-left:200px;padding:14px}.stats-grid{grid-template-columns:1fr 1fr}.detail-grid{grid-template-columns:1fr}.dh-loc-cards{grid-template-columns:1fr}}
@media(max-width:580px){.sidebar{transform:translateX(-100%);transition:transform .25s}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.menu-toggle{display:flex}}
.menu-toggle{display:none;position:fixed;top:14px;left:14px;z-index:200;width:40px;height:40px;background:var(--yellow);border-radius:10px;align-items:center;justify-content:center;font-size:18px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.15)}
</style>
</head>
<body>

<div class="menu-toggle" id="menuToggle">☰</div>

<div class="layout">
<div class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <h1>Ени Тихолов</h1>
    <p>Административен панел</p>
  </div>
  <nav class="nav">
    <div class="nav-item active" data-page="dashboard"><span class="icon">📊</span>Табло</div>
    <div class="nav-item" data-page="dayhistory"><span class="icon">📋</span>История за деня</div>
    <div class="nav-item" data-page="purchases"><span class="icon">🛒</span>Покупки</div>
    <div class="nav-item" data-page="customers"><span class="icon">👥</span>Клиенти</div>
    <div class="nav-item" data-page="push"><span class="icon">🔔</span>Нотификации</div>
    <div class="nav-item" data-page="banners"><span class="icon">🎯</span>Банери</div>
    <div class="nav-item" data-page="audit"><span class="icon">🔍</span>Одит лог</div>
    <div class="nav-item" data-page="locations"><span class="icon">📍</span>Обекти</div>
    <div class="nav-item" data-page="stats"><span class="icon">📈</span>Статистики</div>
    <div class="nav-item" data-page="inventory"><span class="icon">📦</span>Салдо</div>
  </nav>
  <div class="sidebar-bottom">
    <a href="?logout=1" class="logout-btn">Изход →</a>
  </div>
</div>

<div class="main">

<!-- ТАБЛО -->
<div class="page active" id="page-dashboard">
  <div class="page-header">
    <div><div class="page-title">Табло</div><div class="page-sub">Обобщена статистика</div></div>
    <div class="filters">
      <input type="date" class="filter-input" id="dashFrom">
      <input type="date" class="filter-input" id="dashTo">
      <button class="btn btn-yellow" onclick="loadDashboard()">Приложи</button>
    </div>
  </div>
  <div class="loc-tabs" id="dashLocTabs"></div>
  <div class="stats-grid" id="dashStats"><div class="loading">Зареждане...</div></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px">
    <div class="card"><div class="card-title">Оборот по ден</div><div class="chart-wrap"><canvas id="dashChart"></canvas></div></div>
    <div class="card"><div class="card-title">По обекти</div><div class="loc-stat-grid" id="dashByLoc"><div class="loading">Зареждане...</div></div></div>
  </div>
</div>

<!-- ИСТОРИЯ ЗА ДЕНЯ -->
<div class="page" id="page-dayhistory">
  <div class="page-header">
    <div><div class="page-title">История за деня</div><div class="page-sub">Покупки и артикули за всеки обект поотделно</div></div>
    <button class="btn btn-yellow" onclick="loadDayHistory()">↺ Обнови</button>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px" id="dhDayTabs"></div>
  <div id="dhLocCards" class="dh-loc-cards"><div class="loading">Зареждане...</div></div>
</div>

<!-- ПОКУПКИ -->
<div class="page" id="page-purchases">
  <div class="page-header">
    <div><div class="page-title">Покупки</div><div class="page-sub" id="purchasesSubtitle"></div></div>
  </div>
  <div class="loc-tabs" id="purchLocTabs"></div>
  <div class="filters">
    <input type="text" class="filter-input" id="purchSearch" placeholder="🔍 Карта или клиент..." style="width:200px">
    <input type="date" class="filter-input" id="purchFrom">
    <input type="date" class="filter-input" id="purchTo">
    <button class="btn btn-yellow" onclick="loadPurchases(1)">Търси</button>
  </div>
  <div class="card">
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Дата</th><th>Клиент</th><th>Карта</th><th>Обект</th><th>Сума</th><th>Отстъпка</th><th style="text-align:right">Действия</th></tr></thead>
        <tbody id="purchBody"><tr><td colspan="8" class="loading">Зареждане...</td></tr></tbody>
      </table>
    </div>
    <div class="pagination" id="purchPagination"></div>
  </div>
</div>

<!-- КЛИЕНТИ -->
<div class="page" id="page-customers">
  <div class="page-header"><div><div class="page-title">Клиенти</div><div class="page-sub" id="custSubtitle"></div></div></div>
  <div class="filters">
    <input type="text" class="filter-input" id="custSearch" placeholder="🔍 Карта, име или телефон..." style="width:240px">
    <button class="btn btn-yellow" onclick="loadCustomers(1)">Търси</button>
  </div>
  <div class="card">
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Карта</th><th>Клиент</th><th>Телефон</th><th>Покупки</th><th>Оборот</th><th>Статус</th><th></th></tr></thead>
        <tbody id="custBody"><tr><td colspan="8" class="loading">Зареждане...</td></tr></tbody>
      </table>
    </div>
    <div class="pagination" id="custPagination"></div>
  </div>
</div>

<!-- НОТИФИКАЦИИ -->
<div class="page" id="page-push">
  <div class="page-header"><div><div class="page-title">Push нотификации</div><div class="page-sub">Изпращане до клиенти</div></div></div>
  <div class="push-box">
    <div class="card-title">📢 До всички клиенти</div>
    <div class="form-row"><label class="form-label">Заглавие</label><input type="text" class="form-input" id="pushTitleAll" placeholder="Нова оферта!"></div>
    <div class="form-row"><label class="form-label">Текст</label><textarea class="form-input" id="pushBodyAll"></textarea></div>
    <div class="form-row"><label class="form-label">URL</label><input type="text" class="form-input" id="pushUrlAll" value="/loyalty/"></div>
    <button class="btn btn-yellow" onclick="sendPush(false)">📤 Изпрати до всички</button>
    <span id="pushAllResult" style="margin-left:12px;font-size:13px;font-weight:700"></span>
  </div>
  <div class="card">
    <div class="card-title">👤 До конкретен клиент</div>
    <div class="form-row"><label class="form-label">Номер на карта</label><input type="text" class="form-input" id="pushCard" style="max-width:220px"></div>
    <div class="form-row"><label class="form-label">Заглавие</label><input type="text" class="form-input" id="pushTitleOne"></div>
    <div class="form-row"><label class="form-label">Текст</label><textarea class="form-input" id="pushBodyOne"></textarea></div>
    <button class="btn btn-yellow" onclick="sendPush(true)">📤 Изпрати</button>
    <span id="pushOneResult" style="margin-left:12px;font-size:13px;font-weight:700"></span>
  </div>
</div>

<!-- БАНЕРИ -->
<div class="page" id="page-banners">
  <div class="page-header">
    <div><div class="page-title">Банери</div><div class="page-sub">Управление на промо банери в картата</div></div>
    <button class="btn btn-yellow" onclick="openBannerModal(null)">+ Нов банер</button>
  </div>
  <div class="card">
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Снимка</th><th>Заглавие</th><th>Ред</th><th>Статус</th><th></th></tr></thead>
        <tbody id="bannersBody"><tr><td colspan="6" class="loading">Зареждане...</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ОДИТ ЛОГ -->
<div class="page" id="page-audit">
  <div class="page-header"><div><div class="page-title">Одит лог</div><div class="page-sub" id="auditSubtitle"></div></div></div>
  <div class="filters">
    <select class="filter-input" id="auditType">
      <option value="">Всички събития</option>
      <option value="PURCHASE_OK">Покупки</option>
      <option value="SUSPICIOUS_ACTIVITY">Подозрителни</option>
      <option value="BLOCKED_INTERVAL">Блокирани</option>
      <option value="QR_CARD_MISMATCH">QR несъответствие</option>
    </select>
    <button class="btn btn-yellow" onclick="loadAudit(1)">Филтрирай</button>
    <button class="btn btn-ghost" onclick="document.getElementById('auditType').value='SUSPICIOUS_ACTIVITY';loadAudit(1)">⚠ Само подозрителни</button>
  </div>
  <div class="card">
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Дата</th><th>Карта</th><th>Събитие</th><th>IP</th><th>Данни</th></tr></thead>
        <tbody id="auditBody"><tr><td colspan="6" class="loading">Зареждане...</td></tr></tbody>
      </table>
    </div>
    <div class="pagination" id="auditPagination"></div>
  </div>
</div>

<!-- ОБЕКТИ -->
<div class="page" id="page-locations">
  <div class="page-header"><div><div class="page-title">Обекти</div><div class="page-sub">URL за всеки служебен телефон</div></div></div>
  <div class="card" id="locationsCard"><div class="loading">Зареждане...</div></div>
</div>

</div>

<!-- СТАТИСТИКИ -->
<div class="page" id="page-stats">
  <div class="page-header">
    <div>
      <div class="page-title">Статистики</div>
      <div class="page-sub">Всичко което магазинът ти казва</div>
    </div>
  </div>

  <!-- Период филтър -->
  <div class="card" style="padding:12px 16px;margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <span style="font-weight:700;color:var(--text2);font-size:12px;margin-right:4px">ПЕРИОД:</span>
    <button class="btn btn-ghost stat-period" data-p="7">7 дни</button>
    <button class="btn btn-yellow stat-period active" data-p="30">30 дни</button>
    <button class="btn btn-ghost stat-period" data-p="90">90 дни</button>
    <button class="btn btn-ghost stat-period" data-p="365">1 година</button>
    <button class="btn btn-ghost stat-period" data-p="all">Всичко</button>
    <div style="margin-left:auto;font-size:12px;color:var(--text3)" id="statPeriodLabel">—</div>
  </div>

  <!-- Финансови карти -->
  <div class="card-title" style="margin-bottom:10px">💰 Пари</div>
  <div class="stats-grid" id="statsMoney"><div class="loading">Зареждане...</div></div>

  <!-- Приблизителна печалба (на база средна наценка) -->
  <div class="card" id="statsProfitCard" style="margin-top:12px;display:flex;flex-wrap:wrap;align-items:center;gap:16px">
    <div style="display:flex;align-items:center;gap:8px">
      <span style="font-weight:700;color:var(--text2);font-size:13px">Средна наценка:</span>
      <input id="statsMarkup" type="number" inputmode="decimal" step="0.5" min="0" value="53.5"
             style="width:84px;padding:9px;border:1px solid var(--border);border-radius:8px;font-weight:800;text-align:center;font-family:inherit;font-size:15px">
      <span style="font-weight:800;font-size:15px">%</span>
    </div>
    <div style="flex:1;min-width:170px">
      <div style="font-size:11px;color:var(--text2);font-weight:800;text-transform:uppercase;letter-spacing:.3px">💰 Приблизителна печалба</div>
      <div id="statsProfitVal" style="font-size:26px;font-weight:900;color:var(--green);line-height:1.1">— €</div>
      <div id="statsProfitSub" style="font-size:11px;color:var(--text3);margin-top:2px"></div>
    </div>
  </div>

  <!-- Ръст спрямо минал период -->
  <div class="card-title" style="margin:20px 0 10px">📈 Ръст спрямо предходния период</div>
  <div class="stats-grid" id="statsGrowth"></div>

  <!-- Отстъпки в дълбочина -->
  <div class="card-title" style="margin:20px 0 10px">💸 Отстъпки в дълбочина</div>
  <div class="stats-grid" id="statsDiscount"></div>
  <div class="card" id="statsByCard" style="margin-top:12px"></div>

  <!-- Кошница + топ артикули -->
  <div class="card-title" style="margin:20px 0 10px">🛒 Кошница</div>
  <div class="stats-grid" id="statsBasket"></div>
  <div class="stats-two" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px">
    <div class="card"><div class="card-title" style="font-size:13px">Топ артикули по брой</div><div class="table-wrap"><table><thead><tr><th>#</th><th>Код</th><th>Бр.</th><th>Оборот</th></tr></thead><tbody id="statsTopProdQty"></tbody></table></div></div>
    <div class="card"><div class="card-title" style="font-size:13px">Топ артикули по оборот</div><div class="table-wrap"><table><thead><tr><th>#</th><th>Код</th><th>Бр.</th><th>Оборот</th></tr></thead><tbody id="statsTopProdRev"></tbody></table></div></div>
  </div>
  <div class="card" id="statsTopBrandsCard" style="margin-top:12px">
    <div class="card-title" style="font-size:13px">🏭 Топ марки / производители</div>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>Марка</th><th>Бр.</th><th>Оборот</th></tr></thead><tbody id="statsTopBrands"></tbody></table></div>
  </div>

  <!-- Клиенти карти -->
  <div class="card-title" style="margin:20px 0 10px">👥 Клиенти</div>
  <div class="stats-grid" id="statsCustomers"></div>

  <!-- Ваучери + карти -->
  <div class="card-title" style="margin:20px 0 10px">🎟️ Ваучери &amp; карти</div>
  <div class="stats-grid" id="statsVouchers"></div>

  <!-- Графики -->
  <div class="card-title" style="margin:20px 0 10px">📊 Динамика</div>
  <div class="card"><div class="card-title" style="font-size:13px">Оборот по ден</div><div class="chart-wrap"><canvas id="statsDayChart"></canvas></div></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px" class="stats-two">
    <div class="card"><div class="card-title" style="font-size:13px">Оборот по ден от седмицата</div><div class="chart-wrap"><canvas id="statsDowChart"></canvas></div></div>
    <div class="card"><div class="card-title" style="font-size:13px">Оборот по час (0-23)</div><div class="chart-wrap"><canvas id="statsHourChart"></canvas></div></div>
  </div>

  <!-- Обекти -->
  <div class="card-title" style="margin:20px 0 10px">📍 По обекти</div>
  <div class="card" id="statsLocCard"></div>

  <!-- Класация: регистрации по магазин -->
  <div class="card-title" style="margin:20px 0 10px">🏪 Регистрации по магазин</div>
  <div class="card" id="statsRegByLoc"></div>

  <!-- Top списъци -->
  <div class="card-title" style="margin:20px 0 10px">🏆 Топ 10 клиенти</div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px" class="stats-two">
    <div class="card">
      <div class="card-title" style="font-size:13px">По оборот</div>
      <div class="table-wrap"><table><thead><tr><th>#</th><th>Клиент</th><th>Покупки</th><th>Оборот</th></tr></thead><tbody id="statsTopSpend"></tbody></table></div>
    </div>
    <div class="card">
      <div class="card-title" style="font-size:13px">По брой покупки</div>
      <div class="table-wrap"><table><thead><tr><th>#</th><th>Клиент</th><th>Покупки</th><th>Оборот</th></tr></thead><tbody id="statsTopCount"></tbody></table></div>
    </div>
  </div>

  <!-- Най-голяма продажба -->
  <div class="card" id="statsBiggestCard" style="margin-top:16px;display:none"></div>
</div>

<!-- ═══ СКЛАДОВО САЛДО ═══ -->
<div class="page" id="page-inventory">
  <div class="page-header">
    <div>
      <div class="page-title">Складово салдо</div>
      <div class="page-sub">Стойност на стоката в продажни цени, по обект и месец</div>
    </div>
    <div class="filters">
      <input type="month" class="filter-input" id="invMonth">
      <button class="btn btn-yellow" onclick="loadInventory()">Зареди</button>
    </div>
  </div>
  <div class="loc-tabs" id="invLocTabs"></div>
  <div id="invBody"><div class="card"><div class="loading">Зареждане...</div></div></div>
</div>

<style>
.inv-card{max-width:540px}
.inv-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid #eee}
.inv-row > span{font-size:14px;color:#374151;font-weight:600}
.inv-row small{color:#9ca3af;font-weight:500;font-size:11px}
.inv-row.plus > span{color:#15803d}
.inv-row.minus > span{color:#b91c1c}
.inv-inp{width:130px;text-align:right;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:15px;font-weight:700;font-family:inherit;color:#111}
.inv-inp[readonly]{background:#f3f4f6;color:#6b7280}
.inv-note{width:100%;max-width:210px;text-align:left;font-weight:500}
.inv-anchor{display:flex;align-items:center;gap:6px;font-size:12px;color:#6b7280;margin:6px 0 4px;cursor:pointer}
.inv-closing{display:flex;justify-content:space-between;align-items:center;margin-top:14px;padding-top:12px;border-top:2px solid #111}
.inv-closing > span{font-size:15px;font-weight:800;color:#111}
.inv-closing b{font-size:24px;font-weight:900;color:#15803d}
.inv-note-agg{background:#fef3c7;border:1px solid #fde68a;color:#92400e;padding:8px 12px;border-radius:8px;font-size:12px;margin-bottom:12px;font-weight:600}
.inv-meta{font-size:12px;color:#9ca3af;margin-top:12px}
</style>

<style>
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}
.stat-box{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow)}
.stat-box .sb-label{font-size:11px;color:var(--text2);font-weight:700;text-transform:uppercase;letter-spacing:.3px;margin-bottom:6px}
.stat-box .sb-value{font-size:22px;font-weight:800;color:var(--text);line-height:1.1}
.stat-box .sb-sub{font-size:11px;color:var(--text3);margin-top:4px}
.stat-box.red{border-left:3px solid var(--red)} .stat-box.red .sb-value{color:var(--red)}
.stat-box.green{border-left:3px solid var(--green)} .stat-box.green .sb-value{color:var(--green)}
.stat-box.yellow{border-left:3px solid var(--yellow)}
.stat-box.blue{border-left:3px solid var(--blue)} .stat-box.blue .sb-value{color:var(--blue)}
@media(max-width:768px){.stats-two{grid-template-columns:1fr!important}}
</style>

</div>

<!-- МОДАЛ: Клиент -->
<div class="modal-overlay" id="custModal">
  <div class="modal">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <div class="modal-title" id="custModalTitle">Клиент</div>
      <button class="btn btn-ghost" onclick="closeModal('custModal')">✕ Затвори</button>
    </div>
    <div id="custModalBody"><div class="loading">Зареждане...</div></div>
  </div>
</div>


<!-- МОДАЛ: Редактиране на продажба -->
<div class="modal-overlay" id="editPurchaseModal">
  <div class="modal" style="max-width:500px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <div class="modal-title">✏️ Редакция на продажба</div>
      <button class="btn btn-ghost" onclick="closeModal('editPurchaseModal')">✕ Затвори</button>
    </div>

    <div id="editPurchaseBody">
      <div class="form-row"><label class="form-label" style="font-size:11px;color:var(--text2)">ID продажба</label>
        <div id="ep_id_display" style="font-weight:700;font-size:14px">—</div>
      </div>
      <div class="form-row"><label class="form-label" style="font-size:11px;color:var(--text2)">Клиент</label>
        <div id="ep_customer_display" style="font-weight:700">—</div>
      </div>
      <div class="form-row"><label class="form-label" style="font-size:11px;color:var(--text2)">Дата</label>
        <div id="ep_date_display">—</div>
      </div>

      <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

      <div class="form-row">
        <label class="form-label">Сума (€) *</label>
        <input type="number" step="0.01" class="form-input" id="ep_amount">
      </div>
      <div class="form-row">
        <label class="form-label">Отстъпка (€)</label>
        <input type="number" step="0.01" class="form-input" id="ep_discount">
      </div>
      <div class="form-row">
        <label class="form-label">Обект</label>
        <select class="form-input" id="ep_location">
          <option value="">— без обект —</option>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label">Начин на плащане</label>
        <select class="form-input" id="ep_payment">
          <option value="cash">💵 Кеш</option>
          <option value="card">💳 Карта</option>
          <option value="transfer">🏦 Превод</option>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label">Дадени пари (€)</label>
        <input type="number" step="0.01" class="form-input" id="ep_given" placeholder="незадължително">
      </div>
      <div class="form-row">
        <label class="form-label">Ресто (€)</label>
        <input type="number" step="0.01" class="form-input" id="ep_change" placeholder="автоматично">
      </div>

      <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end">
        <button class="btn btn-ghost" onclick="closeModal('editPurchaseModal')">Откажи</button>
        <button class="btn btn-yellow" onclick="savePurchaseEdit()">💾 Запази</button>
      </div>
    </div>
  </div>
</div>

<!-- МОДАЛ: Банер -->
<div class="modal-overlay" id="bannerModal">
  <div class="modal">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <div class="modal-title" id="bannerModalTitle">Банер</div>
      <button class="btn btn-ghost" onclick="closeModal('bannerModal')">✕ Затвори</button>
    </div>
    <div class="form-row"><label class="form-label">Заглавие *</label><input type="text" class="form-input" id="bTitle"></div>
    <div class="form-row"><label class="form-label">Текст</label><textarea class="form-input" id="bBody"></textarea></div>

    <!-- КАЧВАНЕ НА СНИМКА -->
    <div class="form-row">
      <label class="form-label">Снимка за банера</label>
      <div class="upload-area" id="uploadArea">
        <input type="file" id="bImageFile" accept="image/*" onchange="handleImageUpload(this)">
        <div class="ua-icon">📷</div>
        <div class="ua-text">Натисни за да избереш снимка</div>
        <div class="ua-sub">JPG, PNG, WebP · макс. 5MB</div>
      </div>
      <div class="img-preview" id="imgPreview">
        <img id="imgPreviewImg" src="" alt="Преглед">
        <div class="img-preview-url" id="imgPreviewUrl"></div>
      </div>
      <div style="margin-top:8px;font-size:12px;color:var(--text2)">или въведи URL директно:</div>
      <input type="text" class="form-input" id="bImage" placeholder="https://..." style="margin-top:6px" oninput="onImageUrlInput(this.value)">
    </div>

    <div class="form-row"><label class="form-label">Линк при натискане</label><input type="text" class="form-input" id="bLink" placeholder="https://..."></div>
    <div class="form-row"><label class="form-label">Фон цвят</label><input type="color" class="form-input" id="bColor" value="#fff8e1" style="height:42px;padding:4px"></div>
    <div class="form-row"><label class="form-label">Ред на показване</label><input type="number" class="form-input" id="bSort" value="0"></div>
    <div class="form-row" style="display:flex;align-items:center;gap:10px">
      <input type="checkbox" id="bActive" checked style="width:18px;height:18px">
      <label for="bActive" style="font-size:14px;font-weight:700">Активен</label>
    </div>
    <input type="hidden" id="bId" value="0">
    <div id="bUploadStatus" style="font-size:13px;font-weight:700;margin-top:8px"></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('bannerModal')">Отказ</button>
      <button class="btn btn-yellow" onclick="saveBanner()">Запази</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const BASE = '<?= $_SERVER['PHP_SELF'] ?>';
const CURRENT_BIZ_DATE = <?= json_encode($currentBizDate) ?>;
let dashLocId = 0, purchLocId = 0;
let dashChart = null;
let locations = [];
let dhCurrentDate = CURRENT_BIZ_DATE;
let dhLoading = false;

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') }
function euroFmt(n){ return parseFloat(n||0).toFixed(2)+' €' }

/* При изтекла сесия (AJAX → 401) — видим банер вместо тих провал/вечно "Зареждане...". */
function showAuthExpired(){
  if(document.getElementById('authExpiredBar')) return;
  const bar=document.createElement('div');
  bar.id='authExpiredBar';
  bar.style.cssText='position:fixed;top:0;left:0;right:0;z-index:99999;background:#D32B2B;color:#fff;padding:12px 16px;font:700 14px/1.3 Arial,sans-serif;text-align:center;box-shadow:0 4px 16px rgba(0,0,0,.4)';
  bar.innerHTML='Сесията изтече или не си логнат. <a href="'+BASE+'" style="color:#fff;text-decoration:underline;font-weight:900">Влез отново</a>';
  document.body.appendChild(bar);
}
function fmtDate(s){ if(!s)return'—'; const d=new Date(s); return d.toLocaleDateString('bg-BG')+' '+d.toLocaleTimeString('bg-BG',{hour:'2-digit',minute:'2-digit'}) }
function fmtTime(s){ if(!s)return'—'; return String(s).slice(11,16) }

/* Navigation */
document.querySelectorAll('.nav-item').forEach(el=>{
  el.addEventListener('click',()=>{
    document.querySelectorAll('.nav-item').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.page').forEach(x=>x.classList.remove('active'));
    el.classList.add('active');
    const p=document.getElementById('page-'+el.dataset.page);
    if(p) p.classList.add('active');
    const pg=el.dataset.page;
    if(pg==='purchases')  loadPurchases(1);
    if(pg==='customers')  loadCustomers(1);
    if(pg==='banners')    loadBanners();
    if(pg==='audit')      loadAudit(1);
    if(pg==='locations')  loadLocations();
    if(pg==='dayhistory') loadDayHistory();
    if(pg==='stats')      loadStats();
    if(pg==='inventory')  loadInventory();
    document.getElementById('sidebar').classList.remove('open');
  });
});

document.getElementById('menuToggle').addEventListener('click',()=>{
  document.getElementById('sidebar').classList.toggle('open');
});

function closeModal(id){ document.getElementById(id).classList.remove('show') }

function renderPagination(containerId,current,total,onPage){
  const el=document.getElementById(containerId);
  if(!el)return;
  if(total<=1){el.innerHTML='';return;}
  let html='';
  for(let i=1;i<=total;i++) html+=`<button class="pg-btn${i===current?' active':''}" onclick="${onPage}(${i})">${i}</button>`;
  el.innerHTML=html;
}

function initDateDefaults(){
  const now=new Date(), d30=new Date(Date.now()-30*86400000);
  const fmt=d=>d.toISOString().slice(0,10);
  ['dashFrom','purchFrom'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=fmt(d30); });
  ['dashTo','purchTo'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=fmt(now); });
}

async function loadLocTabs(){
  const res=await fetch(`${BASE}?ajax=locations`);
  const data=await res.json();
  if(!data.ok)return;
  locations=data.rows;
  const dt=document.getElementById('dashLocTabs');
  const pt=document.getElementById('purchLocTabs');
  if(dt){
    let h='<div class="loc-tab active" data-loc="0" onclick="setDashLoc(0,this)">Всички</div>';
    data.rows.forEach(l=>{ h+=`<div class="loc-tab" data-loc="${l.id}" onclick="setDashLoc(${l.id},this)">${esc(l.name)}</div>`; });
    dt.innerHTML=h;
  }
  if(pt){
    let h='<div class="loc-tab active" data-loc="0" onclick="setPurchLoc(0,this)">Всички</div>';
    data.rows.forEach(l=>{ h+=`<div class="loc-tab" data-loc="${l.id}" onclick="setPurchLoc(${l.id},this)">${esc(l.name)}</div>`; });
    pt.innerHTML=h;
  }
}

function setDashLoc(id,el){ dashLocId=id; document.querySelectorAll('#dashLocTabs .loc-tab').forEach(x=>x.classList.remove('active')); el.classList.add('active'); loadDashboard(); }
function setPurchLoc(id,el){ purchLocId=id; document.querySelectorAll('#purchLocTabs .loc-tab').forEach(x=>x.classList.remove('active')); el.classList.add('active'); loadPurchases(1); }

/* ТАБЛО */
async function loadDashboard(){
  const from=document.getElementById('dashFrom')?.value||'';
  const to=document.getElementById('dashTo')?.value||'';
  const res=await fetch(`${BASE}?ajax=dashboard&loc=${dashLocId}&from=${from}&to=${to}`);
  if(res.status===401){
    showAuthExpired();
    document.getElementById('dashStats').innerHTML='<div class="empty" style="padding:20px">Сесията изтече — влез отново.</div>';
    const _bl=document.getElementById('dashByLoc'); if(_bl) _bl.innerHTML='<div class="empty">—</div>';
    return;
  }
  const data=await res.json();
  if(!data.ok){
    document.getElementById('dashStats').innerHTML='<div class="empty" style="padding:20px">Грешка: '+esc(data.error||'неизвестна')+'</div>';
    const _bl=document.getElementById('dashByLoc'); if(_bl) _bl.innerHTML='<div class="empty">—</div>';
    return;
  }
  const s=data.sales;
  document.getElementById('dashStats').innerHTML=`
    <div class="stat-card yellow"><div class="stat-label">Оборот</div><div class="stat-value">${euroFmt(s.total)}</div><div class="stat-sub">За избрания период</div></div>
    <div class="stat-card blue"><div class="stat-label">Покупки</div><div class="stat-value">${s.cnt}</div><div class="stat-sub">Брой транзакции</div></div>
    <div class="stat-card red"><div class="stat-label">Отстъпки</div><div class="stat-value">${euroFmt(s.disc)}</div><div class="stat-sub">Общо дадени</div></div>
    <div class="stat-card green"><div class="stat-label">Клиенти</div><div class="stat-value">${data.totalCustomers}</div><div class="stat-sub">${data.newCustomers} нови за периода</div></div>
  `;
  const bl=document.getElementById('dashByLoc');
  if(data.byLocation.length===0){ bl.innerHTML='<div class="empty">Няма данни</div>'; }
  else { bl.innerHTML=data.byLocation.map(l=>`<div class="loc-stat-box"><div class="loc-stat-name">${esc(l.location_name)}</div><div class="loc-stat-val">${euroFmt(l.total)}</div><div class="loc-stat-sub">${l.cnt} покупки</div></div>`).join(''); }
  if(data.byDay.length>0){
    const labels=data.byDay.map(d=>d.day), values=data.byDay.map(d=>parseFloat(d.total));
    if(dashChart)dashChart.destroy();
    dashChart=new Chart(document.getElementById('dashChart'),{type:'bar',data:{labels,datasets:[{label:'Оборот (€)',data:values,backgroundColor:'rgba(232,184,0,.55)',borderColor:'#E8B800',borderWidth:2,borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:v=>v+'€'}}}}});
  }
}

/* ИСТОРИЯ ЗА ДЕНЯ */
function buildDhDayTabs(){
  const container=document.getElementById('dhDayTabs');
  if(!container)return;
  let html='';
  for(let i=0;i<7;i++){
    const d=new Date();
    const hourBg=new Date().toLocaleString('en-US',{timeZone:'Europe/Sofia',hour:'numeric',hour12:false});
    const offset=parseInt(hourBg)<19?1:0;
    d.setDate(d.getDate()-(i+offset));
    const bizDate=d.toISOString().slice(0,10);
    const label=i===0?'Днес':i===1?'Вчера':d.toLocaleDateString('bg-BG',{day:'2-digit',month:'2-digit'});
    const active=bizDate===dhCurrentDate?' active':'';
    html+=`<button class="dh-day-tab${active}" data-date="${bizDate}" onclick="selectDhDay(this)">${esc(label)}</button>`;
  }
  container.innerHTML=html;
  const tabs=container.querySelectorAll('.dh-day-tab');
  let found=false;
  tabs.forEach(t=>{ if(t.dataset.date===dhCurrentDate){t.classList.add('active');found=true;}else t.classList.remove('active'); });
  if(!found && tabs.length>0){ tabs[0].classList.add('active'); dhCurrentDate=tabs[0].dataset.date; }
}

function selectDhDay(btn){
  document.querySelectorAll('.dh-day-tab').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  dhCurrentDate=btn.dataset.date;
  loadDayHistory();
}
window.selectDhDay=selectDhDay;

async function loadDayHistory(){
  if(dhLoading)return;
  dhLoading=true;
  const container=document.getElementById('dhLocCards');
  if(container) container.innerHTML='<div class="loading">Зареждане на историята...</div>';
  try {
    const locList=[{id:0,name:'Всички обекти'},...locations];
    const results=await Promise.all(locList.map(async loc=>{
      const url=`${BASE}?ajax=day_history&loc=${loc.id}&date=${dhCurrentDate}&_ts=${Date.now()}`;
      const res=await fetch(url,{cache:'no-store'});
      const data=await res.json();
      return {loc,data};
    }));
    if(!container){dhLoading=false;return;}
    container.innerHTML='';
    for(const {loc,data} of results){
      if(!data.ok) continue;
      const purchases=data.purchases||[];
      const dayFinal=parseFloat(data.day_final||0);
      const dayDiscount=parseFloat(data.day_discount||0);
      const dayGross=parseFloat(data.day_gross||0);
      const discPct=(dayGross+dayDiscount)>0?(dayDiscount/(dayGross+dayDiscount)*100).toFixed(1):'0.0';
      const detailId=`dhDetail_${loc.id}`;
      let purchasesHtml='';
      if(purchases.length===0){
        purchasesHtml=`<tr><td colspan="${loc.id===0?6:5}" class="dh-empty">Няма покупки за този ден</td></tr>`;
      } else {
        purchasesHtml=purchases.map(p=>{
          const items=p.parsed_items||[];
          let itemsList='<span style="color:var(--text2)">—</span>';
          if(items.length>0){
            itemsList=items.map(it=>{
              const parts=[];
              if(it.code && it.code!=='—') parts.push(it.code);
              if(it.model) parts.push(it.model);
              const label=parts.join(' · ')||'—';
              return `<div>${esc(label)} &times;<strong>${it.qty}</strong> &mdash; <strong>${parseFloat(it.unit_price).toFixed(2)} €</strong></div>`;
            }).join('');
          }
          const gross=(parseFloat(p.amount)+parseFloat(p.discount_amount||0)).toFixed(2);
          return `<tr>
            <td style="font-weight:800;white-space:nowrap">${fmtTime(p.created_at)}</td>
            <td><div style="font-weight:800">${esc((p.customer_name||'').trim()||'—')}</div><div style="font-size:11px;color:var(--text2)">${esc(p.card_number||'—')}</div></td>
            ${loc.id===0?`<td><span class="badge badge-yellow">${esc(p.location_name||'—')}</span></td>`:''}
            <td><strong>${parseFloat(p.amount).toFixed(2)} €</strong><br><span style="font-size:11px;color:var(--text2);text-decoration:line-through">${gross} €</span></td>
            <td style="color:var(--red);font-weight:700;white-space:nowrap">-${parseFloat(p.discount_amount||0).toFixed(2)} €</td>
            <td><div class="dh-items-mini">${itemsList}</div></td>
          </tr>`;
        }).join('');
      }
      const dayItems=data.day_items||[];
      let summaryHtml='';
      if(dayItems.length>0){
        let totBase=0;
        const rows=dayItems.map(item=>{ const base=parseFloat(item.line_base||0); totBase+=base; return `<tr><td style="font-weight:800">${esc(item.code||'—')}</td><td style="color:var(--text2)">${esc(item.model||'—')}</td><td style="text-align:center;font-weight:900;font-size:15px">${item.qty}</td><td style="font-weight:700">${parseFloat(item.unit_price).toFixed(2)} €</td><td style="font-weight:900">${base.toFixed(2)} €</td></tr>`; }).join('');
        summaryHtml=`<div class="dh-summary-box"><div class="dh-summary-title">📦 Артикули за деня</div><div style="overflow-x:auto"><table class="dh-summary-tbl"><thead><tr><th>Артикул</th><th>Модел</th><th>Бройки</th><th>Цена</th><th>Общо</th></tr></thead><tbody>${rows}</tbody></table></div><div class="dh-totals-box" style="margin-top:8px"><table><tr><td class="tl">Общо без отстъпка:</td><td class="tv">${totBase.toFixed(2)} €</td></tr><tr><td class="tl">Реално взето:</td><td class="tv">${dayFinal.toFixed(2)} €</td></tr><tr><td class="tl" style="font-weight:900;color:var(--red)">Обща отстъпка:</td><td class="tv td" style="font-size:16px">-${dayDiscount.toFixed(2)} € (${discPct}%)</td></tr></table></div></div>`;
      }
      const card=document.createElement('div');
      card.className='dh-loc-card';
      card.innerHTML=`<div class="dh-loc-header"><div class="dh-loc-name">📍 ${esc(loc.name)}</div><span class="badge badge-gray">${purchases.length} продажби</span></div><div class="dh-loc-stats"><div class="dh-loc-stat"><div class="k">Взето</div><div class="v" style="color:var(--blue)">${dayFinal.toFixed(2)} €</div></div><div class="dh-loc-stat"><div class="k">Без отст.</div><div class="v">${(dayFinal+dayDiscount).toFixed(2)} €</div></div><div class="dh-loc-stat"><div class="k">Отстъпка</div><div class="v" style="color:var(--red)">-${dayDiscount.toFixed(2)} €</div></div><div class="dh-loc-stat"><div class="k">Отст. %</div><div class="v" style="color:var(--red)">${discPct}%</div></div></div><button class="dh-loc-expand" onclick="toggleDhDetail('${detailId}',this)">▼ Покажи детайли</button><div class="dh-loc-detail" id="${detailId}"><div style="overflow-x:auto"><table class="dh-purchases-table"><thead><tr><th>Час</th><th>Клиент / Карта</th>${loc.id===0?'<th>Обект</th>':''}<th>Взето</th><th>Отстъпка</th><th>Артикули</th></tr></thead><tbody>${purchasesHtml}</tbody></table></div>${summaryHtml}</div>`;
      container.appendChild(card);
    }
    if(container.children.length===0) container.innerHTML='<div class="empty">Няма данни за избрания ден</div>';
  } catch(e) {
    if(container) container.innerHTML=`<div class="empty" style="color:var(--red)">Грешка: ${esc(e.message)}</div>`;
  }
  dhLoading=false;
}
window.loadDayHistory=loadDayHistory;

function toggleDhDetail(id,btn){ const el=document.getElementById(id); if(!el)return; el.classList.toggle('open'); btn.textContent=el.classList.contains('open')?'▲ Скрий детайли':'▼ Покажи детайли'; }
window.toggleDhDetail=toggleDhDetail;

/* ПОКУПКИ */
async function loadPurchases(page){
  const q=document.getElementById('purchSearch')?.value||'';
  const from=document.getElementById('purchFrom')?.value||'';
  const to=document.getElementById('purchTo')?.value||'';
  const res=await fetch(`${BASE}?ajax=purchases&loc=${purchLocId}&q=${encodeURIComponent(q)}&from=${from}&to=${to}&page=${page}`);
  const data=await res.json();
  const tbody=document.getElementById('purchBody');
  const sub=document.getElementById('purchasesSubtitle');
  if(sub)sub.textContent=`Намерени: ${data.total} покупки`;
  if(!data.rows.length){tbody.innerHTML='<tr><td colspan="7" class="empty">Няма резултати</td></tr>';return;}
  tbody.innerHTML=data.rows.map(r=>`<tr><td><span class="badge badge-gray">#${r.id}</span></td><td>${fmtDate(r.created_at)}</td><td>${esc(r.customer_name?.trim()||'—')}</td><td><code>${esc(r.card_number||'—')}</code></td><td><span class="badge badge-yellow">${esc(r.location_name||'—')}</span></td><td style="font-weight:800">${euroFmt(r.amount)}</td><td style="color:var(--red)">${euroFmt(r.discount_amount)}</td><td style="text-align:right;white-space:nowrap"><button class="btn btn-ghost" style="padding:4px 10px;font-size:12px" onclick="editPurchase(${r.id})" title="Редакция">✏️</button> <button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;color:var(--red)" onclick="deletePurchase(${r.id})" title="Изтрий">🗑️</button></td></tr>`).join('');
  renderPagination('purchPagination',data.page,data.pages,'loadPurchases');
}

/* КЛИЕНТИ */
async function loadCustomers(page){
  const q=document.getElementById('custSearch')?.value||'';
  const res=await fetch(`${BASE}?ajax=customers&q=${encodeURIComponent(q)}&page=${page}`);
  const data=await res.json();
  const tbody=document.getElementById('custBody');
  const sub=document.getElementById('custSubtitle');
  if(sub)sub.textContent=`Намерени: ${data.total} клиента`;
  if(!data.rows.length){tbody.innerHTML='<tr><td colspan="8" class="empty">Няма резултати</td></tr>';return;}
  tbody.innerHTML=data.rows.map(r=>`<tr><td>${r.id}</td><td><code>${esc(r.card_number||'—')}</code></td><td style="font-weight:700">${esc((r.first_name+' '+r.last_name).trim()||'—')}</td><td>${esc(r.phone||'—')}</td><td style="text-align:center"><strong>${r.total_purchases}</strong></td><td style="font-weight:800">${euroFmt(r.total_spent)}</td><td>${r.suspicious>0?'<span class="badge badge-red">⚠ Подозрителен</span>':'<span class="badge badge-green">ОК</span>'}</td><td><button class="btn btn-ghost" style="padding:5px 10px;font-size:12px" onclick="showCustomer(${r.id})">Детайли</button></td></tr>`).join('');
  renderPagination('custPagination',data.page,data.pages,'loadCustomers');
}

async function showCustomer(id){
  document.getElementById('custModal').classList.add('show');
  document.getElementById('custModalBody').innerHTML='<div class="loading">Зареждане...</div>';
  const res=await fetch(`${BASE}?ajax=customer_detail&id=${id}`);
  const data=await res.json();
  if(!data.ok){document.getElementById('custModalBody').innerHTML='<div class="empty">Грешка</div>';return;}
  const c=data.customer;
  document.getElementById('custModalTitle').textContent=(c.first_name+' '+c.last_name).trim()||'Клиент #'+id;
  const vHtml=data.vouchers.length?data.vouchers.map(v=>`<tr><td><code>${esc(v.code)}</code></td><td>${esc(v.voucher_type)}</td><td>${v.percent_value}${v.voucher_type==='percent'?'%':'€'}</td><td>${v.used?'<span class="badge badge-gray">Използван</span>':'<span class="badge badge-green">Активен</span>'}</td></tr>`).join(''):'<tr><td colspan="4" class="empty">Няма ваучери</td></tr>';
  const pHtml=data.purchases.slice(0,10).map(p=>`<tr><td>${fmtDate(p.created_at)}</td><td style="font-weight:800">${euroFmt(p.amount)}</td><td style="color:var(--red)">${euroFmt(p.discount_amount)}</td><td>${esc(p.location_name||'—')}</td></tr>`).join('');
  document.getElementById('custModalBody').innerHTML=`<div class="detail-grid"><div class="detail-box"><div class="detail-label">Карта</div><div class="detail-value" style="font-size:15px;letter-spacing:1px">${esc(c.card_number||'—')}</div></div><div class="detail-box"><div class="detail-label">Телефон</div><div class="detail-value" style="font-size:16px">${esc(c.phone||'—')}</div></div><div class="detail-box"><div class="detail-label">Общо покупки</div><div class="detail-value">${c.total_purchases}</div></div><div class="detail-box"><div class="detail-label">Общ оборот</div><div class="detail-value" style="font-size:20px">${euroFmt(c.total_spent)}</div></div><div class="detail-box"><div class="detail-label">Цикъл 100€</div><div class="detail-value" style="font-size:18px">${euroFmt(c.cycle_spent_100)} / 100€</div></div><div class="detail-box"><div class="detail-label">Покупки (10/50/100)</div><div class="detail-value" style="font-size:14px">${c.cycle_purchases_10} / ${c.cycle_purchases_50} / ${c.cycle_purchases_100}</div></div></div><div class="card-title" style="margin-top:16px">Ваучери</div><div class="table-wrap" style="margin-bottom:16px"><table><thead><tr><th>Код</th><th>Тип</th><th>Стойност</th><th>Статус</th></tr></thead><tbody>${vHtml}</tbody></table></div><div class="card-title">Последни покупки</div><div class="table-wrap"><table><thead><tr><th>Дата</th><th>Сума</th><th>Отстъпка</th><th>Обект</th></tr></thead><tbody>${pHtml}</tbody></table></div>`;
}

/* PUSH */
async function sendPush(single){
  const title=document.getElementById(single?'pushTitleOne':'pushTitleAll').value.trim();
  const body=document.getElementById(single?'pushBodyOne':'pushBodyAll').value.trim();
  const url=document.getElementById('pushUrlAll')?.value.trim()||'/loyalty/';
  const card=single?document.getElementById('pushCard').value.trim():'';
  const resEl=document.getElementById(single?'pushOneResult':'pushAllResult');
  if(!title||!body){resEl.textContent='⚠ Попълни заглавие и текст';resEl.style.color='var(--red)';return;}
  resEl.textContent='Изпращане...';resEl.style.color='var(--text2)';
  const res=await fetch(`${BASE}?ajax=push_send`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({title,body,url,card})});
  const data=await res.json();
  if(data.ok){resEl.textContent=`✅ Изпратено: ${data.sent} / ${data.total} (грешки: ${data.failed})`;resEl.style.color='var(--green)';}
  else{resEl.textContent='❌ '+data.error;resEl.style.color='var(--red)';}
}

/* ══ БАНЕРИ + КАЧВАНЕ НА СНИМКИ ══ */
async function loadBanners(){
  const res=await fetch(`${BASE}?ajax=banners_list&_ts=${Date.now()}`,{cache:'no-store'});
  const data=await res.json();
  const tbody=document.getElementById('bannersBody');
  if(!data.ok||!data.rows.length){tbody.innerHTML='<tr><td colspan="6" class="empty">Няма банери</td></tr>';return;}
  tbody.innerHTML=data.rows.map(b=>`<tr>
    <td>${b.id}</td>
    <td>${b.image_url?`<img src="${esc(b.image_url)}" style="height:44px;width:70px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">`:'-'}</td>
    <td style="font-weight:700">${esc(b.title)}</td>
    <td>${b.sort_order}</td>
    <td>${b.active?'<span class="badge badge-green">Активен</span>':'<span class="badge badge-gray">Неактивен</span>'}</td>
    <td style="display:flex;gap:6px;padding-top:8px">
      <button class="btn btn-ghost" style="font-size:12px;padding:5px 10px" onclick='openBannerModal(${JSON.stringify(b)})'>Редактирай</button>
      <button class="btn btn-red" style="font-size:12px;padding:5px 10px" onclick="deleteBanner(${b.id})">Изтрий</button>
    </td>
  </tr>`).join('');
}

function showImgPreview(url){
  const preview=document.getElementById('imgPreview');
  const img=document.getElementById('imgPreviewImg');
  const urlEl=document.getElementById('imgPreviewUrl');
  if(url){
    img.src=url; urlEl.textContent=url;
    preview.style.display='block';
  } else {
    preview.style.display='none';
  }
}

function onImageUrlInput(val){
  showImgPreview(val);
}

async function handleImageUpload(input){
  const file=input.files[0];
  if(!file) return;
  if(file.size > 5*1024*1024){ alert('Снимката е твърде голяма. Максимум 5MB.'); return; }

  const statusEl=document.getElementById('bUploadStatus');
  statusEl.textContent='⏳ Качване...';
  statusEl.style.color='var(--text2)';

  const formData=new FormData();
  formData.append('image', file);

  try {
    const res=await fetch(`${BASE}?ajax=banner_upload`, {method:'POST', body:formData});
    const data=await res.json();
    if(data.ok){
      document.getElementById('bImage').value=data.url;
      showImgPreview(data.url);
      statusEl.textContent='✅ Снимката е качена успешно!';
      statusEl.style.color='var(--green)';
    } else {
      statusEl.textContent='❌ '+data.error;
      statusEl.style.color='var(--red)';
    }
  } catch(e){
    statusEl.textContent='❌ Грешка при качване';
    statusEl.style.color='var(--red)';
  }
}

function openBannerModal(b){
  document.getElementById('bannerModal').classList.add('show');
  document.getElementById('bannerModalTitle').textContent=b?'Редактирай банер':'Нов банер';
  document.getElementById('bId').value=b?.id||0;
  document.getElementById('bTitle').value=b?.title||'';
  document.getElementById('bBody').value=b?.body||'';
  document.getElementById('bImage').value=b?.image_url||'';
  document.getElementById('bLink').value=b?.link_url||'';
  document.getElementById('bColor').value=b?.bg_color||'#fff8e1';
  document.getElementById('bSort').value=b?.sort_order||0;
  document.getElementById('bActive').checked=b?!!parseInt(b.active):true;
  document.getElementById('bUploadStatus').textContent='';
  document.getElementById('bImageFile').value='';
  showImgPreview(b?.image_url||'');
}

async function saveBanner(){
  const d={
    id:parseInt(document.getElementById('bId').value)||0,
    title:document.getElementById('bTitle').value.trim(),
    body:document.getElementById('bBody').value.trim(),
    image_url:document.getElementById('bImage').value.trim(),
    link_url:document.getElementById('bLink').value.trim(),
    bg_color:document.getElementById('bColor').value,
    sort_order:parseInt(document.getElementById('bSort').value)||0,
    active:document.getElementById('bActive').checked?1:0
  };
  if(!d.title){alert('Въведи заглавие');return;}
  const res=await fetch(`${BASE}?ajax=banner_save`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});
  const data=await res.json();
  if(data.ok){closeModal('bannerModal');loadBanners();}else alert(data.error);
}

async function deleteBanner(id){
  if(!confirm('Изтриване на банера?'))return;
  await fetch(`${BASE}?ajax=banner_delete`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
  loadBanners();
}

/* ОДИТ ЛОГ */
async function loadAudit(page){
  const type=document.getElementById('auditType')?.value||'';
  const res=await fetch(`${BASE}?ajax=audit&type=${encodeURIComponent(type)}&page=${page}`);
  const data=await res.json();
  const tbody=document.getElementById('auditBody');
  const sub=document.getElementById('auditSubtitle');
  if(sub)sub.textContent=`Общо: ${data.total} записа`;
  const typeColors={'PURCHASE_OK':'badge-green','SUSPICIOUS_ACTIVITY':'badge-red','BLOCKED_INTERVAL':'badge-red','QR_CARD_MISMATCH':'badge-red'};
  if(!data.rows.length){tbody.innerHTML='<tr><td colspan="6" class="empty">Няма записи</td></tr>';return;}
  tbody.innerHTML=data.rows.map(r=>`<tr><td>${r.id}</td><td style="white-space:nowrap">${fmtDate(r.created_at)}</td><td><code>${esc(r.card_number)}</code></td><td><span class="badge ${typeColors[r.event_type]||'badge-gray'}">${esc(r.event_type)}</span></td><td style="font-size:12px;color:var(--text2)">${esc(r.ip_address||'—')}</td><td style="font-size:11px;color:var(--text2);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(r.event_data||'—')}</td></tr>`).join('');
  renderPagination('auditPagination',data.page,data.pages,'loadAudit');
}

/* ОБЕКТИ */
async function loadLocations(){
  const res=await fetch(`${BASE}?ajax=locations`);
  const data=await res.json();
  const card=document.getElementById('locationsCard');
  if(!data.ok||!data.rows.length){card.innerHTML='<div class="empty">Няма обекти</div>';return;}
  const baseUrl=window.location.origin+window.location.pathname.replace('papu4koo82.php','scan.php');
  card.innerHTML=`<div class="table-wrap"><table><thead><tr><th>#</th><th>Обект</th><th>URL за служебния телефон</th></tr></thead><tbody>${data.rows.map(l=>`<tr><td>${l.id}</td><td style="font-weight:800;font-size:15px">${esc(l.name)}</td><td><code style="background:#f3f4f6;padding:6px 10px;border-radius:6px;font-size:13px;display:inline-block;word-break:break-all">${esc(baseUrl)}?location=${l.id}</code><button class="btn btn-ghost" style="margin-left:8px;padding:5px 10px;font-size:12px" onclick="navigator.clipboard.writeText('${baseUrl}?location=${l.id}');this.textContent='✓ Копирано!'">Копирай</button></td></tr>`).join('')}</tbody></table></div><p style="margin-top:14px;font-size:13px;color:var(--text2);font-weight:600">💡 Запиши всеки URL като отметка на съответния служебен телефон.</p>`;
}

/* INIT */
initDateDefaults();
dhCurrentDate = CURRENT_BIZ_DATE;
/* ═══════════ EDIT/DELETE PURCHASE ═══════════ */
let currentEditId = null;

async function editPurchase(id){
  currentEditId = id;
  const res = await fetch(`${BASE}?ajax=purchase_get&id=${id}`);
  const d = await res.json();
  if(!d.ok){ alert('Грешка: '+(d.error||'неизвестна')); return; }
  const r = d.row;

  document.getElementById('ep_id_display').textContent = '#' + r.id;
  document.getElementById('ep_customer_display').textContent = (r.customer_name || '— без карта —') + (r.card_number ? ' · ' + r.card_number : '');
  document.getElementById('ep_date_display').textContent = fmtDate(r.created_at);
  document.getElementById('ep_amount').value = r.amount || '0';
  document.getElementById('ep_discount').value = r.discount_amount || '0';
  document.getElementById('ep_payment').value = r.payment_method || 'cash';
  document.getElementById('ep_given').value = r.given_amount || '';
  document.getElementById('ep_change').value = r.change_amount || '';

  /* Locations dropdown */
  const sel = document.getElementById('ep_location');
  sel.innerHTML = '<option value="">— без обект —</option>';
  if (typeof locations !== 'undefined' && locations.length) {
    locations.forEach(l => {
      const o = document.createElement('option');
      o.value = l.id; o.textContent = l.name;
      if (String(l.id) === String(r.location_id)) o.selected = true;
      sel.appendChild(o);
    });
  }

  document.getElementById('editPurchaseModal').classList.add('show');
}

async function savePurchaseEdit(){
  if (!currentEditId) return;
  const payload = {
    id: currentEditId,
    amount: parseFloat(document.getElementById('ep_amount').value) || 0,
    discount_amount: parseFloat(document.getElementById('ep_discount').value) || 0,
    location_id: parseInt(document.getElementById('ep_location').value) || null,
    payment_method: document.getElementById('ep_payment').value || 'cash',
    given_amount: document.getElementById('ep_given').value || null,
    change_amount: document.getElementById('ep_change').value || null,
  };
  if (payload.amount < 0) { alert('Сумата трябва да е >= 0'); return; }

  const res = await fetch(`${BASE}?ajax=purchase_update`, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });
  const d = await res.json();
  if(!d.ok){ alert('Грешка: '+(d.error||'неизвестна')); return; }

  closeModal('editPurchaseModal');
  currentEditId = null;
  loadPurchases(1);
}

async function deletePurchase(id){
  if (!confirm('Сигурен ли си че искаш да изтриеш продажба #' + id + '?\n\nТова е soft delete — може да се възстанови от Одит лога.')) return;

  const res = await fetch(`${BASE}?ajax=purchase_delete`, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id: id})
  });
  const d = await res.json();
  if(!d.ok){ alert('Грешка: '+(d.error||'неизвестна')); return; }

  loadPurchases(1);
}

/* Auto-compute change when given/amount changes in edit modal */
['ep_given','ep_amount'].forEach(id => {
  document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', () => {
      const given  = parseFloat(document.getElementById('ep_given').value) || 0;
      const amount = parseFloat(document.getElementById('ep_amount').value) || 0;
      if (given > 0 && amount > 0 && given >= amount) {
        document.getElementById('ep_change').value = (given - amount).toFixed(2);
      }
    });
  });
});

/* ═══════════ СТАТИСТИКИ ═══════════ */
let statsPeriod = '30';
let _lastStats = null;
function recalcProfit(){
  if(!_lastStats) return;
  const mInp = document.getElementById('statsMarkup');
  let m = parseFloat(mInp ? mInp.value : NaN);
  if(isNaN(m) || m < 0) m = 53.5;
  try { localStorage.setItem('stats_markup', String(m)); } catch(e){}
  const net   = Number((_lastStats.core && _lastStats.core.total) || 0);
  const gross = Number(_lastStats.gross || 0);
  const cost  = gross / (1 + m/100);
  const profit = net - cost;
  const margin = net > 0 ? (profit / net * 100) : 0;
  const pv = document.getElementById('statsProfitVal');
  const psub = document.getElementById('statsProfitSub');
  if(pv){ pv.textContent = euroFmt(profit); pv.style.color = profit >= 0 ? 'var(--green)' : 'var(--red)'; }
  if(psub) psub.textContent = 'Марж ' + margin.toFixed(1) + '% от оборота · себестойност ~' + euroFmt(cost);
}
window.recalcProfit = recalcProfit;
let statsDayChart = null, statsDowChart = null, statsHourChart = null;

document.querySelectorAll('.stat-period').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.stat-period').forEach(b => { b.classList.remove('btn-yellow','active'); b.classList.add('btn-ghost'); });
    btn.classList.remove('btn-ghost'); btn.classList.add('btn-yellow','active');
    statsPeriod = btn.dataset.p;
    loadStats();
  });
});

async function loadStats(){
  const label = document.getElementById('statPeriodLabel');
  if(label) label.textContent = 'Зареждане...';
  const res = await fetch(`${BASE}?ajax=stats_all&period=${statsPeriod}&loc=${dashLocId}`);
  if(res.status===401){
    showAuthExpired();
    document.getElementById('statsMoney').innerHTML = '<div class="empty">Сесията изтече — влез отново.</div>';
    return;
  }
  const d = await res.json();
  if(!d.ok){
    document.getElementById('statsMoney').innerHTML = `<div class="empty">Грешка: ${esc(d.error||'')}</div>`;
    return;
  }
  if(label) label.textContent = `${d.date_from.slice(0,10)} → ${d.date_to.slice(0,10)} · ${d.days} дни`;

  /* Приблизителна печалба — запомни данните + init на наценката */
  _lastStats = d;
  const _mInp = document.getElementById('statsMarkup');
  if(_mInp){
    const _saved = localStorage.getItem('stats_markup');
    if(_saved) _mInp.value = _saved;
    _mInp.oninput = recalcProfit;
  }
  recalcProfit();

  const c = d.core;
  const cu = d.customers;
  const v = d.vouchers;
  const ca = d.cards;

  /* MONEY: 8 карти */
  document.getElementById('statsMoney').innerHTML = `
    <div class="stat-box yellow"><div class="sb-label">Оборот</div><div class="sb-value">${euroFmt(c.total)}</div><div class="sb-sub">Net (след отстъпки)</div></div>
    <div class="stat-box"><div class="sb-label">Брутно</div><div class="sb-value">${euroFmt(d.gross)}</div><div class="sb-sub">Преди отстъпки</div></div>
    <div class="stat-box red"><div class="sb-label">Отстъпки</div><div class="sb-value">${euroFmt(c.disc)}</div><div class="sb-sub">${d.disc_pct}% от брутното</div></div>
    <div class="stat-box"><div class="sb-label">Покупки</div><div class="sb-value">${c.cnt}</div><div class="sb-sub">${d.avg_per_day}/ден средно</div></div>
    <div class="stat-box green"><div class="sb-label">Средна покупка</div><div class="sb-value">${euroFmt(c.avg_amt)}</div><div class="sb-sub">Медиана: ${euroFmt(d.median)}</div></div>
    <div class="stat-box"><div class="sb-label">Най-голяма</div><div class="sb-value">${euroFmt(c.max_amt)}</div><div class="sb-sub">${d.biggest?.name||'—'}</div></div>
    <div class="stat-box"><div class="sb-label">Най-малка</div><div class="sb-value">${euroFmt(c.min_amt)}</div><div class="sb-sub">Без нулеви</div></div>
    <div class="stat-box blue"><div class="sb-label">Оборот/ден</div><div class="sb-value">${euroFmt(d.avg_rev_day)}</div><div class="sb-sub">За периода</div></div>
  `;

  /* CUSTOMERS: 8 карти */
  const onePct = cu.with_purchase > 0 ? Math.round(cu.one_purchase/cu.with_purchase*100) : 0;
  const loyalPct = cu.with_purchase > 0 ? Math.round(cu.loyal_10/cu.with_purchase*100) : 0;
  document.getElementById('statsCustomers').innerHTML = `
    <div class="stat-box"><div class="sb-label">Общо клиенти</div><div class="sb-value">${cu.total}</div><div class="sb-sub">${cu.with_purchase} с покупка</div></div>
    <div class="stat-box green"><div class="sb-label">Нови (период)</div><div class="sb-value">${cu.new}</div><div class="sb-sub">Регистрирани в периода</div></div>
    <div class="stat-box red"><div class="sb-label">Спящи &gt;30д</div><div class="sb-value">${cu.dormant_30}</div><div class="sb-sub">${cu.dormant_60} са &gt;60д</div></div>
    <div class="stat-box yellow"><div class="sb-label">🎂 Рожденици</div><div class="sb-value">${cu.birthdays}</div><div class="sb-sub">${cu.birthdays_week} в следващите 7д</div></div>
    <div class="stat-box"><div class="sb-label">С 1 покупка</div><div class="sb-value">${cu.one_purchase}</div><div class="sb-sub">${onePct}% (churn риск)</div></div>
    <div class="stat-box green"><div class="sb-label">Лоялни (10+)</div><div class="sb-value">${cu.loyal_10}</div><div class="sb-sub">${loyalPct}% от активните</div></div>
    <div class="stat-box"><div class="sb-label">Супер-лоялни (50+)</div><div class="sb-value">${cu.loyal_50}</div><div class="sb-sub">Най-ценни</div></div>
    <div class="stat-box red"><div class="sb-label">Подозрителни</div><div class="sb-value">${cu.suspicious}</div><div class="sb-sub">Маркирани ръчно</div></div>
  `;

  /* VOUCHERS + CARDS: 6 карти */
  document.getElementById('statsVouchers').innerHTML = `
    <div class="stat-box"><div class="sb-label">Издадени ваучери</div><div class="sb-value">${v.total}</div><div class="sb-sub">От старта</div></div>
    <div class="stat-box green"><div class="sb-label">Използвани</div><div class="sb-value">${v.used}</div><div class="sb-sub">${v.redemption_pct}% redemption</div></div>
    <div class="stat-box yellow"><div class="sb-label">Активни</div><div class="sb-value">${v.active}</div><div class="sb-sub">Издадени, неизползвани</div></div>
    <div class="stat-box red"><div class="sb-label">Дадена стойност</div><div class="sb-value">${euroFmt(v.value)}</div><div class="sb-sub">Отстъпки от ваучери</div></div>
    <div class="stat-box"><div class="sb-label">Общо карти</div><div class="sb-value">${ca.total}</div><div class="sb-sub">Регистрирани клиенти</div></div>
    <div class="stat-box green"><div class="sb-label">Активни карти</div><div class="sb-value">${ca.active}</div><div class="sb-sub">${ca.activation_pct}% активация (90д)</div></div>
  `;

  /* CHART: По ден (line) */
  const days = d.by_day || [];
  const dayLabels = days.map(x => x.d.slice(5));
  const dayValues = days.map(x => Number(x.total));
  if(statsDayChart) statsDayChart.destroy();
  statsDayChart = new Chart(document.getElementById('statsDayChart'), {
    type:'line',
    data:{labels:dayLabels, datasets:[{label:'Оборот (€)', data:dayValues, borderColor:'#00f0ff', backgroundColor:'rgba(0,240,255,.18)', borderWidth:2, tension:.3, fill:true, pointRadius:3}]},
    options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, ticks:{color:'rgba(255,255,255,.55)', callback:v=>v+'€'}, grid:{color:'rgba(255,255,255,.06)'}}, x:{ticks:{color:'rgba(255,255,255,.55)'}, grid:{color:'rgba(255,255,255,.06)'}}}}
  });

  /* CHART: По ден от седмицата (bar) */
  const dowNames = ['Нд','Пн','Вт','Ср','Чт','Пт','Сб'];
  const dowData = Array(7).fill(0);
  (d.by_dow||[]).forEach(x => { dowData[x.dow-1] = Number(x.total); });
  // MySQL DAYOFWEEK: 1=Sun, 2=Mon, ..., 7=Sat — пренареждам за Пн-Нд вид:
  const dowLabels = ['Пн','Вт','Ср','Чт','Пт','Сб','Нд'];
  const dowReordered = [dowData[1], dowData[2], dowData[3], dowData[4], dowData[5], dowData[6], dowData[0]];
  if(statsDowChart) statsDowChart.destroy();
  statsDowChart = new Chart(document.getElementById('statsDowChart'), {
    type:'bar',
    data:{labels:dowLabels, datasets:[{label:'€', data:dowReordered, backgroundColor:'rgba(168,85,247,.65)', borderColor:'#a855f7', borderWidth:2, borderRadius:6}]},
    options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, ticks:{color:'rgba(255,255,255,.55)', callback:v=>v+'€'}, grid:{color:'rgba(255,255,255,.06)'}}, x:{ticks:{color:'rgba(255,255,255,.55)'}, grid:{color:'rgba(255,255,255,.06)'}}}}
  });

  /* CHART: По час (bar) */
  const hourData = Array(24).fill(0);
  (d.by_hour||[]).forEach(x => { hourData[x.h] = Number(x.total); });
  const hourLabels = Array.from({length:24}, (_,i) => i+'ч');
  if(statsHourChart) statsHourChart.destroy();
  statsHourChart = new Chart(document.getElementById('statsHourChart'), {
    type:'bar',
    data:{labels:hourLabels, datasets:[{label:'€', data:hourData, backgroundColor:'rgba(34,238,136,.65)', borderColor:'#22ee88', borderWidth:2, borderRadius:4}]},
    options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, ticks:{color:'rgba(255,255,255,.55)', callback:v=>v+'€'}, grid:{color:'rgba(255,255,255,.06)'}}, x:{ticks:{color:'rgba(255,255,255,.55)'}, grid:{color:'rgba(255,255,255,.06)'}}}}
  });

  /* Обекти breakdown */
  const locRows = d.by_loc || [];
  const totalSum = locRows.reduce((a,b) => a + Number(b.total), 0);
  let locHtml = '<div class="table-wrap"><table><thead><tr><th>#</th><th>Обект</th><th>Покупки</th><th>Оборот</th><th>Средно</th><th>Отстъпка</th><th>% от общото</th></tr></thead><tbody>';
  locRows.forEach((l,i) => {
    const pct = totalSum > 0 ? ((Number(l.total)/totalSum)*100).toFixed(1) : '0.0';
    locHtml += `<tr><td><span class="badge badge-gray">#${i+1}</span></td><td><strong>${esc(l.location_name)}</strong></td><td>${l.cnt}</td><td style="font-weight:800">${euroFmt(l.total)}</td><td>${euroFmt(l.avg_amt)}</td><td style="color:var(--red)">${euroFmt(l.disc||0)}</td><td>${pct}%</td></tr>`;
  });
  if(locRows.length === 0) locHtml += '<tr><td colspan="7" class="empty">Няма данни</td></tr>';
  locHtml += '</tbody></table></div>';
  document.getElementById('statsLocCard').innerHTML = locHtml;

  /* Класация: регистрации по магазин */
  const regRows = d.reg_by_loc || [];
  const regTotal = regRows.reduce((a,b)=>a+Number(b.cnt),0);
  let regHtml = '<div class="table-wrap"><table><thead><tr><th>#</th><th>Магазин</th><th>Регистрации</th><th>% от общото</th></tr></thead><tbody>';
  regRows.forEach((r,i)=>{
    const pct = regTotal>0 ? ((Number(r.cnt)/regTotal)*100).toFixed(1) : '0.0';
    const medal = i===0?'🥇 ':i===1?'🥈 ':i===2?'🥉 ':'';
    regHtml += `<tr><td><span class="badge badge-gray">#${i+1}</span></td><td><strong>${medal}${esc(r.loc)}</strong></td><td style="font-weight:800">${r.cnt}</td><td>${pct}%</td></tr>`;
  });
  if(regRows.length===0) regHtml += '<tr><td colspan="4" class="empty">Няма регистрации за периода</td></tr>';
  regHtml += '</tbody></table></div>';
  const _regEl = document.getElementById('statsRegByLoc');
  if(_regEl) _regEl.innerHTML = regHtml;

  /* 📈 Ръст спрямо предходния период */
  const g = d.growth || {};
  const growthPct = (cur, prev) => {
    if(!prev || prev === 0) return cur > 0 ? {txt:'нов', cls:'green'} : {txt:'—', cls:''};
    const p = ((cur - prev) / prev) * 100;
    return {txt:(p>=0?'+':'')+p.toFixed(1)+'%', cls:p>=0?'green':'red'};
  };
  const gT = growthPct(Number(g.cur_total||0), Number(g.prev_total||0));
  const gC = growthPct(Number(g.cur_cnt||0),   Number(g.prev_cnt||0));
  const gR = growthPct(Number(g.cur_reg||0),   Number(g.prev_reg||0));
  document.getElementById('statsGrowth').innerHTML = `
    <div class="stat-box ${gT.cls}"><div class="sb-label">Оборот</div><div class="sb-value">${gT.txt}</div><div class="sb-sub">${euroFmt(g.cur_total)} срещу ${euroFmt(g.prev_total)}</div></div>
    <div class="stat-box ${gC.cls}"><div class="sb-label">Покупки</div><div class="sb-value">${gC.txt}</div><div class="sb-sub">${g.cur_cnt||0} срещу ${g.prev_cnt||0}</div></div>
    <div class="stat-box ${gR.cls}"><div class="sb-label">Регистрации</div><div class="sb-value">${gR.txt}</div><div class="sb-sub">${g.cur_reg||0} срещу ${g.prev_reg||0}</div></div>
  `;

  /* 💸 Отстъпки в дълбочина */
  const dd = d.discount_deep || {};
  document.getElementById('statsDiscount').innerHTML = `
    <div class="stat-box red"><div class="sb-label">Средна отстъпка / продажба</div><div class="sb-value">${euroFmt(dd.avg_per_sale)}</div><div class="sb-sub">Върху всички продажби</div></div>
    <div class="stat-box yellow"><div class="sb-label">Продажби с отстъпка</div><div class="sb-value">${dd.pct_sales_disc||0}%</div><div class="sb-sub">${dd.sales_with_disc||0} продажби</div></div>
    <div class="stat-box red"><div class="sb-label">Средно (само с отстъпка)</div><div class="sb-value">${euroFmt(dd.avg_on_discounted)}</div><div class="sb-sub">Без нулевите</div></div>
    <div class="stat-box"><div class="sb-label">Обща отстъпка</div><div class="sb-value">${euroFmt(c.disc)}</div><div class="sb-sub">${d.disc_pct}% от брутното</div></div>
  `;
  /* С карта vs без карта */
  const bc = d.by_card || [];
  const cardRow = bc.find(x=>Number(x.has_card)===1) || {cnt:0,net:0,disc:0,avg_amt:0};
  const noCardRow = bc.find(x=>Number(x.has_card)===0) || {cnt:0,net:0,disc:0,avg_amt:0};
  const cardDiscPer = cardRow.cnt>0 ? (cardRow.disc/cardRow.cnt) : 0;
  const noCardDiscPer = noCardRow.cnt>0 ? (noCardRow.disc/noCardRow.cnt) : 0;
  document.getElementById('statsByCard').innerHTML = `
    <div class="card-title" style="font-size:13px;margin-bottom:8px">С лоялна карта vs без карта</div>
    <div class="table-wrap"><table>
      <thead><tr><th></th><th>Продажби</th><th>Оборот</th><th>Средна покупка</th><th>Отстъпка/прод.</th></tr></thead>
      <tbody>
        <tr><td><strong>💳 С карта</strong></td><td>${cardRow.cnt}</td><td style="font-weight:800">${euroFmt(cardRow.net)}</td><td>${euroFmt(cardRow.avg_amt)}</td><td style="color:var(--red)">${euroFmt(cardDiscPer)}</td></tr>
        <tr><td><strong>🚶 Без карта</strong></td><td>${noCardRow.cnt}</td><td style="font-weight:800">${euroFmt(noCardRow.net)}</td><td>${euroFmt(noCardRow.avg_amt)}</td><td style="color:var(--red)">${euroFmt(noCardDiscPer)}</td></tr>
      </tbody>
    </table></div>`;

  /* 🛒 Кошница + топ артикули */
  const bk = d.basket || {};
  document.getElementById('statsBasket').innerHTML = `
    <div class="stat-box blue"><div class="sb-label">Средно артикули / продажба</div><div class="sb-value">${bk.avg_qty||0}</div><div class="sb-sub">Брой бройки в кошницата</div></div>
    <div class="stat-box"><div class="sb-label">Различни артикули / продажба</div><div class="sb-value">${bk.avg_lines||0}</div><div class="sb-sub">Отделни редове</div></div>
    <div class="stat-box green"><div class="sb-label">Продажби с артикули</div><div class="sb-value">${bk.sales||0}</div><div class="sb-sub">С въведени артикули</div></div>
  `;
  const prodRow = (p,i) => `<tr><td>#${i+1}</td><td><strong>${esc(p.code||'—')}</strong>${p.brand?'<div style="font-size:11px;color:var(--text3)">'+esc(p.brand)+'</div>':''}</td><td style="font-weight:800">${p.qty}</td><td>${euroFmt(p.rev)}</td></tr>`;
  const fillTbl = (id, rows, fn) => { const el=document.getElementById(id); if(el) el.innerHTML = (rows&&rows.length)? rows.map(fn).join('') : '<tr><td colspan="4" class="empty">Няма данни</td></tr>'; };
  fillTbl('statsTopProdQty', d.top_products_qty, prodRow);
  fillTbl('statsTopProdRev', d.top_products_rev, prodRow);
  fillTbl('statsTopBrands', d.top_brands, (b,i)=>`<tr><td>#${i+1}</td><td><strong>${esc(b.brand||'—')}</strong></td><td style="font-weight:800">${b.qty}</td><td>${euroFmt(b.rev)}</td></tr>`);

  /* Top 10 by spend */
  const tSpend = d.top_spend || [];
  document.getElementById('statsTopSpend').innerHTML = tSpend.length === 0
    ? '<tr><td colspan="4" class="empty">Няма данни</td></tr>'
    : tSpend.map((c,i) => `<tr><td>#${i+1}</td><td><strong>${esc((c.first_name||'')+' '+(c.last_name||''))}</strong><div style="font-size:11px;color:var(--text3)">${esc(c.card_number||'—')}</div></td><td>${c.total_purchases}</td><td style="font-weight:800">${euroFmt(c.total_spent)}</td></tr>`).join('');

  /* Top 10 by count */
  const tCount = d.top_count || [];
  document.getElementById('statsTopCount').innerHTML = tCount.length === 0
    ? '<tr><td colspan="4" class="empty">Няма данни</td></tr>'
    : tCount.map((c,i) => `<tr><td>#${i+1}</td><td><strong>${esc((c.first_name||'')+' '+(c.last_name||''))}</strong><div style="font-size:11px;color:var(--text3)">${esc(c.card_number||'—')}</div></td><td style="font-weight:800">${c.total_purchases}</td><td>${euroFmt(c.total_spent)}</td></tr>`).join('');

  /* Най-голяма продажба */
  if(d.biggest && Number(d.biggest.amount) > 0){
    const b = d.biggest;
    const bc = document.getElementById('statsBiggestCard');
    bc.style.display = 'block';
    bc.innerHTML = `<div class="card-title" style="font-size:13px">🏆 Рекордна продажба (в периода)</div>
      <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center">
        <div><div class="sb-label">Сума</div><div style="font-size:28px;font-weight:900;color:var(--yellow)">${euroFmt(b.amount)}</div></div>
        <div><div class="sb-label">Клиент</div><div style="font-size:16px;font-weight:700">${esc(b.name||'—')}</div></div>
        <div><div class="sb-label">Обект</div><div>${esc(b.location_name||'—')}</div></div>
        <div><div class="sb-label">Дата</div><div>${fmtDate(b.created_at)}</div></div>
      </div>`;
  }
}

/* ═══════════ СКЛАДОВО САЛДО ═══════════ */
let invLocId = 0;
function buildInvLocTabs(){
  const t=document.getElementById('invLocTabs'); if(!t) return;
  let h='<div class="loc-tab'+(invLocId===0?' active':'')+'" onclick="setInvLoc(0,this)">Всички</div>';
  (locations||[]).forEach(l=>{ h+='<div class="loc-tab'+(invLocId===l.id?' active':'')+'" onclick="setInvLoc('+l.id+',this)">'+esc(l.name)+'</div>'; });
  t.innerHTML=h;
}
function setInvLoc(id,el){ invLocId=id; document.querySelectorAll('#invLocTabs .loc-tab').forEach(x=>x.classList.remove('active')); if(el) el.classList.add('active'); loadInventory(); }
window.setInvLoc=setInvLoc;

function invF(id){ const e=document.getElementById(id); return e?(parseFloat(e.value)||0):0; }
function invRecalc(){
  const closing = invF('invOpening')+invF('invReceived')+invF('invMarkup')+invF('invTransferIn')
                - invF('invNet')-invF('invDisc')-invF('invMarkdown')-invF('invTransferOut');
  const el=document.getElementById('invClosing'); if(el) el.textContent = closing.toFixed(2)+' €';
}
function invToggleManual(){
  const cb=document.getElementById('invOpeningManual'); const op=document.getElementById('invOpening');
  if(!cb||!op) return;
  if(cb.checked){ op.removeAttribute('readonly'); try{op.focus();}catch(e){} }
  else { op.setAttribute('readonly','readonly'); }
}
function invShowMsg(t, ok){ const e=document.getElementById('invMsg'); if(!e)return; e.textContent=t; e.style.color=ok?'#15803d':'#b91c1c'; e.style.fontWeight='700'; }
window.invRecalc=invRecalc; window.invToggleManual=invToggleManual;

async function loadInventory(){
  const m=document.getElementById('invMonth');
  if(m && !m.value){ m.value=new Date().toISOString().slice(0,7); }
  const period = m ? m.value : '';
  buildInvLocTabs();
  const body=document.getElementById('invBody');
  body.innerHTML='<div class="card"><div class="loading">Зареждане...</div></div>';
  try {
    const res=await fetch(`${BASE}?ajax=inv_get&loc=${invLocId}&period=${period}`);
    if(res.status===401){ showAuthExpired(); body.innerHTML='<div class="card"><div class="empty">Сесията изтече — влез отново.</div></div>'; return; }
    const d=await res.json();
    if(!d.ok){ body.innerHTML='<div class="card"><div class="empty">Грешка: '+esc(d.error||'')+'</div></div>'; return; }
    renderInventory(d);
  } catch(e){ body.innerHTML='<div class="card"><div class="empty">Грешка: '+esc(e.message)+'</div></div>'; }
}
window.loadInventory=loadInventory;

function renderInventory(d){
  const agg=!!d.aggregate;
  const mn=d.manual;
  const ro = agg ? 'readonly' : '';
  const noteV = esc(mn.note||'').replace(/"/g,'&quot;');
  const body=document.getElementById('invBody');
  body.innerHTML = `
   <div class="card inv-card">
     ${agg?'<div class="inv-note-agg">Сборно за всички обекти (само за четене). За въвеждане избери конкретен обект.</div>':''}
     <div class="inv-row"><span class="inv-lbl">Начално салдо ${d.opening_carried?'<small>(пренос от мин. месец)</small>':''}</span>
        <input class="inv-inp" inputmode="decimal" id="invOpening" value="${Number(mn.opening_balance).toFixed(2)}" oninput="invRecalc()" ${(agg||!mn.opening_manual)?'readonly':''}></div>
     ${agg?'':`<label class="inv-anchor"><input type="checkbox" id="invOpeningManual" ${mn.opening_manual?'checked':''} onchange="invToggleManual()"> Ръчно начално салдо (за първия месец / корекция)</label>`}
     <div class="inv-row plus"><span>＋ Получена стока</span><input class="inv-inp" inputmode="decimal" id="invReceived" value="${Number(mn.goods_received).toFixed(2)}" ${ro} oninput="invRecalc()"></div>
     <div class="inv-row plus"><span>＋ Увеличение на цени</span><input class="inv-inp" inputmode="decimal" id="invMarkup" value="${Number(mn.markup_total).toFixed(2)}" ${ro} oninput="invRecalc()"></div>
     <div class="inv-row plus"><span>＋ Трансфер вход</span><input class="inv-inp" inputmode="decimal" id="invTransferIn" value="${Number(mn.transfer_in).toFixed(2)}" ${ro} oninput="invRecalc()"></div>
     <div class="inv-row minus"><span>− Реален оборот <small>(авто)</small></span><input class="inv-inp" id="invNet" value="${Number(d.net).toFixed(2)}" readonly></div>
     <div class="inv-row minus"><span>− Отстъпки <small>(авто)</small></span><input class="inv-inp" id="invDisc" value="${Number(d.disc).toFixed(2)}" readonly></div>
     <div class="inv-row minus"><span>− Намаление на цени</span><input class="inv-inp" inputmode="decimal" id="invMarkdown" value="${Number(mn.markdown_total).toFixed(2)}" ${ro} oninput="invRecalc()"></div>
     <div class="inv-row minus"><span>− Трансфер изход</span><input class="inv-inp" inputmode="decimal" id="invTransferOut" value="${Number(mn.transfer_out).toFixed(2)}" ${ro} oninput="invRecalc()"></div>
     <div class="inv-row"><span>Бележка</span><input class="inv-inp inv-note" type="text" id="invNote" value="${noteV}" ${agg?'readonly':''}></div>
     <div class="inv-closing"><span>Крайно салдо</span><b id="invClosing">${Number(d.closing).toFixed(2)} €</b></div>
     ${agg?'':'<button class="btn btn-yellow" id="invSaveBtn" style="width:100%;margin-top:12px" onclick="saveInventory()">Запази</button>'}
     <div id="invMsg" style="margin-top:8px"></div>
     <div class="inv-meta">Брутен оборот: ${Number(d.gross).toFixed(2)} € · ${d.cnt} продажби за месеца</div>
   </div>`;
  invRecalc();
}

async function saveInventory(){
  const m=document.getElementById('invMonth');
  if(invLocId<=0){ invShowMsg('Избери конкретен обект, за да запишеш.', false); return; }
  const cb=document.getElementById('invOpeningManual');
  const btn=document.getElementById('invSaveBtn'); if(btn){ btn.disabled=true; btn.textContent='Записване...'; }
  const payload={
    loc:invLocId, period:m.value,
    opening_manual: (cb&&cb.checked)?1:0,
    opening_balance: invF('invOpening'),
    goods_received: invF('invReceived'),
    markup_total: invF('invMarkup'),
    transfer_in: invF('invTransferIn'),
    markdown_total: invF('invMarkdown'),
    transfer_out: invF('invTransferOut'),
    note: (document.getElementById('invNote')||{}).value||''
  };
  try{
    const res=await fetch(`${BASE}?ajax=inv_save`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    if(res.status===401){ showAuthExpired(); return; }
    const d=await res.json();
    if(d.ok){ invShowMsg('Записано ✓ (салдото се пренесе към следващия месец)', true); loadInventory(); }
    else invShowMsg('Грешка: '+(d.error||''), false);
  }catch(e){ invShowMsg('Грешка: '+e.message, false); }
  finally{ if(btn){ btn.disabled=false; btn.textContent='Запази'; } }
}
window.saveInventory=saveInventory;

loadLocTabs().then(()=>{ loadDashboard(); buildDhDayTabs(); });
</script>
</body>
</html>