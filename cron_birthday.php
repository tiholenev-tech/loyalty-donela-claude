<?php
/*
 * cron_birthday.php — издава -20% ваучер 7 дни ПРЕДИ рожден ден
 *
 * Cron: 0 8 * * * php /var/www/donela.bg/public_html/loyalty/cron_birthday.php
 *
 * Правила (от handoff):
 * - 7 дни ПРЕДИ рожден ден → ваучер + push
 * - Валиден 14 дни (7 преди + 7 след)
 * - -20% отстъпка (percent_value=20, voucher_type='percent')
 * - source='birthday', code='BD-<CID>-<YEAR>'
 * - Дедупликация: по (customer_id, source, YEAR) — 1 ваучер годишно
 *
 * Ръчен тест (CLI):
 *   php /var/www/donela.bg/public_html/loyalty/cron_birthday.php
 *   php /var/www/donela.bg/public_html/loyalty/cron_birthday.php --dry-run
 *   php /var/www/donela.bg/public_html/loyalty/cron_birthday.php --days=0   (днес е рожден ден, за тест)
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

/* ── CLI only (защита срещу web abuse) ── */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

/* ── Параметри ── */
$argsRaw = $argv ?? [];
$dryRun  = in_array('--dry-run', $argsRaw, true);
$days    = 7;
foreach ($argsRaw as $a) {
    if (preg_match('/^--days=(\d+)$/', $a, $m)) $days = (int)$m[1];
}

/* ── Log ── */
$logFile = __DIR__ . '/cron_birthday.log';
function cblog(string $msg): void {
    global $logFile;
    $line = date('Y-m-d H:i:s') . ' ' . $msg;
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
    echo $line . PHP_EOL;
}

cblog("═══ cron_birthday START" . ($dryRun ? ' [DRY-RUN]' : '') . " days=$days ═══");

try {
    /* Ден X: рожден ден точно след $days дни (по месец+ден) */
    $targetDate = (new DateTime("+$days days"))->format('m-d');
    $todayYear  = (int)date('Y');
    $issuedAt   = date('Y-m-d H:i:s');
    /* Валидност: 14 дни общо (7 преди + ден X + 7 след = 14 дни от днес) */
    $expiresAt  = (new DateTime('+14 days'))->format('Y-m-d 23:59:59');

    cblog("target mm-dd: $targetDate, year: $todayYear, expires: $expiresAt");

    /* Клиенти с рожден ден на тази дата */
    $stmt = $pdo->prepare("
        SELECT c.id AS customer_id, c.first_name, c.last_name, c.birth_date
        FROM customers c
        WHERE c.birth_date IS NOT NULL
          AND DATE_FORMAT(c.birth_date, '%m-%d') = :t
    ");
    $stmt->execute(['t' => $targetDate]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    cblog("found " . count($customers) . " customer(s) with birthday on $targetDate");

    $issued = 0; $skipped = 0; $pushed = 0; $errors = 0;

    foreach ($customers as $c) {
        $cid  = (int)$c['customer_id'];
        $name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
        if ($name === '') $name = 'клиент';

        /* Дедупликация: вече издаден за тази година? */
        $dup = $pdo->prepare("
            SELECT id FROM vouchers
            WHERE customer_id = :cid
              AND source = 'birthday'
              AND YEAR(COALESCE(issued_at, created_at)) = :yr
            LIMIT 1
        ");
        $dup->execute(['cid' => $cid, 'yr' => $todayYear]);
        if ($dup->fetchColumn()) {
            cblog("  [skip] CID=$cid ($name) — вече издаден за $todayYear");
            $skipped++;
            continue;
        }

        if ($dryRun) {
            cblog("  [dry] CID=$cid ($name) → BD-$cid-$todayYear");
            $issued++;
            continue;
        }

        /* INSERT ваучер */
        $code = "BD-$cid-$todayYear";
        try {
            $ins = $pdo->prepare("
                INSERT INTO vouchers
                    (customer_id, code, voucher_type, percent_value, min_spent,
                     used, source, issued_at, created_at, expires_at)
                VALUES
                    (:cid, :code, 'percent', 20, 0,
                     0, 'birthday', :iss, :iss, :exp)
            ");
            $ins->execute([
                'cid'  => $cid,
                'code' => $code,
                'iss'  => $issuedAt,
                'exp'  => $expiresAt,
            ]);
            cblog("  [ISSUED] CID=$cid ($name) → $code, изтича $expiresAt");
            $issued++;

            /* Push нотификация */
            $pushRes = sendBirthdayPush($pdo, $cid, $name);
            if ($pushRes > 0) {
                $pushed += $pushRes;
                cblog("    [push] CID=$cid → $pushRes device(s)");
            } else {
                cblog("    [push-skip] CID=$cid (няма subscription или helper)");
            }
        } catch (PDOException $e) {
            cblog("  [ERROR] CID=$cid ($name) — " . $e->getMessage());
            $errors++;
        }
    }

    cblog("═══ DONE: issued=$issued, skipped=$skipped, pushed=$pushed, errors=$errors ═══");
    exit($errors > 0 ? 1 : 0);

} catch (Throwable $e) {
    cblog("FATAL: " . $e->getMessage());
    exit(2);
}

/**
 * Изпраща push нотификация до всички subscriptions на клиента.
 * Опитва съществуващ helper; ако няма — skip-ва (ваучерът вече е издаден,
 * клиентът ще го види при следващо отваряне на card.php).
 *
 * @return int брой успешно изпратени push-и
 */
function sendBirthdayPush(PDO $pdo, int $customer_id, string $name): int {
    $title = 'Рожденият ти ден наближава';
    $body  = "$name, подарък -20% те очаква. Валиден 14 дни.";
    $url   = '/loyalty/card.php';

    /* Опит 1: съществуващ helper в loyalty/ */
    $helpers = [
        __DIR__ . '/push_helper.php',
        __DIR__ . '/send_push.php',
        __DIR__ . '/push_notify.php',
        __DIR__ . '/push.php',
    ];
    foreach ($helpers as $h) {
        if (file_exists($h)) {
            require_once $h;
            /* PUSH_INTEGRATION_v1 — use real sendPush from push_helper */
            if (function_exists('sendPush')) {
                global $pdo;
                $r = sendPush($pdo, $customer_id, $title, $body, 'auto', 'birthday', $url);
            } else if (function_exists('sendPushToCustomer')) {
                $r = sendPushToCustomer($customer_id, $title, $body, $url);
                return is_int($r) ? $r : ($r ? 1 : 0);
            }
            if (function_exists('send_push_to_customer')) {
                $r = send_push_to_customer($customer_id, $title, $body, $url);
                return is_int($r) ? $r : ($r ? 1 : 0);
            }
        }
    }

    /* Опит 2: composer web-push library (ако е инсталирана) */
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload) && class_exists('Minishlink\\WebPush\\WebPush', false) === false) {
        require_once $autoload;
    }
    if (class_exists('Minishlink\\WebPush\\WebPush')) {
        try {
            $stmt = $pdo->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE customer_id = :cid");
            $stmt->execute(['cid' => $customer_id]);
            $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$subs) return 0;

            $vapidPublic  = 'BJQdGYTWHFlFOsrslhw-nluDXo_xnB34yxogrfx45DuuCKdmZ8NJsK6bNuvjOr5SyjN90We27G5M02vHU8-WmYs';
            /* VAPID private key се чете от vapid_private.txt ако съществува */
            $privPath = __DIR__ . '/vapid_private.txt';
            if (!file_exists($privPath)) return 0;
            $vapidPrivate = trim(file_get_contents($privPath));

            $auth = [
                'VAPID' => [
                    'subject'    => 'mailto:tihol@donela.bg',
                    'publicKey'  => $vapidPublic,
                    'privateKey' => $vapidPrivate,
                ],
            ];
            $webPush = new Minishlink\WebPush\WebPush($auth);
            $payload = json_encode([
                'title' => $title,
                'body'  => $body,
                'url'   => $url,
                'icon'  => '/loyalty/icon-192.png',
            ], JSON_UNESCAPED_UNICODE);

            $ok = 0;
            foreach ($subs as $s) {
                $sub = Minishlink\WebPush\Subscription::create([
                    'endpoint'        => $s['endpoint'],
                    'publicKey'       => $s['p256dh'],
                    'authToken'       => $s['auth'],
                    'contentEncoding' => 'aesgcm',
                ]);
                $webPush->queueNotification($sub, $payload);
            }
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) $ok++;
            }
            return $ok;
        } catch (Throwable $e) {
            cblog("    [push-err] " . $e->getMessage());
            return 0;
        }
    }

    return 0;
}
