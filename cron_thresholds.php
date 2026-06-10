<?php
/*
 * cron_thresholds.php — ежедневен push 17:00 BG
 * 1) Клиенти на 80%+ от cycle 100€ → "близо до 5% бонус"
 * 2) Клиенти на 8/48/98 покупки → "близо до награда"
 * 3) Клиенти с ДР след 10 дни → "наближава рожден ден"
 *
 * Anti-spam: sendPush() prави 1/седмица dedup.
 */

date_default_timezone_set('Europe/Sofia');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/push_helper.php';

$sent = 0; $skipped = 0; $errors = 0;
$log_lines = [];

function logMsg(string $msg) {
    global $log_lines;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    $log_lines[] = $line;
    echo $line . "\n";
}

logMsg('=== cron_thresholds START ===');

try {
    /* ── 1) Cycle 100€ — клиенти с cycle_spent_100 >= 80 ── */
    $stmt = $pdo->query("
        SELECT id, first_name, ROUND(cycle_spent_100, 2) AS spent
        FROM customers
        WHERE cycle_spent_100 >= 80 AND cycle_spent_100 < 100
          AND status = 'active'
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cid       = (int)$c['id'];
        $remaining = round(100 - (float)$c['spent'], 2);
        $title     = '💰 Близо до награда!';
        $body      = 'Само ' . number_format($remaining, 2) . ' € те делят от 5% отстъпка на следваща покупка!';
        $r = sendPush($pdo, $cid, $title, $body, 'auto', 'near_reward', '/loyalty/card.php');
        if ($r['sent'] > 0) $sent++; elseif ($r['skipped'] > 0) $skipped++; else $errors++;
        logMsg("cycle_100: cid=$cid sent={$r['sent']} reason={$r['reason']}");
    }

    /* ── 2) Milestone 10/50/100 — клиенти на 8/48/98 покупки ── */
    $milestones = [
        ['col'=>'cycle_purchases_10',  'near'=>8,  'reward'=>'5 € ваучер',   'goal'=>10],
        ['col'=>'cycle_purchases_50',  'near'=>48, 'reward'=>'50 € ваучер',  'goal'=>50],
        ['col'=>'cycle_purchases_100', 'near'=>98, 'reward'=>'150 € ваучер', 'goal'=>100],
    ];
    foreach ($milestones as $m) {
        $stmt = $pdo->prepare("
            SELECT id FROM customers
            WHERE {$m['col']} >= :near AND {$m['col']} < :goal
              AND status = 'active'
        ");
        $stmt->execute(['near'=>$m['near'], 'goal'=>$m['goal']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $cid = (int)$cid;
            $title = '🎯 Близо до награда!';
            $body  = 'Само още покупки те делят от ' . $m['reward'] . '! (всяка покупка над 10 €)';
            $r = sendPush($pdo, $cid, $title, $body, 'auto', 'near_milestone', '/loyalty/card.php');
            if ($r['sent'] > 0) $sent++; elseif ($r['skipped'] > 0) $skipped++; else $errors++;
            logMsg("milestone_{$m['goal']}: cid=$cid sent={$r['sent']} reason={$r['reason']}");
        }
    }

    /* ── 3) Рожден ден след 10 дни ── */
    $target = date('m-d', strtotime('+10 days'));
    $stmt = $pdo->prepare("
        SELECT id, first_name FROM customers
        WHERE birth_date IS NOT NULL
          AND DATE_FORMAT(birth_date, '%m-%d') = :t
          AND status = 'active'
    ");
    $stmt->execute(['t' => $target]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cid   = (int)$c['id'];
        $title = '🎂 Наближава рожденият ти ден!';
        $body  = 'След няколко дни ще получиш подарък -20% ваучер за 14 дни. Очаквай!';
        $r = sendPush($pdo, $cid, $title, $body, 'auto', 'birthday_soon', '/loyalty/card.php');
        if ($r['sent'] > 0) $sent++; elseif ($r['skipped'] > 0) $skipped++; else $errors++;
        logMsg("birthday_soon: cid=$cid sent={$r['sent']} reason={$r['reason']}");
    }

    logMsg("=== TOTALS: sent=$sent skipped=$skipped errors=$errors ===");
} catch (Throwable $e) {
    logMsg('FATAL: ' . $e->getMessage());
    error_log('cron_thresholds: ' . $e->getMessage());
}

logMsg('=== cron_thresholds END ===');
