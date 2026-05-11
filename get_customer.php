<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$card = trim($_GET['card'] ?? '');

if ($card === '') {
    echo json_encode(['ok'=>false]);
    exit;
}

$stmt = $pdo->prepare("
SELECT c.first_name, c.last_name
FROM loyalty_cards lc
JOIN customers c ON c.id = lc.customer_id
WHERE lc.card_number = :card
LIMIT 1
");
$stmt->execute(['card'=>$card]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$row){
    echo json_encode(['ok'=>false]);
    exit;
}

$name = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''));

echo json_encode([
    'ok'=>true,
    'name'=>$name !== '' ? $name : 'Клиент'
]);