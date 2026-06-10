<?php
/*
 * lookup_code.php — auto-fill памет за касиерския екран.
 * Връща ВСИЧКИ варианти на код (brand+price комбинации) от item_variants.
 *
 * Източник: item_variants таблица (multi-variant backfill from purchase_scans).
 * Обновява се при всеки save в kalkulator.php.
 *
 * GET ?code=119
 * Response 200:
 *   {"ok": true, "variants": [
 *      {"brand":"Дафи","price":3.80,"use_count":79},
 *      {"brand":"Статера","price":4.50,"use_count":12}
 *   ]}
 *   {"ok": false, "reason": "not_found"}
 *   {"ok": false, "reason": "invalid_code"}
 *   {"ok": false, "reason": "db_error"}
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/config.php';

$code = preg_replace('/[^A-Za-z0-9._\-]/u', '', (string)($_GET['code'] ?? ''));

if ($code === '' || strlen($code) > 50) {
    echo json_encode(['ok' => false, 'reason' => 'invalid_code']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT brand, price, use_count, last_seen
           FROM item_variants
          WHERE code = :code AND hidden = 0 /* VARIANT_HIDE_v1 */
          ORDER BY use_count DESC, (brand IS NULL OR brand = \'\') ASC, last_seen DESC
          LIMIT 10 /* FIX_LOOKUP_BRAND_FIRST_v3 */'
    );
    $stmt->execute([':code' => $code]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo json_encode(['ok' => false, 'reason' => 'not_found']);
        exit;
    }

    $variants = [];
    foreach ($rows as $r) {
        $variants[] = [
            'brand'     => (string)($r['brand'] ?? ''),
            'price'     => (float)$r['price'],
            'use_count' => (int)$r['use_count'],
            'last_seen' => $r['last_seen'] ?? null,
        ];
    }

    echo json_encode(['ok' => true, 'variants' => $variants], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('lookup_code.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'reason' => 'db_error']);
}
