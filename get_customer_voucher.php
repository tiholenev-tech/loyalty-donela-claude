<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=UTF-8');

$card = trim($_GET['card'] ?? '');

if ($card === '') {
    echo json_encode(['ok' => false]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT c.id, c.total_purchases
    FROM loyalty_cards lc
    JOIN customers c ON c.id = lc.customer_id
    WHERE lc.card_number = :card
    LIMIT 1
");
$stmt->execute(['card' => $card]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    echo json_encode(['ok' => false]);
    exit;
}

$customerId = (int)$customer['id'];

$stmt = $pdo->prepare("
    SELECT id, code, voucher_type, percent_value, used
    FROM vouchers
    WHERE customer_id = :cid
      AND used = 0
    ORDER BY created_at ASC, id ASC
");
$stmt->execute(['cid' => $customerId]);
$vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasReferral = false;
$hasBirthday = false;
$hasWelcome = false;
$hasTurnover = false;
$fixedMax = 0.0;

foreach ($vouchers as $v) {
    $code = strtoupper((string)($v['code'] ?? ''));
    $type = strtolower((string)($v['voucher_type'] ?? ''));
    $value = (float)($v['percent_value'] ?? 0);

    if ($type === 'referral' || strpos($code, 'REF20') === 0 || strpos($code, 'REFER') === 0) {
        $hasReferral = true;
        continue;
    }

    if ($type === 'birthday' || strpos($code, 'BDAY10') === 0 || strpos($code, 'BIRTH10') === 0) {
        $hasBirthday = true;
        continue;
    }

    if (strpos($code, 'WELCOME5') === 0) {
        $hasWelcome = true;
        continue;
    }

    if (strpos($code, 'PCT5') === 0) {
        $hasTurnover = true;
        continue;
    }

    if ($type === 'fixed') {
        $fixedMax = max($fixedMax, $value);
        continue;
    }

    if (strpos($code, 'EUR5') === 0) {
        $fixedMax = max($fixedMax, 5);
        continue;
    }

    if (strpos($code, 'EUR50') === 0) {
        $fixedMax = max($fixedMax, 50);
        continue;
    }

    if (strpos($code, 'EUR150') === 0) {
        $fixedMax = max($fixedMax, 150);
        continue;
    }
}

if ($hasReferral) {
    echo json_encode([
        'ok' => true,
        'percent' => 20,
        'fixed' => 0,
        'referral' => true
    ]);
    exit;
}

$percent = 0;
if ($hasBirthday) $percent += 10;
if ($hasWelcome) $percent += 5;
if ($hasTurnover) $percent += 5;

// Direct welcome logic for first purchase
if ($totalPurchases === 0 && !$hasWelcome) {
    $percent += 5;
}

echo json_encode([
    'ok' => true,
    'percent' => $percent,
    'fixed' => $fixedMax,
    'referral' => false
]);