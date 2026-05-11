<?php
require_once __DIR__ . '/config.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$found    = null;
$error    = '';
$searched = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $searched = true;

    if ($phone === '') {
        $error = 'Въведи телефонен номер.';
    } else {
        try {
            // Търсим по точен номер или без водещата 0/+359
            $phoneClean = preg_replace('/\D/', '', $phone);
            $stmt = $pdo->prepare("
                SELECT c.id, c.first_name, c.last_name, c.phone, lc.card_number
                FROM customers c
                INNER JOIN loyalty_cards lc ON lc.customer_id = c.id
                WHERE REGEXP_REPLACE(c.phone, '[^0-9]', '') LIKE :phone
                   OR REGEXP_REPLACE(c.phone, '[^0-9]', '') LIKE :phone2
                LIMIT 1
            ");
            $stmt->execute([
                'phone'  => '%' . $phoneClean,
                'phone2' => $phoneClean . '%',
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $found = $row;
            } else {
                $error = 'Не намерихме карта с този телефон. Провери номера или се регистрирай отново.';
            }
        } catch (Throwable $e) {
            $error = 'Грешка при търсене. Опитай отново.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Намери картата си — Ени Тихолов</title>
<style>
*{ box-sizing:border-box; margin:0; padding:0; }
body{
  font-family: Arial, Helvetica, sans-serif;
  background: #f7f3ef;
  min-height: 100vh;
  padding: 20px;
  font-size: 18px;
}
.wrap{
  max-width: 500px;
  margin: 30px auto;
}
.logo-bar{
  text-align: center;
  margin-bottom: 24px;
}
.logo-bar img{
  height: 70px;
  width: auto;
}
.card{
  background: #fff;
  border-radius: 16px;
  padding: 28px 24px;
  box-shadow: 0 5px 20px rgba(0,0,0,.07);
}
h2{
  font-size: 22px;
  font-weight: 900;
  color: #1a1a1a;
  margin-bottom: 8px;
  text-align: center;
}
.sub{
  font-size: 14px;
  color: #777;
  text-align: center;
  margin-bottom: 24px;
  line-height: 1.5;
}
label{
  display: block;
  font-size: 13px;
  font-weight: 800;
  color: #555;
  text-transform: uppercase;
  letter-spacing: .5px;
  margin-bottom: 6px;
}
input[type=tel]{
  width: 100%;
  padding: 16px 14px;
  font-size: 20px;
  border: 2px solid #e0e0e0;
  border-radius: 12px;
  background: #fafafa;
  color: #1a1a1a;
  margin-bottom: 16px;
  outline: none;
  -webkit-appearance: none;
}
input[type=tel]:focus{ border-color: #b37a5a; background: #fff; }
button[type=submit]{
  width: 100%;
  padding: 16px;
  font-size: 18px;
  font-weight: 900;
  background: #b37a5a;
  color: #fff;
  border: none;
  border-radius: 12px;
  cursor: pointer;
}
button:active{ opacity: .85; }

/* Резултат */
.result-box{
  margin-top: 20px;
  background: #f0fdf4;
  border: 2px solid #86efac;
  border-radius: 14px;
  padding: 22px 20px;
  text-align: center;
}
.result-name{
  font-size: 20px;
  font-weight: 900;
  color: #1a1a1a;
  margin-bottom: 6px;
}
.result-card{
  font-size: 24px;
  font-weight: 900;
  color: #b37a5a;
  letter-spacing: 2px;
  margin-bottom: 18px;
}
.btn-open{
  display: block;
  width: 100%;
  padding: 16px;
  font-size: 18px;
  font-weight: 900;
  background: #b37a5a;
  color: #fff;
  text-decoration: none;
  border-radius: 12px;
  text-align: center;
}
.btn-install{
  display: block;
  width: 100%;
  padding: 14px;
  font-size: 16px;
  font-weight: 800;
  background: #E8B800;
  color: #1a1a1a;
  text-decoration: none;
  border-radius: 12px;
  text-align: center;
  margin-top: 10px;
}

/* Грешка */
.error-box{
  margin-top: 16px;
  background: #fff1f0;
  border: 2px solid #fca5a5;
  border-radius: 12px;
  padding: 16px;
  text-align: center;
  font-size: 15px;
  font-weight: 700;
  color: #dc2626;
}
.register-link{
  display: block;
  margin-top: 12px;
  font-size: 14px;
  text-align: center;
  color: #b37a5a;
  font-weight: 700;
}
</style>
</head>
<body>
<div class="wrap">

  <div class="logo-bar">
    <img src="/loyalty/icon-192.png" alt="Ени Тихолов" onerror="this.style.display='none'">
  </div>

  <div class="card">
    <h2>🔍 Намери картата си</h2>
    <p class="sub">Въведи телефона с който се регистрира и ще ти покажем картата.</p>

    <form method="post" id="findForm">
      <label>Телефонен номер</label>
      <input type="tel" name="phone" id="phoneInput"
             placeholder="0888 123 456"
             value="<?= $searched ? h($_POST['phone']??'') : '' ?>"
             autocomplete="tel"
             required>
      <button type="submit" id="submitBtn">Намери картата →</button>
    </form>

    <?php if ($found): ?>
    <div class="result-box">
      <div class="result-name">👋 <?= h(trim($found['first_name'].' '.$found['last_name'])) ?></div>
      <div class="result-card"><?= h($found['card_number']) ?></div>
      <a class="btn-open" href="/loyalty/card.php?card=<?= urlencode($found['card_number']) ?>">
        Отвори лоялната карта →
      </a>
      <a class="btn-install" href="/loyalty/card.php?card=<?= urlencode($found['card_number']) ?>">
        📲 Добави на началния екран
      </a>
    </div>
    <?php elseif ($searched && $error): ?>
    <div class="error-box">
      <?= h($error) ?>
    </div>
    <a class="register-link" href="/loyalty/register.php">
      → Регистрирай се с нова карта
    </a>
    <?php endif; ?>
  </div>

</div>
<script>
document.getElementById('findForm').addEventListener('submit', function(){
  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.textContent = '⏳ Търсене...';
});
</script>
</body>
</html>