<?php
require "config.php";
$incomingRef = trim($_GET["ref"] ?? "");
$source      = trim($_GET["source"] ?? "");

/* ── Self-heal: увери се, че колоните за магазина на регистрация съществуват ──
   (ако миграцията не е пускана на живо, картите се записваха „без магазин") */
try {
    try { $pdo->query("SELECT reg_location_id FROM customers LIMIT 0"); }
    catch (Throwable $e) { $pdo->exec("ALTER TABLE customers ADD COLUMN reg_location_id INT NULL DEFAULT NULL"); }
    try { $pdo->query("SELECT reg_location_name FROM customers LIMIT 0"); }
    catch (Throwable $e) { $pdo->exec("ALTER TABLE customers ADD COLUMN reg_location_name VARCHAR(100) NULL DEFAULT NULL"); }
} catch (Throwable $e) { /* липсва ALTER право — пропускаме тихо */ }

/* Обекти за селектора „в кой магазин е направена картата" */
$regLocations = [];
try { $regLocations = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $first_name  = trim($_POST["first_name"]  ?? "");
    $last_name   = trim($_POST["last_name"]   ?? "");
    $phone       = trim($_POST["phone"]       ?? "");
    $birth_day   = trim($_POST["birth_day"]   ?? "");
    $birth_month = trim($_POST["birth_month"] ?? "");
    $birth_year  = trim($_POST["birth_year"]  ?? "");
    $reg_location_id = (int)($_POST["reg_location"] ?? 0);

    if (
        !preg_match('/^\d{1,2}$/', $birth_day) ||
        !preg_match('/^\d{1,2}$/', $birth_month) ||
        !preg_match('/^\d{4}$/', $birth_year)
    ) {
        $errorDate = true;
    } else {
        $errorDate = false;
    }

    $errorLoc = $reg_location_id <= 0;

    if (!$errorDate && !$errorLoc) {
        $birth_date = $birth_year . "-" . str_pad($birth_month,2,'0',STR_PAD_LEFT) . "-" . str_pad($birth_day,2,'0',STR_PAD_LEFT);
        $ref_code   = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));

        try {
            $hasRefCode = false;
            try {
                $pdo->query("SELECT ref_code FROM customers LIMIT 0");
                $hasRefCode = true;
            } catch (Throwable $e) {}

            $referred_by = null;
            if ($hasRefCode && $incomingRef !== "") {
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE ref_code = ? LIMIT 1");
                $stmt->execute([$incomingRef]);
                $referrer = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($referrer && !empty($referrer["id"])) {
                    $referred_by = (int)$referrer["id"];
                }
            }

            /* Име на обекта, в който се прави картата */
            $reg_location_name = null;
            if ($reg_location_id > 0) {
                $ls = $pdo->prepare("SELECT name FROM locations WHERE id = ? LIMIT 1");
                $ls->execute([$reg_location_id]);
                $reg_location_name = (string)($ls->fetchColumn() ?: '');
            }
            $hasRegLoc = false;
            try { $pdo->query("SELECT reg_location_id FROM customers LIMIT 0"); $hasRegLoc = true; } catch (Throwable $e) {}

            $cols = ['first_name','last_name','phone','birth_date'];
            $vals = [$first_name, $last_name, $phone, $birth_date];
            if ($hasRefCode) { $cols[]='ref_code'; $vals[]=$ref_code; $cols[]='referred_by'; $vals[]=$referred_by; }
            if ($hasRegLoc)  { $cols[]='reg_location_id'; $vals[]=($reg_location_id ?: null); $cols[]='reg_location_name'; $vals[]=$reg_location_name; }
            $ph  = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO customers (".implode(',', $cols).") VALUES ($ph)")->execute($vals);

            $customer_id = (int)$pdo->lastInsertId();

            do {
                $card_number = "ET" . rand(100000, 999999);
                $check = $pdo->prepare("SELECT id FROM loyalty_cards WHERE card_number = ? LIMIT 1");
                $check->execute([$card_number]);
            } while ($check->fetchColumn());

            $stmt = $pdo->prepare("INSERT INTO loyalty_cards (customer_id, card_number) VALUES (?, ?)");
            $stmt->execute([$customer_id, $card_number]);

            try {
                $voucherCode = 'WELCOME5-' . $customer_id;
                $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
                $pdo->prepare("INSERT INTO vouchers (customer_id, code, voucher_type, percent_value, used, source, status, expires_at, issued_at, created_at) VALUES (?, ?, 'percent', 5, 0, 'welcome', 'active', ?, NOW(), NOW())")
                    ->execute([$customer_id, $voucherCode, $expiresAt]);
            } catch (Throwable $e) {
                error_log('[WELCOME VOUCHER FAIL] customer_id=' . $customer_id . ' err=' . $e->getMessage());
            }

            $successCardNumber = $card_number;

        } catch (Throwable $e) {
            $errorGeneric = true;
        }
    }
}

function h($str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover">
<title>Регистрация — Ени Тихолов</title>
<meta name="theme-color" content="#f5f7fb">
<link rel="apple-touch-icon" href="/loyalty/icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --hue1:255; --hue2:222;
  --border:1px; --border-color:rgba(0,0,0,.06);
  --radius:22px; --radius-sm:14px;
  --bg-main:#f5f7fb;
  --text-primary:#0f172a;
  --text-secondary:#475569;
  --text-muted:#94a3b8;
}
*{ box-sizing:border-box; margin:0; padding:0; -webkit-tap-highlight-color:transparent; }
html,body{
  background:var(--bg-main);
  color:var(--text-primary);
  font-family:'Montserrat',Inter,system-ui,sans-serif;
  min-height:100vh;
  overflow-x:hidden;
}
body{
  background:
    radial-gradient(ellipse 900px 600px at 15% 5%, hsl(var(--hue1) 85% 88% / .55) 0%, transparent 55%),
    radial-gradient(ellipse 800px 600px at 90% 90%, hsl(var(--hue2) 85% 88% / .45) 0%, transparent 55%),
    radial-gradient(ellipse 600px 400px at 50% 50%, hsl(38 85% 92% / .35) 0%, transparent 50%),
    linear-gradient(180deg,#fafbff 0%,#eef2f9 100%);
  background-attachment:fixed;
  padding:20px 14px 40px;
  position:relative;
}
body::before{
  content:''; position:fixed; inset:0;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.5'/%3E%3C/svg%3E");
  opacity:.025; pointer-events:none; z-index:1; mix-blend-mode:multiply;
}

.wrap{ max-width:500px; margin:0 auto; position:relative; z-index:2; }

/* Logo */
.top-logo-wrap{ text-align:center; padding:6px 0 18px; }
.top-logo{ height:56px; width:auto; filter:drop-shadow(0 0 16px hsl(38 70% 55% / .35)); }

/* Glass */
.glass{
  position:relative;
  border-radius:var(--radius);
  border:var(--border) solid var(--border-color);
  background:
    linear-gradient(235deg,hsl(var(--hue1) 85% 85% / .55),hsl(var(--hue1) 85% 85% / 0) 40%),
    linear-gradient(45deg,hsl(var(--hue2) 85% 85% / .55),hsl(var(--hue2) 85% 85% / 0) 40%),
    linear-gradient(hsl(0 0% 100% / .78));
  backdrop-filter:blur(16px) saturate(1.3);
  -webkit-backdrop-filter:blur(16px) saturate(1.3);
  box-shadow:
    0 1px 0 rgba(255,255,255,.95) inset,
    0 4px 12px rgba(15,23,42,.04),
    0 16px 40px -12px rgba(15,23,42,.08);
  isolation:isolate;
}
.glass.sm{ --radius:var(--radius-sm); }
.glass .shine,.glass .glow{ --hue:var(--hue1); }
.glass .shine-bottom,.glass .glow-bottom{ --hue:var(--hue2); --conic:135deg; }
.glass .shine,.glass .shine::before,.glass .shine::after{
  pointer-events:none; border-radius:0;
  border-top-right-radius:inherit; border-bottom-left-radius:inherit;
  border:1px solid transparent;
  width:75%; aspect-ratio:1; display:block; position:absolute;
  right:calc(var(--border) * -1); top:calc(var(--border) * -1);
  left:auto; z-index:1; --start:12%;
  background:conic-gradient(from var(--conic,-45deg) at center in oklch,transparent var(--start,0%),hsl(var(--hue),var(--sat,85%),var(--lit,55%)),transparent var(--end,50%)) border-box;
  mask:linear-gradient(transparent),linear-gradient(black);
  mask-repeat:no-repeat; mask-clip:padding-box,border-box; mask-composite:subtract;
  opacity:.8;
}
.glass .shine::before,.glass .shine::after{ content:""; width:auto; inset:-2px; mask:none; }
.glass .shine::after{ z-index:2; --start:17%; --end:33%; background:conic-gradient(from var(--conic,-45deg) at center in oklch,transparent var(--start,0%),hsl(var(--hue),var(--sat,90%),var(--lit,70%)),transparent var(--end,50%)); }
.glass .shine-bottom{ top:auto; bottom:calc(var(--border) * -1); left:calc(var(--border) * -1); right:auto; }
.glass .glow{
  pointer-events:none;
  border-top-right-radius:calc(var(--radius) * 2.5);
  border-bottom-left-radius:calc(var(--radius) * 2.5);
  border:calc(var(--radius) * 1.25) solid transparent;
  inset:calc(var(--radius) * -2);
  width:75%; aspect-ratio:1; display:block; position:absolute; left:auto; bottom:auto;
  mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='240' height='240'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='3' seed='5'/%3E%3CfeColorMatrix values='0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 1 0'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
  mask-mode:luminance; mask-size:29%; opacity:.7; filter:blur(14px) saturate(1.5) brightness(1);
  mix-blend-mode:multiply; z-index:3;
}
.glass .glow.glow-bottom{ inset:calc(var(--radius) * -2); top:auto; right:auto; }
.glass .glow::before,.glass .glow::after{
  content:""; position:absolute; inset:0;
  border:inherit; border-radius:inherit;
  background:conic-gradient(from var(--conic,-45deg) at center in oklch,transparent var(--start,0%),hsl(var(--hue),var(--sat,95%),var(--lit,55%)),transparent var(--end,50%)) border-box;
  mask:linear-gradient(transparent),linear-gradient(black);
  mask-repeat:no-repeat; mask-clip:padding-box,border-box; mask-composite:subtract;
  filter:saturate(2.5) brightness(1.1);
}
.glass .glow::after{ --lit:60%; --sat:100%; --start:15%; --end:35%; border-width:calc(var(--radius) * 1.75); border-radius:calc(var(--radius) * 2.75); inset:calc(var(--radius) * -.25); z-index:4; opacity:.55; }

.q1{ --hue1:0;   --hue2:340 }
.q3{ --hue1:145; --hue2:165 }
.q5{ --hue1:38;  --hue2:28  }

/* Ref note */
.ref-note{
  padding:14px 16px; margin-bottom:14px;
  display:flex; align-items:center; gap:12px;
  font-size:13px; font-weight:700; color:var(--text-primary);
}
.ref-note-icon{
  width:40px; height:40px; border-radius:12px; flex-shrink:0;
  background:linear-gradient(135deg,hsl(38 80% 55%),hsl(28 80% 50%));
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 0 18px hsl(38 70% 50% / .45);
  position:relative; z-index:5;
}
.ref-note-icon svg{ width:20px; height:20px; stroke:#fff; stroke-width:2; fill:none; stroke-linecap:round; stroke-linejoin:round; }
.ref-note-text{ flex:1; position:relative; z-index:5; }
.ref-note-text strong{ color:hsl(38 65% 42%); font-weight:900; }

/* Form card */
.form-card{ padding:24px 22px 22px; }
.form-title{
  font-size:22px; font-weight:900; letter-spacing:-.02em; color:var(--text-primary);
  text-align:center; margin-bottom:6px; position:relative; z-index:5;
}
.form-sub{
  font-size:12px; font-weight:600; color:var(--text-secondary);
  text-align:center; margin-bottom:20px; position:relative; z-index:5;
}
.welcome-chip{
  display:inline-flex; align-items:center; gap:6px; padding:6px 12px; margin-bottom:14px;
  background:linear-gradient(135deg,hsl(38 80% 55%),hsl(28 80% 50%));
  border-radius:100px; font-size:11px; font-weight:900; letter-spacing:.08em; text-transform:uppercase;
  color:#fff; box-shadow:0 0 16px hsl(38 70% 50% / .4);
  position:relative; z-index:5;
}
.welcome-chip svg{ width:12px; height:12px; stroke:#fff; stroke-width:2.5; fill:none; stroke-linecap:round; }
.welcome-wrap{ text-align:center; position:relative; z-index:5; }

.field{ margin-bottom:14px; position:relative; z-index:5; }
.field-label{
  display:block; font-size:10px; font-weight:900; letter-spacing:.12em;
  text-transform:uppercase; color:hsl(var(--hue1) 60% 48%); margin-bottom:6px;
}
.field input[type=text],
.field input[type=tel]{
  width:100%; padding:14px 16px; font-size:15px; font-weight:600; font-family:inherit;
  border:1px solid rgba(15,23,42,.08);
  border-radius:14px; background:rgba(255,255,255,.7);
  color:var(--text-primary);
  transition:border-color .2s, box-shadow .2s;
}
.field input:focus{
  outline:none;
  border-color:hsl(var(--hue1) 60% 60%);
  background:#fff;
  box-shadow:0 0 0 3px hsl(var(--hue1) 70% 85% / .5);
}
.field input::placeholder{ color:var(--text-muted); font-weight:500; }

.field select{
  width:100%; padding:14px 16px; font-size:15px; font-weight:600; font-family:inherit;
  border:1px solid rgba(15,23,42,.08);
  border-radius:14px; background:rgba(255,255,255,.7);
  color:var(--text-primary); appearance:none; -webkit-appearance:none; cursor:pointer;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='7' viewBox='0 0 12 7'%3E%3Cpath fill='%2394a3b8' d='M6 7L0 0h12z'/%3E%3C/svg%3E");
  background-repeat:no-repeat; background-position:right 16px center;
  transition:border-color .2s, box-shadow .2s;
}
.field select:focus{
  outline:none; border-color:hsl(var(--hue1) 60% 60%); background-color:#fff;
  box-shadow:0 0 0 3px hsl(var(--hue1) 70% 85% / .5);
}

.birth-row{ display:flex; gap:8px; align-items:center; }
.birth-row input{ text-align:center; font-variant-numeric:tabular-nums; font-weight:700; }
.birth-day,.birth-month{ flex:0 0 70px; }
.birth-year{ flex:1; }
.birth-sep{ font-size:20px; font-weight:900; color:var(--text-muted); user-select:none; }

.submit-btn{
  width:100%; padding:16px; font-size:15px; font-weight:900; letter-spacing:.02em;
  color:#fff; font-family:inherit; cursor:pointer;
  background:linear-gradient(135deg,hsl(var(--hue1) 75% 58%),hsl(var(--hue2) 75% 50%));
  border:1px solid hsl(var(--hue1) 65% 55%); border-radius:14px;
  box-shadow:0 8px 24px hsl(var(--hue1) 65% 45% / .35),0 0 24px hsl(var(--hue1) 75% 55% / .3),inset 0 1px 0 rgba(255,255,255,.3);
  display:flex; align-items:center; justify-content:center; gap:8px;
  transition:transform .15s, box-shadow .2s;
  position:relative; z-index:5;
  margin-top:6px;
}
.submit-btn:hover{ box-shadow:0 10px 30px hsl(var(--hue1) 65% 45% / .45),0 0 32px hsl(var(--hue1) 75% 55% / .4),inset 0 1px 0 rgba(255,255,255,.3); }
.submit-btn:active{ transform:scale(.98); }
.submit-btn:disabled{ opacity:.6; cursor:default; transform:none; }
.submit-btn svg{ width:16px; height:16px; stroke:currentColor; stroke-width:2.5; fill:none; stroke-linecap:round; stroke-linejoin:round; }

#status{ text-align:center; margin-top:10px; font-size:13px; color:hsl(var(--hue1) 65% 45%); font-weight:800; min-height:18px; }

/* Success/Error cards */
.result-card{ padding:28px 22px; text-align:center; }
.result-icon-wrap{
  width:64px; height:64px; margin:0 auto 16px; border-radius:18px;
  display:flex; align-items:center; justify-content:center;
  position:relative; z-index:5;
}
.result-icon-wrap.success{
  background:linear-gradient(135deg,hsl(145 65% 50%),hsl(165 65% 45%));
  box-shadow:0 0 32px hsl(145 70% 50% / .5);
}
.result-icon-wrap.error{
  background:linear-gradient(135deg,hsl(0 70% 55%),hsl(340 70% 50%));
  box-shadow:0 0 32px hsl(0 70% 50% / .5);
}
.result-icon-wrap svg{ width:34px; height:34px; stroke:#fff; stroke-width:2.5; fill:none; stroke-linecap:round; stroke-linejoin:round; filter:drop-shadow(0 0 8px rgba(255,255,255,.5)); }
.result-title{
  font-size:22px; font-weight:900; letter-spacing:-.02em; color:var(--text-primary);
  margin-bottom:8px; position:relative; z-index:5;
}
.result-sub{
  font-size:13px; font-weight:600; color:var(--text-secondary);
  margin-bottom:18px; position:relative; z-index:5; line-height:1.5;
}
.card-num-display{
  display:inline-block; padding:14px 24px; margin-bottom:18px;
  background:#fff; border:1px solid hsl(var(--hue1) 60% 85%);
  border-radius:14px;
  font-size:26px; font-weight:900; letter-spacing:.14em; color:var(--text-primary);
  font-variant-numeric:tabular-nums;
  box-shadow:0 0 24px hsl(var(--hue1) 70% 55% / .3),0 8px 20px rgba(15,23,42,.08);
  position:relative; z-index:5;
}
.result-btn{
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  padding:14px 22px; text-decoration:none; font-size:14px; font-weight:900; letter-spacing:.02em;
  color:#fff; font-family:inherit;
  background:linear-gradient(135deg,hsl(var(--hue1) 75% 58%),hsl(var(--hue2) 75% 50%));
  border:1px solid hsl(var(--hue1) 65% 55%); border-radius:14px;
  box-shadow:0 8px 24px hsl(var(--hue1) 65% 45% / .35),0 0 24px hsl(var(--hue1) 75% 55% / .3),inset 0 1px 0 rgba(255,255,255,.3);
  position:relative; z-index:5;
  transition:transform .15s;
}
.result-btn:active{ transform:scale(.97); }
.result-btn svg{ width:16px; height:16px; stroke:currentColor; stroke-width:2.5; fill:none; stroke-linecap:round; }

@media (max-width:400px){
  .birth-day,.birth-month{ flex:0 0 60px; }
  .card-num-display{ font-size:22px; padding:12px 18px; }
}
</style>
</head>
<body>

<div class="wrap">

  <div class="top-logo-wrap">
    <img src="/loyalty/icon-512.png" alt="Ени Тихолов" class="top-logo" onerror="this.src='/loyalty/icon-192.png';">
  </div>

  <?php if (!empty($successCardNumber)): ?>
    <!-- ══ SUCCESS ══ -->
    <div class="glass q3 result-card">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <div class="result-icon-wrap success">
        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <div class="result-title">Регистрацията е успешна</div>
      <div class="result-sub">Твоята лоялна карта е готова. Добави я на началния екран за бърз достъп.</div>
      <div class="welcome-wrap" style="margin:6px 0 14px">
        <span class="welcome-chip success-welcome-chip">
          <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          Welcome -5% активиран
        </span>
        <div style="font-size:11px;color:var(--text-muted);font-weight:700;margin-top:8px;letter-spacing:.04em">
          Валиден до <?= date('d.m.Y', strtotime('+30 days')) ?>
        </div>
      </div>
      <div class="card-num-display"><?= h($successCardNumber) ?></div>
      <div>
        <a class="result-btn" href="card.php?card=<?= urlencode($successCardNumber) ?>">
          <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM21 14h-3M14 21h3M21 17v4"/></svg>
          <span>Отвори картата</span>
        </a>
      </div>
    </div>

  <?php elseif (!empty($errorDate)): ?>
    <!-- ══ ERROR: невалидна дата ══ -->
    <div class="glass q1 result-card">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <div class="result-icon-wrap error">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <div class="result-title">Невалидна дата</div>
      <div class="result-sub">Използвай формат <strong>ДД / ММ / ГГГГ</strong> — например 25 / 12 / 1985</div>
      <div>
        <a class="result-btn" href="register.php<?= $source ? '?source='.urlencode($source) : '' ?><?= ($source && $incomingRef) ? '&ref='.urlencode($incomingRef) : ($incomingRef ? '?ref='.urlencode($incomingRef) : '') ?>">
          <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
          <span>Опитай отново</span>
        </a>
      </div>
    </div>

  <?php elseif (!empty($errorLoc)): ?>
    <!-- ══ ERROR: липсва магазин ══ -->
    <div class="glass q1 result-card">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <div class="result-icon-wrap error">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <div class="result-title">Избери магазин</div>
      <div class="result-sub">Трябва да посочиш <strong>в кой магазин</strong> се прави картата.</div>
      <div>
        <a class="result-btn" href="register.php<?= $source ? '?source='.urlencode($source) : '' ?><?= ($source && $incomingRef) ? '&ref='.urlencode($incomingRef) : ($incomingRef ? '?ref='.urlencode($incomingRef) : '') ?>">
          <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
          <span>Опитай отново</span>
        </a>
      </div>
    </div>

  <?php elseif (!empty($errorGeneric)): ?>
    <!-- ══ ERROR: generic ══ -->
    <div class="glass q1 result-card">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <div class="result-icon-wrap error">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <div class="result-title">Грешка при регистрация</div>
      <div class="result-sub">Нещо се обърка. Моля, опитай отново след малко.</div>
      <div>
        <a class="result-btn" href="register.php<?= $source ? '?source='.urlencode($source) : '' ?><?= ($source && $incomingRef) ? '&ref='.urlencode($incomingRef) : ($incomingRef ? '?ref='.urlencode($incomingRef) : '') ?>">
          <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
          <span>Опитай отново</span>
        </a>
      </div>
    </div>

  <?php else: ?>
    <!-- ══ FORM ══ -->
    <?php if ($incomingRef !== ""): ?>
    <div class="glass sm q5 ref-note">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>
      <div class="ref-note-icon">
        <svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
      </div>
      <div class="ref-note-text">Поканен си от приятел — получаваш <strong>Welcome -5%</strong> бонус при регистрация</div>
    </div>
    <?php endif; ?>

    <div class="glass form-card">
      <span class="shine"></span><span class="shine shine-bottom"></span>
      <span class="glow"></span><span class="glow glow-bottom"></span>

      <div class="welcome-wrap">
        <span class="welcome-chip">
          <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          Welcome -5%
        </span>
      </div>
      <div class="form-title">Регистрация на лоялна карта</div>
      <div class="form-sub">Попълни данните си — получаваш моментален <strong>-5% Welcome ваучер</strong> и награди за всяка покупка</div>

      <form method="post" id="regForm">
        <div class="field">
          <label class="field-label" for="fname">Име</label>
          <input id="fname" type="text" name="first_name" required autocomplete="given-name" placeholder="Иван">
        </div>

        <div class="field">
          <label class="field-label" for="lname">Фамилия</label>
          <input id="lname" type="text" name="last_name" required autocomplete="family-name" placeholder="Иванов">
        </div>

        <div class="field">
          <label class="field-label" for="phone">Телефон</label>
          <input id="phone" type="tel" name="phone" required autocomplete="tel" placeholder="0888 123 456">
        </div>

        <div class="field">
          <label class="field-label">Рожден ден</label>
          <div class="birth-row">
            <input class="birth-day" type="text" name="birth_day" placeholder="ДД" maxlength="2" inputmode="numeric" required>
            <span class="birth-sep">/</span>
            <input class="birth-month" type="text" name="birth_month" placeholder="ММ" maxlength="2" inputmode="numeric" required>
            <span class="birth-sep">/</span>
            <input class="birth-year" type="text" name="birth_year" placeholder="ГГГГ" maxlength="4" inputmode="numeric" required>
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="regLoc">Магазин — къде се прави картата</label>
          <select id="regLoc" name="reg_location" required>
            <option value="" disabled <?= empty($_POST['reg_location']) ? 'selected' : '' ?>>— избери магазин —</option>
            <?php foreach ($regLocations as $loc): ?>
              <option value="<?= (int)$loc['id'] ?>" <?= ((int)($_POST['reg_location'] ?? 0) === (int)$loc['id']) ? 'selected' : '' ?>><?= h($loc['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <button type="submit" class="submit-btn" id="submitBtn">
          <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          <span>Създай карта</span>
        </button>
        <div id="status"></div>
      </form>
    </div>

    <script>
    document.getElementById('regForm').addEventListener('submit', function(){
      const btn = document.getElementById('submitBtn');
      btn.disabled = true;
      const span = btn.querySelector('span');
      if (span) span.textContent = 'Регистриране...';
      document.getElementById('status').textContent = 'Моля изчакай...';
    });
    document.querySelector('.birth-day').addEventListener('input', function(){
      if(this.value.length === 2) document.querySelector('.birth-month').focus();
    });
    document.querySelector('.birth-month').addEventListener('input', function(){
      if(this.value.length === 2) document.querySelector('.birth-year').focus();
    });
    </script>
  <?php endif; ?>

</div>

</body>
</html>
