<?php
/*
 * card.php — Лоялна карта Ени Тихолов (Light Neon Glass редизайн)
 *
 * БД таблици (изпълни веднъж ако ги нямаш):
 *
 * CREATE TABLE IF NOT EXISTS push_subscriptions (
 *   id            INT AUTO_INCREMENT PRIMARY KEY,
 *   customer_id   INT NOT NULL,
 *   card_number   VARCHAR(64) NOT NULL,
 *   endpoint      TEXT NOT NULL,
 *   p256dh        TEXT NOT NULL,
 *   auth          TEXT NOT NULL,
 *   created_at    DATETIME NOT NULL,
 *   updated_at    DATETIME NOT NULL,
 *   UNIQUE KEY uq_endpoint (endpoint(255)),
 *   INDEX idx_customer (customer_id)
 * );
 *
 * CREATE TABLE IF NOT EXISTS banners (
 *   id          INT AUTO_INCREMENT PRIMARY KEY,
 *   title       VARCHAR(200) NOT NULL,
 *   body        TEXT,
 *   image_url   VARCHAR(500),
 *   link_url    VARCHAR(500),
 *   bg_color    VARCHAR(32) DEFAULT '#fff8e1',
 *   active      TINYINT(1) NOT NULL DEFAULT 1,
 *   sort_order  INT NOT NULL DEFAULT 0,
 *   starts_at   DATETIME,
 *   ends_at     DATETIME,
 *   created_at  DATETIME NOT NULL
 * );
 */

require_once __DIR__ . '/config.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

function h($str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
function euro_raw($n): string {
    return number_format((float)$n, 2, '.', '');
}
function euro_text($n): string {
    return euro_raw($n) . '€';
}
function jsonResponse(array $data): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function clamp_percent($value): float {
    return max(0, min(100, (float)$value));
}
function customerDisplayName(array $customer): string {
    $name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
    return $name !== '' ? $name : 'Лоялен клиент';
}

function findCustomerByCard(PDO $pdo, string $cardNumber): ?array {
    $stmt = $pdo->prepare("
        SELECT c.id AS customer_id, c.first_name, c.last_name, c.status,
               c.total_spent, c.total_purchases, c.last_percent_reward,
               c.cycle_spent_100, c.cycle_purchases_10, c.cycle_purchases_50,
               c.cycle_purchases_100, c.referred_by, lc.card_number
        FROM loyalty_cards lc
        INNER JOIN customers c ON c.id = lc.customer_id
        WHERE lc.card_number = :card_number LIMIT 1
    ");
    $stmt->execute(['card_number' => $cardNumber]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $row['customer_id']        = (int)$row['customer_id'];
    $row['total_spent']        = round((float)$row['total_spent'], 2);
    $row['total_purchases']    = (int)$row['total_purchases'];
    $row['last_percent_reward']= round((float)$row['last_percent_reward'], 2);
    $row['cycle_spent_100']    = round(max(0, (float)$row['cycle_spent_100']), 2);
    $row['cycle_purchases_10'] = max(0, (int)$row['cycle_purchases_10']);
    $row['cycle_purchases_50'] = max(0, (int)$row['cycle_purchases_50']);
    $row['cycle_purchases_100']= max(0, (int)$row['cycle_purchases_100']);
    return $row;
}

function loadUnusedVouchers(PDO $pdo, int $customerId): array {
    /* FIX_CARD_VOUCHER_MAPPING_v1 — пълен филтър + source + amount */
    $stmt = $pdo->prepare("
        SELECT id, code, voucher_type, percent_value, amount, min_spent, used, status, source, expires_at, created_at
        FROM vouchers WHERE customer_id = :customer_id
          AND (used IS NULL OR used = 0)
          AND (status IS NULL OR status = 'active')
          AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY created_at ASC, id ASC
    ");
    $stmt->execute(['customer_id' => $customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function detectVoucherKind(array $v): string {
    /* FIX_CARD_VOUCHER_MAPPING_v1 — чете source + amount правилно */
    $code   = strtoupper(trim((string)($v['code'] ?? '')));
    $type   = strtolower(trim((string)($v['voucher_type'] ?? '')));
    $source = strtolower(trim((string)($v['source'] ?? '')));
    $pct    = (float)($v['percent_value'] ?? 0);
    $amt    = (float)($v['amount'] ?? 0);

    /* 1) Source колона — най-надеждна */
    if ($source === 'welcome')   return 'welcome5';
    if ($source === 'birthday')  return 'birthday20';
    if ($source === 'turnover')  return 'turnover5';
    if ($source === 'milestone') {
        if ($amt >= 149.99 || $amt >= 100) return 'fixed150';
        if ($amt >= 49.99  || $amt >= 50)  return 'fixed50';
        if ($amt >= 4.99   || $amt >= 5)   return 'fixed5';
    }

    /* 2) Code prefix — fallback */
    if (strpos($code, 'WELCOME5') === 0) return 'welcome5';
    if (strpos($code, 'BD-')      === 0) return 'birthday20';
    if (strpos($code, 'PCT5')     === 0) return 'turnover5';
    if (strpos($code, 'EUR150')   === 0) return 'fixed150';
    if (strpos($code, 'EUR50')    === 0) return 'fixed50';
    if (strpos($code, 'EUR5')     === 0) return 'fixed5';

    /* 3) Type+value — последна линия защита */
    if ($type === 'percent' && abs($pct - 20) < 0.001) return 'birthday20';
    if ($type === 'percent' && abs($pct - 5)  < 0.001) return 'welcome5';
    if ($type === 'fixed') {
        if ($amt >= 149.99) return 'fixed150';
        if ($amt >= 49.99)  return 'fixed50';
        if ($amt >= 4.99)   return 'fixed5';
    }
    return 'unknown';
}

function voucherMeta(string $kind): ?array {
    switch ($kind) {
        case 'welcome5':   return ['key'=>'welcome',   'title'=>'Welcome -5%', 'label'=>'Welcome -5%', 'badge'=>'-5%', 'desc'=>'Еднократен бонус за първата покупка.',                            'type_group'=>'welcome',     'sort'=>10];
        case 'birthday20': return ['key'=>'birthday',  'title'=>'Рожден ден -20%','label'=>'Рожден ден -20%','badge'=>'-20%','desc'=>'Подарък за рождения ден — валиден 14 дни (7 преди + 7 след).',     'type_group'=>'welcome',     'sort'=>15]; /* FIX_CARD_VOUCHER_MAPPING_v1 */
        case 'turnover5': return ['key'=>'pct5',      'title'=>'5% бонус',   'label'=>'5% бонус',   'badge'=>'-5%', 'desc'=>'Активен 5% бонус след достигнат оборотен цикъл 100€.',            'type_group'=>'accumulated', 'sort'=>20];
        case 'fixed5':    return ['key'=>'reward_5',  'title'=>'5€ ваучер',  'label'=>'5€ ваучер',  'badge'=>'-5€', 'desc'=>'Награда за 10 покупки. Условие: всяка покупка трябва да е минимум 10€.',              'type_group'=>'accumulated', 'sort'=>30];
        case 'fixed50':   return ['key'=>'reward_50', 'title'=>'50€ ваучер', 'label'=>'50€ ваучер', 'badge'=>'-50€','desc'=>'Награда за 50 покупки. Условие: всяка покупка трябва да е минимум 10€.',             'type_group'=>'accumulated', 'sort'=>40];
        case 'fixed150':  return ['key'=>'reward_150','title'=>'150€ ваучер','label'=>'150€ ваучер','badge'=>'-150€','desc'=>'Награда за 100 покупки. Условие: всяка покупка трябва да е минимум 10€.',           'type_group'=>'accumulated', 'sort'=>50];
    }
    return null;
}

function buildActiveBonuses(array $customer, array $vouchers): array {
    /* FIX_CARD_VOUCHER_MAPPING_v1 — welcome се показва ако е активен реално, не само за нови клиенти */
    $items = []; $seen = [];
    foreach ($vouchers as $v) {
        $kind = detectVoucherKind($v);
        $norm = voucherMeta($kind);
        if (!$norm || isset($seen[$norm['key']])) continue;
        $seen[$norm['key']] = true; $items[] = $norm;
    }
    /* Ако нямa welcome ваучер още, но е нов клиент — показваме като "потенциален" */
    if ((int)$customer['total_purchases'] === 0 && !isset($seen['welcome'])) {
        $w = voucherMeta('welcome5');
        if ($w) { $items[] = $w; }
    }
    usort($items, fn($a,$b) => ($a['sort']??999)<=>($b['sort']??999));
    return array_values($items);
}

function buildActiveBonusNotice(array $activeBonuses): string {
    $labels = array_filter(array_column($activeBonuses, 'label'));
    if (!$labels) return 'В момента няма активни бонуси.';
    if (count($labels) === 1) return 'Активен бонус: ' . reset($labels) . '.';
    return 'Активни бонуси: ' . implode(', ', $labels) . '.';
}

function loadCustomerRefCode(PDO $pdo, int $customerId): string {
    try {
        $stmt = $pdo->prepare("SELECT ref_code FROM customers WHERE id = ? LIMIT 1");
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (string)($row['ref_code'] ?? '');
    } catch (Throwable $e) {
        return '';
    }
}

function loadLastPurchase(PDO $pdo, int $customerId): array {
    $stmt = $pdo->prepare("SELECT amount, created_at FROM purchase_scans WHERE customer_id=:cid ORDER BY id DESC LIMIT 1");
    $stmt->execute(['cid' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['amount'=>null,'created_at'=>null];
    return ['amount'=>round((float)$row['amount'],2), 'created_at'=>$row['created_at']??null];
}

function loadActiveBanners(PDO $pdo): array {
    try {
        $stmt = $pdo->query("
            SELECT id, title, body, image_url, link_url, bg_color
            FROM banners
            WHERE active = 1
              AND (starts_at IS NULL OR starts_at <= NOW())
              AND (ends_at   IS NULL OR ends_at   >= NOW())
            ORDER BY sort_order ASC, id DESC
            LIMIT 5
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function buildCardState(PDO $pdo, string $cardNumber): array {
    if ($cardNumber === '') return ['ok'=>false,'errorMessage'=>'Липсва номер на карта.'];

    $customer = findCustomerByCard($pdo, $cardNumber);
    if (!$customer) return ['ok'=>false,'errorMessage'=>'Картата не е намерена.'];

    $customerId    = (int)$customer['customer_id'];
    $vouchers      = loadUnusedVouchers($pdo, $customerId);
    $activeBonuses = buildActiveBonuses($customer, $vouchers);
    $lastPurchase  = loadLastPurchase($pdo, $customerId);
    $banners       = loadActiveBanners($pdo);

    $lastPurchaseDisplay     = $lastPurchase['amount'] !== null ? euro_text($lastPurchase['amount']) : '—';
    $lastPurchaseDateDisplay = '—';
    if (!empty($lastPurchase['created_at'])) {
        $ts = strtotime((string)$lastPurchase['created_at']);
        if ($ts) $lastPurchaseDateDisplay = date('d.m.Y H:i', $ts);
    }

    $statusMap = ['active'=>'Активна','gold'=>'Gold клиент','vip'=>'VIP клиент','inactive'=>'Неактивна'];
    $statusRaw = strtolower((string)($customer['status'] ?? 'active'));
    $statusLabel = $statusMap[$statusRaw] ?? 'Активна';

    $p10 = clamp_percent(((int)$customer['cycle_purchases_10'] / 10) * 100);
    $p50 = clamp_percent(((int)$customer['cycle_purchases_50'] / 50) * 100);
    $p100= clamp_percent(((int)$customer['cycle_purchases_100']/ 100)* 100);

    $cashbackProgress = clamp_percent(((float)$customer['cycle_spent_100'] / 100) * 100);

    $refCode = loadCustomerRefCode($pdo, $customerId);

    return [
        'ok'                    => true,
        'errorMessage'          => '',
        'customerId'            => $customerId,
        'customerName'          => customerDisplayName($customer),
        'statusLabel'           => $statusLabel,
        'cardNumber'            => (string)$customer['card_number'],
        'refCode'               => $refCode,
        'totalSpent'            => round((float)$customer['total_spent'], 2),
        'totalPurchases'        => (int)$customer['total_purchases'],
        'cycleSpent100'         => round((float)$customer['cycle_spent_100'], 2),
        'cyclePurchases10'      => (int)$customer['cycle_purchases_10'],
        'cyclePurchases50'      => (int)$customer['cycle_purchases_50'],
        'cyclePurchases100'     => (int)$customer['cycle_purchases_100'],
        'toNext5Percent'        => max(0, round(100 - (float)$customer['cycle_spent_100'], 2)),
        'cashbackProgress'      => $cashbackProgress,
        'lastPurchaseDisplay'   => $lastPurchaseDisplay,
        'lastPurchaseDateDisplay'=> $lastPurchaseDateDisplay,
        'activeBonuses'         => $activeBonuses,
        'activeBonusNotice'     => buildActiveBonusNotice($activeBonuses),
        'progress10'            => $p10,
        'progress50'            => $p50,
        'progress100'           => $p100,
        'left10'                => max(0, 10  - (int)$customer['cycle_purchases_10']),
        'left50'                => max(0, 50  - (int)$customer['cycle_purchases_50']),
        'left100'               => max(0, 100 - (int)$customer['cycle_purchases_100']),
        'banners'               => $banners,
        'serverTs'              => time(),
    ];
}

/* ── AJAX: save push subscription ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax']) && $_GET['ajax'] === 'push_subscribe') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $cardNumber = trim((string)($body['card'] ?? ''));
    $sub        = $body['subscription'] ?? [];
    if ($cardNumber && $sub) {
        $customer = findCustomerByCard($pdo, $cardNumber);
        if ($customer) {
            $endpoint = (string)($sub['endpoint'] ?? '');
            $p256dh   = (string)($sub['keys']['p256dh'] ?? '');
            $auth     = (string)($sub['keys']['auth']   ?? '');
            if ($endpoint && $p256dh && $auth) {
                $now = date('Y-m-d H:i:s');
                $stmt = $pdo->prepare("
                    INSERT INTO push_subscriptions (customer_id, card_number, endpoint, p256dh, auth, created_at, updated_at)
                    VALUES (:cid, :card, :ep, :p256dh, :auth, :now, :now)
                    ON DUPLICATE KEY UPDATE
                        customer_id = VALUES(customer_id),
                        card_number = VALUES(card_number),
                        p256dh      = VALUES(p256dh),
                        auth        = VALUES(auth),
                        updated_at  = VALUES(updated_at)
                ");
                $stmt->execute(['cid'=>(int)$customer['customer_id'],'card'=>$cardNumber,'ep'=>$endpoint,'p256dh'=>$p256dh,'auth'=>$auth,'now'=>$now]);
            }
        }
    }
    jsonResponse(['ok' => true]);
}

/* ── AJAX: card status ── */
$cardParam = isset($_GET['card']) ? preg_replace('/[^A-Za-z0-9\-]/', '', trim((string)$_GET['card'])) : '';

if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    jsonResponse(buildCardState($pdo, $cardParam));
}

/* ── Initial page render ── */
$state = buildCardState($pdo, $cardParam);

$errorMessage            = $state['errorMessage'] ?? '';
$customerName            = $state['customerName'] ?? 'Лоялен клиент';
$statusLabel             = $state['statusLabel']  ?? 'Активна';
$cardNumber              = $state['cardNumber']   ?? $cardParam;
$refCode                 = $state['refCode']      ?? '';
$totalSpent              = $state['totalSpent']   ?? 0;
$lastPurchaseDisplay     = $state['lastPurchaseDisplay']     ?? '—';
$lastPurchaseDateDisplay = $state['lastPurchaseDateDisplay'] ?? '—';
$toNext5Percent          = $state['toNext5Percent'] ?? 100.00;
$cashbackProgress        = $state['cashbackProgress'] ?? 0;
$cycleSpent100           = $state['cycleSpent100'] ?? 0;
$cyclePurchases10        = $state['cyclePurchases10']  ?? 0;
$cyclePurchases50        = $state['cyclePurchases50']  ?? 0;
$cyclePurchases100       = $state['cyclePurchases100'] ?? 0;
$progress10              = $state['progress10'] ?? 0;
$progress50              = $state['progress50'] ?? 0;
$progress100             = $state['progress100']?? 0;
$left10                  = $state['left10']  ?? 10;
$left50                  = $state['left50']  ?? 50;
$left100                 = $state['left100'] ?? 100;
$activeBonuses           = $state['activeBonuses']     ?? [];
$activeBonusNotice       = $state['activeBonusNotice'] ?? 'В момента няма активни бонуси.';
$banners                 = $state['banners'] ?? [];

/* QR код — директно от номера на картата, без токен */
$qrCardNum  = $cardNumber ?: 'ET000000';
$qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&margin=10&data=' . rawurlencode($qrCardNum);

/* VAPID public key за Web Push нотификации */
$vapidPublicKey = 'BJQdGYTWHFlFOsrslhw-nluDXo_xnB34yxogrfx45DuuCKdmZ8NJsK6bNuvjOr5SyjN90We27G5M02vHU8-WmYs';

/* Контактен телефон (Viber + tel) */
$contactPhone = '+359898697197';

/* SVG dashoffset изчисления за progress rings (radius=34, circumference=213.6) */
$ringOffset10  = 213.6 - (213.6 * ($progress10  / 100));
$ringOffset50  = 213.6 - (213.6 * ($progress50  / 100));
$ringOffset100 = 213.6 - (213.6 * ($progress100 / 100));
?>
<!doctype html>
<html lang="bg">
<head>
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover">
<title>Моята карта – Ени Тихолов</title>
<meta name="theme-color" content="#f5f7fb">
<link rel="manifest" href="/manifest.php?card=<?= h($cardNumber) ?>">
<link rel="apple-touch-icon" href="/icon-192.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Ени">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ═══ Light Neon Glass tokens ═══ */
:root{
  --hue1:255; --hue2:222;
  --border:1px; --border-color:rgba(0,0,0,.06);
  --radius:22px; --radius-sm:14px;
  --ease:cubic-bezier(0.5,1,0.89,1);
  --bg-main:#f5f7fb;
  --text-primary:#0f172a;
  --text-secondary:#475569;
  --text-muted:#94a3b8;
  --surface:rgba(255,255,255,.72);
}
*{ box-sizing:border-box; margin:0; padding:0; -webkit-tap-highlight-color:transparent; }
html,body{
  background:var(--bg-main);
  color:var(--text-primary);
  font-family:'Montserrat',Inter,system-ui,sans-serif;
  min-height:100vh;
  overflow-x:hidden;
}
body{
  background:
    radial-gradient(ellipse 900px 600px at 15% 5%, hsl(var(--hue1) 85% 88% / .55) 0%, transparent 55%),
    radial-gradient(ellipse 800px 600px at 90% 90%, hsl(var(--hue2) 85% 88% / .45) 0%, transparent 55%),
    radial-gradient(ellipse 600px 400px at 50% 50%, hsl(38 85% 92% / .35) 0%, transparent 50%),
    linear-gradient(180deg,#fafbff 0%,#eef2f9 100%);
  background-attachment:fixed;
  padding-bottom:40px;
  position:relative;
}
body::before{
  content:''; position:fixed; inset:0;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.5'/%3E%3C/svg%3E");
  opacity:.025; pointer-events:none; z-index:1; mix-blend-mode:multiply;
}

.app{ width:100%; max-width:520px; margin:0 auto; padding:0 0 40px; position:relative; z-index:2; }

/* ═══ GLASS pattern (светла версия) ═══ */
.glass{
  position:relative;
  border-radius:var(--radius);
  border:var(--border) solid var(--border-color);
  background:
    linear-gradient(235deg,hsl(var(--hue1) 85% 85% / .55),hsl(var(--hue1) 85% 85% / 0) 40%),
    linear-gradient(45deg,hsl(var(--hue2) 85% 85% / .55),hsl(var(--hue2) 85% 85% / 0) 40%),
    linear-gradient(hsl(0 0% 100% / .78));
  backdrop-filter:blur(16px) saturate(1.3);
  -webkit-backdrop-filter:blur(16px) saturate(1.3);
  box-shadow:
    0 1px 0 rgba(255,255,255,.95) inset,
    0 4px 12px rgba(15,23,42,.04),
    0 16px 40px -12px rgba(15,23,42,.08);
  isolation:isolate;
}
.glass.sm{ --radius:var(--radius-sm); }
.glass .shine,.glass .glow{ --hue:var(--hue1); }
.glass .shine-bottom,.glass .glow-bottom{ --hue:var(--hue2); --conic:135deg; }
.glass .shine,.glass .shine::before,.glass .shine::after{
  pointer-events:none; border-radius:0;
  border-top-right-radius:inherit; border-bottom-left-radius:inherit;
  border:1px solid transparent;
  width:75%; aspect-ratio:1; display:block; position:absolute;
  right:calc(var(--border) * -1); top:calc(var(--border) * -1);
  left:auto; z-index:1; --start:12%;
  background:conic-gradient(from var(--conic,-45deg) at center in oklch,transparent var(--start,0%),hsl(var(--hue),var(--sat,85%),var(--lit,55%)),transparent var(--end,50%)) border-box;
  mask:linear-gradient(transparent),linear-gradient(black);
  mask-repeat:no-repeat; mask-clip:padding-box,border-box; mask-composite:subtract;
  opacity:.8;
}
.glass .shine::before,.glass .shine::after{ content:""; width:auto; inset:-2px; mask:none; }
.glass .shine::after{ z-index:2; --start:17%; --end:33%; background:conic-gradient(from var(--conic,-45deg) at center in oklch,transparent var(--start,0%),hsl(var(--hue),var(--sat,90%),var(--lit,70%)),transparent var(--end,50%)); }
.glass .shine-bottom{ top:auto; bottom:calc(var(--border) * -1); left:calc(var(--border) * -1); right:auto; }
.glass .glow{
  pointer-events:none;
  border-top-right-radius:calc(var(--radius) * 2.5);
  border-bottom-left-radius:calc(var(--radius) * 2.5);
  border:calc(var(--radius) * 1.25) solid transparent;
  inset:calc(var(--radius) * -2);
  width:75%; aspect-ratio:1; display:block; position:absolute; left:auto; bottom:auto;
  mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='240' height='240'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='3' seed='5'/%3E%3CfeColorMatrix values='0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 1 0'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
  mask-mode:luminance; mask-size:29%; opacity:.7; filter:blur(14px) saturate(1.5) brightness(1);
  mix-blend-mode:multiply; z-index:3;
}
.glass .glow.glow-bottom{ inset:calc(var(--radius) * -2); top:auto; right:auto; }
.glass .glow::before,.glass .glow::after{
  content:""; position:absolute; inset:0;
  border:inherit; border-radius:inherit;
  background:conic-gradient(from var(--conic,-45deg) at center in oklch,transparent var(--start,0%),hsl(var(--hue),var(--sat,95%),var(--lit,55%)),transparent var(--end,50%)) border-box;
  mask:linear-gradient(transparent),linear-gradient(black);
  mask-repeat:no-repeat; mask-clip:padding-box,border-box; mask-composite:subtract;
  filter:saturate(2.5) brightness(1.1);
}
.glass .glow::after{ --lit:60%; --sat:100%; --start:15%; --end:35%; border-width:calc(var(--radius) * 1.75); border-radius:calc(var(--radius) * 2.75); inset:calc(var(--radius) * -.25); z-index:4; opacity:.55; }

/* ═══ Hue variants ═══ */
.q1{ --hue1:0;   --hue2:340 }
.q2{ --hue1:280; --hue2:260 }
.q3{ --hue1:145; --hue2:165 }
.q4{ --hue1:175; --hue2:195 }
.q5{ --hue1:38;  --hue2:28  }
.q6{ --hue1:220; --hue2:230 }

/* ═══ TOPBAR ═══ */
.topbar{
  padding:14px 16px 12px;
  display:flex; align-items:center; justify-content:center;
  position:sticky; top:0; z-index:50;
  background:linear-gradient(180deg,rgba(245,247,251,.92),rgba(245,247,251,.72));
  backdrop-filter:blur(10px);
  -webkit-backdrop-filter:blur(10px);
  border-bottom:1px solid rgba(15,23,42,.04);
}
.topbar-logo{
  height:52px; width:auto; display:block;
  filter:drop-shadow(0 0 16px hsl(38 70% 55% / .35));
}

/* ═══ ENGAGEMENT NUDGE ═══ */
.section{ margin:14px 14px 0; }
.nudge{ padding:16px 18px; display:flex; align-items:center; gap:14px; cursor:pointer; }
.nudge-icon{
  width:48px; height:48px; border-radius:14px; flex-shrink:0;
  background:linear-gradient(135deg,hsl(var(--hue1) 75% 58%),hsl(var(--hue2) 75% 52%));
  display:flex; align-items:center; justify-content:center; position:relative; z-index:5;
  box-shadow:0 0 28px hsl(var(--hue1) 75% 55% / .5),0 4px 12px hsl(var(--hue1) 60% 45% / .3);
  animation:nudge-pulse 2.5s ease-in-out infinite;
  color:#fff;
}
.nudge-icon svg{ width:22px; height:22px; stroke:#fff; stroke-width:2; fill:none; stroke-linecap:round; stroke-linejoin:round; filter:drop-shadow(0 0 6px rgba(255,255,255,.6)); }
@keyframes nudge-pulse{
  0%,100%{ box-shadow:0 0 28px hsl(var(--hue1) 75% 55% / .5),0 4px 12px hsl(var(--hue1) 60% 45% / .3); }
  50%{ box-shadow:0 0 48px hsl(var(--hue1) 75% 55% / .8),0 0 80px hsl(var(--hue1) 75% 55% / .25),0 4px 12px hsl(var(--hue1) 60% 45% / .3); }
}
.nudge-text{ flex:1; min-width:0; position:relative; z-index:5; }
.nudge-label{ font-size:9px; font-weight:900; letter-spacing:.14em; color:hsl(var(--hue1) 60% 48%); text-transform:uppercase; margin-bottom:4px; }
.nudge-title{ font-size:15px; font-weight:800; color:var(--text-primary); line-height:1.3; margin-bottom:3px; }
.nudge-sub{ font-size:11.5px; color:var(--text-secondary); font-weight:600; }
.nudge-arrow{ flex-shrink:0; color:hsl(var(--hue1) 65% 55%); position:relative; z-index:5; }
.nudge-arrow svg{ width:20px; height:20px; stroke:currentColor; stroke-width:2.5; fill:none; stroke-linecap:round; }

/* ═══ HERO CARD ═══ */
.hero-card{ margin:14px 14px 0; padding:22px 20px 22px; text-align:center; }
.hero-qr-wrap{
  width:180px; height:180px; margin:0 auto 16px; padding:14px;
  background:#fff; border-radius:18px; position:relative; z-index:5;
  box-shadow:
    0 0 50px hsl(var(--hue1) 75% 55% / .3),
    0 0 0 1px rgba(15,23,42,.04),
    0 8px 24px rgba(15,23,42,.08);
}
.hero-qr-wrap img{ width:100%; height:100%; display:block; object-fit:contain; }
.hero-status{
  display:inline-flex; align-items:center; gap:6px; padding:5px 12px; margin-bottom:8px;
  background:linear-gradient(135deg,hsl(var(--hue1) 85% 96%),hsl(var(--hue2) 85% 96%));
  border:1px solid hsl(var(--hue1) 60% 85%);
  border-radius:100px; font-size:10px; font-weight:900; letter-spacing:.12em; text-transform:uppercase;
  color:hsl(var(--hue1) 65% 42%);
  position:relative; z-index:5;
}
.hero-status::before{ content:''; width:6px; height:6px; border-radius:50%; background:currentColor; box-shadow:0 0 8px currentColor; }
.hero-name{ font-size:22px; font-weight:900; letter-spacing:-.02em; color:var(--text-primary); margin-bottom:4px; position:relative; z-index:5; }
.hero-card-num{ font-size:11px; font-weight:700; color:var(--text-muted); letter-spacing:.18em; font-variant-numeric:tabular-nums; position:relative; z-index:5; }
.hero-big-label{ font-size:10px; font-weight:800; letter-spacing:.12em; color:var(--text-muted); text-transform:uppercase; margin-top:18px; position:relative; z-index:5; }
.hero-big-val{
  display:flex; align-items:baseline; justify-content:center; gap:6px; margin-top:4px; position:relative; z-index:5;
  font-variant-numeric:tabular-nums;
}
.hero-big-val .big{
  font-size:42px; font-weight:900; letter-spacing:-.04em; line-height:1;
  background:linear-gradient(135deg,hsl(var(--hue1) 65% 40%) 0%,hsl(var(--hue2) 65% 45%) 100%);
  -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent;
}
.hero-big-val .cur{ font-size:16px; color:var(--text-muted); font-weight:700; }
.qr-hint-light{ margin-top:10px; font-size:11px; color:var(--text-secondary); font-weight:600; position:relative; z-index:5; }
.qr-sync-light{ margin-top:4px; font-size:10px; color:var(--text-muted); font-weight:600; letter-spacing:.05em; position:relative; z-index:5; }

/* ═══ CYCLE RINGS (3 колони) ═══ */
.cycles{ display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin:14px 14px 0; }
.cycle-box{ padding:14px 8px 12px; text-align:center; }
.cycle-ring{ width:68px; height:68px; margin:0 auto 8px; position:relative; z-index:5; }
.cycle-ring svg{ width:100%; height:100%; transform:rotate(-90deg); }
.cycle-ring .track{ fill:none; stroke:rgba(15,23,42,.08); stroke-width:6; }
.cycle-ring .fill{ fill:none; stroke-width:6; stroke-linecap:round; stroke:url(#cycleGrad); transition:stroke-dashoffset .6s cubic-bezier(.4,0,.2,1); }
.cycle-num{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:18px; font-weight:900; color:var(--text-primary); font-variant-numeric:tabular-nums; }
.cycle-label{ font-size:10px; font-weight:800; color:var(--text-muted); text-transform:uppercase; letter-spacing:.08em; margin-bottom:2px; position:relative; z-index:5; }
.cycle-remain{ font-size:11px; font-weight:700; color:hsl(var(--hue1) 60% 45%); position:relative; z-index:5; }
.cycle-remain.hot{ color:hsl(145 65% 38%); }

/* ═══ CASHBACK BAR ═══ */
.cashback{ padding:14px 16px 12px; }
.cashback-head{ display:flex; align-items:baseline; justify-content:space-between; margin-bottom:10px; position:relative; z-index:5; }
.cashback-lbl{ font-size:11px; font-weight:900; letter-spacing:.1em; text-transform:uppercase; color:hsl(var(--hue1) 60% 45%); display:flex; align-items:center; gap:6px; }
.cashback-lbl svg{ width:12px; height:12px; stroke:currentColor; stroke-width:2.5; fill:none; stroke-linecap:round; }
.cashback-val{ font-size:18px; font-weight:900; color:var(--text-primary); font-variant-numeric:tabular-nums; }
.cashback-val small{ font-size:12px; color:var(--text-muted); font-weight:700; }
.cashback-bar{ height:10px; background:rgba(15,23,42,.05); border-radius:100px; overflow:hidden; position:relative; z-index:5; border:1px solid rgba(15,23,42,.04); }
.cashback-fill{ height:100%; border-radius:100px; background:linear-gradient(90deg,hsl(var(--hue1) 75% 58%),hsl(var(--hue2) 75% 55%)); box-shadow:0 0 16px hsl(var(--hue1) 75% 55% / .7); min-width:3px; transition:width .6s cubic-bezier(.4,0,.2,1); }
.cashback-sub{ font-size:11px; color:var(--text-secondary); margin-top:8px; position:relative; z-index:5; }
.cashback-sub strong{ color:var(--text-primary); font-weight:900; }

/* ═══ SECTION LABEL ═══ */
.sec-label{ font-size:10px; font-weight:900; letter-spacing:.12em; text-transform:uppercase; padding:8px 4px 10px; color:hsl(var(--hue1) 60% 45%); display:flex; align-items:center; gap:8px; }
.sec-label svg{ width:12px; height:12px; stroke:currentColor; stroke-width:2.5; fill:none; stroke-linecap:round; }
.sec-cnt{ margin-left:auto; color:var(--text-muted); font-weight:700; letter-spacing:.04em; }

/* ═══ PROGRESS REWARDS (legacy секция, запазена) ═══ */
.section-card{ padding:18px 16px; }
.sec-title{
  font-size:11px; font-weight:900; letter-spacing:.12em; text-transform:uppercase;
  color:hsl(var(--hue1) 60% 45%);
  margin:0 0 14px; display:flex; align-items:center; gap:10px;
  position:relative; z-index:5;
}
.sec-title-icon{
  width:28px; height:28px; border-radius:9px;
  background:hsl(var(--hue1) 85% 95%); border:1px solid hsl(var(--hue1) 55% 85%);
  display:inline-flex; align-items:center; justify-content:center; flex-shrink:0;
}
.sec-title-icon svg{ width:14px; height:14px; stroke:hsl(var(--hue1) 65% 42%); stroke-width:2; fill:none; stroke-linecap:round; stroke-linejoin:round; }
.reward-row{ margin-bottom:14px; position:relative; z-index:5; }
.reward-row:last-child{ margin-bottom:0; }
.reward-meta{ display:flex; justify-content:space-between; align-items:baseline; margin-bottom:8px; }
.reward-name{ font-size:14px; font-weight:800; color:var(--text-primary); }
.reward-count{ font-size:12px; font-weight:700; color:var(--text-secondary); font-variant-numeric:tabular-nums; }
.reward-count strong{ color:hsl(var(--hue1) 65% 45%); font-weight:900; }
.progress-track{ width:100%; height:10px; background:rgba(15,23,42,.05); border-radius:999px; overflow:hidden; border:1px solid rgba(15,23,42,.04); }
.progress-fill{ height:100%; border-radius:999px; background:linear-gradient(90deg,hsl(var(--hue1) 75% 58%),hsl(var(--hue2) 75% 50%)); box-shadow:0 0 12px hsl(var(--hue1) 70% 50% / .5); transition:width .6s cubic-bezier(.4,0,.2,1); min-width:3px; }
.reward-foot{ margin-top:6px; font-size:11px; color:var(--text-muted); font-weight:600; }
.reward-divider{ height:1px; background:linear-gradient(90deg,transparent,rgba(15,23,42,.08),transparent); margin:14px 0; }

/* ═══ TURNOVER BOX (запазен legacy) ═══ */
.turnover-box{ display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.t-box{ padding:14px; }
.t-label{ font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.1em; color:var(--text-muted); margin-bottom:6px; position:relative; z-index:5; }
.t-value{ font-size:22px; font-weight:900; line-height:1.05; letter-spacing:-.02em; color:var(--text-primary); font-variant-numeric:tabular-nums; position:relative; z-index:5; }
.t-meta{ margin-top:5px; font-size:11px; color:var(--text-muted); font-weight:600; position:relative; z-index:5; }

/* ═══ ACTIVE BONUSES LIST ═══ */
.ab-item{
  padding:14px; border-radius:var(--radius-sm);
  background:rgba(255,255,255,.6); border:1px solid rgba(15,23,42,.06);
  margin-bottom:10px; display:flex; align-items:flex-start; gap:12px;
}
.ab-item:last-child{ margin-bottom:0; }
.ab-badge-big{
  flex-shrink:0; width:44px; height:44px; border-radius:12px;
  display:flex; align-items:center; justify-content:center;
  font-size:12px; font-weight:900; letter-spacing:-.02em;
}
.ab-badge-welcome{ background:linear-gradient(135deg,hsl(38 80% 55%),hsl(28 80% 50%)); color:#fff; box-shadow:0 0 16px hsl(38 70% 50% / .4); }
.ab-badge-acc{ background:linear-gradient(135deg,hsl(var(--hue1) 75% 58%),hsl(var(--hue2) 75% 52%)); color:#fff; box-shadow:0 0 16px hsl(var(--hue1) 70% 50% / .4); }
.ab-content{ flex:1; min-width:0; }
.ab-title{ margin:0 0 3px; font-size:14px; font-weight:900; color:var(--text-primary); }
.ab-desc{ margin:0; font-size:12px; font-weight:600; color:var(--text-secondary); line-height:1.5; }
.ab-empty{
  text-align:center; padding:16px; font-size:12px; font-weight:600; color:var(--text-muted);
  background:rgba(255,255,255,.4); border-radius:var(--radius-sm); border:1px dashed rgba(15,23,42,.08);
}

/* ═══ HERO BONUS CHIPS (запазена логика) ═══ */
.hero-bonuses{ display:flex; flex-wrap:wrap; gap:7px; justify-content:center; margin-top:14px; position:relative; z-index:5; }
.bonus-chip{ padding:7px 12px; border-radius:999px; font-size:12px; font-weight:900; letter-spacing:.02em; display:inline-flex; align-items:center; gap:5px; }
.bonus-chip-welcome{ background:linear-gradient(135deg,hsl(38 80% 55%),hsl(28 80% 50%)); color:#fff; box-shadow:0 0 18px hsl(38 70% 50% / .4); }
.bonus-chip-acc{ background:linear-gradient(135deg,hsl(var(--hue1) 75% 58%),hsl(var(--hue2) 75% 52%)); color:#fff; box-shadow:0 0 14px hsl(var(--hue1) 70% 50% / .35); }
.bonus-chip-empty{ background:rgba(15,23,42,.04); color:var(--text-muted); font-size:11px; font-weight:700; border:1px dashed rgba(15,23,42,.12); }

/* ═══ LAST PURCHASE row (нов компактен стил) ═══ */
.last-purchase{ padding:14px 16px; display:flex; align-items:center; gap:12px; }
.lp-icon{ width:40px; height:40px; border-radius:12px; flex-shrink:0; background:hsl(var(--hue1) 85% 95%); border:1px solid hsl(var(--hue1) 55% 85%); display:flex; align-items:center; justify-content:center; position:relative; z-index:5; }
.lp-icon svg{ width:18px; height:18px; stroke:hsl(var(--hue1) 65% 42%); stroke-width:2; fill:none; stroke-linecap:round; stroke-linejoin:round; }
.lp-text{ flex:1; position:relative; z-index:5; min-width:0; }
.lp-lbl{ font-size:10px; font-weight:800; color:var(--text-muted); text-transform:uppercase; letter-spacing:.1em; margin-bottom:2px; }
.lp-val{ font-size:17px; font-weight:900; color:var(--text-primary); font-variant-numeric:tabular-nums; }
.lp-date{ font-size:11px; color:var(--text-secondary); margin-top:2px; font-weight:600; }

/* ═══ ACTION BUTTONS ROW ═══ */
.act-row{ display:grid; grid-template-columns:1fr 1fr; gap:8px; margin:14px 14px 0; }
.act-btn{
  padding:14px 12px; border-radius:14px; font-family:inherit; cursor:pointer;
  display:flex; align-items:center; justify-content:center; gap:8px;
  font-size:13px; font-weight:800; letter-spacing:.02em;
  border:1px solid rgba(15,23,42,.08);
  background:rgba(255,255,255,.7); color:var(--text-primary);
  transition:all .2s;
  box-shadow:0 2px 8px rgba(15,23,42,.04);
}
.act-btn:hover{ background:#fff; box-shadow:0 4px 16px rgba(15,23,42,.08); }
.act-btn:active{ transform:scale(.97); }
.act-btn.primary{
  background:linear-gradient(135deg,hsl(var(--hue1) 75% 58%),hsl(var(--hue2) 75% 52%));
  border-color:hsl(var(--hue1) 65% 55%);
  color:#fff;
  box-shadow:0 8px 24px hsl(var(--hue1) 65% 45% / .35),0 0 24px hsl(var(--hue1) 75% 55% / .3),inset 0 1px 0 rgba(255,255,255,.3);
}
.act-btn.primary:hover{ box-shadow:0 10px 30px hsl(var(--hue1) 65% 45% / .45),0 0 32px hsl(var(--hue1) 75% 55% / .4),inset 0 1px 0 rgba(255,255,255,.3); }
.act-btn svg{ width:15px; height:15px; stroke:currentColor; stroke-width:2.5; fill:none; stroke-linecap:round; stroke-linejoin:round; }

/* ═══ BANNERS ═══ */
.banner-item{
  padding:16px; margin-bottom:10px; text-decoration:none; display:block;
  transition:transform .15s; color:inherit; position:relative; overflow:hidden;
}
.banner-item:active{ transform:scale(.98); }
.banner-img{ width:100%; border-radius:12px; margin-bottom:10px; display:block; object-fit:cover; max-height:140px; position:relative; z-index:5; }
.banner-title{ margin:0 0 5px; font-size:15px; font-weight:900; color:var(--text-primary); position:relative; z-index:5; }
.banner-body{ margin:0; font-size:12px; font-weight:600; color:var(--text-secondary); line-height:1.5; position:relative; z-index:5; }
.banner-empty{ padding:22px 16px; text-align:center; font-size:12px; color:var(--text-muted); font-weight:600; display:flex; align-items:center; justify-content:center; gap:10px; position:relative; z-index:5; }
.banner-empty svg{ width:16px; height:16px; stroke:hsl(var(--hue1) 65% 50%); stroke-width:2; fill:none; stroke-linecap:round; flex-shrink:0; }

/* ═══ INSTALL FOOTER ═══ */
#installSection .section-card{ text-align:center; padding:22px 16px; }
.install-icon-wrap{
  width:56px; height:56px; margin:0 auto 12px; border-radius:16px;
  background:linear-gradient(135deg,hsl(var(--hue1) 70% 52%),hsl(var(--hue2) 70% 45%));
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 0 24px hsl(var(--hue1) 70% 50% / .4);
  position:relative; z-index:5;
}
.install-icon-wrap svg{ width:26px; height:26px; stroke:#fff; stroke-width:2; fill:none; stroke-linecap:round; stroke-linejoin:round; }
.install-title{ margin:0 0 6px; font-size:16px; font-weight:900; color:var(--text-primary); position:relative; z-index:5; }
.install-body{ margin:0 0 16px; font-size:12px; font-weight:600; color:var(--text-secondary); line-height:1.5; position:relative; z-index:5; }

/* ═══ BUTTONS (overlay) ═══ */
.ov-btn{
  border:none; border-radius:14px; padding:14px 16px;
  font-size:14px; font-weight:900; letter-spacing:.02em;
  cursor:pointer; font-family:inherit;
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  transition:transform .15s;
}
.ov-btn:active{ transform:scale(.97); }
.ov-btn svg{ width:16px; height:16px; stroke:currentColor; stroke-width:2.5; fill:none; stroke-linecap:round; stroke-linejoin:round; flex-shrink:0; }
.ov-btn-primary{
  background:linear-gradient(135deg,hsl(var(--hue1) 75% 58%),hsl(var(--hue2) 75% 50%));
  color:#fff; border:1px solid hsl(var(--hue1) 65% 55%);
  box-shadow:0 6px 20px hsl(var(--hue1) 60% 45% / .35),0 0 20px hsl(var(--hue1) 75% 55% / .3),inset 0 1px 0 rgba(255,255,255,.3);
}
.ov-btn-secondary{ background:rgba(15,23,42,.04); color:var(--text-secondary); border:1px solid rgba(15,23,42,.08); }
.ov-btn-viber{ background:linear-gradient(135deg,#7360f2,#5c4ad7); color:#fff; border:1px solid #5c4ad7; box-shadow:0 6px 20px rgba(115,96,242,.35); }
.ov-btn-whatsapp{ background:linear-gradient(135deg,#25d366,#128c7e); color:#fff; border:1px solid #128c7e; box-shadow:0 6px 20px rgba(37,211,102,.35); }
.ov-btn-sms{ background:linear-gradient(135deg,hsl(210 75% 55%),hsl(220 75% 50%)); color:#fff; border:1px solid hsl(210 65% 50%); box-shadow:0 6px 20px hsl(210 60% 50% / .35); }
.ov-btn-call{ background:linear-gradient(135deg,hsl(145 65% 50%),hsl(165 65% 45%)); color:#fff; border:1px solid hsl(145 60% 50%); box-shadow:0 6px 20px hsl(145 60% 45% / .35); }

/* Invite grid — 2x2 на 4-те опции */
.invite-grid{ display:grid; grid-template-columns:1fr 1fr; gap:10px; position:relative; z-index:5; margin-bottom:6px; }
.invite-btn{ text-decoration:none; width:100%; }

/* ═══ OVERLAYS ═══ */
.overlay{
  position:fixed; inset:0; background:rgba(15,23,42,.5);
  display:flex; align-items:flex-end; justify-content:center;
  z-index:9999; padding:16px;
  backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
  animation:ov-in .25s ease-out;
}
@keyframes ov-in{ from{opacity:0} to{opacity:1} }
@keyframes sheet-in{ from{transform:translateY(30px);opacity:0} to{transform:translateY(0);opacity:1} }
.overlay-modal{ width:100%; max-width:480px; padding:24px 20px 20px; animation:sheet-in .3s cubic-bezier(.2,.8,.3,1); }
.overlay-bell-wrap{
  width:64px; height:64px; margin:0 auto 14px; border-radius:18px;
  background:linear-gradient(135deg,hsl(var(--hue1) 75% 58%),hsl(var(--hue2) 75% 50%));
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 0 32px hsl(var(--hue1) 70% 50% / .55);
  position:relative; z-index:5;
}
.overlay-bell-wrap svg{ width:32px; height:32px; stroke:#fff; stroke-width:2; fill:none; stroke-linecap:round; stroke-linejoin:round; filter:drop-shadow(0 0 8px rgba(255,255,255,.5)); }
.overlay-logo-wrap{
  width:64px; height:64px; margin:0 auto 14px; border-radius:16px;
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 0 32px hsl(var(--hue1) 70% 50% / .45); overflow:hidden;
  position:relative; z-index:5;
  background:#fff;
}
.overlay-logo-wrap img{ width:100%; height:100%; object-fit:contain; border-radius:16px; }
.overlay-title{ text-align:center; font-size:20px; font-weight:900; letter-spacing:-.01em; color:var(--text-primary); margin:0 0 8px; position:relative; z-index:5; }
.overlay-text{ text-align:center; font-size:13px; font-weight:600; color:var(--text-secondary); line-height:1.55; margin:0 0 18px; position:relative; z-index:5; }
.overlay-text strong{ color:var(--text-primary); font-weight:900; }
.overlay-steps{
  background:rgba(255,255,255,.5); border:1px solid rgba(15,23,42,.06);
  border-radius:14px; padding:14px; margin-bottom:16px;
  position:relative; z-index:5;
}
.overlay-step{ display:flex; gap:10px; align-items:flex-start; font-size:12px; font-weight:700; color:var(--text-primary); margin-bottom:10px; line-height:1.4; }
.overlay-step:last-child{ margin-bottom:0; }
.overlay-step strong{ color:hsl(var(--hue1) 60% 42%); }
.step-num{
  flex-shrink:0; width:22px; height:22px;
  background:linear-gradient(135deg,hsl(var(--hue1) 75% 58%),hsl(var(--hue2) 75% 50%));
  color:#fff; border-radius:999px; font-size:11px; font-weight:900;
  display:flex; align-items:center; justify-content:center; margin-top:1px;
  box-shadow:0 0 10px hsl(var(--hue1) 60% 50% / .5);
}
.overlay-actions{ display:grid; grid-template-columns:1fr 1fr; gap:10px; position:relative; z-index:5; }
.overlay-actions.single{ grid-template-columns:1fr; }
.ov-skip{
  margin-top:14px; text-align:center; font-size:12px; font-weight:600;
  color:var(--text-muted); cursor:pointer; padding:6px; transition:color .15s;
}
.ov-skip:hover{ color:var(--text-secondary); }

/* ═══ ERROR PANEL ═══ */
#errorPanel .section-card,
.section-card.is-error{ background:linear-gradient(135deg,hsl(0 80% 96%),hsl(0 80% 94%)); border:1px solid hsl(0 70% 80%); }
#errorText{ margin:0; font-size:13px; font-weight:700; color:hsl(0 70% 38%); position:relative; z-index:5; }

.hidden{ display:none !important; }

@media (max-width:400px){
  .turnover-box{ grid-template-columns:1fr; }
  .overlay-actions{ grid-template-columns:1fr; }
  .hero-name{ font-size:21px; }
  .act-row{ grid-template-columns:1fr; }
}
</style>
</head>
<body>

<svg width="0" height="0" style="position:absolute">
  <defs>
    <linearGradient id="cycleGrad" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="hsl(255 75% 62%)"/>
      <stop offset="100%" stop-color="hsl(222 75% 58%)"/>
    </linearGradient>
  </defs>
</svg>

<div class="app">

  <div class="topbar">
    <img src="/icon-512.png" alt="Ени Тихолов" class="topbar-logo" onerror="this.src='/icon-192.png';">
  </div>

  <!-- ══ ERROR ══ -->
  <?php if ($errorMessage): ?>
  <div class="section">
    <div class="glass sm section-card is-error q1">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <p id="errorText"><?= h($errorMessage) ?></p>
    </div>
  </div>
  <?php else: ?>
  <div class="section hidden" id="errorPanel">
    <div class="glass sm section-card is-error q1">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <p id="errorText"></p>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ NUDGE #1: Активен ваучер (auto-show) ══ -->
  <div class="section hidden" id="nudgeVoucher">
    <div class="glass q1 nudge" id="nudgeVoucherBtn">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <div class="nudge-icon">
        <svg viewBox="0 0 24 24"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 100-5C13 2 12 7 12 7z"/></svg>
      </div>
      <div class="nudge-text">
        <div class="nudge-label" id="nudgeVoucherLabel">Активен бонус</div>
        <div class="nudge-title" id="nudgeVoucherTitle">Имаш активен ваучер</div>
        <div class="nudge-sub" id="nudgeVoucherSub">Покажи картата при следваща покупка</div>
      </div>
      <div class="nudge-arrow">
        <svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
      </div>
    </div>
  </div>

  <!-- ══ HERO CLIENT CARD (QR + status + name + total spent) ══ -->
  <div class="glass hero-card">
    <span class="shine"></span><span class="shine shine-bottom"></span>
    <span class="glow"></span><span class="glow glow-bottom"></span>
    <div class="hero-qr-wrap">
      <img src="<?= h($qrImageUrl) ?>" alt="QR код" id="jsQrImage" loading="eager">
    </div>
    <div class="hero-status" id="jsStatusLabel"><?= h($statusLabel) ?></div>
    <div class="hero-name" id="jsCustomerName"><?= h($customerName) ?></div>
    <div class="hero-card-num" id="jsCardNumber"><?= h($cardNumber) ?></div>
    <div class="hero-card-num" id="jsQrCardNum" style="display:none;"><?= h($cardNumber) ?></div>
    <div class="hero-big-label">Общо похарчени</div>
    <div class="hero-big-val">
      <span class="big" id="jsTotalSpent"><?= number_format($totalSpent, 2, ',', ' ') ?></span>
      <span class="cur">€</span>
    </div>
    <div class="hero-bonuses" id="jsHeroBonuses">
      <?php if (!empty($activeBonuses)): ?>
        <?php foreach ($activeBonuses as $b): ?>
          <span class="bonus-chip <?= $b['type_group']==='welcome' ? 'bonus-chip-welcome' : 'bonus-chip-acc' ?>">
            <?= h($b['badge']) ?> <?= h($b['title']) ?>
          </span>
        <?php endforeach; ?>
      <?php else: ?>
        <span class="bonus-chip bonus-chip-empty">Няма активни бонуси</span>
      <?php endif; ?>
    </div>
    <p class="qr-hint-light">Сканирай кода при всяка покупка</p>
    <p class="qr-sync-light" id="jsSyncNote">Картата се обновява автоматично</p>
  </div>

  <!-- ══ CYCLE RINGS (3 колони) ══ -->
  <div class="cycles">
    <div class="glass sm q3 cycle-box">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <div class="cycle-ring">
        <svg viewBox="0 0 80 80">
          <circle class="track" cx="40" cy="40" r="34"/>
          <circle class="fill" cx="40" cy="40" r="34" stroke-dasharray="213.6" stroke-dashoffset="<?= number_format($ringOffset10, 2, '.', '') ?>" id="jsBar10" style="stroke:hsl(145 65% 45%);filter:drop-shadow(0 0 6px hsl(145 70% 50% / .6));"/>
        </svg>
        <div class="cycle-num" id="jsCount10"><?= (int)$cyclePurchases10 ?></div>
      </div>
      <div class="cycle-label">/ 10</div>
      <div class="cycle-remain" id="jsLeft10Wrap">Остават <span id="jsLeft10"><?= (int)$left10 ?></span></div>
    </div>

    <div class="glass sm cycle-box">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <div class="cycle-ring">
        <svg viewBox="0 0 80 80">
          <circle class="track" cx="40" cy="40" r="34"/>
          <circle class="fill" cx="40" cy="40" r="34" stroke-dasharray="213.6" stroke-dashoffset="<?= number_format($ringOffset50, 2, '.', '') ?>" id="jsBar50" style="filter:drop-shadow(0 0 6px hsl(255 70% 60% / .6));"/>
        </svg>
        <div class="cycle-num" id="jsCount50"><?= (int)$cyclePurchases50 ?></div>
      </div>
      <div class="cycle-label">/ 50</div>
      <div class="cycle-remain">Остават <span id="jsLeft50"><?= (int)$left50 ?></span></div>
    </div>

    <div class="glass sm cycle-box">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <div class="cycle-ring">
        <svg viewBox="0 0 80 80">
          <circle class="track" cx="40" cy="40" r="34"/>
          <circle class="fill" cx="40" cy="40" r="34" stroke-dasharray="213.6" stroke-dashoffset="<?= number_format($ringOffset100, 2, '.', '') ?>" id="jsBar100" style="filter:drop-shadow(0 0 6px hsl(255 70% 60% / .6));"/>
        </svg>
        <div class="cycle-num" id="jsCount100"><?= (int)$cyclePurchases100 ?></div>
      </div>
      <div class="cycle-label">/ 100</div>
      <div class="cycle-remain">Остават <span id="jsLeft100"><?= (int)$left100 ?></span></div>
    </div>
  </div>

  <!-- ══ CASHBACK BAR (5% бонус прогрес) ══ -->
  <div class="section">
    <div class="glass sm cashback">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <div class="cashback-head">
        <div class="cashback-lbl">
          <svg viewBox="0 0 24 24"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
          До 5% бонус
        </div>
        <div class="cashback-val" id="jsToNextBonus"><?= h(euro_text($toNext5Percent)) ?></div>
      </div>
      <div class="cashback-bar">
        <div class="cashback-fill" id="jsCashbackFill" style="width:<?= number_format($cashbackProgress, 2, '.', '') ?>%"></div>
      </div>
      <div class="cashback-sub">Похарчени <strong id="jsCycleSpent"><?= number_format($cycleSpent100, 2, '.', '') ?>€</strong> / 100€ · 5% бонус при следваща 100€ обороти</div>
    </div>
  </div>

  <!-- ══ NUDGE #2: Почти на награда (auto-show при left10 ≤ 2) ══ -->
  <div class="section hidden" id="nudgeAlmost">
    <div class="glass q5 nudge">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <div class="nudge-icon">
        <svg viewBox="0 0 24 24"><path d="M8.5 14.5A2.5 2.5 0 0011 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 11-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 002.5 2.5z"/></svg>
      </div>
      <div class="nudge-text">
        <div class="nudge-label">Почти там!</div>
        <div class="nudge-title" id="nudgeAlmostTitle">Само 1 покупка до награда</div>
        <div class="nudge-sub">Награда 5€ ваучер при следваща покупка</div>
      </div>
      <div class="nudge-arrow">
        <svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
      </div>
    </div>
  </div>

  <!-- ══ PROGRESS REWARDS (legacy секция, запазена) ══ -->
  <div class="section">
    <div class="glass sm q3 section-card">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <div class="sec-title">
        <span class="sec-title-icon">
          <svg viewBox="0 0 24 24"><path d="M6 9H4.5a2.5 2.5 0 010-5H6"/><path d="M18 9h1.5a2.5 2.5 0 000-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0012 0V2z"/></svg>
        </span>
        Прогрес на наградите
      </div>

      <div class="reward-row">
        <div class="reward-meta">
          <span class="reward-name">Награда 5€</span>
          <span class="reward-count"><strong id="jsCount10b"><?= (int)$cyclePurchases10 ?></strong> / 10 покупки</span>
        </div>
        <div class="progress-track"><div class="progress-fill" id="jsBar10b" style="width:<?= $progress10 ?>%"></div></div>
        <div class="reward-foot">Остават <span id="jsLeft10b"><?= (int)$left10 ?></span> покупки</div>
      </div>

      <div class="reward-divider"></div>

      <div class="reward-row">
        <div class="reward-meta">
          <span class="reward-name">Награда 50€</span>
          <span class="reward-count"><strong id="jsCount50b"><?= (int)$cyclePurchases50 ?></strong> / 50 покупки</span>
        </div>
        <div class="progress-track"><div class="progress-fill" id="jsBar50b" style="width:<?= $progress50 ?>%"></div></div>
        <div class="reward-foot">Остават <span id="jsLeft50b"><?= (int)$left50 ?></span> покупки</div>
      </div>

      <div class="reward-divider"></div>

      <div class="reward-row">
        <div class="reward-meta">
          <span class="reward-name">Награда 150€</span>
          <span class="reward-count"><strong id="jsCount100b"><?= (int)$cyclePurchases100 ?></strong> / 100 покупки</span>
        </div>
        <div class="progress-track"><div class="progress-fill" id="jsBar100b" style="width:<?= $progress100 ?>%"></div></div>
        <div class="reward-foot">Остават <span id="jsLeft100b"><?= (int)$left100 ?></span> покупки</div>
      </div>
    </div>
  </div>

  <!-- ══ LAST PURCHASE (нов компактен row) ══ -->
  <div class="section">
    <div class="sec-label">
      <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
      Последна покупка
    </div>
    <div class="glass sm last-purchase">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <div class="lp-icon">
        <svg viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
      </div>
      <div class="lp-text">
        <div class="lp-lbl">Сума</div>
        <div class="lp-val" id="jsLastPurchase"><?= h($lastPurchaseDisplay) ?></div>
        <div class="lp-date" id="jsLastPurchaseDate"><?= h($lastPurchaseDateDisplay) ?></div>
      </div>
    </div>
  </div>

  <!-- ══ ACTION BUTTONS ══ -->
  <div class="act-row">
    <button class="act-btn primary" id="inviteBtn">
      <svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
      Покани приятел
    </button>
    <button class="act-btn" id="contactBtn">
      <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
      Контакт
    </button>
  </div>

  <!-- ══ BANNERS ══ -->
  <div class="section">
    <div class="sec-label">
      <svg viewBox="0 0 24 24"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 100-5C13 2 12 7 12 7z"/></svg>
      Актуални оферти
    </div>
    <div id="jsBannerArea">
      <?php if (!empty($banners)): ?>
        <?php foreach ($banners as $b): ?>
          <?php $wrap = !empty($b['link_url']) ? '<a href="'.h($b['link_url']).'" class="glass sm q6 banner-item">' : '<div class="glass sm q6 banner-item">';
                $close = !empty($b['link_url']) ? '</a>' : '</div>'; ?>
          <?= $wrap ?>
            <span class="shine"></span><span class="shine shine-bottom"></span>
            <span class="glow"></span><span class="glow glow-bottom"></span>
            <?php if (!empty($b['image_url'])): ?>
              <img src="<?= h($b['image_url']) ?>" class="banner-img" alt="" loading="lazy">
            <?php endif; ?>
            <p class="banner-title"><?= h($b['title']) ?></p>
            <?php if (!empty($b['body'])): ?><p class="banner-body"><?= h($b['body']) ?></p><?php endif; ?>
          <?= $close ?>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="glass sm q6 banner-empty">
          <span class="shine"></span><span class="shine shine-bottom"></span>
          <span class="glow"></span><span class="glow glow-bottom"></span>
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
          <span>Скоро тук ще се появят специални оферти само за теб</span>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ ACTIVE BONUSES DETAIL ══ -->
  <div class="section">
    <div class="glass sm q5 section-card">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <div class="sec-title">
        <span class="sec-title-icon">
          <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        </span>
        Активни бонуси
      </div>
      <p style="margin:0 0 12px;font-size:12px;font-weight:600;color:var(--text-secondary);position:relative;z-index:5;" id="jsActiveBonusNotice">
        <?= h($activeBonusNotice) ?>
      </p>
      <div id="jsBonusList" style="position:relative;z-index:5;">
        <?php if (!empty($activeBonuses)): ?>
          <?php foreach ($activeBonuses as $b): ?>
            <div class="ab-item">
              <div class="ab-badge-big <?= $b['type_group']==='welcome' ? 'ab-badge-welcome' : 'ab-badge-acc' ?>"><?= h($b['badge']) ?></div>
              <div class="ab-content">
                <p class="ab-title"><?= h($b['title']) ?></p>
                <p class="ab-desc"><?= h($b['desc']) ?></p>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="ab-empty">В момента няма активни бонуси или ваучери</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ══ ИНСТАЛИРАЙ ПРИЛОЖЕНИЕТО ══ -->
  <div class="section" id="installSection">
    <div class="glass sm q5 section-card">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <div class="install-icon-wrap">
        <svg viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
      </div>
      <p class="install-title">Добави на началния екран</p>
      <p class="install-body">Достъп до картата с едно докосване, без да отваряш браузър</p>
      <button class="ov-btn ov-btn-primary" id="installFooterBtn" style="width:100%;margin-bottom:10px;">
        <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span>Инсталирай приложението</span>
      </button>
      <p style="margin:0;font-size:11px;color:var(--text-muted);font-weight:600;position:relative;z-index:5;" id="installFooterHint"></p>
    </div>
  </div>

</div><!-- /app -->

<!-- ══ A2HS OVERLAY ══ -->
<div class="overlay hidden" id="a2hsOverlay">
  <div class="glass q5 overlay-modal">
    <span class="shine"></span><span class="shine shine-bottom"></span>
    <span class="glow"></span><span class="glow glow-bottom"></span>
    <div class="overlay-logo-wrap">
      <img src="/assets/img/eni-tiholov-logo.png" alt="Ени Тихолов" onerror="this.src='/icon-192.png';">
    </div>
    <p class="overlay-title">Добави на началния екран</p>
    <p class="overlay-text">Запази лоялната си карта на телефона — достъп с едно докосване, без браузър.</p>

    <div id="a2hsAndroid">
      <div class="overlay-actions">
        <button class="ov-btn ov-btn-primary" id="a2hsInstallBtn">
          <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          <span>Инсталирай</span>
        </button>
        <button class="ov-btn ov-btn-secondary" id="a2hsLaterBtn">Не искам да имам отстъпки</button>
      </div>
    </div>

    <div id="a2hsAndroidManual" class="hidden">
      <div class="overlay-steps">
        <div class="overlay-step">
          <span class="step-num">1</span>
          <span>Натисни <strong>⋮</strong> (три точки горе дясно) в Chrome
            <svg style="display:inline-block;vertical-align:middle;width:14px;height:14px;stroke:hsl(var(--hue1) 60% 42%);stroke-width:2;fill:none;stroke-linecap:round;margin-left:2px" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
          </span>
        </div>
        <div class="overlay-step">
          <span class="step-num">2</span>
          <span>Избери <strong>„Добави на началния екран"</strong>
            <br><span style="font-size:11px;color:var(--text-muted)">(или „Install app" на англ.)</span>
          </span>
        </div>
        <div class="overlay-step">
          <span class="step-num">3</span>
          <span>Потвърди с <strong>„Добави"</strong> в pop-up-а</span>
        </div>
      </div>
      <button class="ov-btn ov-btn-secondary" style="width:100%" id="a2hsAndroidManualClose">
        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        <span>Разбрах</span>
      </button>
    </div>

    <div id="a2hsIos" class="hidden">
      <div class="overlay-steps">
        <div class="overlay-step">
          <span class="step-num">1</span>
          <span>Докосни бутона <strong>Споделяне</strong> в долната лента на Safari
            <svg style="display:inline-block;vertical-align:middle;width:14px;height:14px;stroke:hsl(var(--hue1) 60% 42%);stroke-width:2;fill:none;stroke-linecap:round;margin-left:2px" viewBox="0 0 24 24"><path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
          </span>
        </div>
        <div class="overlay-step">
          <span class="step-num">2</span>
          <span>Избери <strong>„Добави към начален екран"</strong> от менюто</span>
        </div>
        <div class="overlay-step">
          <span class="step-num">3</span>
          <span>Потвърди с <strong>„Добави"</strong> горе вдясно</span>
        </div>
      </div>
      <button class="ov-btn ov-btn-secondary" style="width:100%" id="a2hsIosClose">
        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        <span>Разбрах</span>
      </button>
    </div>

    <p class="ov-skip" id="a2hsSkip">Пропусни засега</p>
  </div>
</div>

<!-- ══ PUSH PERMISSION OVERLAY ══ -->
<div class="overlay hidden" id="pushOverlay">
  <div class="glass q5 overlay-modal">
    <span class="shine"></span><span class="shine shine-bottom"></span>
    <span class="glow"></span><span class="glow glow-bottom"></span>
    <div class="overlay-bell-wrap">
      <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
    </div>
    <p class="overlay-title">Получавай известия</p>
    <p class="overlay-text">Разреши известия и ще те уведомим при <strong>нов ваучер</strong>, <strong>отключена награда</strong> или <strong>седмична оферта</strong>.</p>
    <div class="overlay-actions">
      <button class="ov-btn ov-btn-primary" id="pushAllowBtn">
        <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        <span>Разреши</span>
      </button>
      <button class="ov-btn ov-btn-secondary" id="pushLaterBtn">Не искам да имам отстъпки</button>
    </div>
    <p class="ov-skip" id="pushSkip">Никога не питай отново</p>
  </div>
</div>

<!-- ══ CONTACT OVERLAY (Viber + Tel) ══ -->
<div class="overlay hidden" id="contactOverlay">
  <div class="glass q3 overlay-modal">
    <span class="shine"></span><span class="shine shine-bottom"></span>
    <span class="glow"></span><span class="glow glow-bottom"></span>
    <div class="overlay-bell-wrap">
      <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
    </div>
    <p class="overlay-title">Свържи се с нас</p>
    <p class="overlay-text">Избери как искаш да се свържеш — <strong><?= h($contactPhone) ?></strong></p>
    <div class="overlay-actions">
      <a href="viber://chat?number=<?= h(urlencode($contactPhone)) ?>" class="ov-btn ov-btn-viber" id="contactViberBtn">
        <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2h-4l-4 4v-4H7a2 2 0 01-2-2V5a2 2 0 012-2h12a2 2 0 012 2z"/></svg>
        <span>Viber</span>
      </a>
      <a href="tel:<?= h($contactPhone) ?>" class="ov-btn ov-btn-call" id="contactCallBtn">
        <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
        <span>Обади се</span>
      </a>
    </div>
    <p class="ov-skip" id="contactClose">Затвори</p>
  </div>
</div>

<!-- ══ INVITE OVERLAY (Viber / WhatsApp / SMS / Copy link) ══ -->
<div class="overlay hidden" id="inviteOverlay">
  <div class="glass q1 overlay-modal">
    <span class="shine"></span><span class="shine shine-bottom"></span>
    <span class="glow"></span><span class="glow glow-bottom"></span>
    <div class="overlay-bell-wrap">
      <svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
    </div>
    <p class="overlay-title">Покани приятел</p>
    <p class="overlay-text">Приятелят ти получава <strong>-5% Welcome бонус</strong> при регистрация. Избери как да изпратиш поканата.</p>
    <div class="invite-grid">
      <a href="#" class="ov-btn ov-btn-viber invite-btn" data-method="viber">
        <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2h-4l-4 4v-4H7a2 2 0 01-2-2V5a2 2 0 012-2h12a2 2 0 012 2z"/></svg>
        <span>Viber</span>
      </a>
      <a href="#" class="ov-btn ov-btn-whatsapp invite-btn" data-method="whatsapp">
        <svg viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
        <span>WhatsApp</span>
      </a>
      <a href="#" class="ov-btn ov-btn-sms invite-btn" data-method="sms">
        <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        <span>SMS</span>
      </a>
      <button class="ov-btn ov-btn-secondary invite-btn" data-method="copy" id="inviteCopyLinkBtn">
        <svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
        <span>Копирай линк</span>
      </button>
    </div>
    <p class="ov-skip" id="inviteCloseBtn">Затвори</p>
    <p class="ov-skip" id="inviteCopiedNote" style="display:none;color:hsl(145 65% 38%);font-weight:800;">Линкът е копиран в clipboard!</p>
  </div>
</div>

<script>
/* ── Запазване/възстановяване на картата от localStorage ── */
(function(){
  const LS_KEY = 'eni_card_number';
  const params = new URLSearchParams(window.location.search);
  const cardInUrl = params.get('card') || '';

  if (cardInUrl) {
    try { localStorage.setItem(LS_KEY, cardInUrl); } catch(e){}
  } else {
    let saved = '';
    try { saved = localStorage.getItem(LS_KEY) || ''; } catch(e){}
    if (saved) {
      const url = new URL(window.location.href);
      url.searchParams.set('card', saved);
      window.location.replace(url.toString());
    }
  }
})();

const CARD_NUMBER = <?= json_encode($cardNumber, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const REF_CODE    = <?= json_encode($refCode, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const VAPID_KEY   = <?= json_encode($vapidPublicKey, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const CONTACT_PHONE = <?= json_encode($contactPhone, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const BASE_URL    = window.location.pathname;
const PAGE_URL    = window.location.href;
const RING_CIRCUMFERENCE = 213.6;

const INVITE_BASE_URL = 'https://loyalty.donela.bg/register.php';

function buildInviteUrl(source){
  let url = INVITE_BASE_URL + '?source=' + encodeURIComponent(source||'share');
  if (REF_CODE) url += '&ref=' + encodeURIComponent(REF_CODE);
  return url;
}
function buildInviteText(source){
  const link = buildInviteUrl(source);
  return 'Здравей! Препоръчвам ти лоялната карта на Ени Тихолов — получаваш 5% Welcome бонус и награди за всяка покупка.\n\nРегистрирай се тук: ' + link;
}

const $ = id => document.getElementById(id);
const jsCustomerName      = $('jsCustomerName');
const jsStatusLabel       = $('jsStatusLabel');
const jsCardNumber        = $('jsCardNumber');
const jsQrImage           = $('jsQrImage');
const jsQrCardNum         = $('jsQrCardNum');
const jsLastPurchase      = $('jsLastPurchase');
const jsLastPurchaseDate  = $('jsLastPurchaseDate');
const jsToNextBonus       = $('jsToNextBonus');
const jsTotalSpent        = $('jsTotalSpent');
const jsCashbackFill      = $('jsCashbackFill');
const jsCycleSpent        = $('jsCycleSpent');
const jsCount10           = $('jsCount10');
const jsCount50           = $('jsCount50');
const jsCount100          = $('jsCount100');
const jsBar10             = $('jsBar10');
const jsBar50             = $('jsBar50');
const jsBar100            = $('jsBar100');
const jsLeft10            = $('jsLeft10');
const jsLeft50            = $('jsLeft50');
const jsLeft100           = $('jsLeft100');
const jsCount10b          = $('jsCount10b');
const jsCount50b          = $('jsCount50b');
const jsCount100b         = $('jsCount100b');
const jsBar10b            = $('jsBar10b');
const jsBar50b            = $('jsBar50b');
const jsBar100b           = $('jsBar100b');
const jsLeft10b           = $('jsLeft10b');
const jsLeft50b           = $('jsLeft50b');
const jsLeft100b          = $('jsLeft100b');
const jsActiveBonusNotice = $('jsActiveBonusNotice');
const jsBonusList         = $('jsBonusList');
const jsHeroBonuses       = $('jsHeroBonuses');
const jsSyncNote          = $('jsSyncNote');
const errorPanel          = $('errorPanel');
const errorText           = $('errorText');
const nudgeVoucher        = $('nudgeVoucher');
const nudgeVoucherTitle   = $('nudgeVoucherTitle');
const nudgeVoucherSub     = $('nudgeVoucherSub');
const nudgeAlmost         = $('nudgeAlmost');
const nudgeAlmostTitle    = $('nudgeAlmostTitle');

function esc(s){
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function euroJs(v){
  const n=parseFloat(v||0);
  return (isNaN(n)?0:n).toFixed(2)+'€';
}
function formatTotal(v){
  const n=parseFloat(v||0);
  return (isNaN(n)?0:n).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}
function clamp(v,lo,hi){ return Math.max(lo,Math.min(hi,parseFloat(v)||0)); }

function renderHeroBonuses(items){
  if (!Array.isArray(items)||!items.length){
    jsHeroBonuses.innerHTML='<span class="bonus-chip bonus-chip-empty">Няма активни бонуси</span>';
    return;
  }
  let h='';
  items.forEach(b=>{
    const cls=b.type_group==='welcome'?'bonus-chip-welcome':'bonus-chip-acc';
    h+=`<span class="bonus-chip ${cls}">${esc(b.badge)} ${esc(b.title)}</span>`;
  });
  jsHeroBonuses.innerHTML=h;
}

function renderBonusList(items){
  if (!Array.isArray(items)||!items.length){
    jsBonusList.innerHTML='<div class="ab-empty">В момента няма активни бонуси или ваучери</div>';
    return;
  }
  let h='';
  items.forEach(b=>{
    const cls=b.type_group==='welcome'?'ab-badge-welcome':'ab-badge-acc';
    h+=`<div class="ab-item">
      <div class="ab-badge-big ${cls}">${esc(b.badge)}</div>
      <div class="ab-content">
        <p class="ab-title">${esc(b.title)}</p>
        <p class="ab-desc">${esc(b.desc)}</p>
      </div>
    </div>`;
  });
  jsBonusList.innerHTML=h;
}

function updateNudges(data){
  /* Nudge #1: Активен ваучер (показва се ако има bonuses) */
  if (Array.isArray(data.activeBonuses) && data.activeBonuses.length > 0) {
    const first = data.activeBonuses[0];
    if (nudgeVoucherTitle) nudgeVoucherTitle.textContent = 'Имаш ' + (first.title || 'активен бонус');
    if (nudgeVoucherSub) nudgeVoucherSub.textContent = first.desc || 'Покажи картата при следваща покупка';
    if (nudgeVoucher) nudgeVoucher.classList.remove('hidden');
  } else {
    if (nudgeVoucher) nudgeVoucher.classList.add('hidden');
  }

  /* Nudge #2: Почти на награда (показва се ако left10 ≤ 2 и > 0) */
  const left10 = parseInt(data.left10 || 0, 10);
  if (left10 > 0 && left10 <= 2) {
    if (nudgeAlmostTitle) nudgeAlmostTitle.textContent = 'Само ' + left10 + ' покупк' + (left10===1?'а':'и') + ' до награда';
    if (nudgeAlmost) nudgeAlmost.classList.remove('hidden');
  } else {
    if (nudgeAlmost) nudgeAlmost.classList.add('hidden');
  }
}

function setRingProgress(circle, percent){
  if (!circle) return;
  const p = clamp(percent, 0, 100);
  const offset = RING_CIRCUMFERENCE - (RING_CIRCUMFERENCE * (p / 100));
  circle.setAttribute('stroke-dashoffset', offset.toFixed(2));
}

function renderCardState(data){
  if (!data||!data.ok){
    if (errorPanel) errorPanel.classList.remove('hidden');
    if (errorText)  errorText.textContent=(data&&data.errorMessage)?data.errorMessage:'Грешка при обновяване.';
    return;
  }
  if (errorPanel) errorPanel.classList.add('hidden');

  jsCustomerName.textContent     = data.customerName||'Лоялен клиент';
  jsStatusLabel.textContent      = data.statusLabel||'Активна';
  jsCardNumber.textContent       = data.cardNumber||CARD_NUMBER;
  if (jsQrCardNum) jsQrCardNum.textContent = data.cardNumber||CARD_NUMBER;
  jsLastPurchase.textContent     = data.lastPurchaseDisplay||'—';
  jsLastPurchaseDate.textContent = data.lastPurchaseDateDisplay||'—';
  jsToNextBonus.textContent      = euroJs(data.toNext5Percent||0);

  if (jsTotalSpent) jsTotalSpent.textContent = formatTotal(data.totalSpent || 0);

  if (jsCashbackFill) jsCashbackFill.style.width = clamp(data.cashbackProgress, 0, 100) + '%';
  if (jsCycleSpent) jsCycleSpent.textContent = (parseFloat(data.cycleSpent100||0)).toFixed(2) + '€';

  jsCount10.textContent  = parseInt(data.cyclePurchases10||0,10);
  jsCount50.textContent  = parseInt(data.cyclePurchases50||0,10);
  jsCount100.textContent = parseInt(data.cyclePurchases100||0,10);

  /* Cycle rings (SVG) */
  setRingProgress(jsBar10, data.progress10);
  setRingProgress(jsBar50, data.progress50);
  setRingProgress(jsBar100, data.progress100);

  jsLeft10.textContent  = parseInt(data.left10||0,10);
  jsLeft50.textContent  = parseInt(data.left50||0,10);
  jsLeft100.textContent = parseInt(data.left100||0,10);

  /* Legacy progress bars (запазена секция Прогрес на наградите) */
  if (jsCount10b)  jsCount10b.textContent  = parseInt(data.cyclePurchases10||0,10);
  if (jsCount50b)  jsCount50b.textContent  = parseInt(data.cyclePurchases50||0,10);
  if (jsCount100b) jsCount100b.textContent = parseInt(data.cyclePurchases100||0,10);
  if (jsBar10b)  jsBar10b.style.width  = clamp(data.progress10,0,100)+'%';
  if (jsBar50b)  jsBar50b.style.width  = clamp(data.progress50,0,100)+'%';
  if (jsBar100b) jsBar100b.style.width = clamp(data.progress100,0,100)+'%';
  if (jsLeft10b)  jsLeft10b.textContent  = parseInt(data.left10||0,10);
  if (jsLeft50b)  jsLeft50b.textContent  = parseInt(data.left50||0,10);
  if (jsLeft100b) jsLeft100b.textContent = parseInt(data.left100||0,10);

  jsActiveBonusNotice.textContent = data.activeBonusNotice||'В момента няма активни бонуси.';
  renderHeroBonuses(data.activeBonuses||[]);
  renderBonusList(data.activeBonuses||[]);

  updateNudges(data);

  if (jsSyncNote){
    const now=new Date();
    jsSyncNote.textContent='Обновено: '
      +String(now.getHours()).padStart(2,'0')+':'
      +String(now.getMinutes()).padStart(2,'0')+':'
      +String(now.getSeconds()).padStart(2,'0');
  }
}

/* ── Polling ── */
let lastHash='', inFlight=false, pollTimer=null;

async function fetchState(force=false){
  if (!CARD_NUMBER||(!force&&inFlight)) return;
  inFlight=true;
  try{
    const url=new URL(PAGE_URL);
    url.searchParams.set('ajax','status');
    url.searchParams.set('card',CARD_NUMBER);
    url.searchParams.set('_ts',Date.now());
    const res=await fetch(url.toString(),{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}});
    const data=await res.json();
    const h=JSON.stringify(data);
    if(force||h!==lastHash){ lastHash=h; renderCardState(data); }
  }catch(e){ console.log('refresh error',e); }
  finally{ inFlight=false; }
}

function startPolling(){
  if(pollTimer) clearInterval(pollTimer);
  pollTimer=setInterval(()=>fetchState(false),3000);
}

window.addEventListener('pageshow',()=>fetchState(true));
window.addEventListener('focus',()=>fetchState(true));
document.addEventListener('visibilitychange',()=>{ if(!document.hidden) fetchState(true); });
fetchState(true);
startPolling();

/* ══ Initial nudges trigger (от server-rendered values) ══ */
(function initialNudges(){
  const initialBonuses = <?= json_encode($activeBonuses, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const initialLeft10 = <?= (int)$left10 ?>;
  updateNudges({ activeBonuses: initialBonuses, left10: initialLeft10 });
})();

/* ══ A2HS ══ */
const A2HS_KEY         = 'eni_a2hs_done';
const a2hsOverlay      = $('a2hsOverlay');
const installSection   = $('installSection');
const installFooterBtn = $('installFooterBtn');
const installFooterHint= $('installFooterHint');
let deferredPrompt = null;

function isIos(){
  return /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
}
function isInStandaloneMode(){
  return window.matchMedia('(display-mode: standalone)').matches
      || window.navigator.standalone === true;
}

function showA2hsOverlay(){
  if (!a2hsOverlay) return;
  $('a2hsAndroid').classList.add('hidden');
  $('a2hsAndroidManual').classList.add('hidden');
  $('a2hsIos').classList.add('hidden');

  if (isIos()){
    $('a2hsIos').classList.remove('hidden');
  } else if (deferredPrompt){
    $('a2hsAndroid').classList.remove('hidden');
  } else {
    $('a2hsAndroidManual').classList.remove('hidden');
  }
  a2hsOverlay.classList.remove('hidden');
}
function hideA2hsOverlay(permanent){
  if (a2hsOverlay) a2hsOverlay.classList.add('hidden');
  if (permanent) { try{ localStorage.setItem(A2HS_KEY,'1'); }catch(e){} }
}

function setInstallBtnText(txt){
  if (!installFooterBtn) return;
  const span = installFooterBtn.querySelector('span');
  if (span) span.textContent = txt;
  else installFooterBtn.textContent = txt;
}

function updateInstallFooter(){
  if (!installSection) return;
  if (isInStandaloneMode()){
    installSection.classList.add('hidden'); return;
  }
  installSection.classList.remove('hidden');
  if (isIos()){
    setInstallBtnText('Как да инсталирам (iOS)');
    if (installFooterHint) installFooterHint.textContent = 'Safari → Споделяне → Добави към начален екран';
  } else if (deferredPrompt){
    setInstallBtnText('Инсталирай приложението');
    if (installFooterHint) installFooterHint.textContent = '';
  } else {
    setInstallBtnText('Как да инсталирам');
    if (installFooterHint) installFooterHint.textContent = 'Chrome: меню → „Добави на началния екран"';
  }
}

if (installFooterBtn){
  installFooterBtn.addEventListener('click', async () => {
    if (deferredPrompt){
      deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      deferredPrompt = null;
      if (outcome === 'accepted'){ try{ localStorage.setItem(A2HS_KEY,'1'); }catch(e){} }
      updateInstallFooter();
    } else {
      showA2hsOverlay();
    }
  });
}

window.addEventListener('beforeinstallprompt', e => {
  e.preventDefault();
  deferredPrompt = e;
  window.deferredPrompt = e;
  updateInstallFooter();
});

if ($('a2hsInstallBtn')){
  $('a2hsInstallBtn').addEventListener('click', async () => {
    if (deferredPrompt){
      deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      deferredPrompt = null;
    }
    hideA2hsOverlay(true);
    updateInstallFooter();
  });
}
if ($('a2hsLaterBtn'))  $('a2hsLaterBtn').addEventListener('click', () => hideA2hsOverlay(false));
if ($('a2hsIosClose'))  $('a2hsIosClose').addEventListener('click', () => hideA2hsOverlay(true));
if ($('a2hsAndroidManualClose')) $('a2hsAndroidManualClose').addEventListener('click', () => hideA2hsOverlay(true));
if ($('a2hsSkip'))      $('a2hsSkip').addEventListener('click',     () => hideA2hsOverlay(true));

window.matchMedia('(display-mode: standalone)').addEventListener('change', updateInstallFooter);
updateInstallFooter();

/* ══ WEB PUSH ══ */
const PUSH_CHOICE_KEY = 'loyalty_push_choice';
const PUSH_LAST_ASK_KEY = 'loyalty_push_last_ask';
const pushOverlay= $('pushOverlay');

function urlBase64ToUint8Array(base64String){
  const padding='='.repeat((4-base64String.length%4)%4);
  const base64=(base64String+padding).replace(/-/g,'+').replace(/_/g,'/');
  const raw=window.atob(base64);
  return Uint8Array.from([...raw].map(c=>c.charCodeAt(0)));
}

async function subscribePush(){
  if (!('serviceWorker' in navigator)||!('PushManager' in window)) return;
  if (VAPID_KEY==='YOUR_VAPID_PUBLIC_KEY_HERE') return;

  const reg=await navigator.serviceWorker.ready;
  const sub=await reg.pushManager.subscribe({
    userVisibleOnly:true,
    applicationServerKey:urlBase64ToUint8Array(VAPID_KEY)
  });

  const url=new URL(PAGE_URL);
  url.searchParams.set('ajax','push_subscribe');
  await fetch(url.toString(),{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({card:CARD_NUMBER, subscription:sub.toJSON()})
  });
}

async function requestPush(){
  if (!('Notification' in window)) return;
  const perm=await Notification.requestPermission();
  if (perm==='granted') await subscribePush();
  localStorage.setItem(PUSH_CHOICE_KEY,'allowed');
  localStorage.setItem(PUSH_LAST_ASK_KEY, String(Date.now()));
  hidePushOverlay();
}

function showPushOverlay(){
  if (!pushOverlay) return;
  pushOverlay.classList.remove('hidden');
}
function hidePushOverlay(){
  if (pushOverlay) pushOverlay.classList.add('hidden');
}

if ($('pushAllowBtn')) $('pushAllowBtn').addEventListener('click',requestPush);
if ($('pushLaterBtn')) $('pushLaterBtn').addEventListener('click',()=>{
  localStorage.setItem(PUSH_CHOICE_KEY,'later');
  localStorage.setItem(PUSH_LAST_ASK_KEY, String(Date.now()));
  hidePushOverlay();
});
if ($('pushSkip')) $('pushSkip').addEventListener('click',()=>{
  localStorage.setItem(PUSH_CHOICE_KEY,'no_discounts');
  localStorage.setItem(PUSH_LAST_ASK_KEY, String(Date.now()));
  hidePushOverlay();
});

function shouldShowPushOverlay(){
  if (!('Notification' in window)) return false;
  if (Notification.permission === 'granted') return false;
  if (Notification.permission === 'denied') return false;

  let choice = null, lastAsk = 0;
  try {
    choice = localStorage.getItem(PUSH_CHOICE_KEY);
    lastAsk = parseInt(localStorage.getItem(PUSH_LAST_ASK_KEY) || '0', 10);
  } catch(e){}

  if (!choice) return true;
  if (choice === 'allowed') return false;

  const daysSince = (Date.now() - lastAsk) / 86400000;
  if (choice === 'later' && daysSince >= 1) return true;
  if (choice === 'no_discounts' && daysSince >= 14) return true;
  return false;
}

/* old auto-show of pushOverlay disabled — replaced by eni_install_push_modals at end of <body> */

if ('serviceWorker' in navigator){
  navigator.serviceWorker.register('/sw.js')
    .then(async ()=>{
      console.log('SW registered');
      try {
        if ('Notification' in window && Notification.permission === 'granted'){
          const reg = await navigator.serviceWorker.ready;
          const existing = await reg.pushManager.getSubscription();

          let needsResubscribe = !existing;
          if (existing) {
            const currentKey = existing.options?.applicationServerKey;
            if (currentKey) {
              const expectedKey = urlBase64ToUint8Array(VAPID_KEY);
              const currentArr = new Uint8Array(currentKey);
              if (currentArr.length !== expectedKey.length ||
                  !currentArr.every((b,i) => b === expectedKey[i])) {
                await existing.unsubscribe();
                needsResubscribe = true;
              }
            }
          }

          if (needsResubscribe) {
            console.log('Auto-resubscribing with new VAPID key');
            await subscribePush();
          }
        }
      } catch(e) { console.log('Auto-resubscribe failed', e); }
    })
    .catch(e=>console.log('SW failed',e));
}

/* ══ CONTACT BUTTON ══ */
const contactOverlay = $('contactOverlay');
const contactBtn = $('contactBtn');
const contactClose = $('contactClose');

if (contactBtn) contactBtn.addEventListener('click', () => {
  if (contactOverlay) contactOverlay.classList.remove('hidden');
});
if (contactClose) contactClose.addEventListener('click', () => {
  if (contactOverlay) contactOverlay.classList.add('hidden');
});
if (contactOverlay) {
  contactOverlay.addEventListener('click', (e) => {
    if (e.target === contactOverlay) contactOverlay.classList.add('hidden');
  });
}

/* ══ INVITE BUTTON (4 опции: Viber / WhatsApp / SMS / Copy) ══ */
const inviteBtn = $('inviteBtn');
const inviteOverlay = $('inviteOverlay');
const inviteCloseBtn = $('inviteCloseBtn');
const inviteCopyLinkBtn = $('inviteCopyLinkBtn');
const inviteCopiedNote = $('inviteCopiedNote');

function showInviteOverlay(){
  if (!inviteOverlay) return;
  if (inviteCopiedNote) inviteCopiedNote.style.display = 'none';
  inviteOverlay.classList.remove('hidden');
}
function hideInviteOverlay(){
  if (inviteOverlay) inviteOverlay.classList.add('hidden');
}

if (inviteBtn) inviteBtn.addEventListener('click', () => {
  showInviteOverlay();
});
if (inviteCloseBtn) inviteCloseBtn.addEventListener('click', hideInviteOverlay);
if (inviteOverlay) {
  inviteOverlay.addEventListener('click', (e) => {
    if (e.target === inviteOverlay) hideInviteOverlay();
  });
}

/* Всеки invite-btn с data-method → build правилния линк и open */
document.querySelectorAll('.invite-btn').forEach(btn => {
  btn.addEventListener('click', async (e) => {
    e.preventDefault();
    const method = btn.getAttribute('data-method') || 'share';
    const text = buildInviteText(method);
    const link = buildInviteUrl(method);

    if (method === 'viber') {
      window.location.href = 'viber://forward?text=' + encodeURIComponent(text);
      return;
    }
    if (method === 'whatsapp') {
      window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
      return;
    }
    if (method === 'sms') {
      /* iOS/Android различен разделител, но и двата приемат ?body= */
      window.location.href = 'sms:?&body=' + encodeURIComponent(text);
      return;
    }
    if (method === 'copy') {
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(link);
        } else {
          const ta = document.createElement('textarea');
          ta.value = link;
          ta.style.position = 'fixed'; ta.style.left = '-9999px';
          document.body.appendChild(ta);
          ta.select();
          document.execCommand('copy');
          document.body.removeChild(ta);
        }
        if (inviteCopiedNote) inviteCopiedNote.style.display = 'block';
      } catch(err) {
        console.log('Clipboard copy failed', err);
      }
      return;
    }
  });
});

/* ══ NUDGE click handlers (scroll до съответната секция) ══ */
const nudgeVoucherBtn = $('nudgeVoucherBtn');
if (nudgeVoucherBtn) nudgeVoucherBtn.addEventListener('click', () => {
  const target = jsBonusList ? jsBonusList.closest('.section') : null;
  if (target) target.scrollIntoView({ behavior:'smooth', block:'start' });
});

/* expose for the eni install/push modals at end of body */
window.subscribePush = subscribePush;
window.__ENI_CARD_NUMBER = CARD_NUMBER;
</script>

<!-- ═══════════════════════════════════════════════════════════
     ENI INSTALL + PUSH MODALS
     Install: показва се ВСЕКИ път при отваряне когато не е standalone.
     Push:    показва се само ако eni_check.php връща show_push:true
              И Notification.permission === 'default'.
══════════════════════════════════════════════════════════════ -->
<style>
  .eni-modal-bg{position:fixed;inset:0;z-index:99999;display:none;align-items:flex-end;justify-content:center;background:rgba(0,0,0,.65);-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);font-family:'Montserrat',system-ui,sans-serif;padding:0 16px 24px;box-sizing:border-box}
  .eni-modal-bg.eni-show{display:flex}
  @media (min-width:600px){ .eni-modal-bg{align-items:center;padding-bottom:16px} }
  .eni-modal-card{width:100%;max-width:360px;background:#fff;border-radius:24px;padding:28px 22px 22px;box-shadow:0 -8px 40px rgba(0,0,0,.28),0 0 0 1px rgba(232,184,0,.18);transform:translateY(40px);opacity:0;transition:transform .35s cubic-bezier(.2,.8,.2,1),opacity .35s ease;text-align:center;color:#1a1a1a;box-sizing:border-box}
  .eni-modal-bg.eni-show .eni-modal-card{transform:translateY(0);opacity:1}
  .eni-modal-emoji{font-size:46px;line-height:1;margin-bottom:10px}
  .eni-modal-title{font-size:19px;font-weight:800;margin:0 0 8px;color:#1a1a1a;letter-spacing:.2px;font-family:inherit}
  .eni-modal-text{font-size:14px;font-weight:500;line-height:1.45;margin:0 0 22px;color:#555;font-family:inherit}
  .eni-modal-actions{display:flex;gap:10px}
  .eni-modal-btn{flex:1;border:0;border-radius:14px;padding:13px 14px;font-family:'Montserrat',system-ui,sans-serif;font-size:15px;font-weight:700;cursor:pointer;transition:transform .15s ease,box-shadow .15s ease;line-height:1.2}
  .eni-modal-btn:active{transform:scale(.97)}
  .eni-modal-btn-no{background:#eef0f3;color:#444}
  .eni-modal-btn-yes{background:linear-gradient(135deg,#E8B800 0%,#f5c724 100%);color:#1a1a1a;box-shadow:0 4px 16px rgba(232,184,0,.4)}
  .eni-steps{text-align:left;margin:0 0 18px;padding:0;list-style:none}
  .eni-steps li{display:flex;align-items:flex-start;gap:10px;padding:8px 0;font-size:13px;color:#333;line-height:1.45}
  .eni-steps li b.eni-step-num{display:inline-flex;width:22px;height:22px;border-radius:50%;background:#E8B800;color:#fff;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;margin-top:1px;font-weight:700}
</style>

<div class="eni-modal-bg" id="eniInstallModal" role="dialog" aria-modal="true" aria-labelledby="eniInstallTitle">
  <div class="eni-modal-card">
    <div id="eniInstallView1">
      <div class="eni-modal-emoji">📲</div>
      <p class="eni-modal-title" id="eniInstallTitle">Запази картата на телефона!</p>
      <p class="eni-modal-text">Така няма да я губиш и винаги ще ти е под ръка при пазаруване.</p>
      <div class="eni-modal-actions">
        <button type="button" class="eni-modal-btn eni-modal-btn-no" id="eniInstallNo">Не</button>
        <button type="button" class="eni-modal-btn eni-modal-btn-yes" id="eniInstallYes">Да</button>
      </div>
    </div>
    <div id="eniInstallViewIos" style="display:none">
      <div class="eni-modal-emoji">🍎</div>
      <p class="eni-modal-title">Добави на началния екран</p>
      <ul class="eni-steps">
        <li><b class="eni-step-num">1</b><span>Натисни <strong>Споделяне ⬆️</strong> в долната лента на Safari</span></li>
        <li><b class="eni-step-num">2</b><span>Превърти и избери <strong>„Add to Home Screen"</strong></span></li>
        <li><b class="eni-step-num">3</b><span>Натисни <strong>Add</strong> горе вдясно</span></li>
      </ul>
      <button type="button" class="eni-modal-btn eni-modal-btn-yes" style="width:100%" id="eniInstallIosOk">Разбрах</button>
    </div>
    <div id="eniInstallViewAndroid" style="display:none">
      <div class="eni-modal-emoji">📱</div>
      <p class="eni-modal-title">Добави на началния екран</p>
      <ul class="eni-steps">
        <li><b class="eni-step-num">1</b><span>Натисни <strong>⋮</strong> (три точки горе вдясно) в Chrome</span></li>
        <li><b class="eni-step-num">2</b><span>Избери <strong>„Добави на началния екран"</strong></span></li>
        <li><b class="eni-step-num">3</b><span>Потвърди с <strong>„Добави"</strong></span></li>
      </ul>
      <button type="button" class="eni-modal-btn eni-modal-btn-yes" style="width:100%" id="eniInstallAndroidOk">Разбрах</button>
    </div>
  </div>
</div>

<div class="eni-modal-bg" id="eniPushModal" role="dialog" aria-modal="true" aria-labelledby="eniPushTitle">
  <div class="eni-modal-card">
    <div class="eni-modal-emoji">🔔</div>
    <p class="eni-modal-title" id="eniPushTitle">Известия за бонуси?</p>
    <p class="eni-modal-text">Получавай съобщение когато имаш нов ваучер или подарък.</p>
    <div class="eni-modal-actions">
      <button type="button" class="eni-modal-btn eni-modal-btn-no" id="eniPushNo">Не</button>
      <button type="button" class="eni-modal-btn eni-modal-btn-yes" id="eniPushYes">Да</button>
    </div>
  </div>
</div>

<script>
(function(){
  const installModal = document.getElementById('eniInstallModal');
  const pushModal    = document.getElementById('eniPushModal');
  if (!installModal || !pushModal) return;

  const v1   = document.getElementById('eniInstallView1');
  const vIos = document.getElementById('eniInstallViewIos');
  const vAnd = document.getElementById('eniInstallViewAndroid');

  const PUSH_SNOOZE_KEY = 'eni_push_snooze_until';
  const SNOOZE_MS = 7 * 24 * 60 * 60 * 1000;

  function isStandalone(){
    return window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;
  }
  function isIos(){
    return /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
  }

  // Втори listener — гарантира, че deferredPrompt e достъпен в window дори ако
  // първият listener (горе в скрипта) не е изпълнен преди това.
  window.addEventListener('beforeinstallprompt', function(e){
    e.preventDefault();
    window.deferredPrompt = e;
  });

  function showInstall(){
    if (isStandalone()) return;
    v1.style.display = '';
    vIos.style.display = 'none';
    vAnd.style.display = 'none';
    installModal.classList.add('eni-show');
  }
  function hideInstall(){
    installModal.classList.remove('eni-show');
  }

  document.getElementById('eniInstallNo').addEventListener('click', function(){
    hideInstall();
    setTimeout(maybeShowPush, 800);
  });

  document.getElementById('eniInstallYes').addEventListener('click', async function(){
    if (isIos()){
      v1.style.display = 'none';
      vIos.style.display = '';
      return;
    }
    const dp = window.deferredPrompt;
    if (dp && typeof dp.prompt === 'function'){
      try {
        dp.prompt();
        const choice = await dp.userChoice;
        window.deferredPrompt = null;
        hideInstall();
        if (!choice || choice.outcome !== 'accepted'){
          setTimeout(maybeShowPush, 800);
        }
      } catch(err){
        v1.style.display = 'none';
        vAnd.style.display = '';
      }
    } else {
      v1.style.display = 'none';
      vAnd.style.display = '';
    }
  });

  document.getElementById('eniInstallIosOk').addEventListener('click', function(){
    hideInstall();
    setTimeout(maybeShowPush, 800);
  });
  document.getElementById('eniInstallAndroidOk').addEventListener('click', function(){
    hideInstall();
    setTimeout(maybeShowPush, 800);
  });

  function pushSnoozedUntil(){
    try { return parseInt(localStorage.getItem(PUSH_SNOOZE_KEY) || '0', 10); }
    catch(e){ return 0; }
  }
  function snoozePush(){
    try { localStorage.setItem(PUSH_SNOOZE_KEY, String(Date.now() + SNOOZE_MS)); }
    catch(e){}
  }
  function getCardNumber(){
    if (window.__ENI_CARD_NUMBER) return window.__ENI_CARD_NUMBER;
    try { return new URLSearchParams(location.search).get('card') || ''; }
    catch(e){ return ''; }
  }

  async function maybeShowPush(){
    if (!('Notification' in window)) return;
    if (Notification.permission !== 'default') return;
    if (Date.now() < pushSnoozedUntil()) return;
    const card = getCardNumber();
    if (!card) return;
    try {
      const r = await fetch('/eni_check.php?card=' + encodeURIComponent(card), { cache:'no-store' });
      if (!r.ok) return;
      const j = await r.json();
      if (j && j.show_push === true){
        pushModal.classList.add('eni-show');
      }
    } catch(e){}
  }

  document.getElementById('eniPushNo').addEventListener('click', function(){
    snoozePush();
    pushModal.classList.remove('eni-show');
  });

  document.getElementById('eniPushYes').addEventListener('click', async function(){
    pushModal.classList.remove('eni-show'); // hide ВЕДНАГА преди native popup
    if (!('Notification' in window)) return;
    try {
      const perm = await Notification.requestPermission();
      if (perm === 'granted' && typeof window.subscribePush === 'function'){
        try { await window.subscribePush(); } catch(e){}
      } else if (perm !== 'granted') {
        snoozePush();
      }
    } catch(e){}
  });

  function onReady(fn){
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }
  onReady(function(){
    setTimeout(function(){
      if (!isStandalone()) showInstall();
      else maybeShowPush();
    }, 1200);
  });
})();
</script>

</body>
</html>
