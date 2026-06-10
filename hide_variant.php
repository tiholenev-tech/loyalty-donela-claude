<?php
/*
 * hide_variant.php — VARIANT_HIDE_v3 FINAL (с price за уникалност)
 * POST {code, brand, price}
 * UPDATE филтрира code + brand + price → 1 запис
 */
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false, 'error'=>'POST only']);
    exit;
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$code  = trim((string)($body['code']  ?? ''));
$brand = (string)($body['brand'] ?? '');
$price = round((float)($body['price'] ?? 0), 2);
if ($code === '' || $price <= 0) {
    echo json_encode(['ok'=>false, 'error'=>'invalid_input']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE item_variants 
                           SET hidden=1, hidden_at=NOW() 
                           WHERE code=:code AND brand=:brand AND ROUND(price, 2)=:price AND hidden=0
                           LIMIT 1");
    $stmt->execute([':code'=>$code, ':brand'=>$brand, ':price'=>$price]);
    $affected = $stmt->rowCount();
    echo json_encode(['ok'=>true, 'hidden'=>$affected]);
} catch (Throwable $e) {
    error_log('hide_variant: ' . $e->getMessage());
    echo json_encode(['ok'=>false, 'error'=>'db_error']);
}
