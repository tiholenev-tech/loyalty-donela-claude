<?php
/*
 * cron_push_weekly.php — Седмичен auto push cron
 *
 * Cron: 0 17 * * 1-6 php /var/www/donela.bg/public_html/loyalty/cron_push_weekly.php
 *
 * Логика:
 *   За всеки клиент с push subscription, проверява 4 trigger-а по приоритет:
 *     1. Изтичащ ваучер (≤3 дни) → "Ваучерът ти изтича!"
 *     2. Рожден ден (днес или след 7 дни) → "Честит рожден ден!"
 *     3. Близо до награда (cycle_purchases_10=9, _50=45, _100=95) → "Само 1/5 покупки до X"
 *     4. Близо до 5% cashback (cycle_spent_100 >= 90) → "Още X€ до 5% cashback"
 *
 *   Ако в push_log за тази седмица вече има auto push → SKIP
 *   Праща САМО най-важния (приоритет 1 > 2 > 3 > 4)
 *
 * Flags:
 *   --dry-run         Не праща нищо, само показва кой би получил
 *   --customer=ID     Само за конкретен клиент (за тест)
 *   --force           Игнорирай седмичния dedup (внимание!)
 */

// CLI only
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/push_helper.php';

// ── Парсиране на CLI аргументи
$dryRun     = in_array('--dry-run', $argv, true);
$forceSend  = in_array('--force', $argv, true);
$customerId = null;
foreach ($argv as $a) {
    if (preg_match('/^--customer=(\d+)$/', $a, $m)) $customerId = (int)$m[1];
}

$logFile = __DIR__ . '/cron_push_weekly.log';
function plog(string $msg): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

plog("═══ START " . ($dryRun ? '(DRY-RUN)' : '') . ($forceSend ? ' (FORCE)' : '') . " ═══");

// ── Вземи всички клиенти с активна push subscription
$sql = "
    SELECT DISTINCT c.id AS customer_id, c.first_name, c.last_name,
           c.birth_date, c.cycle_spent_100, c.cycle_purchases_10,
           c.cycle_purchases_50, c.cycle_purchases_100
    FROM customers c
    INNER JOIN push_subscriptions ps ON ps.customer_id = c.id
";
if ($customerId) {
    $sql .= " WHERE c.id = :cid";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($customerId ? ['cid' => $customerId] : []);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

plog("Customers with push subscription: " . count($customers));

if (!$customers) {
    plog("No customers to process. Exit.");
    exit(0);
}

// ── Stats
$stats = ['sent'=>0, 'skipped_dedup'=>0, 'skipped_no_trigger'=>0, 'failed'=>0];

foreach ($customers as $c) {
    $cid    = (int)$c['customer_id'];
    $name   = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: 'Клиент';
    $first  = $c['first_name'] ?: 'там';

    // Dedup проверка (1/седмица)
    if (!$forceSend) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM push_log
            WHERE customer_id = :cid AND push_type = 'auto'
              AND week_year = DATE_FORMAT(NOW(), '%x-W%v')
              AND success = 1
        ");
        $stmt->execute(['cid' => $cid]);
        if ((int)$stmt->fetchColumn() > 0) {
            $stats['skipped_dedup']++;
            plog("[SKIP-DEDUP] cust={$cid} ({$name}) — already got auto push this week");
            continue;
        }
    }

    // ── TRIGGER 1: Изтичащ ваучер (≤3 дни, не used)
    $stmt = $pdo->prepare("
        SELECT id, code, voucher_type, percent_value, amount, expires_at,
               DATEDIFF(expires_at, NOW()) AS days_left
        FROM vouchers
        WHERE customer_id = :cid AND used = 0
          AND expires_at IS NOT NULL
          AND expires_at >= NOW()
          AND expires_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)
        ORDER BY expires_at ASC LIMIT 1
    ");
    $stmt->execute(['cid' => $cid]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($voucher) {
        $value = '';
        if (($voucher['voucher_type'] ?? '') === 'percent' && $voucher['percent_value']) {
            $value = '-' . (int)$voucher['percent_value'] . '%';
        } elseif (($voucher['voucher_type'] ?? '') === 'fixed' && $voucher['amount']) {
            $value = '-' . number_format((float)$voucher['amount'], 0) . '€';
        } else {
            $value = $voucher['code'];
        }
        $days  = (int)$voucher['days_left'];
        $title = $days <= 0
            ? "🚨 ПОСЛЕДЕН ДЕН за {$value} ваучер!"
            : "⏰ Твоят {$value} ваучер изтича след {$days} " . ($days === 1 ? 'ден' : 'дни');
        $body  = "Използвай го преди да е изтекъл.";
        $trigger = 'voucher_expiring';

        plog("[TRIG-1] cust={$cid} ({$name}) — voucher {$value} expires in {$days}d");
        sendOrLog($pdo, $cid, $title, $body, $trigger, $dryRun, $stats);
        continue;
    }

    // ── TRIGGER 2: Рожден ден (днес или в следващите 7 дни)
    if (!empty($c['birth_date'])) {
        $bd = $c['birth_date'];
        // Изчисли този рожден ден тази година
        $today = new DateTime('today');
        $year  = (int)$today->format('Y');
        $month = (int)substr($bd, 5, 2);
        $day   = (int)substr($bd, 8, 2);

        if (checkdate($month, $day, $year)) {
            $bdThis = new DateTime("{$year}-{$month}-{$day}");
            // Ако е минал — взимаме другата година
            if ($bdThis < $today) {
                $bdThis = new DateTime(($year+1) . "-{$month}-{$day}");
            }
            $daysToBd = (int)$today->diff($bdThis)->days;

            if ($daysToBd === 0) {
                $title = "🎂 Честит рожден ден, {$first}!";
                $body  = "Имаш специален -20% само за теб, валиден 14 дни.";
                $trigger = 'birthday_today';
                plog("[TRIG-2] cust={$cid} ({$name}) — birthday TODAY");
                sendOrLog($pdo, $cid, $title, $body, $trigger, $dryRun, $stats);
                continue;
            } elseif ($daysToBd <= 7) {
                // (Ако cron_birthday.php вече е издал ваучер преди 7 дни — той ще е в TRIG-1)
                // Тук просто пропускаме, очакваме cron_birthday.php да си свърши работата
            }
        }
    }

    // ── TRIGGER 3: Близо до награда
    $p10  = (int)$c['cycle_purchases_10'];
    $p50  = (int)$c['cycle_purchases_50'];
    $p100 = (int)$c['cycle_purchases_100'];

    $reward = null;
    if ($p10 === 9)        $reward = ['left'=>1,  'next'=>'-5€',   'trig'=>'near_reward_10'];
    elseif ($p50 === 45)   $reward = ['left'=>5,  'next'=>'-50€',  'trig'=>'near_reward_50'];
    elseif ($p50 === 49)   $reward = ['left'=>1,  'next'=>'-50€',  'trig'=>'near_reward_50'];
    elseif ($p100 === 95)  $reward = ['left'=>5,  'next'=>'-150€', 'trig'=>'near_reward_100'];
    elseif ($p100 === 99)  $reward = ['left'=>1,  'next'=>'-150€', 'trig'=>'near_reward_100'];

    if ($reward) {
        $word  = $reward['left'] === 1 ? 'покупка' : 'покупки';
        $title = "🎯 Само {$reward['left']} {$word} до {$reward['next']}!";
        $body  = "Толкова си близо. Един скок и наградата е твоя.";
        plog("[TRIG-3] cust={$cid} ({$name}) — near reward {$reward['next']} ({$reward['left']} left)");
        sendOrLog($pdo, $cid, $title, $body, $reward['trig'], $dryRun, $stats);
        continue;
    }

    // ── TRIGGER 4: Близо до 5% cashback (cycle_spent_100 >= 90)
    $spent100 = (float)$c['cycle_spent_100'];
    if ($spent100 >= 90 && $spent100 < 100) {
        $left = round(100 - $spent100, 2);
        $title = "⚡ Още " . number_format($left, 2, '.', '') . "€ до 5% cashback!";
        $body  = "Само една малка покупка и отключваш бонус.";
        plog("[TRIG-4] cust={$cid} ({$name}) — near cashback ({$left}€ left)");
        sendOrLog($pdo, $cid, $title, $body, 'near_cashback', $dryRun, $stats);
        continue;
    }

    // Няма trigger
    $stats['skipped_no_trigger']++;
    plog("[SKIP-NOTRIG] cust={$cid} ({$name}) — no active trigger");
}

plog("═══ END ═══");
plog("Sent: {$stats['sent']} · Skipped (dedup): {$stats['skipped_dedup']} · Skipped (no trigger): {$stats['skipped_no_trigger']} · Failed: {$stats['failed']}");

// ──────────────────────────────────────────────────────
// HELPER
// ──────────────────────────────────────────────────────
function sendOrLog(PDO $pdo, int $cid, string $title, string $body,
                   string $trigger, bool $dryRun, array &$stats): void {
    if ($dryRun) {
        plog("  [DRY] would send: '{$title}' / '{$body}'");
        return;
    }
    $r = sendPush($pdo, $cid, $title, $body, 'auto', $trigger);
    if ($r['sent'] > 0) {
        $stats['sent']++;
        plog("  ✅ SENT (devices: {$r['sent']})");
    } else {
        $stats['failed']++;
        plog("  ❌ FAILED reason={$r['reason']} failed={$r['failed']}");
    }
}
