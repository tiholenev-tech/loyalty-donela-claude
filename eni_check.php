<?php
/*
 * eni_check.php — лек endpoint за PWA модалите в card.php.
 * Връща броя направени покупки за дадена карта и дали push модалът
 * трябва да се показва (т.е. дали клиентът е направил >= 1 покупка).
 *
 * GET ?card=ETXXXXXX
 * Response: {"purchases": <int>, "show_push": <bool>}
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/config.php';

$card = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['card'] ?? ''));

if ($card === '') {
    echo json_encode(['purchases' => 0, 'show_push' => false]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT COUNT(ps.id) AS cnt
           FROM loyalty_cards lc
      LEFT JOIN purchase_scans ps
             ON ps.customer_id = lc.customer_id
            AND ps.deleted_at IS NULL
          WHERE lc.card_number = :card'
    );
    $stmt->execute([':card' => $card]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $count = (int)($row['cnt'] ?? 0);
} catch (Throwable $e) {
    error_log('eni_check.php: ' . $e->getMessage());
    $count = 0;
}

echo json_encode([
    'purchases' => $count,
    'show_push' => $count >= 1,
]);
