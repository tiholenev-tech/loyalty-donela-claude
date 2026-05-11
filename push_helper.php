<?php
/*
 * push_helper.php — Core функция за изпращане на Web Push
 *
 * Използване:
 *   require_once 'push_helper.php';
 *   $result = sendPush($pdo, $customer_id, 'Заглавие', 'Текст', 'auto', 'voucher_issued');
 *
 * Връща: ['sent'=>N, 'skipped'=>N, 'failed'=>N, 'reason'=>'...']
 *
 * Правила:
 *   - auto push: MAX 1/седмица (week_year dedup)
 *   - manual push: без седмичен лимит, но warning ако <14 дни
 *   - Dead subscriptions (410/404) се трият автоматично
 */

require_once __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Изпраща push към един клиент.
 *
 * @param PDO    $pdo
 * @param int    $customer_id
 * @param string $title         (max 200 знака)
 * @param string $body          (max 500 знака)
 * @param string $push_type     'auto' | 'manual'
 * @param string $trigger_type  'voucher_issued' | 'voucher_expiring' | 'birthday' | 'near_reward' | 'manual_promo' | ...
 * @param string $url           (optional) deeplink URL за click, default = /loyalty/card.php
 * @return array ['sent'=>1|0, 'skipped'=>1|0, 'failed'=>1|0, 'reason'=>'...']
 */
function sendPush(PDO $pdo, int $customer_id, string $title, string $body,
                  string $push_type = 'auto', string $trigger_type = 'manual',
                  string $url = '/loyalty/card.php'): array {

    // 1. Dedup за auto: 1/седмица
    if ($push_type === 'auto') {
        $week_year = date('o-\WW');
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM push_log
            WHERE customer_id = :cid
              AND push_type = 'auto'
              AND week_year = :wy
              AND success = 1
        ");
        $stmt->execute(['cid' => $customer_id, 'wy' => $week_year]);
        if ((int)$stmt->fetchColumn() > 0) {
            return ['sent'=>0, 'skipped'=>1, 'failed'=>0, 'reason'=>'already_sent_this_week'];
        }
    }

    // 2. Вземи всички активни subscriptions за клиента
    $stmt = $pdo->prepare("
        SELECT id, endpoint, p256dh, auth
        FROM push_subscriptions
        WHERE customer_id = :cid
    ");
    $stmt->execute(['cid' => $customer_id]);
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$subs) {
        return ['sent'=>0, 'skipped'=>1, 'failed'=>0, 'reason'=>'no_subscriptions'];
    }

    // 3. Прочети VAPID private key
    $privatePath = __DIR__ . '/vapid_private.txt';
    $publicPath  = __DIR__ . '/vapid_public.txt';
    if (!file_exists($privatePath) || !file_exists($publicPath)) {
        return ['sent'=>0, 'skipped'=>0, 'failed'=>1, 'reason'=>'vapid_keys_missing'];
    }

    $vapidPublic  = trim(file_get_contents($publicPath));
    $vapidPrivate = trim(file_get_contents($privatePath));

    $auth = [
        'VAPID' => [
            'subject'    => 'mailto:info@donela.bg',
            'publicKey'  => $vapidPublic,
            'privateKey' => $vapidPrivate,
        ],
    ];

    $webPush = new WebPush($auth);
    $webPush->setDefaultOptions([
        'TTL'     => 86400 * 7, // 7 дни
        'urgency' => 'normal',
    ]);

    // 4. Payload
    $payload = json_encode([
        'title' => mb_substr($title, 0, 200),
        'body'  => mb_substr($body,  0, 500),
        'icon'  => 'https://loyalty.donela.bg/loyalty/icon-192.png',
        'badge' => 'https://loyalty.donela.bg/loyalty/icon-192.png',
        'url'   => $url,
        'tag'   => $trigger_type,
    ], JSON_UNESCAPED_UNICODE);

    // 5. Queue всички subscriptions
    foreach ($subs as $sub) {
        try {
            $subscription = Subscription::create([
                'endpoint'        => $sub['endpoint'],
                'publicKey'       => $sub['p256dh'],
                'authToken'       => $sub['auth'],
                'contentEncoding' => 'aesgcm',
            ]);
            $webPush->queueNotification($subscription, $payload);
        } catch (Throwable $e) {
            // Skip bad subscription
        }
    }

    // 6. Flush и collect resultat
    $sent = 0; $failed = 0; $deadSubIds = [];

    foreach ($webPush->flush() as $report) {
        $endpoint = $report->getRequest()->getUri()->__toString();
        // Намери subscription id по endpoint
        $subId = null;
        foreach ($subs as $s) {
            if (strpos($endpoint, $s['endpoint']) === 0 || $s['endpoint'] === $endpoint) {
                $subId = $s['id']; break;
            }
        }

        if ($report->isSuccess()) {
            $sent++;
        } else {
            $failed++;
            $statusCode = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;
            // 404/410 = subscription е мъртва, триеш я
            if ($statusCode === 404 || $statusCode === 410) {
                if ($subId) $deadSubIds[] = $subId;
            }
        }
    }

    // 7. Изтрий мъртвите subscriptions
    if ($deadSubIds) {
        $placeholders = implode(',', array_fill(0, count($deadSubIds), '?'));
        $pdo->prepare("DELETE FROM push_subscriptions WHERE id IN ($placeholders)")
            ->execute($deadSubIds);
    }

    // 8. Log-ни в push_log
    if ($sent > 0 || $failed > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO push_log
                (customer_id, push_type, trigger_type, title, body, sent_at, week_year, success)
            VALUES
                (:cid, :pt, :tt, :title, :body, NOW(), :wy, :success)
        ");
        $stmt->execute([
            'cid'     => $customer_id,
            'pt'      => $push_type,
            'tt'      => $trigger_type,
            'title'   => $title,
            'body'    => $body,
            'wy'      => date('o-\WW'),
            'success' => $sent > 0 ? 1 : 0,
        ]);
    }

    return [
        'sent'    => $sent,
        'skipped' => 0,
        'failed'  => $failed,
        'reason'  => $sent > 0 ? 'ok' : ($failed > 0 ? 'push_failed' : 'unknown'),
    ];
}

/**
 * Helper: colко дни от последния ръчен push към клиента.
 * Връща INT дни или null ако няма предишен.
 */
function daysSinceLastManualPush(PDO $pdo, int $customer_id): ?int {
    $stmt = $pdo->prepare("
        SELECT DATEDIFF(NOW(), sent_at) AS days
        FROM push_log
        WHERE customer_id = :cid AND push_type = 'manual' AND success = 1
        ORDER BY sent_at DESC LIMIT 1
    ");
    $stmt->execute(['cid' => $customer_id]);
    $d = $stmt->fetchColumn();
    return $d === false ? null : (int)$d;
}
