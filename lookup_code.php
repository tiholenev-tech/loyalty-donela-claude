<?php
/*
 * lookup_code.php — auto-fill памет за касиерския екран.
 * Връща запомнени price + brand за даден артикулен код от item_memory.
 *
 * Източник: item_memory таблица (backfill from purchase_scans history).
 * Логика: most-frequent price + most-frequent brand per code.
 * Обновява се: при всеки save в kalkulator.php (S5 backend rewrite).
 *
 * GET ?code=11685
 * Response 200:
 *   {"ok": true, "price": 1.99, "brand": "Дафи", "use_count": 427}
 *   {"ok": false, "reason": "not_found"}    — код не е в паметта
 *   {"ok": false, "reason": "invalid_code"} — празен или твърде дълъг
 *   {"ok": false, "reason": "db_error"}     — server-side грешка
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/config.php';

// Sanitize: позволяваме само alphanumeric + точка (за коди като 10520.2)
$code = preg_replace('/[^A-Za-z0-9._\-]/u', '', (string)($_GET['code'] ?? ''));

if ($code === '' || strlen($code) > 50) {
    echo json_encode(['ok' => false, 'reason' => 'invalid_code']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT price, brand, use_count, last_seen
           FROM item_memory
          WHERE code = :code
          LIMIT 1'
    );
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['ok' => false, 'reason' => 'not_found']);
        exit;
    }

    echo json_encode([
        'ok'        => true,
        'price'     => $row['price'] !== null ? (float)$row['price'] : null,
        'brand'     => $row['brand'] ?? null,
        'use_count' => (int)$row['use_count'],
        'last_seen' => $row['last_seen'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('lookup_code.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'reason' => 'db_error']);
}
