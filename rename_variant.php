<?php
/*
 * rename_variant.php — RENAME_BRAND_v1
 * Задава/сменя марката (производител) на вариант.
 * POST {code, brand (стара), price, new_brand}
 *  - UPDATE item_variants (с merge при колизия code+нова марка+цена)
 *  - синхронизира item_memory
 *  - добавя новата марка в таблица brands (за падащото меню)
 */
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false, 'error'=>'POST only']);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$code     = trim((string)($body['code']      ?? ''));
$oldBrand = (string)($body['brand']           ?? '');
$price    = round((float)($body['price']      ?? 0), 2);
$newBrand = trim((string)($body['new_brand']  ?? ''));
$newBrand = preg_replace('/\s+/u', ' ', $newBrand);
if (function_exists('mb_substr') && mb_strlen($newBrand) > 80) $newBrand = mb_substr($newBrand, 0, 80);

if ($code === '' || $price <= 0) {
    echo json_encode(['ok'=>false, 'error'=>'invalid_input']);
    exit;
}
if ($newBrand === $oldBrand) {
    echo json_encode(['ok'=>true, 'new_brand'=>$newBrand, 'unchanged'=>true]);
    exit;
}

try {
    $pdo->beginTransaction();

    /* Има ли вече целеви вариант (същи код + нова марка + цена)? → merge */
    $chk = $pdo->prepare("SELECT use_count FROM item_variants
                          WHERE code=:c AND brand=:nb AND ROUND(price,2)=:p LIMIT 1");
    $chk->execute([':c'=>$code, ':nb'=>$newBrand, ':p'=>$price]);
    $targetExists = $chk->fetchColumn();

    if ($targetExists !== false) {
        /* merge: прехвърли use_count от стария към целевия, изтрий стария */
        $src = $pdo->prepare("SELECT use_count FROM item_variants
                              WHERE code=:c AND brand=:ob AND ROUND(price,2)=:p LIMIT 1");
        $src->execute([':c'=>$code, ':ob'=>$oldBrand, ':p'=>$price]);
        $srcUse = (int)$src->fetchColumn();

        $pdo->prepare("UPDATE item_variants SET use_count = use_count + :u, last_seen = NOW()
                       WHERE code=:c AND brand=:nb AND ROUND(price,2)=:p")
            ->execute([':u'=>$srcUse, ':c'=>$code, ':nb'=>$newBrand, ':p'=>$price]);
        $pdo->prepare("DELETE FROM item_variants
                       WHERE code=:c AND brand=:ob AND ROUND(price,2)=:p")
            ->execute([':c'=>$code, ':ob'=>$oldBrand, ':p'=>$price]);
    } else {
        /* обикновено преименуване */
        $pdo->prepare("UPDATE item_variants SET brand=:nb, last_seen = NOW()
                       WHERE code=:c AND brand=:ob AND ROUND(price,2)=:p")
            ->execute([':nb'=>$newBrand, ':c'=>$code, ':ob'=>$oldBrand, ':p'=>$price]);
    }

    /* Синхронизирай item_memory (паметта за auto-fill на този код) */
    $pdo->prepare("UPDATE item_memory SET brand=:nb
                   WHERE code=:c AND (brand=:ob OR brand IS NULL OR brand='')")
        ->execute([':nb'=>$newBrand, ':c'=>$code, ':ob'=>$oldBrand]);

    /* Добави марката в списъка с производители (ако таблицата я има) */
    if ($newBrand !== '') {
        try {
            $pdo->prepare("INSERT IGNORE INTO brands (name, created_at) VALUES (:n, NOW())")
                ->execute([':n'=>$newBrand]);
        } catch (Throwable $be) { /* brands таблицата може още да не съществува — не е критично */ }
    }

    $pdo->commit();
    echo json_encode(['ok'=>true, 'new_brand'=>$newBrand]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('rename_variant: ' . $e->getMessage());
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
