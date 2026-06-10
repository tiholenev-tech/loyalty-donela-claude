<?php
/*
 * report.php — Обобщение за деня
 * Обединява данни от scan.php (purchase_scans) и kalkulator.php (calc_sales)
 *
 * Използване: report.php?location=1
 *             report.php?location=1&date=2026-03-22
 */

require_once __DIR__ . '/config.php';

date_default_timezone_set('Europe/Sofia');

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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

function tableExists(PDO $pdo, string $table): bool {
    try { $s=$pdo->prepare("SHOW TABLES LIKE :t"); $s->execute(['t'=>$table]); return (bool)$s->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

/* ── Всички обекти за таб навигация ── */
$locations = [];
try {
    $locations = $pdo->query("SELECT id, name FROM locations ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* ── Обект ── */
$locationId   = (int)($_GET['location'] ?? 0);
$locationName = '';
if ($locationId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM locations WHERE id=:id LIMIT 1");
        $stmt->execute(['id' => $locationId]);
        $row  = $stmt->fetchColumn();
        if ($row) $locationName = (string)$row;
    } catch (Throwable $e) {}
}

/* ── Дата ── */
$dateParam = trim($_GET['date'] ?? '');
if (!$dateParam || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $dateParam = businessDate();
}
[$start, $end] = bizWindow($dateParam);

/* ══════════════════════════════════════════════════════
   ЧЕТЕМ ОТ ДВАТА ИЗТОЧНИКА И ОБЕДИНЯВАМЕ АРТИКУЛИТЕ
   ══════════════════════════════════════════════════════ */

$allItems    = [];  // обединен списък: ['code','brand','qty','price','disc','final','source','time']
$totalGross  = 0.0;
$totalFinal  = 0.0;
$totalDisc   = 0.0;
$scanCount   = 0;
$calcCount   = 0;
$discountLog = [];

// При филтър по конкретен обект — включваме и записи с NULL location_id
// само ако има само 1 обект (1 магазин не е задавал location никога)
$locCount = count($locations);

// Ако има само 1 обект — автоматично поправяме NULL записите в БД
if ($locCount === 1 && !empty($locations)) {
    $onlyLoc = $locations[0];
    try {
        $pdo->prepare("UPDATE calc_sales SET location_id=:id, location_name=:name WHERE location_id IS NULL")
            ->execute(['id'=>(int)$onlyLoc['id'], 'name'=>$onlyLoc['name']]);
        $pdo->prepare("UPDATE purchase_scans SET location_id=:id, location_name=:name WHERE location_id IS NULL")
            ->execute(['id'=>(int)$onlyLoc['id'], 'name'=>$onlyLoc['name']]);
    } catch (Throwable $e) {}
}

if ($locationId > 0) {
    $locCond = 'AND location_id = :loc';
} else {
    $locCond = ''; // всички обекти
}

/* ── 1. От scan.php (purchase_scans + calc_payload) ── */
try {
    $hasCp = false;
    try { $pdo->query("SELECT calc_payload FROM purchase_scans LIMIT 0"); $hasCp = true; } catch (Throwable $e) {}

    if ($hasCp) {
        $params = ['start'=>$start,'end'=>$end];
        if ($locationId > 0) $params['loc'] = $locationId;

        $stmt = $pdo->prepare("
            SELECT created_at, amount, discount_amount, calc_payload, location_name
            FROM purchase_scans
            WHERE created_at BETWEEN :start AND :end $locCond
            ORDER BY created_at ASC
        ");
        $stmt->execute($params);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $scanCount++;
            $rFinal = (float)$row['amount'];
            $rDisc  = (float)$row['discount_amount'];
            $rGross = $rFinal + $rDisc;
            $totalFinal += $rFinal;
            $totalDisc  += $rDisc;
            $totalGross += $rGross;

            if ($rDisc > 0) {
                $rowItems = [];
                $rawDL = $row['calc_payload'] ?? null;
                $decDL = $rawDL ? json_decode($rawDL, true) : null;
                if (is_array($decDL)) foreach ($decDL as $it) {
                    $p = round((float)($it['price'] ?? 0), 2);
                    if ($p <= 0) continue;
                    $rowItems[] = [
                        'code'=>trim((string)($it['code'] ?? '')),
                        'brand'=>trim((string)($it['brand'] ?? $it['model'] ?? '')),
                        'qty'=>max(1,(int)($it['qty'] ?? 1)), 'price'=>$p,
                        'disc'=>(int)($it['disc'] ?? $it['discount'] ?? 0),
                        'final'=>round((float)($it['final'] ?? 0), 2),
                    ];
                }
                $discountLog[] = [
                    'time'  => substr((string)$row['created_at'], 11, 5),
                    'loc'   => trim((string)($row['location_name'] ?? '')) ?: '-',
                    'gross' => $rGross, 'final' => $rFinal, 'disc' => $rDisc,
                    'pct'   => $rGross > 0 ? round($rDisc / $rGross * 100) : 0,
                    'source'=> 'scan', 'items' => $rowItems,
                ];
            }

            $raw = $row['calc_payload'] ?? null;
            if (!$raw) continue;
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) continue;

            $time = substr((string)$row['created_at'], 11, 5);
            foreach ($decoded as $item) {
                $price = round((float)($item['price'] ?? 0), 2);
                if ($price <= 0) continue;
                $allItems[] = [
                    'code'   => trim((string)($item['code']  ?? '')),
                    'brand'  => trim((string)($item['brand'] ?? $item['model'] ?? '')),
                    'qty'    => max(1, (int)($item['qty'] ?? 1)),
                    'price'  => $price,
                    'disc'   => (int)($item['disc'] ?? $item['discount'] ?? 0),
                    'final'  => round((float)($item['final'] ?? 0), 2),
                    'source' => 'scan',
                    'time'   => $time,
                ];
            }
        }
    }
} catch (Throwable $e) {}

/* ── 2. От kalkulator.php (calc_sales) ── */
if (tableExists($pdo, 'calc_sales')) {
    try {
        $params = ['start'=>$start,'end'=>$end];
        if ($locationId > 0) $params['loc'] = $locationId;

        $stmt = $pdo->prepare("
            SELECT created_at, gross_total, final_total, discount, items_json, location_name
            FROM calc_sales
            WHERE created_at BETWEEN :start AND :end $locCond
            ORDER BY created_at ASC
        ");
        $stmt->execute($params);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $calcCount++;
            $rGross = (float)$row['gross_total'];
            $rFinal = (float)$row['final_total'];
            $rDisc  = (float)$row['discount'];
            $totalFinal += $rFinal;
            $totalDisc  += $rDisc;
            $totalGross += $rGross;

            if ($rDisc > 0) {
                $rowItems = [];
                $decDL = json_decode((string)$row['items_json'], true);
                if (is_array($decDL)) foreach ($decDL as $it) {
                    $p = round((float)($it['price'] ?? 0), 2);
                    if ($p <= 0) continue;
                    $rowItems[] = [
                        'code'=>trim((string)($it['code'] ?? '')),
                        'brand'=>trim((string)($it['brand'] ?? '')),
                        'qty'=>max(1,(int)($it['qty'] ?? 1)), 'price'=>$p,
                        'disc'=>(int)($it['disc'] ?? 0),
                        'final'=>round((float)($it['final'] ?? 0), 2),
                    ];
                }
                $discountLog[] = [
                    'time'  => substr((string)$row['created_at'], 11, 5),
                    'loc'   => trim((string)($row['location_name'] ?? '')) ?: '-',
                    'gross' => $rGross, 'final' => $rFinal, 'disc' => $rDisc,
                    'pct'   => $rGross > 0 ? round($rDisc / $rGross * 100) : 0,
                    'source'=> 'calc', 'items' => $rowItems,
                ];
            }

            $decoded = json_decode((string)$row['items_json'], true);
            if (!is_array($decoded)) continue;

            $time = substr((string)$row['created_at'], 11, 5);
            foreach ($decoded as $item) {
                $price = round((float)($item['price'] ?? 0), 2);
                if ($price <= 0) continue;
                $allItems[] = [
                    'code'   => trim((string)($item['code']  ?? '')),
                    'brand'  => trim((string)($item['brand'] ?? '')),
                    'qty'    => max(1, (int)($item['qty'] ?? 1)),
                    'price'  => $price,
                    'disc'   => (int)($item['disc'] ?? 0),
                    'final'  => round((float)($item['final'] ?? 0), 2),
                    'source' => 'calc',
                    'time'   => $time,
                ];
            }
        }
    } catch (Throwable $e) {}
}

/* ── Агрегираме по артикул + цена ── */
$agg = [];
foreach ($allItems as $item) {
    $key = $item['code'] . '||' . number_format($item['price'], 2, '.', '');
    if (!isset($agg[$key])) {
        $agg[$key] = [
            'code'       => $item['code'] ?: '—',
            'brand'      => $item['brand'],
            'qty'        => 0,
            'price'      => $item['price'],
            'line_base'  => 0.0,
        ];
    }
    $agg[$key]['qty']       += $item['qty'];
    $agg[$key]['line_base'] += round($item['qty'] * $item['price'], 2);
    if ($item['brand'] && !$agg[$key]['brand']) $agg[$key]['brand'] = $item['brand'];
}
usort($agg, fn($a,$b) => strcmp((string)$a['code'], (string)$b['code']));

$totalCount = $scanCount + $calcCount;
$discPct    = $totalGross > 0 ? round(($totalDisc / $totalGross) * 100, 1) : 0;

usort($discountLog, fn($a,$b) => strcmp($a['time'], $b['time']));

/* ── 7 дена назад за табовете ── */
$tzDays = new DateTimeZone('Europe/Sofia');
$dayTabs = [];
for ($i = 0; $i < 7; $i++) {
    $dt = new DateTime('now', $tzDays);
    $dt->modify("-{$i} days");
    $dayTabs[] = [
        'date'   => $dt->format('Y-m-d'),
        'label'  => $i===0 ? 'Днес' : ($i===1 ? 'Вчера' : $dt->format('d.m')),
        'active' => $dt->format('Y-m-d') === $dateParam,
    ];
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta name="theme-color" content="#16a34a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Репорт">
<link rel="manifest" href="/loyalty/manifest_report_<?= (int)$locationId ?>.json">
<link rel="apple-touch-icon" href="/loyalty/icon_report_192.png">
<title>Обобщение<?= $locationName ? ' — '.h($locationName) : '' ?></title>
<style>
:root{
  --gold:#E8B800; --gold-dark:#c9a000;
  --red:#D32B2B; --blue:#2563eb;
  --green:#16a34a; --green-bg:#f0fdf4;
  --white:#fff; --bg:#f0f2f5;
  --text:#1a1a1a; --text2:#666; --text3:#bbb;
  --border:#e8e8e8; --border2:#d0d0d0;
  --shadow:0 2px 12px rgba(0,0,0,.07);
}
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
body{font-family:Arial,Helvetica,sans-serif;background:var(--bg);color:var(--text);padding-bottom:40px}
.wrap{max-width:560px;margin:0 auto}

/* Topbar */
.topbar{background:var(--gold);padding:11px 14px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;box-shadow:0 2px 10px rgba(232,184,0,.35)}
.topbar-left{font-size:15px;font-weight:900;color:#1a1a1a}
.topbar-right{font-size:12px;font-weight:700;color:rgba(0,0,0,.5)}

/* Box */
.sbox{background:var(--white);border:1px solid var(--border2);border-radius:18px;padding:14px;margin:10px 10px 0;box-shadow:var(--shadow)}

/* Ден табове */
.day-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.day-tab{padding:7px 14px;border-radius:999px;border:1px solid var(--border2);background:var(--white);font-size:13px;font-weight:700;color:var(--text2);text-decoration:none;display:inline-block;transition:all .12s}
.day-tab.active{background:var(--blue);border-color:var(--blue);color:#fff}
.day-tab:hover:not(.active){border-color:var(--gold);color:var(--text)}

/* Статистика */
.stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:7px;margin-bottom:16px}
.stat{background:#f8f8f8;border:1px solid var(--border);border-radius:10px;padding:10px 6px;text-align:center}
.stat .sk{font-size:9px;text-transform:uppercase;color:var(--text3);font-weight:700;letter-spacing:.4px}
.stat .sv{font-size:15px;font-weight:900;margin-top:3px}
.stat.red .sv{color:var(--red)}.stat.green .sv{color:var(--green)}.stat.gold .sv{color:var(--gold-dark)}

/* Секции */
.sec-title{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid var(--border)}

/* Обобщена таблица */
.agg-table{width:100%;border-collapse:collapse;font-size:14px}
.agg-table th{text-align:left;padding:7px 8px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text3);border-bottom:2px solid var(--border)}
.agg-table td{padding:10px 8px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.agg-table tr:last-child td{border-bottom:none}
.agg-table tr:hover td{background:#fafbff}
.code-cell{font-size:18px;font-weight:900;color:var(--text)}
.brand-cell{font-size:13px;font-weight:700;color:var(--text2)}
.qty-cell{font-size:20px;font-weight:900;color:var(--text);text-align:center}
.price-cell{font-size:13px;font-weight:700;color:var(--text2);text-align:right;white-space:nowrap}
.total-cell{font-size:15px;font-weight:900;color:var(--text);text-align:right;white-space:nowrap}

/* Тоталне */
.totals-box{background:#fffbf0;border:1px solid rgba(232,184,0,.35);border-radius:12px;padding:14px;margin-top:12px}
.tot-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f5e8c8;font-size:15px}
.tot-row:last-child{border-bottom:none}
.tot-label{font-weight:700;color:var(--text2)}
.tot-value{font-weight:900;color:var(--text)}
.tot-value.big{font-size:22px;color:var(--red)}
.tot-value.green{color:var(--green)}

/* Празно */
.empty{text-align:center;padding:30px;color:var(--text3);font-size:14px;font-weight:700}
.disc-log{width:100%;border-collapse:collapse;font-size:13px;margin-top:4px}
.disc-log th{text-align:left;padding:7px 6px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text3);border-bottom:2px solid var(--border)}
.disc-log td{padding:9px 6px;border-bottom:1px solid #f0f0f0;vertical-align:middle;white-space:nowrap}
.disc-log tr:last-child td{border-bottom:none}
.disc-log tr:hover td{background:#fffafa}
.dl-time{font-size:15px;font-weight:900;color:var(--text)}
.dl-src{font-size:11px;margin-left:5px}
.dl-loc{font-size:11px;font-weight:800;color:#92600a;background:#fff8e6;border:1px solid rgba(232,184,0,.4);border-radius:6px;padding:2px 7px;display:inline-block}
.dl-base{color:var(--text2);font-weight:700;text-align:right}
.dl-disc{color:var(--red);font-weight:900;text-align:right}
.dl-final{color:var(--green);font-weight:900;text-align:right}
.dl-clickable{cursor:pointer}
.dl-clickable:active td{background:#fff4f4}
.dl-arrow{display:inline-block;color:var(--text3);font-size:11px;margin-right:3px}
.dl-detail td{padding:0 6px 10px;background:#fafafa}
.dl-items{background:#fff;border:1px solid var(--border);border-radius:10px;padding:2px 10px;margin-top:2px}
.dl-item{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #f4f4f4;font-size:13px;white-space:normal}
.dl-item:last-child{border-bottom:none}
.dl-item-name{font-weight:700;color:var(--text)}
.dl-item-qty{font-weight:900;color:var(--text2);font-size:12px}
.dl-item-right{display:flex;align-items:center;gap:7px;white-space:nowrap}
.dl-item-was{color:var(--text3);text-decoration:line-through;font-size:12px;font-weight:700}
.dl-item-pct{color:var(--red);font-weight:900;font-size:12px}
.dl-item-final{color:var(--text);font-weight:900}

/* Легенда */
.legend{display:flex;gap:12px;margin-bottom:12px;font-size:12px;font-weight:700}
.leg-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:4px;vertical-align:middle}

/* Принт */
@media print {
  .topbar,.day-tabs,.no-print{display:none}
  body{background:#fff;padding:0}
  .sbox{border:none;box-shadow:none;margin:0;padding:0}
  .wrap{max-width:100%}
}
</style>
</head>
<body>
<div class="wrap">

<!-- Topbar -->
<div class="topbar no-print">
  <div class="topbar-left">
    📊 <?= $locationName ? h($locationName) : ($locationId === 0 ? 'Всички обекти' : 'Обобщение') ?>
  </div>
  <div class="topbar-right" id="clock"></div>
</div>

<?php if ($locationId === 0 && !empty($locations)): ?>
<!-- Ако никой обект не е избран — показваме бързи линкове -->
<div class="sbox no-print" style="background:#fff8e6;border-color:rgba(232,184,0,.4)">
  <div style="font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.5px;color:#92600a;margin-bottom:10px">
    📍 Избери обект или запази директния линк:
  </div>
  <div style="display:flex;flex-direction:column;gap:8px">
    <?php foreach ($locations as $loc): ?>
      <a href="?location=<?= (int)$loc['id'] ?>"
         style="display:block;padding:12px 14px;background:var(--white);border:1px solid var(--border2);border-radius:10px;font-size:15px;font-weight:800;color:var(--text);text-decoration:none">
        📍 <?= h($loc['name']) ?>
        <span style="font-size:12px;font-weight:600;color:var(--text3);display:block;margin-top:2px">
          report.php?location=<?= (int)$loc['id'] ?>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Обобщение -->
<div class="sbox">

  <!-- Обект табове -->
  <?php if (!empty($locations)): ?>
  <div class="day-tabs" style="margin-bottom:10px">
    <a href="?location=0&date=<?= h($dateParam) ?>"
       class="day-tab<?= $locationId===0 ? ' active' : '' ?>"
       style="<?= $locationId===0 ? 'background:#1C1C1C;border-color:#1C1C1C;' : '' ?>">
      Всички
    </a>
    <?php foreach ($locations as $loc): ?>
      <a href="?location=<?= (int)$loc['id'] ?>&date=<?= h($dateParam) ?>"
         class="day-tab<?= $locationId===(int)$loc['id'] ? ' active' : '' ?>">
        <?= h($loc['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
  <hr style="border:none;border-top:1px solid var(--border);margin:0 0 12px">
  <?php endif; ?>

  <!-- Ден табове -->
  <div class="day-tabs">
    <?php foreach ($dayTabs as $tab): ?>
      <a href="?location=<?= (int)$locationId ?>&date=<?= h($tab['date']) ?>"
         class="day-tab<?= $tab['active'] ? ' active' : '' ?>">
        <?= h($tab['label']) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Статистика -->
  <div class="stats-grid">
    <div class="stat green">
      <div class="sk">Продажби</div>
      <div class="sv"><?= $totalCount ?></div>
    </div>
    <div class="stat">
      <div class="sk">Лоялна</div>
      <div class="sv"><?= $scanCount ?></div>
    </div>
    <div class="stat">
      <div class="sk">Калк.</div>
      <div class="sv"><?= $calcCount ?></div>
    </div>
    <div class="stat gold">
      <div class="sk">Взето</div>
      <div class="sv"><?= number_format($totalFinal, 2, '.', '') ?> €</div>
    </div>
    <div class="stat red">
      <div class="sk">Отст.</div>
      <div class="sv"><?= $discPct ?>%</div>
    </div>
  </div>

  <?php if (empty($agg)): ?>
    <div class="empty">Няма продажби за <?= h($dateParam) ?></div>
  <?php else: ?>

    <!-- Обобщена таблица -->
    <div class="sec-title">📦 Артикули за деня — за преписване</div>

    <table class="agg-table">
      <thead>
        <tr>
          <th>Артикул</th>
          <th>Производител</th>
          <th style="text-align:center">Бр.</th>
          <th style="text-align:right">Цена</th>
          <th style="text-align:right">Общо</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($agg as $row): ?>
          <tr>
            <td class="code-cell"><?= h($row['code']) ?></td>
            <td class="brand-cell"><?= h($row['brand'] ?: '—') ?></td>
            <td class="qty-cell"><?= (int)$row['qty'] ?></td>
            <td class="price-cell"><?= number_format($row['price'], 2, '.', '') ?> €</td>
            <td class="total-cell"><?= number_format($row['line_base'], 2, '.', '') ?> €</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Тоталне -->
    <div class="totals-box">
      <div class="tot-row">
        <span class="tot-label">Общо без отстъпка</span>
        <span class="tot-value"><?= number_format($totalGross, 2, '.', '') ?> €</span>
      </div>
      <div class="tot-row">
        <span class="tot-label">Реално взето</span>
        <span class="tot-value green"><?= number_format($totalFinal, 2, '.', '') ?> €</span>
      </div>
      <div class="tot-row">
        <span class="tot-label" style="font-weight:900">Обща отстъпка за деня</span>
        <span class="tot-value big">−<?= number_format($totalDisc, 2, '.', '') ?> € (<?= $discPct ?>%)</span>
      </div>
    </div>

    <?php if (!empty($discountLog)): ?>
    <div class="sec-title" style="margin-top:18px">🏷️ Отстъпки по час<?= $locationId === 0 ? ' и магазин' : '' ?></div>
    <table class="disc-log">
      <thead>
        <tr>
          <th>Час</th>
          <?php if ($locationId === 0): ?><th>Магазин</th><?php endif; ?>
          <th style="text-align:right">Без отст.</th>
          <th style="text-align:right">Отстъпка</th>
          <th style="text-align:right">Взето</th>
        </tr>
      </thead>
      <tbody>
        <?php $dlCols = $locationId === 0 ? 5 : 4; ?>
        <?php foreach ($discountLog as $i => $d): ?>
          <?php $hasIt = !empty($d['items']); ?>
          <tr class="dl-main<?= $hasIt ? ' dl-clickable' : '' ?>"<?= $hasIt ? ' onclick="dlToggle('.$i.')"' : '' ?>>
            <td class="dl-time"><?php if ($hasIt): ?><span class="dl-arrow" id="dlarr<?= $i ?>">▸</span><?php endif; ?><?= h($d['time']) ?><span class="dl-src"><?= $d['source']==='scan' ? '💳' : '🧮' ?></span></td>
            <?php if ($locationId === 0): ?><td><span class="dl-loc"><?= h($d['loc']) ?></span></td><?php endif; ?>
            <td class="dl-base"><?= number_format($d['gross'], 2, '.', '') ?> €</td>
            <td class="dl-disc">−<?= number_format($d['disc'], 2, '.', '') ?> € (<?= (int)$d['pct'] ?>%)</td>
            <td class="dl-final"><?= number_format($d['final'], 2, '.', '') ?> €</td>
          </tr>
          <?php if ($hasIt): ?>
          <tr class="dl-detail" id="dldet<?= $i ?>" style="display:none">
            <td colspan="<?= $dlCols ?>">
              <div class="dl-items">
                <?php foreach ($d['items'] as $it): ?>
                  <?php $nm = trim(($it['code'] ?: '—').($it['brand'] ? ' · '.$it['brand'] : '')); $bs = round($it['qty'] * $it['price'], 2); $id = (int)$it['disc']; ?>
                  <div class="dl-item">
                    <span class="dl-item-name"><?= h($nm) ?> <span class="dl-item-qty">× <?= (int)$it['qty'] ?></span></span>
                    <span class="dl-item-right">
                      <?php if ($id > 0): ?><span class="dl-item-was"><?= number_format($bs, 2, '.', '') ?></span><span class="dl-item-pct">−<?= $id ?>%</span><?php endif; ?>
                      <span class="dl-item-final"><?= number_format($it['final'], 2, '.', '') ?> €</span>
                    </span>
                  </div>
                <?php endforeach; ?>
              </div>
            </td>
          </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

  <?php endif; ?>
</div>

<!-- Бутони -->
<div class="sbox no-print" style="display:flex;gap:10px">
  <button onclick="window.print()" style="flex:1;height:48px;border-radius:12px;border:1.5px solid var(--border2);background:var(--white);font-size:14px;font-weight:800;color:var(--text2);cursor:pointer">
    🖨️ Принтирай
  </button>
  <button onclick="location.reload()" style="flex:1;height:48px;border-radius:12px;border:1.5px solid var(--border2);background:var(--white);font-size:14px;font-weight:800;color:var(--text2);cursor:pointer">
    ↺ Обнови
  </button>
</div>

</div><!-- /wrap -->

<script>
function dlToggle(i){
  const det=document.getElementById('dldet'+i), arr=document.getElementById('dlarr'+i);
  if(!det) return;
  const open=det.style.display!=='none';
  det.style.display=open?'none':'';
  if(arr) arr.textContent=open?'▸':'▾';
}

// Часовник
function tick(){
  const el=document.getElementById('clock');
  if(el) el.textContent=new Date().toLocaleTimeString('bg-BG',{hour:'2-digit',minute:'2-digit'});
}
tick(); setInterval(tick,10000);

// Авто-обновяване на всеки 60 секунди
setInterval(()=>location.reload(), 60000);
</script>
</body>
</html>