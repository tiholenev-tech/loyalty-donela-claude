<?php
require_once __DIR__ . '/config.php';

/**
 * Loyalty System – My Vouchers
 * Показва ваучерите на клиент по номер на карта
 * Не променя scan.php и не пипа логиката за покупки
 */

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function table_columns(PDO $pdo, string $table): array {
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[] = $row['Field'];
    }
    return $cols;
}

function pick_col(array $existing, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (in_array($c, $existing, true)) return $c;
    }
    return null;
}

function has_col(array $existing, string $col): bool {
    return in_array($col, $existing, true);
}

$cardNumber = trim(
    $_GET['card'] ??
    $_GET['card_number'] ??
    $_POST['card'] ??
    $_POST['card_number'] ??
    ''
);
$error = '';
$customer = null;
$vouchers = [];

try {
    $cardCols = table_columns($pdo, 'loyalty_cards');
    $custCols = table_columns($pdo, 'customers');
    $voucherCols = table_columns($pdo, 'vouchers');

    // loyalty_cards
    $lc_id          = pick_col($cardCols, ['id']);
    $lc_customer_id = pick_col($cardCols, ['customer_id']);
    $lc_card_number = pick_col($cardCols, ['card_number', 'card_no', 'number', 'card_code']);

    // customers
    $c_id              = pick_col($custCols, ['id']);
    $c_name            = pick_col($custCols, ['name', 'full_name']);
    $c_first_name      = pick_col($custCols, ['first_name']);
    $c_last_name       = pick_col($custCols, ['last_name']);
    $c_phone           = pick_col($custCols, ['phone', 'phone_number', 'mobile']);
    $c_total_spent     = pick_col($custCols, ['total_spent']);
    $c_total_purchases = pick_col($custCols, ['total_purchases']);

    // vouchers
    $v_id            = pick_col($voucherCols, ['id']);
    $v_customer_id   = pick_col($voucherCols, ['customer_id']);
    $v_card_id       = pick_col($voucherCols, ['card_id', 'loyalty_card_id']);
    $v_code          = pick_col($voucherCols, ['voucher_code', 'code']);
    $v_type          = pick_col($voucherCols, ['voucher_type', 'type']);
    $v_percent       = pick_col($voucherCols, ['percent_value', 'percent', 'discount_percent']);
    $v_amount        = pick_col($voucherCols, ['amount', 'fixed_value', 'value', 'discount_value']);
    $v_min_spent     = pick_col($voucherCols, ['min_spent']);
    $v_used          = pick_col($voucherCols, ['used', 'is_used']);
    $v_created_at    = pick_col($voucherCols, ['created_at', 'issued_at', 'date_created']);
    $v_expires_at    = pick_col($voucherCols, ['expires_at', 'valid_until', 'expiry_date']);

    if (!$lc_card_number || !$lc_customer_id) {
        throw new Exception('Липсват очаквани колони в таблица loyalty_cards.');
    }
    if (!$c_id) {
        throw new Exception('Липсва id колона в таблица customers.');
    }

    if ($cardNumber !== '') {
        // 1) Намери картата + клиента
        $sqlCustomer = "
            SELECT
                lc.`$lc_card_number` AS card_number,
                c.*
            FROM loyalty_cards lc
            INNER JOIN customers c ON c.`$c_id` = lc.`$lc_customer_id`
            WHERE lc.`$lc_card_number` = :card_number
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sqlCustomer);
        $stmt->execute([':card_number' => $cardNumber]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            $error = 'Няма намерена карта или клиент с този номер.';
        } else {
            // 2) Вземи ваучерите
            $where = [];
            $params = [];

            if ($v_customer_id) {
                $where[] = "v.`$v_customer_id` = :customer_id";
                $params[':customer_id'] = $customer[$c_id];
            } elseif ($v_card_id && $lc_id) {
                $sqlCardId = "SELECT `$lc_id` FROM loyalty_cards WHERE `$lc_card_number` = :card_number LIMIT 1";
                $stmtCard = $pdo->prepare($sqlCardId);
                $stmtCard->execute([':card_number' => $cardNumber]);
                $cardRow = $stmtCard->fetch(PDO::FETCH_ASSOC);
                if ($cardRow) {
                    $where[] = "v.`$v_card_id` = :card_id";
                    $params[':card_id'] = $cardRow[$lc_id];
                }
            }

            if (!$where) {
                throw new Exception('Не може да се определи връзката между vouchers и клиента.');
            }

            if ($v_used) {
                $where[] = "(v.`$v_used` = 0 OR v.`$v_used` IS NULL)";
            }

            if ($v_expires_at) {
                $where[] = "(v.`$v_expires_at` IS NULL OR v.`$v_expires_at` >= NOW())";
            }

            $orderBy = $v_created_at ? " ORDER BY v.`$v_created_at` DESC " : "";

            $sqlVouchers = "SELECT v.* FROM vouchers v WHERE " . implode(' AND ', $where) . $orderBy;
            $stmtV = $pdo->prepare($sqlVouchers);
            $stmtV->execute($params);
            $vouchers = $stmtV->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    $error = 'Грешка: ' . $e->getMessage();
}

function voucher_label(array $v, ?string $v_type, ?string $v_percent, ?string $v_amount): string {
    $type = $v_type && isset($v[$v_type]) ? strtolower(trim((string)$v[$v_type])) : '';

    if ($type === 'percent' || $type === 'percentage' || $type === 'promo_percent') {
        $p = $v_percent && isset($v[$v_percent]) ? (float)$v[$v_percent] : 0;
        return rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.') . '% отстъпка';
    }

    if ($type === 'fixed' || $type === 'amount' || $type === 'money' || $type === 'promo_fixed') {
        $a = $v_amount && isset($v[$v_amount]) ? (float)$v[$v_amount] : 0;
        return number_format($a, 2, '.', '') . ' € ваучер';
    }

    if ($v_percent && !empty($v[$v_percent])) {
        $p = (float)$v[$v_percent];
        return rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.') . '% отстъпка';
    }

    if ($v_amount && !empty($v[$v_amount])) {
        $a = (float)$v[$v_amount];
        return number_format($a, 2, '.', '') . ' € ваучер';
    }

    return 'Ваучер';
}

function voucher_kind(array $v, ?string $v_type, ?string $v_percent, ?string $v_amount): string {
    $type = $v_type && isset($v[$v_type]) ? strtolower(trim((string)$v[$v_type])) : '';
    if (in_array($type, ['percent', 'percentage', 'promo_percent'], true)) return 'percent';
    if (in_array($type, ['fixed', 'amount', 'money', 'promo_fixed'], true)) return 'fixed';

    if ($v_percent && !empty($v[$v_percent])) return 'percent';
    if ($v_amount && !empty($v[$v_amount])) return 'fixed';

    return 'unknown';
}

$percentVouchers = [];
$fixedVouchers   = [];

if (!empty($vouchers)) {
    foreach ($vouchers as $v) {
        $kind = voucher_kind($v, $v_type ?? null, $v_percent ?? null, $v_amount ?? null);
        if ($kind === 'percent') {
            $percentVouchers[] = $v;
        } else {
            $fixedVouchers[] = $v;
        }
    }
}

// -----------------------------
// Progress / Next rewards
// -----------------------------
$totalSpentVal = (float)($customer[$c_total_spent ?? ''] ?? 0);
$totalPurchasesVal = (int)($customer[$c_total_purchases ?? ''] ?? 0);

// Следващ 5% ваучер на всеки 100 €
$nextSpentTarget = (floor($totalSpentVal / 100) + 1) * 100;
$remainingToNextPercent = max(0, $nextSpentTarget - $totalSpentVal);

// Награди по покупки
$purchaseRewards = [
    ['target' => 10,  'label' => '5 € ваучер'],
    ['target' => 50,  'label' => '50 € ваучер'],
    ['target' => 100, 'label' => '150 € ваучер'],
];

$nextPurchaseRewards = [];
foreach ($purchaseRewards as $r) {
    if ($totalPurchasesVal < $r['target']) {
        $r['remaining'] = $r['target'] - $totalPurchasesVal;
        $nextPurchaseRewards[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Моите ваучери</title>
    <style>
        body{
            margin:0;
            font-family:Arial, sans-serif;
            background:#f8f5fb;
            color:#2f2340;
        }
        .wrap{
            max-width:900px;
            margin:0 auto;
            padding:24px 16px 40px;
        }
        .card{
            background:#fff;
            border-radius:18px;
            padding:18px;
            box-shadow:0 8px 24px rgba(0,0,0,0.06);
            margin-bottom:18px;
        }
        h1,h2,h3{margin:0 0 12px;}
        .muted{color:#7b6f8f;}
        .search-form{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }
        .search-form input{
            flex:1;
            min-width:220px;
            padding:14px;
            border:1px solid #ddd;
            border-radius:12px;
            font-size:16px;
        }
        .search-form button{
            padding:14px 18px;
            border:0;
            border-radius:12px;
            background:#7b4ce2;
            color:#fff;
            font-size:16px;
            cursor:pointer;
        }
        .error{
            background:#ffe8e8;
            color:#8b1f1f;
            border:1px solid #f2b8b8;
            padding:12px 14px;
            border-radius:12px;
            margin-top:14px;
        }
        .stats{
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));
            gap:12px;
            margin-top:12px;
        }
        .stat{
            background:#faf7ff;
            border:1px solid #eee6ff;
            border-radius:14px;
            padding:14px;
        }
        .voucher-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));
            gap:14px;
            margin-top:12px;
        }
        .voucher{
            border-radius:16px;
            padding:16px;
            color:#fff;
        }
        .voucher.percent{
            background:linear-gradient(135deg, #8f5cff, #6d3ee8);
        }
        .voucher.fixed{
            background:linear-gradient(135deg, #ff7aa2, #e34f7d);
        }
        .voucher .value{
            font-size:26px;
            font-weight:700;
            margin-bottom:8px;
        }
        .voucher .code{
            font-family:monospace;
            background:rgba(255,255,255,0.18);
            display:inline-block;
            padding:6px 10px;
            border-radius:10px;
            margin-top:8px;
        }
        .empty{
            padding:16px;
            border-radius:12px;
            background:#f5f5f5;
            color:#666;
        }
    </style>
</head>
<body>
<div class="wrap">

    <div class="card">
        <h1>🎁 Моите ваучери</h1>
        <p class="muted">Въведи номера на картата си, за да видиш активните награди.</p>

        <form method="get" class="search-form">
            <input type="text" name="card_number" placeholder="Въведи номер на карта" value="<?= h($cardNumber) ?>">
            <button type="submit">Покажи ваучерите</button>
        </form>

        <?php if ($error): ?>
            <div class="error"><?= h($error) ?></div>
        <?php endif; ?>
    </div>

    <?php if ($customer): ?>
        <div class="card">
            <h2>Клиент</h2>
            <div class="stats">
                <div class="stat">
                    <div class="muted">Карта</div>
                    <strong><?= h($customer['card_number'] ?? $cardNumber) ?></strong>
                </div>

                <div class="stat">
                    <div class="muted">Име</div>
                    <strong>
                        <?php
                        if (!empty($customer[$c_name ?? ''])) {
                            echo h($customer[$c_name]);
                        } else {
                            echo h(trim(($customer[$c_first_name ?? ''] ?? '') . ' ' . ($customer[$c_last_name ?? ''] ?? '')));
                        }
                        ?>
                    </strong>
                </div>

                <div class="stat">
                    <div class="muted">Телефон</div>
                    <strong><?= h($customer[$c_phone ?? ''] ?? '-') ?></strong>
                </div>

                <div class="stat">
                    <div class="muted">Общо покупки</div>
                    <strong><?= h($customer[$c_total_purchases ?? ''] ?? 0) ?></strong>
                </div>

                <div class="stat">
                    <div class="muted">Общ оборот</div>
                    <strong><?= number_format((float)($customer[$c_total_spent ?? ''] ?? 0), 2, '.', '') ?> €</strong>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Следващи награди</h2>

            <div class="stats">
                <div class="stat">
                    <div class="muted">Следващ 5% ваучер</div>
                    <strong>Остават <?= number_format($remainingToNextPercent, 2, '.', '') ?> €</strong>
                    <div class="muted" style="margin-top:6px;">
                        Следващ праг: <?= number_format($nextSpentTarget, 2, '.', '') ?> €
                    </div>
                </div>

                <?php if (!empty($nextPurchaseRewards)): ?>
                    <?php foreach ($nextPurchaseRewards as $r): ?>
                        <div class="stat">
                            <div class="muted"><?= h($r['label']) ?></div>
                            <strong>Остават <?= (int)$r['remaining'] ?> покупки</strong>
                            <div class="muted" style="margin-top:6px;">
                                Цел: <?= (int)$r['target'] ?> покупки
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="stat">
                        <div class="muted">Награди по покупки</div>
                        <strong>Всички текущи прагове са достигнати</strong>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h2>Процентни ваучери</h2>

            <?php if (!empty($percentVouchers)): ?>
                <div class="voucher-grid">
                    <?php foreach ($percentVouchers as $v): ?>
                        <div class="voucher percent">
                            <div class="value"><?= h(voucher_label($v, $v_type ?? null, $v_percent ?? null, $v_amount ?? null)) ?></div>

                            <?php if (!empty($v_min_spent) && isset($v[$v_min_spent]) && $v[$v_min_spent] !== null): ?>
                                <div>Минимална покупка: <?= number_format((float)$v[$v_min_spent], 2, '.', '') ?> €</div>
                            <?php endif; ?>

                            <?php if (!empty($v_expires_at) && !empty($v[$v_expires_at])): ?>
                                <div>Валиден до: <?= h($v[$v_expires_at]) ?></div>
                            <?php endif; ?>

                            <?php if (!empty($v_code) && !empty($v[$v_code])): ?>
                                <div class="code"><?= h($v[$v_code]) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty">Няма активни процентни ваучери.</div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Фиксирани ваучери</h2>

            <?php if (!empty($fixedVouchers)): ?>
                <div class="voucher-grid">
                    <?php foreach ($fixedVouchers as $v): ?>
                        <div class="voucher fixed">
                            <div class="value"><?= h(voucher_label($v, $v_type ?? null, $v_percent ?? null, $v_amount ?? null)) ?></div>

                            <?php if (!empty($v_min_spent) && isset($v[$v_min_spent]) && $v[$v_min_spent] !== null): ?>
                                <div>Минимална покупка: <?= number_format((float)$v[$v_min_spent], 2, '.', '') ?> €</div>
                            <?php endif; ?>

                            <?php if (!empty($v_expires_at) && !empty($v[$v_expires_at])): ?>
                                <div>Валиден до: <?= h($v[$v_expires_at]) ?></div>
                            <?php endif; ?>

                            <?php if (!empty($v_code) && !empty($v[$v_code])): ?>
                                <div class="code"><?= h($v[$v_code]) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty">Няма активни фиксирани ваучери.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>
</body>
</html>