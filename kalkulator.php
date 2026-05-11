<?php
/*
 * kalkulator.php — Самостоятелен калкулатор с история за деня
 *
 * Използва се с: ?location=1 (същите обекти като scan.php)
 *
 * Нужна таблица — изпълни веднъж:
 *
 * CREATE TABLE IF NOT EXISTS calc_sales (
 *   id          INT AUTO_INCREMENT PRIMARY KEY,
 *   location_id INT,
 *   location_name VARCHAR(100),
 *   items_json  MEDIUMTEXT NOT NULL,
 *   gross_total DECIMAL(10,2) NOT NULL DEFAULT 0,
 *   final_total DECIMAL(10,2) NOT NULL DEFAULT 0,
 *   discount    DECIMAL(10,2) NOT NULL DEFAULT 0,
 *   created_at  DATETIME NOT NULL,
 *   INDEX idx_date (created_at),
 *   INDEX idx_loc  (location_id)
 * );
 */

require_once __DIR__ . '/config.php';

date_default_timezone_set('Europe/Sofia');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function jsonOut(array $d): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ── Ден: обикновен календарен ден 00:00 – 23:59 ── */
function businessDate(): string {
    $tz  = new DateTimeZone('Europe/Sofia');
    $now = new DateTime('now', $tz);
    return $now->format('Y-m-d');
}

function bizWindow(string $date): array {
    $tz    = new DateTimeZone('Europe/Sofia');
    $start = new DateTime($date . ' 00:00:00', $tz);
    $end   = new DateTime($date . ' 23:59:59', $tz);
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

/* ── Обект ── */
$locationId   = (int)($_GET['location'] ?? 0);
$locationName = '';
if ($locationId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM locations WHERE id=:id LIMIT 1");
        $stmt->execute(['id' => $locationId]);
        $row = $stmt->fetchColumn();
        if ($row) $locationName = (string)$row;
        else $locationId = 0;
    } catch (Throwable $e) { $locationId = 0; }
}

$ajax = $_GET['ajax'] ?? '';


/* ══════════════════════════════════════════════════════
   AJAX: Lookup на клиент по номер на карта (име + ваучер + история)
   Precise — базиран на doney5ne_loyalty схема:
     loyalty_cards.card_number → customer_id → customers (+ vouchers)
   ══════════════════════════════════════════════════════ */
if ($ajax === 'lookup_card') {
    $card = trim((string)($_GET['card'] ?? ''));
    if ($card === '') jsonOut(['ok' => false, 'card' => $card]);

    $result = [
        'ok' => true,
        'card' => $card,
        'name' => null,
        'customer_id' => null,
        'phone' => null,
        'total_spent' => 0,
        'total_purchases' => 0,
        'active_voucher_amount' => 0,
        'active_voucher_id' => null,
        'active_voucher_type' => null,
        'active_voucher_percent' => null,
        'active_voucher_expires' => null,
    ];

    try {
        // 1) Карта → клиент
        $sql = "SELECT c.id AS cid,
                       TRIM(CONCAT_WS(' ', c.first_name, c.last_name)) AS cname,
                       c.phone,
                       c.total_spent,
                       c.total_purchases
                FROM loyalty_cards lc
                JOIN customers c ON c.id = lc.customer_id
                WHERE lc.card_number = :card
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['card' => $card]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['cid'])) {
            $result['customer_id']     = (int)$row['cid'];
            $result['name']            = $row['cname'] !== '' ? $row['cname'] : null;
            $result['phone']           = $row['phone'] ?: null;
            $result['total_spent']     = round((float)($row['total_spent'] ?? 0), 2);
            $result['total_purchases'] = (int)($row['total_purchases'] ?? 0);

            // 2) Активен ваучер
            $vsql = "SELECT id, amount, voucher_type, percent_value, expires_at
                     FROM vouchers
                     WHERE customer_id = :cid
                       AND status = 'active'
                       AND (used IS NULL OR used = 0)
                       AND (expires_at IS NULL OR expires_at > NOW())
                     ORDER BY id DESC
                     LIMIT 1";
            $vstmt = $pdo->prepare($vsql);
            $vstmt->execute(['cid' => $result['customer_id']]);
            $v = $vstmt->fetch(PDO::FETCH_ASSOC);
            if ($v) {
                $result['active_voucher_id']      = (int)$v['id'];
                $result['active_voucher_amount']  = round((float)($v['amount'] ?? 0), 2);
                $result['active_voucher_type']    = $v['voucher_type'] ?: 'fixed';
                $result['active_voucher_percent'] = $v['percent_value'] !== null ? (int)$v['percent_value'] : null;
                $result['active_voucher_expires'] = $v['expires_at'] ?: null;
            }
        }
    } catch (Throwable $e) {
        // Тихо — не разваляме UI ако DB структурата се е променила
        $result['ok'] = false;
        $result['error'] = $e->getMessage();
    }

    jsonOut($result);
}

/* ══════════════════════════════════════════════════════
   AJAX: Запази продажба
   ══════════════════════════════════════════════════════ */
/* S5-REWRITE-INJECTED: save → purchase_scans + voucher used + customer recompute + item_memory */
if ($ajax === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $items    = $body['items']    ?? [];
    $gross    = round((float)($body['gross']    ?? 0), 2);
    $final    = round((float)($body['final']    ?? 0), 2);
    $discount = round((float)($body['discount'] ?? 0), 2);

    /* S79.UNIFIED полета */
    $cardNumber    = trim((string)($body['card_number']    ?? ''));
    $hasCard       = (int)($body['has_card']       ?? 0);
    $customerId    = (int)($body['customer_id']    ?? 0) ?: null;
    $voucherId     = (int)($body['voucher_id']     ?? 0) ?: null;
    $givenAmount   = isset($body['given_amount'])  && $body['given_amount']  !== null && $body['given_amount']  !== '' ? round((float)$body['given_amount'], 2)  : null;
    $changeAmount  = isset($body['change_amount']) && $body['change_amount'] !== null && $body['change_amount'] !== '' ? round((float)$body['change_amount'], 2) : null;
    $paymentMethod = trim((string)($body['payment_method'] ?? 'cash')) ?: 'cash';

    // Локацията — взимаме от URL (?location=X), тя е най-надеждна
    $locId   = $locationId > 0 ? $locationId : (int)($body['location_id'] ?? 0);
    $locName = $locationName ?: trim((string)($body['location_name'] ?? ''));
    if ($locId > 0 && !$locName) {
        try { $s=$pdo->prepare("SELECT name FROM locations WHERE id=:id LIMIT 1"); $s->execute(['id'=>$locId]); $locName=(string)($s->fetchColumn()?:''); } catch(Throwable $e){}
    }

    if (!$items || $final == 0) jsonOut(['ok' => false, 'error' => 'Няма артикули.']);

    $payload = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        $pdo->beginTransaction();

        /* ── 1. INSERT в purchase_scans (главна таблица) ── */
        $stmt = $pdo->prepare("INSERT INTO purchase_scans
            (customer_id, has_card, store_id, amount, discount_amount, discount_label,
             multiplier, awarded_purchases, created_at,
             location_id, location_name, calc_payload,
             given_amount, change_amount, payment_method)
            VALUES (:cust_id, :has_card, NULL, :amount, :disc_amt, NULL,
                    1, :awarded, :now,
                    :loc_id, :loc_name, :payload,
                    :given, :change, :pm)");
        $stmt->execute([
            'cust_id'  => $customerId,
            'has_card' => $hasCard,
            'amount'   => $final,
            'disc_amt' => $discount,
            'awarded'  => $hasCard ? 1 : 0,
            'now'      => date('Y-m-d H:i:s'),
            'loc_id'   => $locId ?: null,
            'loc_name' => $locName ?: null,
            'payload'  => $payload,
            'given'    => $givenAmount,
            'change'   => $changeAmount,
            'pm'       => $paymentMethod,
        ]);
        $newId = (int)$pdo->lastInsertId();

        /* ── 2. Mark voucher used (ако е изпратен voucher_id) ── */
        $voucherMarked = false;
        if ($voucherId && $customerId) {
            $v = $pdo->prepare("UPDATE vouchers
                                SET used=1, status='used', redeemed_at=NOW()
                                WHERE id=:vid AND customer_id=:cid AND used=0
                                LIMIT 1");
            $v->execute(['vid' => $voucherId, 'cid' => $customerId]);
            $voucherMarked = $v->rowCount() > 0;
        }

        /* ── 3. Recompute customer totals (само при has_card=1) ── */
        if ($customerId && $hasCard) {
            $r = $pdo->prepare("UPDATE customers c
                SET c.total_spent = COALESCE((
                        SELECT SUM(ps.amount) FROM purchase_scans ps
                         WHERE ps.customer_id = c.id AND ps.deleted_at IS NULL
                    ), 0),
                    c.total_purchases = COALESCE((
                        SELECT COUNT(*) FROM purchase_scans ps
                         WHERE ps.customer_id = c.id AND ps.deleted_at IS NULL
                    ), 0)
                WHERE c.id = :cid");
            $r->execute(['cid' => $customerId]);
        }

        /* ── 4. UPSERT в item_memory + item_variants (auto-fill памет) ── */
        try {
            $um = $pdo->prepare("INSERT INTO item_memory (code, price, brand, last_seen, use_count)
                VALUES (:code, :price, :brand, NOW(), 1)
                ON DUPLICATE KEY UPDATE
                    price = IF(VALUES(price) > 0, VALUES(price), price),
                    brand = COALESCE(NULLIF(VALUES(brand),''), brand),
                    last_seen = NOW(),
                    use_count = use_count + 1");
            $uv = $pdo->prepare("INSERT INTO item_variants (code, brand, price, use_count, last_seen)
                VALUES (:code, :brand, :price, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    use_count = use_count + 1,
                    last_seen = NOW()");
            foreach ($items as $it) {
                $code  = trim((string)($it['code']  ?? ''));
                $price = round((float)($it['price'] ?? 0), 2);
                $brand = trim((string)($it['brand'] ?? ''));
                if ($code === '' || $price <= 0) continue;
                $um->execute(['code' => $code, 'price' => $price, 'brand' => $brand ?: null]);
                $uv->execute(['code' => $code, 'brand' => $brand, 'price' => $price]);
            }
        } catch (Throwable $e) {
            error_log('kalkulator.save item_memory: ' . $e->getMessage());
        }

        $pdo->commit();

        jsonOut([
            'ok' => true,
            'id' => $newId,
            'loc_id' => $locId,
            'loc_name' => $locName,
            'voucher_marked' => $voucherMarked,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('kalkulator.save: ' . $e->getMessage());
        jsonOut(['ok' => false, 'error' => $e->getMessage()]);
    }
}

/* ══════════════════════════════════════════════════════
   AJAX: История за деня
   ══════════════════════════════════════════════════════ */
if ($ajax === 'history') {
    $locId   = (int)($_GET['loc']  ?? 0);
    $bizDate = trim($_GET['date']  ?? '');
    if (!$bizDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate)) {
        $bizDate = businessDate();
    }

    try {
        /* S5-REWRITE: чете от purchase_scans (главна таблица), filter has_card=0 за касиерска история */
        [$start, $end] = bizWindow($bizDate);
        $params  = ['start' => $start, 'end' => $end];
        $locCond = $locId > 0 ? 'AND location_id = :loc' : '';
        if ($locId > 0) $params['loc'] = $locId;

        $stmt = $pdo->prepare("
            SELECT id, created_at, calc_payload AS items_json, amount AS final_total,
                   discount_amount AS discount, location_name,
                   given_amount, change_amount, payment_method,
                   has_card, customer_id
            FROM purchase_scans
            WHERE created_at BETWEEN :start AND :end
              AND has_card = 0
              AND deleted_at IS NULL
              $locCond
            ORDER BY created_at ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Обобщение по артикули
        $salesList  = [];
        $itemsAgg   = [];   // code||price => {...}
        $dayGross   = 0.0;
        $dayFinal   = 0.0;
        $dayDiscount= 0.0;

        foreach ($rows as $row) {
            $items = json_decode((string)$row['items_json'], true) ?: [];
            /* S5-REWRITE: gross = final + discount (purchase_scans няма gross_total колона) */
            $dayGross    += (float)$row['final_total'] + (float)$row['discount'];
            $dayFinal    += (float)$row['final_total'];
            $dayDiscount += (float)$row['discount'];

            $parsedItems = [];
            foreach ($items as $item) {
                $code  = trim((string)($item['code']  ?? ''));
                $brand = trim((string)($item['brand'] ?? ''));
                $qty   = max(1, (int)($item['qty']    ?? 1));
                $price = round((float)($item['price'] ?? 0), 2);
                $disc  = (int)($item['disc'] ?? 0);
                $base  = round((float)($item['base']  ?? $qty * $price), 2);
                $final = round((float)($item['final'] ?? $base), 2);
                if ($price <= 0) continue;

                $parsedItems[] = ['code'=>$code,'brand'=>$brand,'qty'=>$qty,'price'=>$price,'disc'=>$disc,'final'=>$final];

                // Агрегация: код + цена
                $key = $code . '||' . number_format($price, 2, '.', '');
                if (!isset($itemsAgg[$key])) {
                    $itemsAgg[$key] = ['code'=>$code,'brand'=>$brand,'qty'=>0,'price'=>$price,'line_base'=>0.0];
                }
                $itemsAgg[$key]['qty']       += $qty;
                $itemsAgg[$key]['line_base'] += round($qty * $price, 2);
                if ($brand && !$itemsAgg[$key]['brand']) $itemsAgg[$key]['brand'] = $brand;
            }

            $salesList[] = [
                'id'          => (int)$row['id'],
                'time'        => substr((string)$row['created_at'], 11, 5),
                'items'       => $parsedItems,
                'gross'       => (float)$row['final_total'] + (float)$row['discount'],
                'final'       => (float)$row['final_total'],
                'discount'    => (float)$row['discount'],
                'location'    => $row['location_name'] ?? '',
            ];
        }

        usort($itemsAgg, fn($a,$b) => strcmp((string)$a['code'], (string)$b['code']));

        jsonOut([
            'ok'          => true,
            'sales'       => $salesList,
            'day_items'   => array_values($itemsAgg),
            'day_gross'   => round($dayGross,    2),
            'day_final'   => round($dayFinal,    2),
            'day_discount'=> round($dayDiscount, 2),
            'biz_date'    => $bizDate,
            'count'       => count($salesList),
        ]);
    } catch (Throwable $e) {
        jsonOut(['ok' => false, 'error' => $e->getMessage()]);
    }
}

/* ══════════════════════════════════════════════════════
   AJAX: Изтрий продажба
   ══════════════════════════════════════════════════════ */
if ($ajax === 'delete_sale' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    /* S5-REWRITE: soft delete (deleted_at) на purchase_scans + recompute totals */
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonOut(['ok'=>false,'error'=>'Липсва ID.']);
    try {
        $pdo->beginTransaction();

        /* Прочети customer_id преди soft-delete (за recompute) */
        $s = $pdo->prepare("SELECT customer_id FROM purchase_scans WHERE id=:id LIMIT 1");
        $s->execute(['id'=>$id]);
        $cid = (int)$s->fetchColumn();

        /* Soft delete */
        $stmt = $pdo->prepare("UPDATE purchase_scans SET deleted_at=NOW() WHERE id=:id AND deleted_at IS NULL");
        $stmt->execute(['id'=>$id]);

        /* Recompute totals ако е с карта */
        if ($cid) {
            $r = $pdo->prepare("UPDATE customers c
                SET c.total_spent = COALESCE((SELECT SUM(amount) FROM purchase_scans WHERE customer_id=c.id AND deleted_at IS NULL), 0),
                    c.total_purchases = COALESCE((SELECT COUNT(*) FROM purchase_scans WHERE customer_id=c.id AND deleted_at IS NULL), 0)
                WHERE c.id=:cid");
            $r->execute(['cid'=>$cid]);
        }

        $pdo->commit();
        jsonOut(['ok'=>true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonOut(['ok'=>false,'error'=>$e->getMessage()]);
    }
}

/* ══════════════════════════════════════════════════════
   AJAX: Редактирай продажба (само бележка/връщане)
   ══════════════════════════════════════════════════════ */
if ($ajax === 'update_sale' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    /* S5-REWRITE: UPDATE purchase_scans + recompute */
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $id       = (int)($body['id']       ?? 0);
    $items    = $body['items']           ?? [];
    $gross    = round((float)($body['gross']    ?? 0), 2);
    $final    = round((float)($body['final']    ?? 0), 2);
    $discount = round((float)($body['discount'] ?? 0), 2);
    if (!$id) jsonOut(['ok'=>false,'error'=>'Липсва ID.']);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE purchase_scans
            SET calc_payload=:items, amount=:final, discount_amount=:disc, edited_at=NOW(), edited_by='kalkulator'
            WHERE id=:id");
        $stmt->execute([
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'final' => $final, 'disc' => $discount, 'id' => $id
        ]);

        /* Recompute totals ако с карта */
        $s = $pdo->prepare("SELECT customer_id FROM purchase_scans WHERE id=:id LIMIT 1");
        $s->execute(['id'=>$id]);
        $cid = (int)$s->fetchColumn();
        if ($cid) {
            $r = $pdo->prepare("UPDATE customers c
                SET c.total_spent = COALESCE((SELECT SUM(amount) FROM purchase_scans WHERE customer_id=c.id AND deleted_at IS NULL), 0),
                    c.total_purchases = COALESCE((SELECT COUNT(*) FROM purchase_scans WHERE customer_id=c.id AND deleted_at IS NULL), 0)
                WHERE c.id=:cid");
            $r->execute(['cid'=>$cid]);
        }

        $pdo->commit();
        jsonOut(['ok'=>true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonOut(['ok'=>false,'error'=>$e->getMessage()]);
    }
}

/* ── Текущ бизнес ден за JS ── */
$currentBizDate = businessDate();
?>
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta name="theme-color" content="#E8B800">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Калкулатор">
<link rel="manifest" href="/loyalty/manifest_kalk_<?= (int)$locationId ?>.json">
<link rel="apple-touch-icon" href="/loyalty/icon_kalk_192.png">
<title>Калкулатор<?= $locationName ? ' — '.h($locationName) : '' ?></title>
<style>
/* ═══════════════════════════════════════════════════════════════
   NEON GLASS DESIGN SYSTEM — kalkulator.php (S79.VISUAL UNIFIED)
   Обединен екран: калкулатор + лоялна карта + дадени пари + ресто
   ═══════════════════════════════════════════════════════════════ */
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700;800;900&display=swap');

:root{
  --bg:#08090d;
  --bg-main:#08090d;
  --surface:rgba(15,23,42,.85);
  --accent:#6366f1;
  --accent-light:#818cf8;
  --accent-glow:rgba(99,102,241,.35);

  /* RunMyStore vars (products.php / add-product.html 1:1) */
  --hue1: 255;
  --hue2: 222;
  --ease: cubic-bezier(0.5, 1, 0.89, 1);
  --radius: 22px;
  --radius-sm: 14px;

  /* JS inline ползва тези имена — пренасочени към Neon Glass */
  --gold:#E8B800; --gold-dark:#c9a000;
  --red:#f87171; --red-bg:rgba(239,68,68,.08);
  --blue:#818cf8; --blue-dark:#6366f1;
  --green:#4ade80; --green-bg:rgba(34,197,94,.1);
  --white:rgba(15,23,42,.85);
  --text:#f1f5f9; --text2:#a5b4fc; --text3:rgba(165,180,252,.55);
  --border:rgba(99,102,241,.18); --border2:rgba(99,102,241,.3);
  --shadow:0 8px 32px rgba(0,0,0,.4);
}

*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
html,body{background:var(--bg)}
body{
  font-family:'Montserrat',-apple-system,BlinkMacSystemFont,sans-serif;
  background:
    radial-gradient(ellipse 800px 500px at 20% 10%,hsl(var(--hue1) 60% 35% / .22) 0%,transparent 60%),
    radial-gradient(ellipse 700px 500px at 85% 85%,hsl(var(--hue2) 60% 35% / .22) 0%,transparent 60%),
    linear-gradient(180deg,#0a0b14 0%,#050609 100%);
  background-attachment:fixed;
  color:var(--text);
  padding-bottom:40px;min-height:100vh;
  position:relative;overflow-x:hidden;
}
body::before{
  content:'';position:fixed;inset:0;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.5'/%3E%3C/svg%3E");
  opacity:.03;pointer-events:none;z-index:1;mix-blend-mode:overlay;
}

/* ═══ NEON GLASS (chat.php ЕТАЛОН — DESIGN_SYSTEM v2.0 §C.1+C.2) ═══ */
.glass{
  position:relative;
  border-radius:var(--radius);
  border:var(--border) solid hsl(var(--hue2),12%,20%);
  background:
    linear-gradient(235deg,hsl(var(--hue1) 50% 10% / .8),hsl(var(--hue1) 50% 10% / 0) 33%),
    linear-gradient(45deg,hsl(var(--hue2) 50% 10% / .8),hsl(var(--hue2) 50% 10% / 0) 33%),
    linear-gradient(hsl(220deg 25% 4.8% / .78));
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
  box-shadow:
    hsl(var(--hue2) 50% 2%) 0 10px 16px -8px,
    hsl(var(--hue2) 50% 4%) 0 20px 36px -14px;
  isolation:isolate;
}
.glass .shine,.glass .glow{--hue:var(--hue1)}
.glass .shine-bottom,.glass .glow-bottom{--hue:var(--hue2);--conic:135deg}
.glass .shine,
.glass .shine::before,
.glass .shine::after{
  pointer-events:none;border-radius:0;
  border-top-right-radius:inherit;border-bottom-left-radius:inherit;
  border:1px solid transparent;
  width:75%;aspect-ratio:1;display:block;
  position:absolute;
  right:calc(var(--border) * -1);top:calc(var(--border) * -1);left:auto;
  z-index:1;--start:12%;
  background:conic-gradient(from var(--conic,-45deg) at center in oklch,transparent var(--start,0%),hsl(var(--hue),var(--sat,80%),var(--lit,60%)),transparent var(--end,50%)) border-box;
  mask:linear-gradient(transparent),linear-gradient(black);
  mask-repeat:no-repeat;mask-clip:padding-box,border-box;mask-composite:subtract;
}
.glass .shine::before,.glass .shine::after{content:"";width:auto;inset:-2px;mask:none}
.glass .shine::after{
  z-index:2;--start:17%;--end:33%;
  background:conic-gradient(from var(--conic,-45deg) at center in oklch,transparent var(--start,0%),hsl(var(--hue),var(--sat,80%),var(--lit,85%)),transparent var(--end,50%));
}
.glass .shine-bottom{top:auto;bottom:calc(var(--border) * -1);left:calc(var(--border) * -1);right:auto}
.glass .glow{
  pointer-events:none;
  border-top-right-radius:calc(var(--radius) * 2.5);
  border-bottom-left-radius:calc(var(--radius) * 2.5);
  border:calc(var(--radius) * 1.25) solid transparent;
  inset:calc(var(--radius) * -2);
  width:75%;aspect-ratio:1;display:block;position:absolute;left:auto;bottom:auto;
  mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='240' height='240'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='3' seed='5'/%3E%3CfeColorMatrix values='0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 1 0'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
  mask-mode:luminance;mask-size:29%;
  opacity:1;filter:blur(12px) saturate(1.25) brightness(.5);
  mix-blend-mode:plus-lighter;z-index:3;
}
.glass .glow.glow-bottom{inset:calc(var(--radius) * -2);top:auto;right:auto}
.glass .glow::before,
.glass .glow::after{
  content:"";position:absolute;inset:0;
  border:inherit;border-radius:inherit;
  background:conic-gradient(from var(--conic,-45deg) at center in oklch,transparent var(--start,0%),hsl(var(--hue),var(--sat,95%),var(--lit,60%)),transparent var(--end,50%)) border-box;
  mask:linear-gradient(transparent),linear-gradient(black);
  mask-repeat:no-repeat;mask-clip:padding-box,border-box;mask-composite:subtract;
  filter:saturate(2) brightness(1);
}
.glass .glow::after{
  --lit:70%;--sat:100%;--start:15%;--end:35%;
  border-width:calc(var(--radius) * 1.75);
  border-radius:calc(var(--radius) * 2.75);
  inset:calc(var(--radius) * -.25);
  z-index:4;opacity:.75;
}

.wrap{width:100%;max-width:520px;margin:0 auto;position:relative;z-index:2}

/* ── Topbar ── */
.topbar{
  background:linear-gradient(135deg,rgba(99,102,241,.15),rgba(99,102,241,.05));
  backdrop-filter:blur(20px);
  -webkit-backdrop-filter:blur(20px);
  border-bottom:1px solid var(--border);
  padding:13px 16px;
  display:flex;align-items:center;justify-content:space-between;
  position:sticky;top:0;z-index:50;
  box-shadow:0 4px 24px rgba(99,102,241,.15);
}
.topbar-left{font-size:15px;font-weight:900;color:var(--text);letter-spacing:.2px}
.topbar-right{
  font-size:16px;font-weight:900;color:#fff;
  background:linear-gradient(135deg,var(--accent),var(--accent-light));
  padding:5px 14px;border-radius:999px;
  box-shadow:0 4px 14px var(--accent-glow);
  font-variant-numeric:tabular-nums;
}

/* ── Section box (glass) ── */
.sbox{
  position:relative;
  border-radius:var(--radius);
  border:1px solid hsl(var(--hue2),12%,20%);
  background:
    linear-gradient(235deg,hsl(var(--hue1) 50% 10% / .8),hsl(var(--hue1) 50% 10% / 0) 33%),
    linear-gradient(45deg,hsl(var(--hue2) 50% 10% / .8),hsl(var(--hue2) 50% 10% / 0) 33%),
    linear-gradient(hsl(220deg 25% 4.8% / .78));
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
  box-shadow:
    hsl(var(--hue2) 50% 2%) 0 10px 16px -8px,
    hsl(var(--hue2) 50% 4%) 0 20px 36px -14px,
    inset 0 1px 0 rgba(255,255,255,.04);
  padding:16px;margin:12px 10px 0;
  isolation:isolate;
}
.sbox .shine,.sbox .glow{--hue:var(--hue1)}
.sbox .shine-bottom,.sbox .glow-bottom{--hue:var(--hue2);--conic:135deg}
.sbox .shine,
.sbox .shine::before,
.sbox .shine::after{
  pointer-events:none;border-radius:0;
  border-top-right-radius:inherit;border-bottom-left-radius:inherit;
  border:1px solid transparent;
  width:75%;aspect-ratio:1;display:block;
  position:absolute;right:-1px;top:-1px;left:auto;
  z-index:1;--start:12%;
  background:conic-gradient(from var(--conic,-45deg) at center in oklch,transparent var(--start,0%),hsl(var(--hue),var(--sat,80%),var(--lit,60%)),transparent var(--end,50%)) border-box;
  mask:linear-gradient(transparent),linear-gradient(black);
  mask-repeat:no-repeat;mask-clip:padding-box,border-box;mask-composite:subtract;
}
.sbox .shine::before,.sbox .shine::after{content:"";width:auto;inset:-2px;mask:none}
.sbox .shine::after{z-index:2;--start:17%;--end:33%;background:conic-gradient(from var(--conic,-45deg) at center in oklch,transparent var(--start,0%),hsl(var(--hue),var(--sat,80%),var(--lit,85%)),transparent var(--end,50%))}
.sbox .shine-bottom{top:auto;bottom:-1px;left:-1px;right:auto}

/* ═══ СЕКЦИЯ: ЛОЯЛНА КАРТА (нова) ═══ */
.card-section{
  background:rgba(99,102,241,.04);
  border:1px dashed var(--border2);
  border-radius:14px;padding:12px;margin-bottom:12px;
  transition:all .2s;
}
.card-section.active{
  background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(99,102,241,.03));
  border-style:solid;border-color:var(--accent);
}
.card-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.card-head-title{font-size:13px;font-weight:800;color:var(--text2);letter-spacing:.3px}
.card-head-tag{
  font-size:9px;font-weight:800;padding:3px 8px;border-radius:5px;
  background:rgba(232,184,0,.15);color:var(--gold);letter-spacing:.5px;
}
.card-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.card-btn{
  height:48px;border-radius:12px;
  border:1px solid var(--border2);
  background:rgba(99,102,241,.06);
  color:var(--text);
  font-size:13px;font-weight:800;cursor:pointer;
  font-family:'Montserrat',sans-serif;
  display:flex;align-items:center;justify-content:center;gap:6px;
  transition:all .2s;
}
.card-btn:hover{background:rgba(99,102,241,.14)}
.card-btn.active{
  background:linear-gradient(135deg,var(--accent),var(--accent-light));
  border-color:transparent;color:#fff;
  box-shadow:0 4px 14px var(--accent-glow);
}
.qr-expanded{margin-top:10px;padding-top:10px;border-top:1px solid var(--border)}
.qr-expanded.hidden{display:none}
#reader{
  width:100%;max-width:240px;margin:0 auto 8px;
  overflow:hidden;border-radius:14px;
  border:1px solid var(--border2);
  background:rgba(3,7,18,.6);
  box-shadow:0 0 24px rgba(99,102,241,.15);
}
#reader video{border-radius:14px !important}
.manual-card-input{
  width:100%;height:46px;
  border:1px solid var(--border2);border-radius:12px;
  background:rgba(99,102,241,.05);
  font-size:17px;font-weight:800;text-align:center;
  color:var(--text);outline:none;padding:0 12px;
  font-family:'Montserrat',sans-serif;letter-spacing:1px;
  transition:all .2s;
}
.manual-card-input:focus{
  border-color:var(--accent);background:rgba(99,102,241,.1);
  box-shadow:0 0 0 3px rgba(99,102,241,.15);
}
.manual-card-input::placeholder{color:var(--text3);font-weight:500;font-size:13px;letter-spacing:.3px}
.scan-status{
  text-align:center;padding:8px 12px;border-radius:10px;
  background:rgba(99,102,241,.06);border:1px solid var(--border);
  font-size:12px;font-weight:700;color:var(--text2);margin-top:8px;
}
.scan-status.ok{background:rgba(34,197,94,.1);border-color:rgba(74,222,128,.3);color:var(--green)}
.client-block{
  display:flex;align-items:center;justify-content:space-between;gap:10px;
  padding:12px 14px;
  background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(99,102,241,.03));
  border-radius:12px;border:1px solid var(--border2);
  margin-top:10px;
}
.client-name{font-size:17px;font-weight:900;color:var(--text)}
.client-card-num{font-size:11px;font-weight:700;color:var(--text2);margin-top:2px;letter-spacing:.5px}
.client-bonus{
  font-size:12px;font-weight:900;padding:6px 11px;border-radius:999px;
  background:linear-gradient(135deg,var(--accent),var(--accent-light));
  color:#fff;box-shadow:0 4px 14px var(--accent-glow);
}

/* ═══ КАЛКУЛАТОР (entry card) ═══ */
.calc-title{
  font-size:12px;font-weight:900;text-transform:uppercase;
  letter-spacing:.8px;color:var(--text2);margin:4px 0 10px;
}
.entry-card{
  background:rgba(99,102,241,.05);
  border:1px solid var(--border2);
  border-radius:14px;padding:13px 11px;margin-bottom:10px;
}
.row-top{display:grid;grid-template-columns:74px 1fr 90px;gap:7px;margin-bottom:9px}
.f-label{
  font-size:10px;font-weight:800;text-transform:uppercase;
  letter-spacing:.5px;color:var(--text3);margin-bottom:4px;
}
.f-input{
  width:100%;height:50px;
  border:1px solid var(--border2);border-radius:11px;
  background:rgba(99,102,241,.05);
  font-size:19px;font-weight:900;text-align:center;color:var(--text);outline:none;
  font-family:'Montserrat',sans-serif;transition:all .15s;
}
.f-input::placeholder{color:var(--text3);font-weight:500;font-size:14px}
.f-input:focus{
  border-color:var(--gold);background:rgba(232,184,0,.08);
  box-shadow:0 0 0 3px rgba(232,184,0,.15);
}
.f-select{
  width:100%;height:50px;
  border:1px solid var(--border2);border-radius:11px;
  background:rgba(99,102,241,.05) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath fill='%23a5b4fc' d='M5 6L0 0h10z'/%3E%3C/svg%3E") no-repeat right 10px center;
  font-size:13px;font-weight:700;color:var(--text2);
  outline:none;padding:0 22px 0 6px;
  appearance:none;-webkit-appearance:none;text-align:center;cursor:pointer;
  font-family:'Montserrat',sans-serif;transition:all .15s;
}
.f-select:focus{border-color:var(--gold)}
.f-select.chosen{color:var(--text);font-weight:900}
.f-select option{background:#0f172a;color:var(--text)}

.row-qty, .row-disc{display:flex;gap:5px;margin-bottom:9px;align-items:center;flex-wrap:wrap}
.row-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);white-space:nowrap;margin-right:3px}
.qty-b{
  flex:1;min-width:42px;height:42px;border-radius:10px;
  border:1px solid var(--border);background:rgba(99,102,241,.04);
  font-size:14px;font-weight:900;color:var(--text3);cursor:pointer;
  font-family:'Montserrat',sans-serif;transition:all .12s;
}
.qty-b:hover{background:rgba(99,102,241,.08);color:var(--text2)}
.qty-b.active{
  background:linear-gradient(135deg,var(--accent),var(--accent-light));
  border-color:transparent;color:#fff;
  box-shadow:0 4px 14px var(--accent-glow);
}
.qty-custom{
  width:50px;height:42px;
  border:1px solid var(--border);border-radius:10px;
  background:rgba(99,102,241,.04);
  font-size:15px;font-weight:900;text-align:center;color:var(--text);outline:none;
  font-family:'Montserrat',sans-serif;
}
.qty-custom::placeholder{color:var(--text3);font-weight:500;font-size:12px}
.qty-custom:focus{border-color:var(--accent);background:rgba(99,102,241,.08)}
.disc-b{
  flex:1;min-width:42px;height:42px;border-radius:10px;
  border:1px solid var(--border);background:rgba(99,102,241,.04);
  font-size:13px;font-weight:900;color:var(--text3);cursor:pointer;
  font-family:'Montserrat',sans-serif;transition:all .12s;
}
.disc-b:hover{background:rgba(239,68,68,.08);color:var(--red)}
.disc-b.active{
  background:linear-gradient(135deg,#ef4444,#dc2626);
  border-color:transparent;color:#fff;
  box-shadow:0 4px 14px rgba(239,68,68,.35);
}

.add-btn{
  width:100%;height:56px;border-radius:14px;
  border:1px solid hsl(var(--hue1) 60% 55%);
  background:linear-gradient(135deg,hsl(var(--hue1) 70% 52%),hsl(var(--hue1) 80% 42%));
  font-size:15px;font-weight:800;color:#fff;cursor:pointer;
  box-shadow:
    0 8px 24px hsl(var(--hue1) 70% 40% / .4),
    0 0 24px hsl(var(--hue1) 70% 50% / .25),
    inset 0 1px 0 rgba(255,255,255,.25);
  text-shadow:0 0 12px rgba(255,255,255,.3);
  display:flex;align-items:center;justify-content:center;gap:8px;
  font-family:inherit;letter-spacing:.01em;
  transition:all .25s var(--ease);
}
.add-btn:hover{transform:translateY(-1px);box-shadow:0 12px 28px hsl(var(--hue1) 70% 40% / .5),0 0 32px hsl(var(--hue1) 70% 50% / .4),inset 0 1px 0 rgba(255,255,255,.3)}
.add-btn:active{transform:translateY(0) scale(.98)}

/* ── Return mode бутон (добави артикул на минус) ── */
.return-mode-btn{
  width:100%;height:44px;border-radius:12px;
  border:1px solid rgba(255,255,255,.1);
  background:rgba(255,255,255,.03);
  color:var(--text2);font-size:13px;font-weight:700;cursor:pointer;
  font-family:inherit;letter-spacing:.01em;
  margin-top:8px;transition:all .25s var(--ease);
}
.return-mode-btn:hover{
  background:hsl(var(--hue1) 30% 18% / .3);
  border-color:hsl(var(--hue1) 40% 40% / .5);
  color:hsl(var(--hue1) 60% 90%);
}
.return-mode-btn.active{
  border-color:hsl(0 65% 55%);
  background:linear-gradient(135deg,hsl(0 70% 50%),hsl(0 80% 40%));
  color:#fff;font-weight:800;
  box-shadow:
    0 8px 24px hsl(0 70% 40% / .4),
    0 0 24px hsl(0 70% 50% / .3),
    inset 0 1px 0 rgba(255,255,255,.2);
  text-shadow:0 0 10px rgba(255,255,255,.25);
}

/* ── Добавени артикули ── */
.items-label{
  font-size:10px;font-weight:800;text-transform:uppercase;
  letter-spacing:.6px;color:var(--text3);margin:10px 4px 7px;
}
.item-row{
  background:rgba(99,102,241,.05);
  border:1px solid var(--border);
  border-radius:12px;padding:11px 12px;margin-bottom:7px;
  display:flex;align-items:center;gap:8px;
  transition:background .15s;
}
.item-row:hover{background:rgba(99,102,241,.08)}
.item-info{flex:1;min-width:0}
.item-name{
  font-size:13px;font-weight:900;color:var(--text);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.item-sub{font-size:11px;color:var(--text3);margin-top:3px;font-weight:600}
.item-sub .disc-t{color:var(--red);font-weight:900}
.item-prices{text-align:right;flex-shrink:0}
.item-orig{font-size:11px;color:var(--text3);text-decoration:line-through}
.item-final{font-size:16px;font-weight:900;color:var(--text)}
.item-del{
  flex-shrink:0;width:34px;height:34px;border-radius:9px;
  border:1px solid rgba(239,68,68,.2);
  background:rgba(239,68,68,.08);color:var(--red);
  font-size:15px;font-weight:900;cursor:pointer;
  transition:all .15s;
}
.item-del:hover{background:rgba(239,68,68,.18)}
.empty-hint{text-align:center;padding:18px;color:var(--text3);font-size:13px;font-weight:600}

/* ── Totals row (запазваме id-та tBase/tDisc/tFinal) ── */
.totals-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:7px}
.tot{
  background:var(--surface);backdrop-filter:blur(20px);
  border:1px solid var(--border);border-radius:11px;
  padding:9px 6px;text-align:center;
}
.tot .tk{font-size:9px;text-transform:uppercase;color:var(--text3);font-weight:800;letter-spacing:.4px}
.tot .tv{font-size:15px;font-weight:900;margin-top:3px;color:var(--text)}
.tot.gold{border-color:rgba(232,184,0,.3);background:rgba(232,184,0,.06)}
.tot.gold .tv{color:var(--gold)}
.tot.red{border-color:rgba(239,68,68,.2);background:rgba(239,68,68,.05)}
.tot.red .tv{color:var(--red)}

/* ═══ PAY BLOCK (hero сумата) ═══ */
.pay-block{
  background:linear-gradient(135deg,rgba(99,102,241,.15),rgba(99,102,241,.05));
  border:1px solid var(--border2);
  border-radius:18px;padding:16px;text-align:center;margin:12px 0;
  box-shadow:0 0 40px rgba(99,102,241,.1);
  position:relative;overflow:hidden;
}
.pay-block::before{
  content:'';position:absolute;top:-50%;left:-50%;
  width:200%;height:200%;
  background:conic-gradient(from 0deg,transparent 0deg,rgba(99,102,241,.08) 90deg,transparent 180deg);
  animation:payRotate 8s linear infinite;pointer-events:none;
}
@keyframes payRotate{to{transform:rotate(360deg)}}
.pay-block > *{position:relative;z-index:1}
.pay-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--text2);margin-bottom:4px}
.pay-amount-big{
  font-size:48px;font-weight:900;line-height:1;
  background:linear-gradient(to right,#f1f5f9,#a5b4fc,#f1f5f9);
  background-size:200% auto;
  -webkit-background-clip:text;background-clip:text;
  -webkit-text-fill-color:transparent;
  animation:payShine 4s linear infinite;
  letter-spacing:-1px;
  filter:drop-shadow(0 0 20px rgba(99,102,241,.4));
}
@keyframes payShine{0%{background-position:0% center}100%{background-position:200% center}}
.pay-loyalty{
  margin-top:6px;font-size:12px;font-weight:800;color:var(--accent-light);letter-spacing:.2px;
}

/* ═══ ДАДЕНИ ПАРИ + NUMPAD ═══ */
.given-block{
  background:var(--surface);backdrop-filter:blur(20px);
  border:1px solid var(--border);
  border-radius:16px;padding:14px;margin-bottom:10px;
}
.given-label{
  font-size:11px;font-weight:800;text-transform:uppercase;
  letter-spacing:.8px;color:var(--text2);margin-bottom:10px;text-align:center;
}
.label-tag{
  display:inline-block;font-size:9px;font-weight:800;
  padding:3px 8px;border-radius:5px;
  background:rgba(232,184,0,.15);color:var(--gold);letter-spacing:.5px;margin-left:5px;
}
.given-input-native{
  width:100%;height:60px;
  border:1px solid var(--border2);border-radius:14px;
  background:rgba(99,102,241,.05);color:var(--text);
  font-size:30px;font-weight:900;text-align:center;
  font-family:inherit;margin-bottom:10px;letter-spacing:-.5px;
  outline:none;padding:0 14px;
  transition:all .25s var(--ease);
  -moz-appearance:textfield;
}
.given-input-native::-webkit-outer-spin-button,
.given-input-native::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.given-input-native::placeholder{color:var(--text3);font-size:16px;font-weight:500;letter-spacing:0}
.given-input-native:focus{
  border-color:hsl(var(--hue1) 60% 55%);background:rgba(99,102,241,.1);
  box-shadow:0 0 0 3px hsl(var(--hue1) 70% 50% / .2);
}
.exact-row{display:grid;grid-template-columns:1fr auto;gap:8px;margin-bottom:10px}
.exact-btn{
  height:42px;border-radius:11px;
  border:1px solid rgba(232,184,0,.35);
  background:linear-gradient(135deg,rgba(232,184,0,.15),rgba(232,184,0,.05));
  color:var(--gold);
  font-size:13px;font-weight:900;cursor:pointer;
  font-family:'Montserrat',sans-serif;letter-spacing:.3px;
}
.exact-btn:hover{background:rgba(232,184,0,.2)}
.clear-btn{
  width:42px;height:42px;
  border:1px solid rgba(248,113,113,.25);
  background:rgba(239,68,68,.08);color:var(--red);
  border-radius:11px;font-size:15px;font-weight:900;cursor:pointer;
}
/* numpad/quick-bills/divider премахнати (S79.CLEAN — Тихол: "излишен шум") */

/* ═══ РЕСТО ═══ */
.change-block{
  background:linear-gradient(135deg,rgba(74,222,128,.18),rgba(34,197,94,.05));
  border:1px solid rgba(74,222,128,.35);
  border-radius:16px;padding:14px;text-align:center;margin-bottom:10px;
  box-shadow:0 0 28px rgba(74,222,128,.12);
}
.change-block.hidden{display:none}
.change-block.zero{
  background:linear-gradient(135deg,rgba(232,184,0,.15),rgba(232,184,0,.03));
  border-color:rgba(232,184,0,.4);box-shadow:0 0 28px rgba(232,184,0,.15);
}
.change-block.negative{
  background:linear-gradient(135deg,rgba(248,113,113,.15),rgba(239,68,68,.05));
  border-color:rgba(248,113,113,.35);box-shadow:0 0 28px rgba(248,113,113,.15);
}
.change-label{
  font-size:11px;font-weight:800;text-transform:uppercase;
  letter-spacing:1px;margin-bottom:6px;color:#86efac;
}
.change-block.zero .change-label{color:var(--gold)}
.change-block.negative .change-label{color:var(--red)}
.change-amount{
  font-size:40px;font-weight:900;line-height:1;color:var(--green);
  filter:drop-shadow(0 0 14px rgba(74,222,128,.35));letter-spacing:-.5px;
}
.change-block.zero .change-amount{color:var(--gold);filter:drop-shadow(0 0 14px rgba(232,184,0,.35))}
.change-block.negative .change-amount{color:var(--red);filter:drop-shadow(0 0 14px rgba(248,113,113,.35))}
.change-note{margin-top:4px;font-size:12px;font-weight:700;color:#86efac}
.change-block.zero .change-note{color:var(--gold)}
.change-block.negative .change-note{color:var(--red)}

/* ═══ PAYMENT METHOD ═══ */
.pm-block{
  background:var(--surface);backdrop-filter:blur(20px);
  border:1px solid var(--border);
  border-radius:16px;padding:14px;margin-bottom:10px;
}
.pm-label{
  font-size:11px;font-weight:800;text-transform:uppercase;
  letter-spacing:.8px;color:var(--text2);margin-bottom:10px;text-align:center;
}
.pm-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}
.pm-btn{
  height:54px;border-radius:12px;
  border:1px solid var(--border2);background:rgba(99,102,241,.04);
  color:var(--text2);font-size:12px;font-weight:800;cursor:pointer;
  font-family:'Montserrat',sans-serif;
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;
  transition:all .15s;
}
.pm-btn .pm-icon{font-size:18px}
.pm-btn:hover{background:rgba(99,102,241,.08);color:var(--text)}
.pm-btn.active{
  background:linear-gradient(135deg,var(--accent),var(--accent-light));
  border-color:transparent;color:#fff;box-shadow:0 4px 14px var(--accent-glow);
}

/* ═══ SAVE BUTTON ═══ */
.save-btn{
  width:100%;height:62px;border-radius:18px;
  border:1px solid hsl(var(--hue1) 60% 55%);
  background:linear-gradient(135deg,hsl(var(--hue1) 70% 52%),hsl(var(--hue1) 80% 42%));
  font-size:17px;font-weight:800;color:#fff;cursor:pointer;
  box-shadow:
    0 10px 32px hsl(var(--hue1) 70% 40% / .45),
    0 0 28px hsl(var(--hue1) 70% 50% / .3),
    inset 0 1px 0 rgba(255,255,255,.25);
  text-shadow:0 0 14px rgba(255,255,255,.35);
  font-family:inherit;letter-spacing:.01em;
  transition:all .25s var(--ease);margin-top:8px;
}
.save-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 14px 40px hsl(var(--hue1) 70% 40% / .6),0 0 36px hsl(var(--hue1) 70% 55% / .45),inset 0 1px 0 rgba(255,255,255,.3)}
.save-btn:active:not(:disabled){transform:translateY(0) scale(.98)}
.save-btn:disabled{opacity:.35;cursor:default;transform:none;box-shadow:none}

.save-result{
  display:none;margin-top:10px;padding:12px;border-radius:12px;
  text-align:center;font-size:14px;font-weight:800;
}

.print-btn{
  display:block;width:100%;margin-top:8px;
  height:50px;border-radius:13px;
  border:1px solid var(--border2);
  background:var(--surface);backdrop-filter:blur(20px);
  font-size:15px;font-weight:800;color:var(--text2);cursor:pointer;
  font-family:'Montserrat',sans-serif;letter-spacing:.2px;
  transition:all .2s;
}
.print-btn:hover{background:rgba(99,102,241,.08);color:var(--text);border-color:var(--accent)}
.print-btn:disabled{opacity:.35;cursor:default}

/* ═══ ИСТОРИЯ ═══ */
.hist-section{margin:16px 10px 0}
.hist-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding:0 2px}
.hist-title{font-size:16px;font-weight:900;color:var(--text);letter-spacing:.2px}
.hist-refresh{
  border:1px solid var(--border2);background:rgba(99,102,241,.06);
  border-radius:10px;padding:7px 14px;font-size:12px;font-weight:800;
  color:var(--text2);cursor:pointer;font-family:'Montserrat',sans-serif;
}
.hist-refresh:hover{background:rgba(99,102,241,.12);color:var(--text)}
.day-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.day-tab{
  padding:7px 14px;border-radius:999px;
  border:1px solid var(--border2);background:rgba(99,102,241,.04);
  font-size:12px;font-weight:800;color:var(--text2);cursor:pointer;
  font-family:'Montserrat',sans-serif;transition:all .15s;
}
.day-tab:hover{background:rgba(99,102,241,.1);color:var(--text)}
.day-tab.active{
  background:linear-gradient(135deg,var(--accent),var(--accent-light));
  border-color:transparent;color:#fff;box-shadow:0 4px 16px var(--accent-glow);
}
.hist-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px}
.hs{
  background:var(--surface);backdrop-filter:blur(20px);
  border:1px solid var(--border);border-radius:12px;
  padding:11px 8px;text-align:center;box-shadow:var(--shadow);
}
.hk{font-size:9px;text-transform:uppercase;color:var(--text3);font-weight:800;letter-spacing:.5px}
.hv{font-size:17px;font-weight:900;margin-top:4px;color:var(--text)}
.hs.green .hv{color:var(--green)}
.hs.red .hv{color:var(--red)}

.fullscreen-btn{
  display:block;width:100%;margin-top:12px;height:50px;border-radius:13px;
  border:1px solid var(--border2);
  background:var(--surface);backdrop-filter:blur(20px);
  font-size:15px;font-weight:800;color:var(--text2);cursor:pointer;
  font-family:'Montserrat',sans-serif;
}
.fullscreen-btn:hover{background:rgba(99,102,241,.08);color:var(--text);border-color:var(--accent)}

/* ═══ FULLSCREEN OVERLAY ═══ */
.fs-overlay{
  position:fixed;inset:0;z-index:300;background:var(--bg);
  display:flex;flex-direction:column;
}
.fs-overlay.hidden{display:none}
.fs-overlay::before{
  content:'';position:fixed;top:-200px;left:50%;
  transform:translateX(-50%);width:700px;height:500px;
  background:radial-gradient(ellipse,rgba(99,102,241,.08) 0%,transparent 70%);
  pointer-events:none;
}
.fs-bar{
  flex-shrink:0;position:relative;z-index:1;
  background:linear-gradient(135deg,rgba(99,102,241,.15),rgba(99,102,241,.05));
  backdrop-filter:blur(20px);border-bottom:1px solid var(--border2);
  padding:14px 16px;display:flex;align-items:center;justify-content:space-between;
  box-shadow:0 4px 24px rgba(0,0,0,.3);
}
.fs-close{
  border:1px solid var(--border2);border-radius:11px;
  background:rgba(99,102,241,.1);padding:9px 18px;
  font-size:14px;font-weight:900;color:var(--text);cursor:pointer;
  font-family:'Montserrat',sans-serif;
}
.fs-close:hover{background:rgba(99,102,241,.2)}
.fs-body{flex:1;overflow-y:auto;position:relative;z-index:1;padding:16px 14px 30px}
.fs-footer{flex-shrink:0;padding:12px 14px;background:var(--surface);border-top:1px solid var(--border);backdrop-filter:blur(20px)}
.fs-back-btn{
  width:100%;height:52px;border-radius:14px;border:none;
  background:linear-gradient(135deg,var(--accent),var(--accent-light));
  font-size:17px;font-weight:900;color:#fff;cursor:pointer;
  font-family:'Montserrat',sans-serif;
  box-shadow:0 6px 20px var(--accent-glow);
}
.fs-no-data{text-align:center;padding:30px;color:var(--text3);font-size:14px;font-weight:700}
.fs-item-row{
  display:flex;justify-content:space-between;align-items:baseline;
  padding:9px 0;border-bottom:1px solid rgba(99,102,241,.08);
}
.fs-item-row:last-child{border-bottom:none}
.fs-item-left{}
.fs-item-code{font-size:17px;font-weight:900;color:var(--text)}
.fs-item-brand{font-size:13px;font-weight:700;color:var(--text2);margin-top:2px}
.fs-item-right{text-align:right;flex-shrink:0;padding-left:10px}
.fs-item-qty{font-size:20px;font-weight:900;color:var(--text)}
.fs-item-price{font-size:13px;font-weight:700;color:var(--text2)}
.fs-totals{
  margin-top:14px;
  background:var(--surface);backdrop-filter:blur(20px);
  border:1px solid var(--border);border-radius:12px;padding:13px;
}
.fs-tot-row{
  display:flex;justify-content:space-between;padding:7px 0;
  border-bottom:1px solid rgba(99,102,241,.08);font-size:15px;
}
.fs-tot-row:last-child{border-bottom:none}
.fs-tot-label{font-weight:700;color:var(--text2)}
.fs-tot-value{font-weight:900;color:var(--text)}
.fs-tot-value.big{font-size:22px;color:var(--red);filter:drop-shadow(0 0 10px rgba(248,113,113,.3))}

.hidden{display:none!important}



/* Exit bтн в client-block + voucher line */
.client-exit-btn{
  flex-shrink:0;width:34px;height:34px;border-radius:50%;
  border:1px solid rgba(248,113,113,.3);
  background:rgba(239,68,68,.08);
  color:var(--red);cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  transition:all .2s var(--ease);
  margin-left:8px;
}
.client-exit-btn:hover{background:rgba(239,68,68,.18);transform:rotate(90deg)}
.client-exit-btn:active{transform:scale(.9)}
.client-voucher{
  display:flex;align-items:center;gap:6px;
  margin-top:5px;font-size:12px;font-weight:800;
  color:hsl(145 70% 70%);
  background:rgba(34,197,94,.1);
  border:1px solid rgba(34,197,94,.25);
  border-radius:8px;padding:4px 8px;
  box-shadow:0 0 14px rgba(34,197,94,.15);
  text-shadow:0 0 8px rgba(34,197,94,.35);
  width:fit-content;
}
.client-stats{
  display:flex;align-items:center;gap:6px;
  margin-top:4px;font-size:11px;font-weight:700;
  color:hsl(var(--hue1) 50% 75%);
  opacity:.75;
  width:fit-content;
}
.client-block{align-items:flex-start !important}

/* ═══ SVG Icons global styling ═══ */
.card-btn svg, .add-btn svg, .save-btn svg, .return-mode-btn svg,
.fullscreen-btn svg, .print-btn svg, .fs-close svg, .fs-back-btn svg,
.hist-refresh svg, .topbar-left svg, .card-head-title svg,
.calc-title svg, .pm-label svg, .given-label svg, .hist-title svg,
.clear-btn svg, .item-del svg, .day-tab svg {
  flex-shrink:0; vertical-align:middle;
  stroke:currentColor; fill:none;
  stroke-width:2; stroke-linecap:round; stroke-linejoin:round;
}
.pm-btn .pm-icon svg { stroke:currentColor; }
.topbar-left { display:flex; align-items:center; gap:8px; }
.topbar-left svg { color:hsl(var(--hue1) 50% 70%); filter:drop-shadow(0 0 6px hsl(var(--hue1) 70% 50% / .4)); }
.card-head-title, .calc-title, .pm-label, .given-label, .hist-title {
  display:flex; align-items:center; gap:6px;
}
.hist-refresh { display:inline-flex; align-items:center; gap:5px; }
.save-btn svg { filter:drop-shadow(0 0 8px rgba(255,255,255,.35)); }
.add-btn svg  { filter:drop-shadow(0 0 8px rgba(255,255,255,.3)); }

/* Scrollbars */
.fs-body::-webkit-scrollbar{width:6px}
.fs-body::-webkit-scrollbar-track{background:transparent}
.fs-body::-webkit-scrollbar-thumb{background:rgba(99,102,241,.3);border-radius:3px}

/* ═══ PRINT ═══ */
@media print {
  body{background:#fff !important;color:#000 !important}
  body::before,body::after{display:none}
  body *{visibility:hidden}
  #printZone, #printZone *{visibility:visible;color:#000 !important}
  #printZone{
    display:block !important;position:fixed;top:0;left:0;
    width:100%;padding:10px;background:#fff;
  }
  .pr-header{text-align:center;margin-bottom:12px;border-bottom:2px solid #000;padding-bottom:8px}
  .pr-title{font-size:18px;font-weight:900}
  .pr-sub{font-size:12px;color:#555;margin-top:3px}
  .pr-table{width:100%;border-collapse:collapse;margin:10px 0;font-size:13px}
  .pr-table th{border-bottom:1px solid #000;padding:5px 4px;text-align:left;font-size:11px;text-transform:uppercase}
  .pr-table td{padding:6px 4px;border-bottom:1px solid #ddd}
  .pr-table td.right{text-align:right;font-weight:700}
  .pr-totals{margin-top:10px;border-top:2px solid #000;padding-top:8px}
  .pr-tot-row{display:flex;justify-content:space-between;font-size:14px;padding:3px 0}
  .pr-tot-row.big{font-size:18px;font-weight:900}
  .pr-tot-row.red{color:#c00}
  .pr-footer{margin-top:16px;text-align:center;font-size:11px;color:#888;border-top:1px solid #ddd;padding-top:8px}
  .pr-sale-block{margin-bottom:12px;padding-bottom:10px;border-bottom:1px dashed #ccc}
  .pr-sale-head{display:flex;justify-content:space-between;font-size:13px;font-weight:700;margin-bottom:6px}
}

@media(max-width:380px){
  .row-top{grid-template-columns:62px 1fr 80px}
  .pay-amount-big{font-size:40px}
  .change-amount{font-size:34px}
  .sbox{margin:10px 8px 0;padding:14px}
}
</style>
</head>
<body>
<div class="wrap">

<!-- Topbar -->
<div class="topbar">
  <div class="topbar-left">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> <span><?= $locationName ? h($locationName) : 'Калкулатор' ?></span>
  </div>
  <div class="topbar-right" id="hTotal">0.00 €</div>
</div>

<!-- ═══ ГЛАВЕН WORK BOX ═══ -->
<div class="sbox">
  <span class="shine"></span>
  <span class="shine shine-bottom"></span>


  <!-- ⏹ СЕКЦИЯ 1: ЛОЯЛНА КАРТА (опционално) -->
  <div class="card-section" id="cardSection">
    <div class="card-head">
      <div class="card-head-title"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> <span>Лоялна карта на клиента</span></div>
      <div class="card-head-tag">опционално</div>
    </div>
    <div class="card-actions">
      <button type="button" class="card-btn" onclick="toggleScan()" id="scanBtn"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg><span>Сканирай</span></button>
      <button type="button" class="card-btn" onclick="toggleManual()" id="manualBtn"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2" ry="2"/><path d="M6 8h.01M10 8h.01M14 8h.01M18 8h.01M6 12h.01M10 12h.01M14 12h.01M18 12h.01M7 16h10"/></svg><span>Въведи ръчно</span></button>
    </div>
    <div class="qr-expanded" id="qrExpanded">
      <div id="reader"></div>
      <div class="scan-status" id="scanStatus" style="display:none"></div>
    </div>
    <div class="qr-expanded hidden" id="manualExpanded">
      <input class="manual-card-input" placeholder="ET000050" id="cardInput" autocomplete="off">
    </div>
    <div class="client-block" id="clientBlock" style="display:none">
      <div style="flex:1;min-width:0">
        <div class="client-name" id="clientName">—</div>
        <div class="client-card-num" id="clientCard">—</div>
        <div class="client-stats" id="clientStats" style="display:none"></div>
        <div class="client-voucher" id="clientVoucher" style="display:none"></div>
      </div>
      <div class="client-bonus" id="clientBonus" style="display:none"></div>
      <button type="button" id="clientExitBtn" class="client-exit-btn" onclick="clearCardVisual()" aria-label="Нулирай клиента">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
  </div>

  <!-- ⏹ СЕКЦИЯ 2: КАЛКУЛАТОР -->
  <div class="calc-title"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><line x1="8" y1="6" x2="16" y2="6"/><path d="M8 14h.01M8 18h.01M12 14h.01M12 18h.01M16 14h.01M16 18h.01"/></svg> <span>Артикули в покупката</span></div>

  <div class="entry-card">
    <div class="row-top">
      <div>
        <div class="f-label">Код</div>
        <input id="codeInput" class="f-input" type="text" inputmode="numeric" placeholder="330" autocomplete="off" enterkeyhint="next">
      </div>
      <div>
        <div class="f-label">Производител</div>
        <select id="brandSelect" class="f-select">
          <option value="">— без —</option>
          <option>Статера</option><option>Лорд</option><option>Спико</option>
          <option>Дафи</option><option>Ареал</option><option>DX</option>
          <option>Ивон</option><option>Иватакс</option><option>Петков</option>
          <option>Роял Тайгър</option><option>Китайско</option>
        </select>
      </div>
      <div>
        <div class="f-label">Цена (€)</div>
        <input id="priceInput" class="f-input" type="number" inputmode="decimal" step="0.01" placeholder="0.00" autocomplete="off" enterkeyhint="done">
      </div>
    </div>
    <div class="row-qty">
      <span class="row-lbl">Бр:</span>
      <button class="qty-b active" data-q="1">1</button>
      <button class="qty-b" data-q="2">2</button>
      <button class="qty-b" data-q="3">3</button>
      <button class="qty-b" data-q="4">4</button>
      <button class="qty-b" data-q="5">5</button>
      <button class="qty-b" data-q="10">10</button>
      <input id="qtyCustom" class="qty-custom" type="number" inputmode="numeric" min="1" placeholder="др." enterkeyhint="done">
    </div>
    <div class="row-disc">
      <span class="row-lbl">Отст:</span>
      <button class="disc-b" data-d="5">5%</button>
      <button class="disc-b" data-d="10">10%</button>
      <button class="disc-b" data-d="15">15%</button>
      <button class="disc-b" data-d="20">20%</button>
      <button class="disc-b" data-d="30">30%</button>
      <button class="disc-b" data-d="40">40%</button>
      <button class="disc-b" data-d="50">50%</button>
      <input id="discCustom" class="qty-custom" type="number" inputmode="numeric" min="1" max="99" placeholder="др%" enterkeyhint="done" style="width:58px">
    </div>
    <button class="add-btn" id="addBtn"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg><span>Добави артикул</span></button>
    <button type="button" id="returnModeBtn" onclick="toggleReturnMode()" class="return-mode-btn">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg><span>Добави артикул на минус</span>
    </button>
    <button class="save-btn" id="saveBtnTop" style="margin-top:8px;height:54px;font-size:15px" disabled><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><span>Запиши продажбата</span></button>
  </div>

  <!-- Добавени артикули -->
  <div id="itemsLabel" class="items-label" style="display:none">Добавени артикули</div>
  <div id="itemsList"></div>

  <!-- Totals row (запазваме id-тата за JS) -->
  <div class="totals-row" style="margin-top:10px">
    <div class="tot"><div class="tk">Без отст.</div><div class="tv" id="tBase">0.00 €</div></div>
    <div class="tot red"><div class="tk">Отстъпка</div><div class="tv" id="tDisc">—</div></div>
    <div class="tot gold"><div class="tk">За плащане</div><div class="tv" id="tFinal">0.00 €</div></div>
  </div>

  <!-- ⏹ СЕКЦИЯ 3: PAY BLOCK (hero) -->
  <div class="pay-block">
    <div class="pay-label">За плащане</div>
    <div class="pay-amount-big" id="payAmountBig">0.00 €</div>
    <div class="pay-loyalty" id="payLoyalty" style="display:none"></div>
  </div>

  <!-- ⏹ СЕКЦИЯ 4: ДАДЕНИ ПАРИ (native input — ръчно въвеждане) -->
  <div class="given-block">
    <div class="given-label"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01"/><path d="M18 12h.01"/></svg> <span>Колко даде клиентът?</span><span class="label-tag">опционално</span></div>
    <input type="number" inputmode="decimal" step="0.01" min="0"
           id="givenInput" class="given-input-native"
           placeholder="— въведи сума —" autocomplete="off" enterkeyhint="done">
    <div class="exact-row">
      <button type="button" class="exact-btn" id="exactBtn" onclick="setExactGiven()">= Точна сума</button>
      <button type="button" class="clear-btn" onclick="clearGiven()"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
  </div>

  <!-- ⏹ СЕКЦИЯ 5: РЕСТО -->
  <div class="change-block hidden" id="changeBlock">
    <div class="change-label" id="changeLabel">Връщаме ресто</div>
    <div class="change-amount" id="changeAmount">0.00 €</div>
    <div class="change-note" id="changeNote"></div>
  </div>

  <!-- ⏹ СЕКЦИЯ 6: PAYMENT METHOD -->
  <div class="pm-block">
    <div class="pm-label"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> <span>Начин на плащане</span></div>
    <div class="pm-grid">
      <button type="button" class="pm-btn active" data-pm="cash" onclick="selectPm(this)">
        <span class="pm-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01"/><path d="M18 12h.01"/></svg></span>Кеш
      </button>
      <button type="button" class="pm-btn" data-pm="card" onclick="selectPm(this)">
        <span class="pm-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></span>Карта
      </button>
      <button type="button" class="pm-btn" data-pm="transfer" onclick="selectPm(this)">
        <span class="pm-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="21" x2="21" y2="21"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="5 6 12 3 19 6"/><line x1="4" y1="10" x2="4" y2="21"/><line x1="20" y1="10" x2="20" y2="21"/><line x1="8" y1="14" x2="8" y2="17"/><line x1="12" y1="14" x2="12" y2="17"/><line x1="16" y1="14" x2="16" y2="17"/></svg></span>Превод
      </button>
    </div>
  </div>

  <!-- ⏹ SUBMIT -->
  <button class="save-btn" id="saveBtn" disabled><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><span>Запиши продажбата</span></button>
  <div id="saveResult" class="save-result"><span style="display:inline-flex;align-items:center;gap:6px"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Продажбата е записана!</span></div>
  <button class="print-btn" id="printSaleBtn" onclick="printSale()" disabled><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg><span>Печат на покупката</span></button>

</div>

<!-- ═══ ИСТОРИЯ (7 дни) ═══ -->
<div class="hist-section">
  <div class="hist-head">
    <div class="hist-title"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg> <span>История (7 дни)</span></div>
    <button class="hist-refresh" onclick="loadHistory()"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> <span>Обнови</span></button>
  </div>

  <div class="day-tabs" id="dayTabs">
    <?php
    $tz = new DateTimeZone('Europe/Sofia');
    for ($i = 0; $i < 7; $i++) {
        $dt = new DateTime('now', $tz);
        $dt->modify("-{$i} days");
        $bDate = $dt->format('Y-m-d');
        $label = $i===0 ? 'Днес' : ($i===1 ? 'Вчера' : $dt->format('d.m'));
        $active = $i===0 ? ' active' : '';
        echo '<button class="day-tab'.$active.'" data-date="'.h($bDate).'" onclick="selectDay(this)">'.h($label).'</button>';
    }
    ?>
  </div>

  <div class="hist-stats">
    <div class="hs green"><div class="hk">Продажби</div><div class="hv" id="hCount">—</div></div>
    <div class="hs green"><div class="hk">Взето</div><div class="hv" id="hFinal">—</div></div>
    <div class="hs red"><div class="hk">Отстъпка</div><div class="hv" id="hDisc">—</div></div>
  </div>

  <button class="fullscreen-btn" onclick="openFullscreen()">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg><span>Виж обобщението за преписване</span>
  </button>
  <button class="print-btn" onclick="printHistory()" style="margin-top:8px">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg><span>Печат на историята за деня</span>
  </button>

</div>

</div><!-- /wrap -->

<!-- ═══ PRINT ЗОНА ═══ -->
<div id="printZone" style="display:none">
  <div id="printContent"></div>
</div>

<!-- ═══ FULLSCREEN OVERLAY ═══ -->
<div class="fs-overlay hidden" id="fsOverlay">
  <div class="fs-bar">
    <div id="fsTitle" style="font-size:15px;font-weight:900;color:var(--text)">Обобщение</div>
    <button class="fs-close" onclick="closeFullscreen()"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg><span>Назад</span></button>
  </div>
  <div class="fs-body" id="fsBody">
    <div class="fs-no-data">Зареждане...</div>
  </div>
  <div class="fs-footer">
    <button class="fs-back-btn" onclick="closeFullscreen()"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg><span>Назад към калкулатора</span></button>
  </div>
</div>

<!-- QR scanner lib -->
<script src="https://unpkg.com/html5-qrcode"></script>

<script>
const LOCATION_ID   = <?= (int)$locationId ?>;
const LOCATION_NAME = <?= json_encode($locationName, JSON_UNESCAPED_UNICODE) ?>;
const PAGE_URL      = window.location.href;
const BIZ_DATE      = <?= json_encode($currentBizDate) ?>;

/* S79.UNIFIED globals — ПРЕДИ всичко за избягване на TDZ */
let scannedCard = '';
let scannedCustomerId = null;
let scannedCustomerData = null;
let givenAmount = '';
let paymentMethod = 'cash';
let html5QrCode = null;
let lastScan = '', lastScanTs = 0;

let items   = [];
let selQty  = 1;
let selDisc = 0;
let histDate = BIZ_DATE;
let histLoading = false;
let _histData = null;

const codeInput   = document.getElementById('codeInput');
const priceInput  = document.getElementById('priceInput');
const brandSelect = document.getElementById('brandSelect');
const qtyCustom   = document.getElementById('qtyCustom');
const saveBtn     = document.getElementById('saveBtn');
const saveBtnTop  = document.getElementById('saveBtnTop');
if(saveBtnTop) saveBtnTop.addEventListener('click', () => saveBtn && saveBtn.click());
const saveResult  = document.getElementById('saveResult');
const itemsList   = document.getElementById('itemsList');
const itemsLabel  = document.getElementById('itemsLabel');

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') }

/* ── Количество ── */
document.querySelectorAll('.qty-b').forEach(btn => {
  btn.addEventListener('click', () => {
    selQty = parseInt(btn.dataset.q);
    qtyCustom.value = '';
    syncQty();
  });
});
qtyCustom.addEventListener('input', () => {
  const v = parseInt(qtyCustom.value);
  if(v >= 1){ selQty = v; syncQty(); }
});
qtyCustom.addEventListener('keydown', e => { if(e.key==='Enter'){ e.preventDefault(); qtyCustom.blur(); } });
function syncQty(){
  document.querySelectorAll('.qty-b').forEach(b => {
    b.classList.toggle('active', parseInt(b.dataset.q)===selQty && !qtyCustom.value);
  });
}

/* ── Отстъпка ── */
document.querySelectorAll('.disc-b').forEach(btn => {
  if(!btn.dataset.d) return; // skip non-preset buttons
  btn.addEventListener('click', () => {
    const d = parseInt(btn.dataset.d);
    if(isNaN(d)) return;
    selDisc = selDisc===d ? 0 : d;
    const dc = document.getElementById('discCustom');
    if(dc) dc.value = ''; // клиращ custom при избор preset
    syncDisc();
  });
});
function syncDisc(){
  document.querySelectorAll('.disc-b').forEach(b => {
    if(!b.dataset.d) return; // skip clear btn
    b.classList.toggle('active', parseInt(b.dataset.d)===selDisc && selDisc!==0);
  });
  const dc = document.getElementById('discCustom');
  if(dc){
    const preset = [5,10,15,20,30,40,50].includes(selDisc);
    if(!preset && selDisc > 0){ dc.value = selDisc; }
    else if(selDisc === 0 || preset){ dc.value = ''; }
  }
}
/* Custom отстъпка */
const discCustomEl = document.getElementById('discCustom');
if(discCustomEl){
  discCustomEl.addEventListener('input', e => {
    const v = parseInt(e.target.value);
    if(v >= 1 && v <= 99){ selDisc = v; syncDisc(); }
    else if(e.target.value === ''){ selDisc = 0; syncDisc(); }
  });
  discCustomEl.addEventListener('keydown', e => { if(e.key==='Enter'){ e.preventDefault(); discCustomEl.blur(); }});
}
/* clearDiscount и clearAllItems функции премахнати — бутоните ги нямаше */

/* ── Автофокус Код → Цена ── */
codeInput.addEventListener('keydown', e => {
  if(e.key==='Enter'){ e.preventDefault(); priceInput.focus(); priceInput.select(); }
});
priceInput.addEventListener('keydown', e => {
  if(e.key==='Enter'){ e.preventDefault(); priceInput.blur(); }
});

brandSelect.addEventListener('change', () => brandSelect.classList.toggle('chosen', !!brandSelect.value));

/* ═══════════════════════════════════════════════════
   S9 AUTO-FILL: при загуба на фокус на код → AJAX lookup_code
   → попълва цена + марка от item_variants история
   → ако има множество варианти (multi-brand) → показва picker
   ═══════════════════════════════════════════════════ */

/* S9.DEBUG: малка badge която показва статуса на auto-fill */
(function(){
  if(document.getElementById('s9DebugBadge')) return;
  const badge = document.createElement('div');
  badge.id = 's9DebugBadge';
  badge.style.cssText = 'position:fixed;top:4px;right:4px;z-index:9999;padding:4px 8px;border-radius:8px;background:rgba(0,0,0,.7);color:#fff;font:11px/1.2 monospace;display:none;max-width:200px;word-break:break-all';
  document.body.appendChild(badge);
})();
function s9dbg(msg, color){
  const b = document.getElementById('s9DebugBadge');
  if(!b) return;
  b.textContent = msg;
  b.style.background = color || 'rgba(0,0,0,.7)';
  b.style.display = 'block';
  clearTimeout(b._t);
  b._t = setTimeout(() => { b.style.display = 'none'; }, 4000);
}

/* S9.PICKER: контейнер за multi-variant chooser */
(function(){
  if(document.getElementById('s9VariantPicker')) return;
  const pk = document.createElement('div');
  pk.id = 's9VariantPicker';
  pk.style.cssText = 'display:none;margin:8px 0;padding:10px;border:2px dashed #6366f1;border-radius:12px;background:rgba(99,102,241,.06)';
  pk.innerHTML = '<div style="font:700 11px/1.4 system-ui;letter-spacing:.05em;text-transform:uppercase;color:#6366f1;margin-bottom:6px">Избери вариант:</div><div id="s9VariantList" style="display:flex;flex-wrap:wrap;gap:6px"></div>';
  /* Вмъкни го след code/price ред (преди brandSelect) */
  const cs = document.getElementById('cardSection');
  const target = brandSelect && brandSelect.closest('div') ? brandSelect.closest('div').parentElement : null;
  if(target) target.parentElement.insertBefore(pk, target);
  else document.body.appendChild(pk);
})();

function hideVariantPicker(){
  const pk = document.getElementById('s9VariantPicker');
  if(pk) pk.style.display = 'none';
}
function showVariantPicker(variants){
  const pk = document.getElementById('s9VariantPicker');
  const list = document.getElementById('s9VariantList');
  if(!pk || !list) return;
  list.innerHTML = '';
  variants.forEach((v, idx) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.style.cssText = 'padding:8px 14px;border-radius:999px;border:1.5px solid #6366f1;background:#fff;color:#6366f1;font:700 13px/1.2 system-ui;cursor:pointer;display:flex;align-items:center;gap:6px';
    const brandLabel = v.brand ? v.brand : '(без марка)';
    btn.innerHTML = '<span>' + brandLabel + '</span><span style="font-family:monospace;font-weight:900">' + v.price.toFixed(2) + ' €</span><span style="opacity:.5;font-size:10px">×' + v.use_count + '</span>';
    btn.addEventListener('click', () => {
      priceInput.value = v.price.toFixed(2);
      priceInput.dataset.autofilled = '1';
      priceInput.style.background = 'rgba(76, 175, 80, 0.15)';
      setTimeout(() => { priceInput.style.background = ''; }, 1500);
      if(v.brand && brandSelect){
        for(let i = 0; i < brandSelect.options.length; i++){
          if(brandSelect.options[i].text === v.brand || brandSelect.options[i].value === v.brand){
            brandSelect.selectedIndex = i;
            brandSelect.classList.add('chosen');
            brandSelect.dataset.autofilled = '1';
            break;
          }
        }
      }
      hideVariantPicker();
      if(typeof updateStickyLive === 'function') updateStickyLive();
    });
    list.appendChild(btn);
  });
  pk.style.display = 'block';
}

let _lookupAbortCtrl = null;
async function autoFillFromCode(code){
  code = String(code||'').trim();
  if(!code){ hideVariantPicker(); return; }

  /* Skip ако цена и марка вече са въведени (НЕ ръчно) */
  const _curPrice = parseFloat(priceInput.value) || 0;
  if(_curPrice > 0 && priceInput.dataset.autofilled !== '1'){
    s9dbg('skip: ръчна цена '+_curPrice);
    hideVariantPicker();
    return;
  }

  s9dbg('Lookup: ' + code + '...', 'rgba(0,80,150,.85)');

  if(_lookupAbortCtrl) { try { _lookupAbortCtrl.abort(); } catch(e){} }
  _lookupAbortCtrl = new AbortController();

  try {
    const res = await fetch('lookup_code.php?code=' + encodeURIComponent(code), {
      credentials: 'same-origin',
      signal: _lookupAbortCtrl.signal
    });
    if(!res.ok){ s9dbg('HTTP '+res.status, 'rgba(200,0,0,.85)'); return; }
    const data = await res.json();
    if(!data){ s9dbg('empty', 'rgba(200,0,0,.85)'); return; }
    if(!data.ok){ s9dbg('Not found', 'rgba(150,100,0,.85)'); hideVariantPicker(); return; }

    const variants = data.variants || [];
    if(variants.length === 0){ s9dbg('Empty variants', 'rgba(150,100,0,.85)'); hideVariantPicker(); return; }

    if(variants.length === 1){
      /* Един вариант → auto-fill директно */
      const v = variants[0];
      hideVariantPicker();
      if(v.price && _curPrice <= 0){
        priceInput.value = v.price.toFixed(2);
        priceInput.dataset.autofilled = '1';
        priceInput.style.background = 'rgba(76, 175, 80, 0.15)';
        setTimeout(() => { priceInput.style.background = ''; }, 1500);
      }
      let brandSet = '';
      if(v.brand && brandSelect && !brandSelect.value){
        for(let i = 0; i < brandSelect.options.length; i++){
          if(brandSelect.options[i].text === v.brand || brandSelect.options[i].value === v.brand){
            brandSelect.selectedIndex = i;
            brandSelect.classList.add('chosen');
            brandSelect.dataset.autofilled = '1';
            brandSet = ' + ' + v.brand;
            break;
          }
        }
      }
      s9dbg('✓ ' + v.price.toFixed(2) + ' €' + brandSet, 'rgba(0,150,50,.85)');
    } else {
      /* Множество варианти → показва picker */
      showVariantPicker(variants);
      s9dbg(variants.length + ' варианта — избери', 'rgba(150,80,0,.85)');
    }

    if(typeof updateStickyLive === 'function') updateStickyLive();
  } catch(err){
    if(err.name !== 'AbortError'){
      s9dbg('ERR: '+(err.message||err.name||'?'), 'rgba(200,0,0,.85)');
      console.warn('lookup_code:', err);
    }
  }
}

/* Trigger 1: при blur на code input (касиерка отстъпва от полето) */
codeInput.addEventListener('blur', () => autoFillFromCode(codeInput.value));

/* Trigger 2: при Enter в code input (преди да focus-не price) */
codeInput.addEventListener('keydown', e => {
  if(e.key === 'Enter'){
    /* Auto-fill преди focus-а върху price */
    autoFillFromCode(codeInput.value);
  }
});

/* Trigger 3: debounced при input (като пише — 600ms след спирането) */
let _lookupTimer = null;
codeInput.addEventListener('input', () => {
  /* S9.CLEAR: ако код стане празен → изчисти auto-filled полета + picker */
  if(!codeInput.value.trim()){
    if(priceInput.dataset.autofilled === '1'){
      priceInput.value = '';
      delete priceInput.dataset.autofilled;
    }
    if(brandSelect && brandSelect.dataset.autofilled === '1'){
      brandSelect.value = '';
      brandSelect.classList.remove('chosen');
      delete brandSelect.dataset.autofilled;
    }
    hideVariantPicker();
    if(_lookupTimer) clearTimeout(_lookupTimer);
    return;
  }
  if(_lookupTimer) clearTimeout(_lookupTimer);
  _lookupTimer = setTimeout(() => autoFillFromCode(codeInput.value), 600);
});

/* S9.MANUAL: при ръчна промяна на цена/марка → маркер че не е auto-fill */
priceInput.addEventListener('input', () => {
  if(priceInput.dataset.autofilled === '1') delete priceInput.dataset.autofilled;
});
if(brandSelect){
  brandSelect.addEventListener('change', () => {
    if(brandSelect.dataset.autofilled === '1') delete brandSelect.dataset.autofilled;
  });
}

/* ── Добави артикул ── */
function flash(el){ el.style.borderColor='var(--red)'; el.style.background='#fff5f5'; setTimeout(()=>{ el.style.borderColor=''; el.style.background=''; }, 1200); }

function addItem(){
  const code  = codeInput.value.trim();
  const price = parseFloat(priceInput.value);
  const brand = brandSelect.value;
  if(!code){ flash(codeInput); codeInput.focus(); return; }
  if(!price||price<=0){ flash(priceInput); priceInput.focus(); return; }

  const base  = Math.round(selQty * price * 100) / 100;
  const final = Math.round(base * (1 - selDisc/100) * 100) / 100;

  // Режим връщане — отрицателни стойности
  const actualQty   = returnMode ? -Math.abs(selQty) : selQty;
  const actualBase  = returnMode ? -Math.abs(base)   : base;
  const actualFinal = returnMode ? -Math.abs(final)  : final;

  items.push({ id: Date.now(), code, brand, qty: actualQty, price, disc: selDisc, base: actualBase, final: actualFinal });

  // Ресет полета
  codeInput.value=''; priceInput.value='';
  selQty=1; selDisc=0;
  qtyCustom.value=''; brandSelect.value=''; brandSelect.classList.remove('chosen');
  syncQty(); syncDisc();
  render(); updateTotals();
  document.getElementById('saveResult').style.display = 'none';

  // FIX: Прибери клавиатурата (blur всички inputs)
  try { codeInput.blur(); priceInput.blur(); qtyCustom.blur(); } catch(e){}
  if(document.activeElement && document.activeElement.blur) {
    try { document.activeElement.blur(); } catch(e){}
  }

  // FIX: Return mode — auto-off след 1 артикул на минус
  if(returnMode){ toggleReturnMode(); }

  document.body.scrollTop = 0; document.documentElement.scrollTop = 0;

  // Запази сесията
  if(typeof saveSessionState === 'function') saveSessionState();
}
document.getElementById('addBtn').addEventListener('click', addItem);

/* ── Рендер списък ── */
function render(){
  itemsLabel.style.display = items.length ? 'block' : 'none';
  const hasReturns = items.some(i => i.qty < 0);
  const hasNormal  = items.some(i => i.qty > 0);
  saveBtn.disabled = items.length === 0;
  if(saveBtnTop) saveBtnTop.disabled = items.length === 0;
  if(hasReturns && !hasNormal) saveBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg><span>Запиши връщането</span>';
  else if(hasReturns && hasNormal) saveBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><span>Запиши (смесено)</span>';
  else saveBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><span>Запиши продажбата</span>';
  document.getElementById('printSaleBtn').disabled = items.length === 0;
  if(!items.length){ itemsList.innerHTML=''; return; }
  itemsList.innerHTML = items.map(item => {
    const name = [item.code, item.brand].filter(Boolean).join(' · ');
    const isReturn = item.qty < 0;
    const hasDisc  = item.disc > 0;
    const qtyAbs   = Math.abs(item.qty);
    const finalAbs = Math.abs(item.final);
    const baseAbs  = Math.abs(item.base);
    return `<div class="item-row" style="${isReturn?'background:#fff5f5;border-color:#fecaca;':''}">
      <div class="item-info">
        <div class="item-name">${isReturn?'<span style="color:var(--red);font-weight:900;display:inline-flex;align-items:center;gap:4px"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>ВРЪЩАНЕ</span> ':''}${esc(name)}</div>
        <div class="item-sub">${isReturn?'−':''}${qtyAbs} бр × ${item.price.toFixed(2)} €${hasDisc?` <span class="disc-t">−${item.disc}%</span>`:''}</div>
      </div>
      <div class="item-prices">
        ${hasDisc?`<div class="item-orig">${isReturn?'−':''}${baseAbs.toFixed(2)} €</div>`:''}
        <div class="item-final" style="${isReturn?'color:var(--red);':''}">
          ${isReturn?'−':''}${finalAbs.toFixed(2)} €
        </div>
      </div>
      <button class="item-del" onclick="delItem(${item.id})"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>`;
  }).join('');
}
window.delItem = id => { items=items.filter(i=>i.id!==id); render(); updateTotals(); };

/* ── Тоталне ── */
function updateTotals(){
  const base  = items.reduce((s,i)=>s+i.base,  0);
  const final = items.reduce((s,i)=>s+i.final, 0);
  const disc  = base - final;
  const isReturn = final < 0;
  document.getElementById('tBase').textContent  = (base < 0 ? '−' : '') + Math.abs(base).toFixed(2) + ' €';
  document.getElementById('tDisc').textContent  = disc > 0 ? '−'+disc.toFixed(2)+' €' : '—';
  document.getElementById('tFinal').textContent = (isReturn ? '−' : '') + Math.abs(final).toFixed(2) + ' €';
  document.getElementById('tFinal').style.color = isReturn ? 'var(--red)' : '';
  document.getElementById('hTotal').textContent = (isReturn ? '−' : '') + Math.abs(final).toFixed(2) + ' €';
  /* S79.UNIFIED */
  try {
    const payEl = document.getElementById('payAmountBig');
    if(payEl) payEl.textContent = (isReturn ? '−' : '') + Math.abs(final).toFixed(2) + ' €';
    const exactBtn = document.getElementById('exactBtn');
    if(exactBtn) exactBtn.textContent = '= Точна сума (' + Math.abs(final).toFixed(2) + ' €)';
    if(typeof updateChange === 'function') updateChange();
  } catch(e){ console.warn('updateTotals extra:', e); }
}

/* ── Запиши продажба ── */
saveBtn.addEventListener('click', async () => {
  if(!items.length) return;
  // Веднага блокираме — предотвратява двоен запис
  saveBtn.disabled = true;
  saveBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg><span>Записване...</span>';
  saveResult.style.display = 'none';

  const base  = items.reduce((s,i)=>s+i.base,  0);
  const final = items.reduce((s,i)=>s+i.final, 0);
  const disc  = base - final;

  // Timeout — ако сървърът не отговори за 15 сек, отключваме бутона
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 15000);

  try {
    const url = new URL(PAGE_URL);
    url.searchParams.set('ajax','save');
    url.searchParams.set('location', LOCATION_ID);
    const res = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({
        items,
        gross: Math.round(base*100)/100,
        final: Math.round(final*100)/100,
        discount: Math.round(disc*100)/100,
        location_id: LOCATION_ID,
        location_name: LOCATION_NAME,
        /* S79.UNIFIED */
        card_number: scannedCard || null,
        has_card: scannedCard ? 1 : 0,
        customer_id: scannedCustomerId || null,
        /* S5-REWRITE: voucher_id за mark used */
        voucher_id: (scannedCustomerData && scannedCustomerData.active_voucher_id) ? scannedCustomerData.active_voucher_id : null,
        given_amount: givenAmount !== '' ? parseFloat(givenAmount) : null,
        change_amount: givenAmount !== '' ? Math.round((parseFloat(givenAmount) - Math.abs(final)) * 100) / 100 : null,
        payment_method: paymentMethod,
      }),
      signal: controller.signal
    });
    clearTimeout(timeout);
    const data = await res.json();

    if(data.ok){
      saveResult.innerHTML = '<span style="display:inline-flex;align-items:center;gap:6px"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Записано! ID: ' + (data.id||'?') + (data.loc_name ? ' · ' + data.loc_name : '') + '</span>';
      saveResult.style.display = 'block';
      saveResult.style.background = 'var(--green-bg)';
      saveResult.style.color = 'var(--green)';
      // Ресет
      items=[]; selQty=1; selDisc=0; returnMode=false;
      codeInput.value=''; priceInput.value='';
      qtyCustom.value=''; brandSelect.value='';
      brandSelect.classList.remove('chosen');
      /* S79.UNIFIED reset */
      scannedCard=''; scannedCustomerId=null; scannedCustomerData=null;
      const _cb=document.getElementById('clientBlock'); if(_cb) _cb.style.display='none';
      const _ci=document.getElementById('cardInput'); if(_ci) _ci.value='';
      const _cs=document.getElementById('cardSection'); if(_cs) _cs.classList.remove('active');
      givenAmount='';
      const _gi=document.getElementById('givenInput'); if(_gi) _gi.value='';
      if(typeof renderGiven==='function') renderGiven();
      /* S4.UX: камера остава ЗАТВОРЕНА след save (Тихол) */
      paymentMethod='cash';
      document.querySelectorAll('.pm-btn').forEach(b=>b.classList.toggle('active', b.dataset.pm==='cash'));
      const _pl=document.getElementById('payLoyalty'); if(_pl) _pl.style.display='none';
      const ab = document.getElementById('addBtn');
      if(ab){ ab.style.background=''; ab.style.color=''; ab.style.boxShadow=''; ab.style.borderColor=''; ab.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg><span>Добави артикул</span>'; }
      const rb = document.getElementById('returnModeBtn');
      if(rb) rb.classList.remove('active');
      /* Скрий scanStatus съобщението */
      const _ss=document.getElementById('scanStatus'); if(_ss){ _ss.style.display='none'; _ss.textContent=''; }
      /* Скрий voucher block */
      const _cv=document.getElementById('clientVoucher'); if(_cv){ _cv.style.display='none'; _cv.innerHTML=''; }
      /* Скрий client stats */
      const _cst=document.getElementById('clientStats'); if(_cst){ _cst.style.display='none'; _cst.innerHTML=''; }
      /* Върни entry-card видим ако е бил скрит */
      const _ec=document.getElementById('entryCard'); if(_ec) _ec.style.display='';
      const _nb=document.getElementById('newItemBtn'); if(_nb) _nb.style.display='none';
      /* Скрий change block */
      const _chb=document.getElementById('changeBlock'); if(_chb) _chb.classList.add('hidden');
      /* FIX: Изчисти запазената сесия — започваме нова продажба */
      if(typeof clearSessionState === 'function') clearSessionState();
      syncQty(); syncDisc();
      render(); updateTotals();
      /* Scroll top за нов клиент */
      window.scrollTo({ top: 0, behavior: 'smooth' });
      // Изчакваме малко преди да обновим историята — сървърът да завърши записа
      setTimeout(() => loadHistory(), 800);
      setTimeout(() => { saveResult.style.display='none'; }, 5000);
    } else {
      saveResult.innerHTML = '<span style="display:inline-flex;align-items:center;gap:6px"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Грешка: ' + (data.error || 'Неизвестна грешка') + '</span>';
      saveResult.style.display = 'block';
      saveResult.style.background = '#fff1f0';
      saveResult.style.color = 'var(--red)';
    }
  } catch(e) {
    clearTimeout(timeout);
    if(e.name === 'AbortError'){
      saveResult.innerHTML = '<span style="display:inline-flex;align-items:center;gap:6px"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Времето изтече — провери дали е записано преди да опиташ отново!</span>';
    } else {
      saveResult.innerHTML = '<span style="display:inline-flex;align-items:center;gap:6px"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> ' + e.message + '</span>';
    }
    saveResult.style.display = 'block';
    saveResult.style.background = '#fff8e6';
    saveResult.style.color = '#8a6700';
  } finally {
    // Винаги отключваме бутона
    saveBtn.disabled = false;
    saveBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><span>Запиши продажбата</span>';
  }
});

/* ══ ИСТОРИЯ ══ */
function selectDay(btn){
  document.querySelectorAll('.day-tab').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  histDate = btn.dataset.date;
  loadHistory();
}
window.selectDay = selectDay;

async function loadHistory(){
  if(histLoading) return;
  histLoading = true;
  try {
    const url = new URL(PAGE_URL);
    url.searchParams.set('ajax','history');
    url.searchParams.set('loc', LOCATION_ID);
    url.searchParams.set('date', histDate);
    url.searchParams.set('_ts', Date.now());

    const res  = await fetch(url, {cache:'no-store'});
    const data = await res.json();
    if(!data.ok){ histLoading=false; return; }

    _histData = data;

    const discPct = data.day_gross > 0 ? ((data.day_discount/data.day_gross)*100).toFixed(1) : '0.0';
    document.getElementById('hCount').textContent = data.count;
    document.getElementById('hFinal').textContent = data.day_final.toFixed(2) + ' €';
    document.getElementById('hDisc').textContent  = data.day_discount > 0
      ? '−'+data.day_discount.toFixed(2)+' € ('+discPct+'%)'
      : '—';

  } catch(e) {}
  histLoading = false;
}

/* ══ FULLSCREEN ОБОБЩЕНИЕ ══ */
function openFullscreen(){
  document.getElementById('fsOverlay').classList.remove('hidden');
  renderFullscreen();
}
function closeFullscreen(){
  document.getElementById('fsOverlay').classList.add('hidden');
}
window.closeFullscreen = closeFullscreen;

function renderFullscreen(){
  const body  = document.getElementById('fsBody');
  const title = document.getElementById('fsTitle');

  // Намери активния таб
  let dateLabel='Днес';
  document.querySelectorAll('.day-tab').forEach(t=>{ if(t.classList.contains('active')) dateLabel=t.textContent; });
  title.innerHTML = '<span style="display:inline-flex;align-items:center;gap:6px"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg> ' + dateLabel + '</span>';

  if(!_histData || !_histData.count){
    body.innerHTML = '<div class="fs-no-data">Няма продажби за този ден</div>';
    return;
  }

  const data = _histData;
  const discPct = data.day_gross > 0 ? ((data.day_discount/data.day_gross)*100).toFixed(1) : '0.0';

  let html = '';

  // ── Обобщение артикули ──
  if(data.day_items && data.day_items.length){
    html += `<div style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.5px;color:var(--gold);padding-bottom:8px;margin-bottom:4px;border-bottom:2px solid hsl(var(--hue2) 20% 18%);display:flex;align-items:center;gap:6px">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg> <span>Артикули за деня — ${data.count} продажби</span>
    </div>`;

    data.day_items.forEach(item => {
      const code  = item.code  || '—';
      const brand = item.brand || '';
      const total = parseFloat(item.line_base||0);
      html += `<div class="fs-item-row">
        <div class="fs-item-left">
          <div class="fs-item-code">${esc(code)}</div>
          ${brand ? `<div class="fs-item-brand">${esc(brand)}</div>` : ''}
        </div>
        <div class="fs-item-right">
          <div class="fs-item-qty">× ${item.qty}</div>
          <div class="fs-item-price">${parseFloat(item.price).toFixed(2)} €/бр</div>
          <div style="font-size:12px;color:var(--text3);font-weight:700">${total.toFixed(2)} €</div>
        </div>
      </div>`;
    });

    // Тоталне
    html += `<div class="fs-totals">
      <div class="fs-tot-row">
        <span class="fs-tot-label">Общо без отстъпка</span>
        <span class="fs-tot-value">${data.day_gross.toFixed(2)} €</span>
      </div>
      <div class="fs-tot-row">
        <span class="fs-tot-label">Реално взето</span>
        <span class="fs-tot-value">${data.day_final.toFixed(2)} €</span>
      </div>
      <div class="fs-tot-row">
        <span class="fs-tot-label" style="font-weight:900">Обща отстъпка</span>
        <span class="fs-tot-value big">−${data.day_discount.toFixed(2)} € (${discPct}%)</span>
      </div>
    </div>`;
  } else {
    html += '<div class="fs-no-data">Няма артикули — използвай + Добави при въвеждане</div>';
  }

  // ── Всяка продажба поотделно ──
  if(data.sales && data.sales.length){
    html += `<div style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);padding:16px 0 8px;margin-top:8px;border-top:2px solid var(--border2)">
      Продажби по час
    </div>`;
    data.sales.forEach(sale => {
      const isReturn = sale.final < 0;
      html += `<div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid var(--border2);${isReturn?'background:#fff5f5;border-radius:8px;padding:8px;':''}">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
          <span style="font-size:18px;font-weight:900">${esc(sale.time)}</span>
          <div style="display:flex;align-items:center;gap:8px">
            <span style="font-size:16px;font-weight:900;color:${isReturn?'var(--red)':'var(--blue)'}">
              ${isReturn?'−':''}${Math.abs(sale.final).toFixed(2)} €
            </span>
            <button onclick="deleteSale(${sale.id})" style="border:none;background:rgba(239,68,68,.1);color:var(--red);border-radius:7px;padding:4px 10px;font-size:12px;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;gap:4px"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg><span>Изтрий</span></button>
          </div>
        </div>`;
      sale.items.forEach(it => {
        const name = [it.code, it.brand].filter(Boolean).join(' · ');
        html += `<div style="display:flex;justify-content:space-between;font-size:13px;padding:2px 0">
          <span style="font-weight:700;color:var(--text2)">${esc(name)}</span>
          <span style="font-weight:900">×${it.qty} · ${parseFloat(it.price).toFixed(2)} €${it.disc>0?' (−'+it.disc+'%)':''}</span>
        </div>`;
      });
      html += '</div>';
    });
  }

  body.innerHTML = html;
}

/* ── Старт ── */
syncQty(); syncDisc(); render(); updateTotals();
loadHistory();
setInterval(()=>{ if(!histLoading) loadHistory(); }, 120000);
setTimeout(()=>codeInput.focus(), 100);

/* ══ ИЗТРИВАНЕ НА ПРОДАЖБА ══ */
window.deleteSale = async function(id) {
  if (!confirm('Сигурен ли си? Продажбата ще се изтрие!')) return;
  try {
    const url = new URL(PAGE_URL);
    url.searchParams.set('ajax','delete_sale');
    const res  = await fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({id})});
    const data = await res.json();
    if (data.ok) loadHistory();
    else alert('Грешка: ' + (data.error||'?'));
  } catch(e) { alert('Грешка: ' + e.message); }
};

/* ══ ВРЪЩАНЕ — добавя артикул с минус количество ══ */
let returnMode = false;
window.toggleReturnMode = function() {
  returnMode = !returnMode;
  const btn = document.getElementById('addBtn');
  const rb  = document.getElementById('returnModeBtn');
  if (returnMode) {
    if(btn){
      btn.style.background = 'linear-gradient(135deg,hsl(0 70% 50%),hsl(0 80% 40%))';
      btn.style.borderColor = 'hsl(0 65% 55%)';
      btn.style.boxShadow = '0 8px 24px hsl(0 70% 40% / .4),0 0 24px hsl(0 70% 50% / .3),inset 0 1px 0 rgba(255,255,255,.2)';
      btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg><span>Добави на минус</span>';
    }
    if(rb) rb.classList.add('active');
  } else {
    if(btn){
      btn.style.background = '';
      btn.style.borderColor = '';
      btn.style.boxShadow = '';
      btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg><span>Добави артикул</span>';
    }
    if(rb) rb.classList.remove('active');
  }
  try { codeInput.focus(); } catch(e){}
  if(typeof saveSessionState === 'function') saveSessionState();
};

/* ══ ПЕЧАТ ══ */
function buildPrintHeader(title, subtitle){
  return `<div class="pr-header">
    <div class="pr-title">${esc(LOCATION_NAME || 'Ени Тихолов')}</div>
    <div class="pr-sub">${esc(title)}</div>
    ${subtitle ? `<div class="pr-sub">${esc(subtitle)}</div>` : ''}
  </div>`;
}

// Печат на текущата покупка
function printSale(){
  if(!items.length) return;
  const base  = items.reduce((s,i)=>s+i.base,  0);
  const final = items.reduce((s,i)=>s+i.final, 0);
  const disc  = base - final;
  const now   = new Date().toLocaleString('bg-BG');

  let rows = items.map(i => {
    const name = [i.code, i.brand].filter(Boolean).join(' · ');
    return `<tr>
      <td>${esc(name)}</td>
      <td class="right">${i.qty}</td>
      <td class="right">${i.price.toFixed(2)} €</td>
      ${i.disc > 0 ? `<td class="right">−${i.disc}%</td>` : '<td></td>'}
      <td class="right">${i.final.toFixed(2)} €</td>
    </tr>`;
  }).join('');

  document.getElementById('printContent').innerHTML = `
    ${buildPrintHeader('Покупка', now)}
    <table class="pr-table">
      <thead><tr><th>Артикул</th><th>Бр.</th><th>Цена</th><th>Отст.</th><th>Сума</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>
    <div class="pr-totals">
      ${disc > 0 ? `<div class="pr-tot-row red"><span>Отстъпка</span><span>−${disc.toFixed(2)} €</span></div>` : ''}
      <div class="pr-tot-row big"><span>ЗА ПЛАЩАНЕ</span><span>${final.toFixed(2)} €</span></div>
    </div>
    <div class="pr-footer">Ени Тихолов · ${esc(LOCATION_NAME || '')}</div>`;

  window.print();
}

// Печат на историята за деня
function printHistory(){
  if(!_histData) { alert('Зареди историята първо.'); return; }
  const data = _histData;
  const discPct = data.day_gross > 0 ? ((data.day_discount/data.day_gross)*100).toFixed(1) : '0.0';

  let tabs = document.querySelectorAll('.day-tab');
  let dateLabel = 'Днес';
  tabs.forEach(t => { if(t.classList.contains('active')) dateLabel = t.textContent; });

  let salesHtml = '';
  (data.sales||[]).forEach(sale => {
    let itemRows = (sale.items||[]).map(it => {
      const name = [it.code, it.brand].filter(Boolean).join(' · ');
      return `<tr>
        <td>${esc(name)}</td>
        <td class="right">${it.qty}</td>
        <td class="right">${parseFloat(it.price).toFixed(2)} €</td>
        ${it.disc > 0 ? `<td class="right">−${it.disc}%</td>` : '<td></td>'}
        <td class="right">${parseFloat(it.final).toFixed(2)} €</td>
      </tr>`;
    }).join('');

    salesHtml += `<div class="pr-sale-block">
      <div class="pr-sale-head">
        <span>${esc(sale.time)}</span>
        <span>${sale.final.toFixed(2)} €${sale.discount > 0 ? ' (−'+sale.discount.toFixed(2)+' €)' : ''}</span>
      </div>
      ${itemRows ? `<table class="pr-table">
        <thead><tr><th>Артикул</th><th>Бр.</th><th>Цена</th><th>Отст.</th><th>Сума</th></tr></thead>
        <tbody>${itemRows}</tbody>
      </table>` : '<div style="font-size:12px;color:#999">Без детайли</div>'}
    </div>`;
  });

  // Обобщение артикули
  let aggRows = (data.day_items||[]).map(item => `<tr>
    <td>${esc(item.code||'—')}</td>
    <td>${esc(item.brand||'—')}</td>
    <td class="right">${item.qty}</td>
    <td class="right">${parseFloat(item.price).toFixed(2)} €</td>
    <td class="right">${parseFloat(item.line_base).toFixed(2)} €</td>
  </tr>`).join('');

  document.getElementById('printContent').innerHTML = `
    ${buildPrintHeader('История за деня', dateLabel + ' · ' + data.count + ' продажби')}

    <div style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.5px;margin:10px 0 6px">Артикули за деня</div>
    <table class="pr-table">
      <thead><tr><th>Артикул</th><th>Производител</th><th>Бр.</th><th>Цена</th><th>Общо</th></tr></thead>
      <tbody>${aggRows || '<tr><td colspan="5" style="color:#999">Няма данни</td></tr>'}</tbody>
    </table>

    <div class="pr-totals">
      <div class="pr-tot-row"><span>Общо без отстъпка</span><span>${data.day_gross.toFixed(2)} €</span></div>
      <div class="pr-tot-row red"><span>Обща отстъпка</span><span>−${data.day_discount.toFixed(2)} € (${discPct}%)</span></div>
      <div class="pr-tot-row big"><span>РЕАЛНО ВЗЕТО</span><span>${data.day_final.toFixed(2)} €</span></div>
    </div>

    <div style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.5px;margin:14px 0 6px;border-top:1px solid #ddd;padding-top:10px">Продажби по час</div>
    ${salesHtml}

    <div class="pr-footer">Ени Тихолов · ${esc(LOCATION_NAME || '')} · Отпечатано: ${new Date().toLocaleString('bg-BG')}</div>`;

  window.print();
}
window.printSale = printSale;
window.printHistory = printHistory;


/* ═══════════════════════════════════════════════════════════════
   S79.UNIFIED — Card scan, Numpad, Change, Payment method
   ═══════════════════════════════════════════════════════════════ */
/* globals moved to top */

function toggleScan(){
  /* Камерата е винаги активна — просто показваме focus */
  const sbtn=document.getElementById('scanBtn');
  const mbtn=document.getElementById('manualBtn');
  const manual=document.getElementById('manualExpanded');
  if(manual) manual.classList.add('hidden');
  if(mbtn) mbtn.classList.remove('active');
  if(sbtn) sbtn.classList.add('active');
  initQr(); // ако не е стартирана
}
function toggleManual(){
  const qr=document.getElementById('qrExpanded'), manual=document.getElementById('manualExpanded');
  const sbtn=document.getElementById('scanBtn'), mbtn=document.getElementById('manualBtn');
  const sect=document.getElementById('cardSection');
  const opened=!manual.classList.contains('hidden');
  manual.classList.toggle('hidden'); qr.classList.add('hidden');
  sbtn.classList.remove('active'); stopQr();
  mbtn.classList.toggle('active', !opened);
  sect.classList.toggle('active', !opened || scannedCard !== '');
  if(!opened) setTimeout(()=>document.getElementById('cardInput').focus(), 100);
}
window.toggleScan=toggleScan; window.toggleManual=toggleManual;

/* ═══════════════════════════════════════════════════════════════
   QR СКЕНЕР — AUTO START (същия паттерн като стария scan.php)
   ═══════════════════════════════════════════════════════════════ */
function setScanStatus(msg, ok){
  const el = document.getElementById('scanStatus');
  if(!el) return;
  if(!msg){ el.style.display='none'; return; }
  el.style.display='';
  el.textContent = msg;
  el.className = 'scan-status' + (ok ? ' ok' : '');
}

function initQr(){
  if(html5QrCode && html5QrCode._isScanning) return;
  if(html5QrCode) return;
  if(typeof Html5Qrcode === 'undefined'){
    setScanStatus('QR библиотеката не е заредена');
    return;
  }
  try {
    html5QrCode = new Html5Qrcode('reader');
    html5QrCode.start(
      { facingMode: 'environment' },
      { fps: 10, qrbox: { width: 200, height: 200 }, aspectRatio: 1 },
      (text) => {
        const now = Date.now();
        if(text === lastScan && now - lastScanTs < 1500) return;
        lastScan = text; lastScanTs = now;
        const card = text.includes(':') ? text.split(':')[0] : text;
        const ci = document.getElementById('cardInput');
        if(ci) ci.value = card;
        try { beep(); } catch(e){}
        onCardEntered(card);
        setScanStatus('Сканирано: ' + card, true);
      },
      () => {}
    ).then(() => { if(html5QrCode) html5QrCode._isScanning = true; })
     .catch((err) => {
       console.error('QR start error:', err);
       setScanStatus('Камерата не стартира');
       html5QrCode = null;
     });
  } catch(e) {
    console.error('QR init error:', e);
    setScanStatus('Грешка: ' + (e.message || e));
  }
}
/* toggle aliases */
function startQr(){ initQr(); }
function stopQr(){ /* камерата остава активна */ }

/* FIX: Camera auto-restart при връщане в таба/апа */
document.addEventListener('visibilitychange', () => {
  if(document.visibilityState === 'visible'){
    /* S4.UX: НЕ авто-стартирай камерата при връщане в таб */
    if(typeof restoreSessionState === 'function') setTimeout(restoreSessionState, 250);
  } else {
    if(typeof saveSessionState === 'function') saveSessionState();
    if(html5QrCode && html5QrCode._isScanning){
      try { html5QrCode.stop().then(()=>{ if(html5QrCode) html5QrCode._isScanning=false; html5QrCode=null; }).catch(()=>{ if(html5QrCode) html5QrCode._isScanning=false; html5QrCode=null; }); }
      catch(e){ if(html5QrCode) html5QrCode._isScanning=false; html5QrCode=null; }
    }
  }
});
window.addEventListener('focus', () => {
  /* S4.UX: камерата остава затворена (Тихол) */
});


/* ═══ BEEP — звуков сигнал при успешно сканиране (WebAudio, без файл) ═══ */
let _audioCtx = null;
function beep(){
  try {
    if(!_audioCtx){
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if(!Ctx) return;
      _audioCtx = new Ctx();
    }
    // Ако контекстът е suspended (iOS изисква user gesture) — опитваме resume
    if(_audioCtx.state === 'suspended'){ try { _audioCtx.resume(); } catch(e){} }
    const osc = _audioCtx.createOscillator();
    const gain = _audioCtx.createGain();
    osc.type = 'triangle';
    osc.frequency.setValueAtTime(880, _audioCtx.currentTime);
    osc.frequency.setValueAtTime(1320, _audioCtx.currentTime + 0.08);
    gain.gain.setValueAtTime(0.35, _audioCtx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, _audioCtx.currentTime + 0.2);
    osc.connect(gain); gain.connect(_audioCtx.destination);
    osc.start();
    osc.stop(_audioCtx.currentTime + 0.2);
    // Vibrate за Android
    if(navigator.vibrate) try { navigator.vibrate(60); } catch(e){}
  } catch(e){}
}
/* Първото user interaction unlock-ва audio context (iOS policy) */
document.addEventListener('touchstart', ()=>{
  if(!_audioCtx){
    try {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if(Ctx){ _audioCtx = new Ctx(); }
    } catch(e){}
  }
}, { once: true, passive: true });
window.beep = beep;

function onCardEntered(card){
  card=String(card||'').trim();
  if(!card){ clearCardVisual(); return; }
  scannedCard=card;
  const cb=document.getElementById('clientBlock');
  const cn=document.getElementById('clientName');
  const cc=document.getElementById('clientCard');
  const cv=document.getElementById('clientVoucher');
  const cs=document.getElementById('clientStats');
  if(cb) cb.style.display='flex';
  if(cn) cn.textContent='Клиент с карта';
  if(cc) cc.textContent=card;
  if(cv) cv.style.display='none';
  if(cs) cs.style.display='none';
  document.getElementById('cardSection').classList.add('active');

  /* Fetch име + ваучер от сървъра (precise — loyalty_cards → customers + vouchers) */
  fetch('?ajax=lookup_card&card=' + encodeURIComponent(card), { credentials: 'same-origin' })
    .then(r => r.ok ? r.json() : null)
    .then(data => {
      if(!data || data.card !== card) return; // race condition guard
      if(data.customer_id){ scannedCustomerId = data.customer_id; scannedCustomerData = data; }

      /* Име */
      if(data.name && cn) cn.textContent = data.name;
      else if(cn) cn.textContent = 'Нерегистрирана карта';

      /* Статистика: брой покупки + общо похарчено */
      if(cs && data.customer_id && (data.total_purchases > 0 || data.total_spent > 0)){
        const purch = parseInt(data.total_purchases || 0);
        const spent = parseFloat(data.total_spent || 0).toFixed(2);
        cs.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg><span>' + purch + ' покупки · ' + spent + ' € общо</span>';
        cs.style.display = 'flex';
      }

      /* Активен ваучер — процент ИЛИ фиксирана сума */
      if(cv){
        let voucherText = '';
        if(data.active_voucher_percent && data.active_voucher_percent > 0){
          voucherText = 'Активен ваучер: -' + data.active_voucher_percent + '% отстъпка';
        } else if(data.active_voucher_amount > 0){
          const amt = parseFloat(data.active_voucher_amount).toFixed(2);
          voucherText = 'Активен ваучер: ' + amt + ' €';
        }
        if(voucherText){
          cv.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg><span>' + voucherText + '</span>';
          cv.style.display = 'flex';
        }
      }

      if(typeof saveSessionState === 'function') saveSessionState();
    })
    .catch(()=>{}); // мълчи при грешка — картата вече е показана
}
function clearCardVisual(){
  scannedCard=''; scannedCustomerId=null; scannedCustomerData=null;
  const cb=document.getElementById('clientBlock'); if(cb) cb.style.display='none';
  const cv=document.getElementById('clientVoucher'); if(cv){ cv.style.display='none'; cv.innerHTML=''; }
  const cs=document.getElementById('clientStats'); if(cs){ cs.style.display='none'; cs.innerHTML=''; }
  const ci=document.getElementById('cardInput'); if(ci) ci.value='';
  const ss=document.getElementById('scanStatus'); if(ss){ ss.style.display='none'; ss.textContent=''; }
  document.getElementById('cardSection').classList.remove('active');
  if(typeof saveSessionState === 'function') saveSessionState();
}
window.clearCardVisual = clearCardVisual;
const _cardInputEl=document.getElementById('cardInput');
if(_cardInputEl){
  _cardInputEl.addEventListener('input', e=>onCardEntered(e.target.value));
  _cardInputEl.addEventListener('keydown', e=>{ if(e.key==='Enter'){ e.preventDefault(); e.target.blur(); }});
}

function renderGiven(){
  const inp=document.getElementById('givenInput');
  const change=document.getElementById('changeBlock');
  const chLbl=document.getElementById('changeLabel');
  const chAmt=document.getElementById('changeAmount');
  const chNote=document.getElementById('changeNote');
  if(!inp) return;
  const raw=(givenAmount===''||givenAmount==null) ? '' : String(givenAmount);
  if(document.activeElement !== inp){
    if(inp.value !== raw) inp.value = raw;
  }
  if(raw==='' || parseFloat(raw)===0 || isNaN(parseFloat(raw))){
    if(change) change.classList.add('hidden');
    return;
  }
  const payFinal=Math.abs(items.reduce((s,i)=>s+i.final, 0));
  const g=parseFloat(raw)||0;
  const diff=g-payFinal;
  if(change){
    change.classList.remove('hidden','zero','negative');
    if(Math.abs(diff)<0.005){
      change.classList.add('zero');
      if(chLbl) chLbl.textContent='Точна сума';
      if(chAmt) chAmt.textContent='0.00 €';
      if(chNote) chNote.textContent='Без ресто';
    } else if(diff>0){
      if(chLbl) chLbl.textContent='Връщаме ресто';
      if(chAmt) chAmt.textContent=diff.toFixed(2)+' €';
      if(chNote) chNote.textContent=g.toFixed(2)+' − '+payFinal.toFixed(2);
    } else {
      change.classList.add('negative');
      if(chLbl) chLbl.textContent='Липсват пари';
      if(chAmt) chAmt.textContent=Math.abs(diff).toFixed(2)+' €';
      if(chNote) chNote.textContent='Още трябва: '+Math.abs(diff).toFixed(2)+' €';
    }
  }
}
function updateChange(){ renderGiven(); }

/* Native input handler — потребителят въвежда с родната клавиатура */
const _givenInputEl=document.getElementById('givenInput');
if(_givenInputEl){
  _givenInputEl.addEventListener('input', e=>{
    givenAmount=e.target.value;
    renderGiven();
    if(typeof saveSessionState === 'function') saveSessionState();
  });
  _givenInputEl.addEventListener('keydown', e=>{
    if(e.key==='Enter'){ e.preventDefault(); e.target.blur(); }
  });
}

function setExactGiven(){
  const pf=Math.abs(items.reduce((s,i)=>s+i.final, 0));
  if(pf<=0) return;
  givenAmount=pf.toFixed(2);
  const inp=document.getElementById('givenInput');
  if(inp) inp.value=givenAmount;
  renderGiven();
  if(typeof saveSessionState === 'function') saveSessionState();
}
function clearGiven(){
  givenAmount='';
  const inp=document.getElementById('givenInput');
  if(inp){ inp.value=''; try{ inp.blur(); }catch(e){} }
  renderGiven();
  if(typeof saveSessionState === 'function') saveSessionState();
}
window.setExactGiven=setExactGiven; window.clearGiven=clearGiven;

function selectPm(btn){
  paymentMethod=btn.dataset.pm || 'cash';
  document.querySelectorAll('.pm-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  if(typeof saveSessionState === 'function') saveSessionState();
}
window.selectPm=selectPm;

renderGiven();

/* ═══════════════════════════════════════════════════════════
   S79.CLEAN — sessionStorage persist (state не се губи при minimize)
   ═══════════════════════════════════════════════════════════ */
const STATE_KEY = 'kalk_state_' + LOCATION_ID;
const STATE_TTL = 8 * 60 * 60 * 1000; // 8 часа — цяла смяна

/* Двойно storage — localStorage (устойчив) + sessionStorage (fallback) */
function _storageSet(v){
  try { localStorage.setItem(STATE_KEY, v); } catch(e){}
  try { sessionStorage.setItem(STATE_KEY, v); } catch(e){}
}
function _storageGet(){
  try {
    const v = localStorage.getItem(STATE_KEY);
    if(v) return v;
  } catch(e){}
  try { return sessionStorage.getItem(STATE_KEY); } catch(e){}
  return null;
}
function _storageClear(){
  try { localStorage.removeItem(STATE_KEY); } catch(e){}
  try { sessionStorage.removeItem(STATE_KEY); } catch(e){}
}

function saveSessionState(){
  try {
    const inp = document.getElementById('givenInput');
    const given = inp ? inp.value : givenAmount;
    _storageSet(JSON.stringify({
      ts: Date.now(),
      items, scannedCard, scannedCustomerId,
      scannedCustomerData,
      givenAmount: given, paymentMethod, returnMode
    }));
  } catch(e){}
}
function restoreSessionState(){
  try {
    const raw = _storageGet();
    if(!raw) return;
    const st = JSON.parse(raw);
    if(!st || Date.now() - (st.ts||0) > STATE_TTL){
      _storageClear(); return;
    }
    if(Array.isArray(st.items) && st.items.length){
      items = st.items; render(); updateTotals();
    }
    if(st.scannedCard){
      scannedCard = st.scannedCard;
      scannedCustomerId = st.scannedCustomerId || null;
      scannedCustomerData = st.scannedCustomerData || null;
      /* Първо покажи кешираните данни без fetch (моментално) */
      const cb=document.getElementById('clientBlock');
      const cn=document.getElementById('clientName');
      const cc=document.getElementById('clientCard');
      const cv=document.getElementById('clientVoucher');
      const cst=document.getElementById('clientStats');
      if(cb) cb.style.display='flex';
      if(cc) cc.textContent=st.scannedCard;
      if(cn) cn.textContent=(st.scannedCustomerData && st.scannedCustomerData.name) || 'Клиент с карта';
      /* Stats от кеша */
      if(cst && st.scannedCustomerData && (st.scannedCustomerData.total_purchases > 0 || st.scannedCustomerData.total_spent > 0)){
        const purch = parseInt(st.scannedCustomerData.total_purchases || 0);
        const spent = parseFloat(st.scannedCustomerData.total_spent || 0).toFixed(2);
        cst.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg><span>' + purch + ' покупки · ' + spent + ' € общо</span>';
        cst.style.display = 'flex';
      }
      /* Voucher от кеша */
      if(cv && st.scannedCustomerData){
        let voucherText = '';
        if(st.scannedCustomerData.active_voucher_percent && st.scannedCustomerData.active_voucher_percent > 0){
          voucherText = 'Активен ваучер: -' + st.scannedCustomerData.active_voucher_percent + '% отстъпка';
        } else if(st.scannedCustomerData.active_voucher_amount > 0){
          const amt = parseFloat(st.scannedCustomerData.active_voucher_amount).toFixed(2);
          voucherText = 'Активен ваучер: ' + amt + ' €';
        }
        if(voucherText){
          cv.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg><span>' + voucherText + '</span>';
          cv.style.display = 'flex';
        }
      }
      document.getElementById('cardSection').classList.add('active');
      /* После — опресни тихо от сървъра (ако ваучерът е бил ползван в др. магазин) */
      onCardEntered(st.scannedCard);
    }
    if(st.givenAmount && st.givenAmount !== ''){
      givenAmount = String(st.givenAmount);
      const inp = document.getElementById('givenInput');
      if(inp) inp.value = givenAmount;
      renderGiven();
    }
    if(st.paymentMethod && st.paymentMethod !== paymentMethod){
      paymentMethod = st.paymentMethod;
      document.querySelectorAll('.pm-btn').forEach(b=>b.classList.toggle('active', b.dataset.pm===paymentMethod));
    }
    if(st.returnMode && !returnMode){
      if(typeof toggleReturnMode === 'function') toggleReturnMode();
    }
  } catch(e){}
}
function clearSessionState(){ _storageClear(); }
window.saveSessionState = saveSessionState;
window.restoreSessionState = restoreSessionState;
window.clearSessionState = clearSessionState;
window.addEventListener('beforeunload', saveSessionState);
window.addEventListener('pagehide', saveSessionState);
setTimeout(restoreSessionState, 200);


</script>
</body>
</html>