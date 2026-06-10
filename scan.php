<?php
require_once __DIR__ . '/config.php';

date_default_timezone_set('Europe/Sofia');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

function h($str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function euro_raw($n): string {
    return number_format((float)$n, 2, '.', '');
}

function euro_text($n): string {
    return euro_raw($n) . ' €';
}

function jsonResponse(array $data): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function generateVoucherCode(string $prefix = 'VCH'): string {
    return $prefix . '-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 10));
}

function customerDisplayName(array $customer): string {
    $name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
    return $name !== '' ? $name : 'Клиент';
}

function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE :table");
        $stmt->execute(['table' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/* ══════════════════════════════════════════════════════════════
   АНТИ-СКАМ КОНСТАНТИ
   ══════════════════════════════════════════════════════════════ */
const ANTISCAM_MIN_PURCHASE_FOR_CYCLE = 10.00;
const ANTISCAM_MIN_INTERVAL_SECONDS = 0;
const ANTISCAM_SUSPICIOUS_THRESHOLD = 3;

function businessDateTime(?DateTime $dt = null): array {
    $tz = new DateTimeZone('Europe/Sofia');
    $now = $dt ?: new DateTime('now', $tz);

    $businessDate = clone $now;
    $hour = (int)$now->format('H');
    if ($hour < 19) {
        $businessDate->modify('-1 day');
    }

    return [
        'created_at' => $now->format('Y-m-d H:i:s'),
        'business_date' => $businessDate->format('Y-m-d'),
    ];
}

function countTodayPurchases(PDO $pdo, int $customerId, string $businessDate): int {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM purchase_scans
            WHERE customer_id = :cid
              AND DATE(created_at) = :bdate
        ");
        $stmt->execute(['cid' => $customerId, 'bdate' => $businessDate]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function getLastScanTimestamp(PDO $pdo, int $customerId): ?int {
    try {
        $stmt = $pdo->prepare("
            SELECT created_at FROM purchase_scans
            WHERE customer_id = :cid
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['cid' => $customerId]);
        $row = $stmt->fetchColumn();
        if (!$row) return null;
        $ts = strtotime((string)$row);
        return $ts ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function writeAuditLog(PDO $pdo, int $customerId, string $cardNumber, string $eventType, array $data = []): void {
    try {
        if (!tableExists($pdo, 'loyalty_audit_log')) return;

        $stmt = $pdo->prepare("
            INSERT INTO loyalty_audit_log
                (customer_id, card_number, event_type, event_data, ip_address, user_agent, created_at)
            VALUES
                (:cid, :card, :evt, :data, :ip, :ua, :now)
        ");
        $stmt->execute([
            'cid'  => $customerId,
            'card' => $cardNumber,
            'evt'  => $eventType,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip'   => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'ua'   => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
            'now'  => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
    }
}

function flagCustomerSuspicious(PDO $pdo, int $customerId): void {
    try {
        $pdo->prepare("UPDATE customers SET suspicious = 1 WHERE id = :id")
            ->execute(['id' => $customerId]);
    } catch (Throwable $e) { }
}

function runAntiScamChecks(PDO $pdo, int $customerId, string $cardNumber, float $paidAmount, string $businessDate): array {
    if (ANTISCAM_MIN_INTERVAL_SECONDS > 0) {
        $lastTs = getLastScanTimestamp($pdo, $customerId);
        if ($lastTs !== null) {
            $elapsed = time() - $lastTs;
            if ($elapsed < ANTISCAM_MIN_INTERVAL_SECONDS) {
                $minutesLeft = ceil((ANTISCAM_MIN_INTERVAL_SECONDS - $elapsed) / 60);
                writeAuditLog($pdo, $customerId, $cardNumber, 'BLOCKED_INTERVAL', [
                    'elapsed_sec'  => $elapsed,
                    'required_sec' => ANTISCAM_MIN_INTERVAL_SECONDS,
                ]);
                return ['ok' => false, 'error' => "Картата беше сканирана наскоро. Моля изчакайте още {$minutesLeft} мин."];
            }
        }
    }

    $todayCount = countTodayPurchases($pdo, $customerId, $businessDate);
    if ($todayCount + 1 >= ANTISCAM_SUSPICIOUS_THRESHOLD) {
        flagCustomerSuspicious($pdo, $customerId);
        writeAuditLog($pdo, $customerId, $cardNumber, 'SUSPICIOUS_ACTIVITY', [
            'today_count' => $todayCount + 1,
            'threshold'   => ANTISCAM_SUSPICIOUS_THRESHOLD,
            'business_date' => $businessDate,
        ]);
    }

    return ['ok' => true];
}

function normalizeSaleItemsPayload($raw): array {
    if (!$raw) return [];
    if (is_string($raw)) $decoded = json_decode($raw, true);
    else $decoded = $raw;
    if (!is_array($decoded)) return [];

    $items = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) continue;
        $code     = preg_replace('/[^0-9]/', '', (string)($item['code'] ?? ''));
        $model    = trim((string)($item['model'] ?? ''));
        $qty      = max(1, (int)($item['qty'] ?? 1));
        $price    = round((float)($item['price'] ?? 0), 2);
        $discount = round((float)($item['discount'] ?? 0), 2);
        if ($price <= 0) continue;
        $base  = round($qty * $price, 2);
        $final = round((float)($item['final'] ?? ($base - ($base * $discount / 100))), 2);
        if ($final < 0) $final = 0;
        $items[] = ['code'=>$code,'model'=>$model,'qty'=>$qty,'price'=>$price,'discount'=>$discount,'base'=>$base,'final'=>$final];
    }
    return $items;
}

function aggregateSummaryItems(array $items): array {
    $grouped = [];
    foreach ($items as $item) {
        $key = implode('|', [(string)($item['code']??''), euro_raw($item['price']??0), euro_raw($item['final']??0), euro_raw($item['discount']??0)]);
        if (!isset($grouped[$key])) {
            $grouped[$key] = ['code'=>(string)($item['code']??''),'qty'=>0,'price'=>round((float)($item['price']??0),2),'final'=>round((float)($item['final']??0),2),'discount'=>round((float)($item['discount']??0),2)];
        }
        $grouped[$key]['qty'] += (int)($item['qty']??0);
    }
    uasort($grouped, fn($a,$b) => strcmp((string)$a['code'],(string)$b['code']));
    return array_values($grouped);
}

function renderSummaryText(array $items, float $grossTotal, float $finalTotal): string {
    $lines = [];
    $summaryItems = aggregateSummaryItems($items);
    foreach ($summaryItems as $item) {
        $lines[] = 'Артикул ' . ($item['code']!==''?$item['code']:'без код') . ' — ' . (int)$item['qty'] . ' бр — ' . euro_raw($item['price']) . ' → ' . euro_raw($item['final']) . ' (' . ($item['discount']>0?'–':'') . euro_raw($item['discount']) . '%)';
    }
    $discountPercent = $grossTotal > 0 ? round((($grossTotal-$finalTotal)/$grossTotal)*100,2) : 0;
    $lines[] = '';
    $lines[] = 'Общо без отстъпка: ' . euro_raw($grossTotal);
    $lines[] = 'Общо за плащане: ' . euro_raw($finalTotal);
    $lines[] = 'Обща отстъпка: ' . euro_raw(max(0,$grossTotal-$finalTotal));
    $lines[] = 'Обща отстъпка %: ' . euro_raw($discountPercent) . '%';
    return implode("\n", $lines);
}

function findCustomerByCard(PDO $pdo, string $cardNumber): ?array {
    $stmt = $pdo->prepare("
        SELECT c.id AS customer_id, c.first_name, c.last_name,
               c.total_spent, c.total_purchases, c.last_percent_reward,
               c.cycle_spent_100, c.cycle_purchases_10, c.cycle_purchases_50,
               c.cycle_purchases_100, c.referred_by, lc.card_number
        FROM loyalty_cards lc
        INNER JOIN customers c ON c.id = lc.customer_id
        WHERE lc.card_number = :card_number LIMIT 1
    ");
    $stmt->execute(['card_number' => $cardNumber]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['customer_id']        = (int)$row['customer_id'];
    $row['total_spent']        = round((float)$row['total_spent'], 2);
    $row['total_purchases']    = (int)$row['total_purchases'];
    $row['last_percent_reward']= round((float)$row['last_percent_reward'], 2);
    $row['cycle_spent_100']    = round(max(0,(float)$row['cycle_spent_100']),2);
    $row['cycle_purchases_10'] = max(0,(int)$row['cycle_purchases_10']);
    $row['cycle_purchases_50'] = max(0,(int)$row['cycle_purchases_50']);
    $row['cycle_purchases_100']= max(0,(int)$row['cycle_purchases_100']);
    return $row;
}

function loadUnusedVouchers(PDO $pdo, int $customerId): array {
    $stmt = $pdo->prepare("SELECT id, code, voucher_type, percent_value, min_spent, used, created_at FROM vouchers WHERE customer_id=:customer_id AND used=0 ORDER BY created_at ASC, id ASC");
    $stmt->execute(['customer_id' => $customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function detectVoucherKind(array $v): string {
    $code  = strtoupper(trim((string)($v['code'] ?? '')));
    $type  = strtolower(trim((string)($v['voucher_type'] ?? '')));
    $value = (float)($v['percent_value'] ?? 0);
    if (strpos($code,'WELCOME5')===0) return 'welcome5';
    if (strpos($code,'PCT5')===0)     return 'turnover5';
    if (strpos($code,'EUR150')===0)   return 'fixed150';
    if (strpos($code,'EUR50')===0)    return 'fixed50';
    if (strpos($code,'EUR5')===0)     return 'fixed5';
    if ($type==='welcome') return 'welcome5';
    if ($type==='percent'&&abs($value-5)<0.001) return 'turnover5';
    if ($type==='fixed') {
        if ($value>=149.99) return 'fixed150';
        if ($value>=49.99)  return 'fixed50';
        if ($value>=4.99)   return 'fixed5';
    }
    return 'unknown';
}

function voucherMeta(string $kind): ?array {
    switch ($kind) {
        case 'welcome5':  return ['key'=>'welcome','title'=>'Welcome -5%','label'=>'Welcome -5%','badge'=>'-5%','desc'=>'Еднократен бонус за първата покупка.','type_group'=>'welcome','sort'=>10];
        case 'turnover5': return ['key'=>'pct5','title'=>'5% бонус','label'=>'5% бонус','badge'=>'-5%','desc'=>'Активен 5% бонус след достигнат оборотен цикъл 100€.','type_group'=>'accumulated','sort'=>20];
        case 'fixed5':    return ['key'=>'reward_5','title'=>'5€ ваучер','label'=>'5€ ваучер','badge'=>'-5€','desc'=>'Награда за 10 покупки.','type_group'=>'accumulated','sort'=>30];
        case 'fixed50':   return ['key'=>'reward_50','title'=>'50€ ваучер','label'=>'50€ ваучер','badge'=>'-50€','desc'=>'Награда за 50 покупки.','type_group'=>'accumulated','sort'=>40];
        case 'fixed150':  return ['key'=>'reward_150','title'=>'150€ ваучер','label'=>'150€ ваучер','badge'=>'-150€','desc'=>'Награда за 100 покупки.','type_group'=>'accumulated','sort'=>50];
    }
    return null;
}

function normalizeVoucherForUi(array $voucher): ?array {
    $kind = detectVoucherKind($voucher);
    if ($kind === 'welcome5') return null;
    return voucherMeta($kind);
}

function buildActiveBonuses(array $customer, array $vouchers): array {
    $items = []; $seen = [];
    if ((int)$customer['total_purchases'] === 0) {
        $welcome = voucherMeta('welcome5'); $items[] = $welcome; $seen[$welcome['key']] = true;
    }
    foreach ($vouchers as $voucher) {
        $norm = normalizeVoucherForUi($voucher);
        if (!$norm||isset($seen[$norm['key']])) continue;
        $seen[$norm['key']] = true; $items[] = $norm;
    }
    usort($items, fn($a,$b) => ($a['sort']??999)<=>($b['sort']??999));
    return array_values($items);
}

function buildActiveBonusLabels(array $activeBonuses): array {
    $labels = [];
    foreach ($activeBonuses as $bonus) {
        if (!empty($bonus['label'])) $labels[] = $bonus['label'];
    }
    return array_values(array_unique($labels));
}

function buildActiveBonusNotice(array $activeBonuses): string {
    $labels = buildActiveBonusLabels($activeBonuses);
    if (!$labels) return 'В момента няма активни бонуси.';
    if (count($labels)===1) return 'Имате активен бонус: '.$labels[0].'.';
    return 'Имате активни бонуси: '.implode(', ',$labels).'.';
}

function buildCurrentBonusSummary(array $activeBonuses): array {
    $labels = buildActiveBonusLabels($activeBonuses);
    if (!$labels) return ['main'=>'—','notice'=>'В момента няма активни бонуси.'];
    if (count($labels)===1) return ['main'=>$labels[0],'notice'=>'Имате активен бонус: '.$labels[0].'.'];
    return ['main'=>count($labels).' активни бонуса','notice'=>'Имате активни бонуси: '.implode(', ',$labels).'.'];
}

function buildIssuedRewardMessages(array $issued): array {
    $items = [];
    if (!empty($issued['turnover5'])) $items[] = '5% бонус';
    if (!empty($issued['fixed5']))    $items[] = '5€ ваучер';
    if (!empty($issued['fixed50']))   $items[] = '50€ ваучер';
    if (!empty($issued['fixed150']))  $items[] = '150€ ваучер';
    return $items;
}

function loadLastPurchase(PDO $pdo, int $customerId): array {
    $stmt = $pdo->prepare("SELECT amount, discount_amount, created_at FROM purchase_scans WHERE customer_id=:customer_id ORDER BY id DESC LIMIT 1");
    $stmt->execute(['customer_id' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['amount'=>null,'created_at'=>null,'display_amount'=>'—','display_date'=>'—'];
    $amount = isset($row['amount'])?round((float)$row['amount'],2):null;
    $createdAt = $row['created_at']??null;
    $displayDate = '—';
    if (!empty($createdAt)) { $ts = strtotime((string)$createdAt); if ($ts) $displayDate=date('d.m.Y H:i',$ts); }
    return ['amount'=>$amount,'created_at'=>$createdAt,'display_amount'=>$amount!==null?euro_text($amount):'—','display_date'=>$displayDate];
}

function listHasVoucherKind(array $vouchers, string $kind): bool {
    foreach ($vouchers as $voucher) { if (detectVoucherKind($voucher)===$kind) return true; }
    return false;
}

function buildProgressDataFromState(array $customerState): array {
    $cycleSpent100 = round(max(0,(float)$customerState['cycle_spent_100']),2);
    $left100e = max(0,round(100-$cycleSpent100,2));
    $p10 = max(0,(int)$customerState['cycle_purchases_10']);
    $p50 = max(0,(int)$customerState['cycle_purchases_50']);
    $p100= max(0,(int)$customerState['cycle_purchases_100']);
    return [
        ['key'=>'spent100','title'=>'Остават до бонус','value'=>euro_raw($left100e).' €','hint'=>'До следващия 5% бонус'],
        ['key'=>'p10','title'=>'До 5€','value'=>max(0,10-$p10).' покупки','hint'=>'Награда при 10 покупки'],
        ['key'=>'p50','title'=>'До 50€','value'=>max(0,50-$p50).' покупки','hint'=>'Награда при 50 покупки'],
        ['key'=>'p100','title'=>'До 150€','value'=>max(0,100-$p100).' покупки','hint'=>'Награда при 100 покупки'],
    ];
}

function findBestAccumulatedVoucher(array $vouchers, float $baseAmount): ?array {
    $baseAmount = round(max(0,$baseAmount),2);
    if ($baseAmount <= 0) return null;
    $best = null;
    foreach ($vouchers as $voucher) {
        $kind = detectVoucherKind($voucher);
        if (!in_array($kind,['turnover5','fixed5','fixed50','fixed150'],true)) continue;
        $discountAmount = 0.0; $rank = 0;
        switch ($kind) {
            case 'turnover5': $discountAmount=round($baseAmount*0.05,2); $rank=100; break;
            case 'fixed5':    $discountAmount=min(5.0,$baseAmount);      $rank=200; break;
            case 'fixed50':   $discountAmount=min(50.0,$baseAmount);     $rank=300; break;
            case 'fixed150':  $discountAmount=min(150.0,$baseAmount);    $rank=400; break;
        }
        $meta = voucherMeta($kind);
        if (!$meta) continue;
        $candidate = ['id'=>(int)$voucher['id'],'kind'=>$kind,'label'=>$meta['label'],'badge'=>$meta['badge'],'discount_amount'=>round($discountAmount,2),'rank'=>$rank];
        if ($best===null) { $best=$candidate; continue; }
        if ($candidate['discount_amount']>$best['discount_amount']) { $best=$candidate; continue; }
        if (abs($candidate['discount_amount']-$best['discount_amount'])<0.0001&&$candidate['rank']>$best['rank']) $best=$candidate;
    }
    return $best;
}

function simulatePurchase(array $customer, array $vouchers, float $amountBefore): array {
    $amountBefore = round(max(0,(float)$amountBefore),2);
    $currentActiveBonuses = buildActiveBonuses($customer,$vouchers);
    $currentProgress = buildProgressDataFromState($customer);

    if ($amountBefore <= 0) {
        return ['amount_before'=>0.00,'paid_amount'=>0.00,'discount_amount'=>0.00,'discount_percent'=>0.00,'voucher_main'=>'—','voucher_sub'=>'Няма приложен бонус','applied_ids'=>[],'applied_text'=>[],'available_bonuses_after'=>buildActiveBonusLabels($currentActiveBonuses),'available_bonuses_after_objects'=>$currentActiveBonuses,'active_bonus_notice_after'=>buildActiveBonusNotice($currentActiveBonuses),'progress_after'=>$currentProgress,'customer_after'=>$customer,'remaining_vouchers_after'=>$vouchers,'issued'=>['turnover5'=>0,'fixed5'=>0,'fixed50'=>0,'fixed150'=>0]];
    }

    $welcomeActive = ((int)$customer['total_purchases'] === 0);
    $appliedIds=[]; $appliedText=[]; $voucherMainParts=[];
    $discountAmount=0.0; $paidAmount=$amountBefore;

    if ($welcomeActive) {
        $welcomeDiscount=round($paidAmount*0.05,2);
        $discountAmount+=$welcomeDiscount;
        $paidAmount=round(max(0,$paidAmount-$welcomeDiscount),2);
        $appliedText[]='Welcome -5%'; $voucherMainParts[]='-5%';
    }

    $bestAccumulated=null;
    if ($paidAmount>0) $bestAccumulated=findBestAccumulatedVoucher($vouchers,$paidAmount);

    if ($bestAccumulated) {
        $currentBase=round($paidAmount,2); $extraDiscount=0.0;
        switch ($bestAccumulated['kind']) {
            case 'turnover5': $extraDiscount=round($currentBase*0.05,2); break;
            case 'fixed5':    $extraDiscount=min(5.0,$currentBase); break;
            case 'fixed50':   $extraDiscount=min(50.0,$currentBase); break;
            case 'fixed150':  $extraDiscount=min(150.0,$currentBase); break;
        }
        $discountAmount+=$extraDiscount;
        $paidAmount=round(max(0,$paidAmount-$extraDiscount),2);
        $appliedIds[]=(int)$bestAccumulated['id'];
        $appliedText[]=$bestAccumulated['label'];
        $voucherMainParts[]=$bestAccumulated['badge'];
    }

    $discountAmount=round(min($discountAmount,$amountBefore),2);
    $paidAmount=round(max(0,$amountBefore-$discountAmount),2);
    $discountPercent=$amountBefore>0?round(($discountAmount/$amountBefore)*100,2):0.0;

    $remainingVouchers=[];
    foreach ($vouchers as $voucher) { if (!in_array((int)$voucher['id'],$appliedIds,true)) $remainingVouchers[]=$voucher; }

    $customerAfter=$customer;
    $customerAfter['total_spent']=round((float)$customer['total_spent']+$paidAmount,2);
    $customerAfter['total_purchases']=(int)$customer['total_purchases']+1;
    $customerAfter['cycle_spent_100']=round((float)$customer['cycle_spent_100']+$paidAmount,2);
    $countsForCycle=($paidAmount>=ANTISCAM_MIN_PURCHASE_FOR_CYCLE);
    $customerAfter['cycle_purchases_10']=(int)$customer['cycle_purchases_10']+($countsForCycle?1:0);
    $customerAfter['cycle_purchases_50']=(int)$customer['cycle_purchases_50']+($countsForCycle?1:0);
    $customerAfter['cycle_purchases_100']=(int)$customer['cycle_purchases_100']+($countsForCycle?1:0);

    $issued=['turnover5'=>0,'fixed5'=>0,'fixed50'=>0,'fixed150'=>0];

    if ($customerAfter['cycle_spent_100']>=100) {
        if (!listHasVoucherKind($remainingVouchers,'turnover5')) {
            $issued['turnover5']=1; $customerAfter['cycle_spent_100']=round($customerAfter['cycle_spent_100']-100,2);
            $remainingVouchers[]=['id'=>0,'code'=>'PCT5-PENDING','voucher_type'=>'percent','percent_value'=>5,'used'=>0,'created_at'=>date('Y-m-d H:i:s')];
        }
    }
    if ($customerAfter['cycle_purchases_100']>=100) {
        if (!listHasVoucherKind($remainingVouchers,'fixed150')) {
            $issued['fixed150']=1; $customerAfter['cycle_purchases_10']=0; $customerAfter['cycle_purchases_50']=0; $customerAfter['cycle_purchases_100']=0;
            $remainingVouchers[]=['id'=>0,'code'=>'EUR150-PENDING','voucher_type'=>'fixed','percent_value'=>150,'used'=>0,'created_at'=>date('Y-m-d H:i:s')];
        }
    } elseif ($customerAfter['cycle_purchases_50']>=50) {
        if (!listHasVoucherKind($remainingVouchers,'fixed50')) {
            $issued['fixed50']=1; $customerAfter['cycle_purchases_10']=0; $customerAfter['cycle_purchases_50']=0;
            $remainingVouchers[]=['id'=>0,'code'=>'EUR50-PENDING','voucher_type'=>'fixed','percent_value'=>50,'used'=>0,'created_at'=>date('Y-m-d H:i:s')];
        }
    } elseif ($customerAfter['cycle_purchases_10']>=10) {
        if (!listHasVoucherKind($remainingVouchers,'fixed5')) {
            $issued['fixed5']=1; $customerAfter['cycle_purchases_10']=0;
            $remainingVouchers[]=['id'=>0,'code'=>'EUR5-PENDING','voucher_type'=>'fixed','percent_value'=>5,'used'=>0,'created_at'=>date('Y-m-d H:i:s')];
        }
    }

    $customerAfter['cycle_spent_100']=round(max(0,(float)$customerAfter['cycle_spent_100']),2);
    $customerAfter['cycle_purchases_10']=max(0,(int)$customerAfter['cycle_purchases_10']);
    $customerAfter['cycle_purchases_50']=max(0,(int)$customerAfter['cycle_purchases_50']);
    $customerAfter['cycle_purchases_100']=max(0,(int)$customerAfter['cycle_purchases_100']);

    $activeAfterObjects=buildActiveBonuses($customerAfter,$remainingVouchers);

    return ['amount_before'=>$amountBefore,'paid_amount'=>$paidAmount,'discount_amount'=>$discountAmount,'discount_percent'=>$discountPercent,'voucher_main'=>$voucherMainParts?implode(' + ',$voucherMainParts):'—','voucher_sub'=>$appliedText?'Приложено: '.implode(' + ',$appliedText):'Няма приложен бонус','applied_ids'=>array_values(array_unique($appliedIds)),'applied_text'=>$appliedText,'available_bonuses_after'=>buildActiveBonusLabels($activeAfterObjects),'available_bonuses_after_objects'=>$activeAfterObjects,'active_bonus_notice_after'=>buildActiveBonusNotice($activeAfterObjects),'progress_after'=>buildProgressDataFromState($customerAfter),'customer_after'=>$customerAfter,'remaining_vouchers_after'=>$remainingVouchers,'issued'=>$issued];
}

function saveDetailedArchiveIfPossible(PDO $pdo, array $saleMeta, array $items, ?string $photoPath, array $resultData, array $engine, string $cardNumber): void {
    if (!tableExists($pdo,'loyalty_sales')||!tableExists($pdo,'loyalty_sale_items')) return;
    $summaryText=renderSummaryText($items,(float)$resultData['purchase_amount'],(float)$resultData['paid_amount']);
    $stmt=$pdo->prepare("INSERT INTO loyalty_sales (customer_id,card_number,customer_name,purchase_scan_id,business_date,gross_total,final_total,discount_amount,discount_percent,applied_bonuses_json,summary_text,photo_path,created_at) VALUES (:customer_id,:card_number,:customer_name,:purchase_scan_id,:business_date,:gross_total,:final_total,:discount_amount,:discount_percent,:applied_bonuses_json,:summary_text,:photo_path,:created_at)");
    $stmt->execute(['customer_id'=>$saleMeta['customer_id'],'card_number'=>$cardNumber,'customer_name'=>$saleMeta['customer_name'],'purchase_scan_id'=>$saleMeta['purchase_scan_id'],'business_date'=>$saleMeta['business_date'],'gross_total'=>$resultData['purchase_amount'],'final_total'=>$resultData['paid_amount'],'discount_amount'=>$resultData['discount_amount'],'discount_percent'=>$resultData['discount_percent'],'applied_bonuses_json'=>json_encode($engine['applied_text'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'summary_text'=>$summaryText,'photo_path'=>$photoPath,'created_at'=>$saleMeta['created_at']]);
    $saleId=(int)$pdo->lastInsertId();
    if ($saleId<=0||!$items) return;
    $itemStmt=$pdo->prepare("INSERT INTO loyalty_sale_items (sale_id,item_code,item_model,qty,unit_price,discount_percent,unit_final_price,line_base,line_final) VALUES (:sale_id,:item_code,:item_model,:qty,:unit_price,:discount_percent,:unit_final_price,:line_base,:line_final)");
    foreach ($items as $item) {
        $unitFinal=$item['qty']>0?round($item['final']/$item['qty'],2):round($item['final'],2);
        $itemStmt->execute(['sale_id'=>$saleId,'item_code'=>$item['code']!==''?$item['code']:null,'item_model'=>$item['model']!==''?$item['model']:null,'qty'=>$item['qty'],'unit_price'=>$item['price'],'discount_percent'=>$item['discount'],'unit_final_price'=>$unitFinal,'line_base'=>$item['base'],'line_final'=>$item['final']]);
    }
}

function savePhotoIfAny(string $inputName='sale_photo'): ?string {
    if (empty($_FILES[$inputName])||!isset($_FILES[$inputName]['tmp_name'])||$_FILES[$inputName]['error']!==UPLOAD_ERR_OK) return null;
    $tmp=$_FILES[$inputName]['tmp_name']; $mime=mime_content_type($tmp);
    if (!in_array($mime,['image/jpeg','image/png','image/webp'],true)) return null;
    $dir=__DIR__.'/uploads/loyalty_sales';
    if (!is_dir($dir)) @mkdir($dir,0775,true);
    if (!is_dir($dir)) return null;
    $ext='.jpg'; if($mime==='image/png')$ext='.png'; if($mime==='image/webp')$ext='.webp';
    $filename='sale_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).$ext;
    $target=$dir.'/'.$filename;
    if (!move_uploaded_file($tmp,$target)) return null;
    return 'uploads/loyalty_sales/'.$filename;
}

/* ══════════════════════════════════════════════════════════════
   ИСТОРИЯ ЗА ДЕНЯ — AJAX
   ══════════════════════════════════════════════════════════════ */

/**
 * Зарежда историята за обекта за даден бизнес-ден.
 * Връща покупки + обобщение на артикулите.
 */
function loadDayHistory(PDO $pdo, int $locationId, string $businessDate): array {
    // Определяме времевия прозорец на бизнес-деня:
    // бизнес-ден D = покупки от D 19:00 до D+1 18:59
    $tz = new DateTimeZone('Europe/Sofia');
    $start = new DateTime($businessDate . ' 19:00:00', $tz);
    $end   = clone $start;
    $end->modify('+1 day')->setTime(18, 59, 59);

    $params = ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')];
    $locCond = $locationId > 0 ? 'AND ps.location_id = :loc' : '';
    if ($locationId > 0) $params['loc'] = $locationId;

    // Покупки за деня
    $stmt = $pdo->prepare("
        SELECT ps.id, ps.created_at, ps.amount, ps.discount_amount,
               ps.customer_id, lc.card_number,
               CONCAT(c.first_name,' ',c.last_name) AS customer_name
        FROM purchase_scans ps
        LEFT JOIN customers c ON c.id = ps.customer_id
        LEFT JOIN loyalty_cards lc ON lc.customer_id = ps.customer_id
        WHERE ps.created_at BETWEEN :start AND :end $locCond
        ORDER BY ps.id DESC
    ");
    $stmt->execute($params);
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Артикули от детайлния архив (ако таблицата съществува)
    $itemsMap = []; // purchase_scan_id => items[]
    if (tableExists($pdo, 'loyalty_sales') && tableExists($pdo, 'loyalty_sale_items')) {
        $scanIds = array_column($purchases, 'id');
        if ($scanIds) {
            $marks = implode(',', array_fill(0, count($scanIds), '?'));
            $stmt2 = $pdo->prepare("
                SELECT ls.purchase_scan_id, li.item_code, li.item_model,
                       li.qty, li.unit_price, li.discount_percent,
                       li.unit_final_price, li.line_base, li.line_final
                FROM loyalty_sale_items li
                INNER JOIN loyalty_sales ls ON ls.id = li.sale_id
                WHERE ls.purchase_scan_id IN ($marks)
            ");
            $stmt2->execute($scanIds);
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $itemsMap[$row['purchase_scan_id']][] = $row;
            }
        }
    }

    // Обобщение на артикулите за целия ден
    // Групираме: артикул_код + цена => qty, line_base, line_final
    $dayItemsAgg = []; // key => [...] 
    $dayGross  = 0.0;
    $dayFinal  = 0.0;
    $dayDiscount = 0.0;

    foreach ($purchases as $p) {
        $dayGross    += (float)$p['amount'] + (float)$p['discount_amount'];
        $dayFinal    += (float)$p['amount'];
        $dayDiscount += (float)$p['discount_amount'];

        $scanItems = $itemsMap[$p['id']] ?? [];
        foreach ($scanItems as $item) {
            // За обобщението използваме ОРИГИНАЛНАТА цена (unit_price без отстъпка)
            $key = ($item['item_code'] ?? 'без_код') . '|' . $item['unit_price'];
            if (!isset($dayItemsAgg[$key])) {
                $dayItemsAgg[$key] = [
                    'code'       => $item['item_code'] ?: 'без код',
                    'model'      => $item['item_model'] ?: '',
                    'qty'        => 0,
                    'unit_price' => (float)$item['unit_price'], // оригинална цена
                    'line_base'  => 0.0, // qty × unit_price (без отстъпка)
                    'line_final' => 0.0, // след отстъпка
                ];
            }
            $dayItemsAgg[$key]['qty']        += (int)$item['qty'];
            $dayItemsAgg[$key]['line_base']  += (float)$item['line_base'];
            $dayItemsAgg[$key]['line_final'] += (float)$item['line_final'];
        }
    }

    // Сортиране по код
    usort($dayItemsAgg, fn($a,$b) => strcmp((string)$a['code'],(string)$b['code']));

    return [
        'purchases'   => $purchases,
        'items_map'   => $itemsMap,
        'day_items'   => array_values($dayItemsAgg),
        'day_gross'   => round($dayGross, 2),
        'day_final'   => round($dayFinal, 2),
        'day_discount'=> round($dayDiscount, 2),
        'business_date'=> $businessDate,
    ];
}

/* ── AJAX: изтриване на покупка ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete_purchase' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonResponse(['ok'=>false,'error'=>'Липсва ID.']);
    try {
        $pdo->prepare("DELETE FROM purchase_scans WHERE id=:id")->execute(['id'=>$id]);
        jsonResponse(['ok'=>true]);
    } catch (Throwable $e) {
        jsonResponse(['ok'=>false,'error'=>$e->getMessage()]);
    }
}

/* ── AJAX: история за деня ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'day_history') {
    $locationId  = (int)($_GET['loc'] ?? 0);
    $businessDate = trim($_GET['date'] ?? '');

    if (!$businessDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
        $dtMeta = businessDateTime();
        $businessDate = $dtMeta['business_date'];
    }

    try {
        // Бизнес-ден D = от D 19:00 до D+1 18:59
        $tz    = new DateTimeZone('Europe/Sofia');
        $start = new DateTime($businessDate . ' 19:00:00', $tz);
        $end   = clone $start;
        $end->modify('+1 day')->setTime(18, 59, 59);

        $params  = ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')];
        $locCond = $locationId > 0 ? 'AND ps.location_id = :loc' : '';
        if ($locationId > 0) $params['loc'] = $locationId;

        // Покупки наредени по created_at ASC (хронологичен ред)
        $hasCp = false;
        try {
            $test = $pdo->query("SELECT calc_payload FROM purchase_scans LIMIT 0");
            $hasCp = true;
        } catch (Throwable $e) {}

        $cpCol = $hasCp ? 'ps.calc_payload,' : '';

        $stmt = $pdo->prepare("
            SELECT ps.id, ps.created_at, ps.amount, ps.discount_amount,
                   ps.location_id, ps.location_name, {$cpCol}
                   lc.card_number,
                   CONCAT(c.first_name,' ',c.last_name) AS customer_name
            FROM purchase_scans ps
            LEFT JOIN customers c ON c.id = ps.customer_id
            LEFT JOIN loyalty_cards lc ON lc.customer_id = ps.customer_id
            WHERE ps.created_at BETWEEN :start AND :end $locCond
            ORDER BY ps.created_at ASC
        ");
        $stmt->execute($params);
        $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Парсваме артикулите от calc_payload
        // Агрегираме за целия ден: артикул(код+цена) => qty, оригинална сума
        $dayItemsAgg = [];   // key => [code, model, qty, unit_price, line_base_orig]
        $dayGross    = 0.0;
        $dayFinal    = 0.0;
        $dayDiscount = 0.0;

        foreach ($purchases as &$p) {
            $dayFinal    += (float)$p['amount'];
            $dayDiscount += (float)$p['discount_amount'];
            // gross = реална цена без loyalty отстъпка = amount + discount_amount
            $dayGross    += (float)$p['amount'] + (float)$p['discount_amount'];

            // Парсваме артикулите от calc_payload
            $p['parsed_items'] = [];
            $raw = $p['calc_payload'] ?? null;
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $item) {
                        if (!is_array($item)) continue;
                        $code  = trim((string)($item['code']  ?? ''));
                        $model = trim((string)($item['model'] ?? ''));
                        $qty   = max(1, (int)($item['qty']    ?? 1));
                        $price = round((float)($item['price'] ?? 0), 2); // цена БЕЗ отстъпка
                        if ($price <= 0) continue;

                        $p['parsed_items'][] = [
                            'code'       => $code ?: '—',
                            'model'      => $model,
                            'qty'        => $qty,
                            'unit_price' => $price,
                        ];

                        // Агрегиране за деня
                        $key = $code . '||' . number_format($price, 2, '.', '');
                        if (!isset($dayItemsAgg[$key])) {
                            $dayItemsAgg[$key] = [
                                'code'       => $code ?: '—',
                                'model'      => $model,
                                'qty'        => 0,
                                'unit_price' => $price,
                                'line_base'  => 0.0,
                            ];
                        }
                        $dayItemsAgg[$key]['qty']       += $qty;
                        $dayItemsAgg[$key]['line_base'] += round($qty * $price, 2);
                        // Ако моделът е попълнен — запазваме го
                        if ($model && !$dayItemsAgg[$key]['model']) {
                            $dayItemsAgg[$key]['model'] = $model;
                        }
                    }
                }
            }
            unset($p['calc_payload']); // не изпращаме сурови данни
        }
        unset($p);

        // Сортиране на артикулите по код
        usort($dayItemsAgg, fn($a,$b) => strcmp((string)$a['code'], (string)$b['code']));

        jsonResponse([
            'ok'            => true,
            'purchases'     => $purchases,
            'day_items'     => array_values($dayItemsAgg),
            'day_gross'     => round($dayGross, 2),
            'day_final'     => round($dayFinal, 2),
            'day_discount'  => round($dayDiscount, 2),
            'business_date' => $businessDate,
        ]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'error' => $e->getMessage()]);
    }
}

/* ── AJAX: preview ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'preview') {
    $cardNumber = trim((string)($_GET['card'] ?? ''));
    $netAmount  = round((float)($_GET['amount'] ?? 0), 2);
    $grossAmount = round((float)($_GET['gross'] ?? $netAmount), 2);

    if ($cardNumber === '') jsonResponse(['ok'=>false,'error'=>'Липсва номер на карта.']);

    $customer = findCustomerByCard($pdo, $cardNumber);
    if (!$customer) jsonResponse(['ok'=>false,'error'=>'Картата не е намерена.']);

    $customerId = (int)$customer['customer_id'];
    $vouchers = loadUnusedVouchers($pdo, $customerId);
    $currentActiveBonuses = buildActiveBonuses($customer,$vouchers);
    $currentBonusSummary  = buildCurrentBonusSummary($currentActiveBonuses);
    $lastPurchase = loadLastPurchase($pdo, $customerId);

    $engine = simulatePurchase($customer, $vouchers, $netAmount);
    $finalPaid = round((float)$engine['paid_amount'], 2);

    $displayDiscountAmount  = max(0, round($grossAmount - $finalPaid, 2));
    $displayDiscountPercent = $grossAmount > 0 ? round(($displayDiscountAmount/$grossAmount)*100, 2) : 0.0;

    $nextRewardMessages = buildIssuedRewardMessages($engine['issued']);

    jsonResponse([
        'ok' => true,
        'customer_name' => customerDisplayName($customer),
        'card_number'   => $customer['card_number'],
        'gross_amount'   => $grossAmount,
        'net_amount'     => $netAmount,
        'paid_amount'    => $finalPaid,
        'discount_amount'  => $displayDiscountAmount,
        'discount_percent' => $displayDiscountPercent,
        'applied_text'   => $engine['applied_text'],
        'active_bonus_main_current'   => $currentBonusSummary['main'],
        'active_bonus_notice_current' => $currentBonusSummary['notice'],
        'current_active_bonuses'      => buildActiveBonusLabels($currentActiveBonuses),
        'current_progress'             => buildProgressDataFromState($customer),
        'last_purchase_display_current' => $lastPurchase['display_amount'],
        'last_purchase_date_current'    => $lastPurchase['display_date'],
        'next_reward_messages' => $nextRewardMessages,
    ]);
}

$message = '';
$error = '';
$prefillCard = trim((string)($_GET['card'] ?? ''));

/* ── Обект ── */
$locationId   = (int)($_GET['location'] ?? 0);
$locationName = '';

if ($locationId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM locations WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $locationId]);
        $loc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($loc) $locationName = (string)$loc['name'];
        else $locationId = 0;
    } catch (Throwable $e) { $locationId = 0; }
}

$result = [
    'customer_name'=>'','card_number'=>'','purchase_amount'=>0,'purchase_amount_net'=>0,
    'paid_amount'=>0,'discount_amount'=>0,'discount_percent'=>0,'new_total_spent'=>0,
    'new_total_purchases'=>0,'issued_percent_vouchers'=>0,'issued_5_euro'=>0,'issued_50_euro'=>0,
    'issued_150_euro'=>0,'applied_text'=>[],'last_purchase'=>0,'remaining_to_bonus'=>0,
    'calc_items'=>[],'summary_text'=>'','business_date'=>'',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cardNumber          = trim((string)($_POST['card_number'] ?? ''));
    $purchaseAmountNet   = round((float)($_POST['purchase_amount'] ?? 0), 2);
    $purchaseAmountGross = round((float)($_POST['purchase_amount_gross'] ?? 0), 2);
    $calcPayloadRaw      = $_POST['calc_payload'] ?? '';
    $calcItems           = normalizeSaleItemsPayload($calcPayloadRaw);
    $prefillCard         = $cardNumber;

    if ($calcItems) {
        $itemsGross = round(array_sum(array_column($calcItems,'base')),2);
        $itemsNet   = round(array_sum(array_column($calcItems,'final')),2);
        if ($purchaseAmountGross<=0) $purchaseAmountGross=$itemsGross;
        if ($purchaseAmountNet<=0)   $purchaseAmountNet=$itemsNet;
    }
    if ($purchaseAmountGross<=0&&$purchaseAmountNet>0) $purchaseAmountGross=$purchaseAmountNet;
    if ($purchaseAmountNet>$purchaseAmountGross&&$purchaseAmountGross>0) $purchaseAmountNet=$purchaseAmountGross;

    if ($cardNumber===''||$purchaseAmountNet<=0||$purchaseAmountGross<=0) {
        $error = 'Въведи карта и потвърди покупка от калкулатора.';
    } else {
        try {
            $pdo->beginTransaction();

            $customer = findCustomerByCard($pdo, $cardNumber);
            if (!$customer) throw new Exception('Картата не е намерена.');

            $customerId   = (int)$customer['customer_id'];
            $customerName = customerDisplayName($customer);

            $dtMetaCheck = businessDateTime();
            $antiScam = runAntiScamChecks($pdo,$customerId,$cardNumber,$purchaseAmountNet,$dtMetaCheck['business_date']);
            if (!$antiScam['ok']) throw new Exception($antiScam['error']);

            $vouchers = loadUnusedVouchers($pdo, $customerId);
            $engine   = simulatePurchase($customer, $vouchers, $purchaseAmountNet);

            $paidAmount              = round((float)$engine['paid_amount'], 2);
            $customerAfter           = $engine['customer_after'];
            $displayDiscountAmount   = max(0, round($purchaseAmountGross-$paidAmount, 2));
            $displayDiscountPercent  = $purchaseAmountGross>0 ? round(($displayDiscountAmount/$purchaseAmountGross)*100,2) : 0.0;

            if (!empty($engine['applied_ids'])) {
                $marks = implode(',', array_fill(0,count($engine['applied_ids']),'?'));
                $stmt  = $pdo->prepare("UPDATE vouchers SET used=1 WHERE id IN ($marks)");
                $stmt->execute($engine['applied_ids']);
            }
            if ((int)$customer['total_purchases']===0) {
                $stmt=$pdo->prepare("UPDATE vouchers SET used=1 WHERE customer_id=:customer_id AND used=0 AND code LIKE 'WELCOME5%'");
                $stmt->execute(['customer_id'=>$customerId]);
            }

            $dtMeta = businessDateTime();

            // Записваме и calc_payload (JSON с артикулите) директно в purchase_scans
            // за да може историята да чете артикули дори без loyalty_sale_items таблица.
            // ALTER TABLE purchase_scans ADD COLUMN IF NOT EXISTS calc_payload MEDIUMTEXT;
            $calcPayloadJson = $calcItems ? json_encode($calcItems, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
            try {
                $stmt=$pdo->prepare("INSERT INTO purchase_scans (customer_id,amount,discount_amount,location_id,location_name,calc_payload,created_at) VALUES (:customer_id,:amount,:discount_amount,:location_id,:location_name,:calc_payload,:created_at)");
                $stmt->execute(['customer_id'=>$customerId,'amount'=>$paidAmount,'discount_amount'=>$displayDiscountAmount,'location_id'=>$locationId?:null,'location_name'=>$locationName?:null,'calc_payload'=>$calcPayloadJson,'created_at'=>$dtMeta['created_at']]);
            } catch (Throwable $eInsert) {
                // Ако колоната calc_payload не съществува — записваме без нея
                $stmt=$pdo->prepare("INSERT INTO purchase_scans (customer_id,amount,discount_amount,location_id,location_name,created_at) VALUES (:customer_id,:amount,:discount_amount,:location_id,:location_name,:created_at)");
                $stmt->execute(['customer_id'=>$customerId,'amount'=>$paidAmount,'discount_amount'=>$displayDiscountAmount,'location_id'=>$locationId?:null,'location_name'=>$locationName?:null,'created_at'=>$dtMeta['created_at']]);
            }
            $purchaseScanId=(int)$pdo->lastInsertId();

            $countsForCycle=($purchaseAmountNet>=ANTISCAM_MIN_PURCHASE_FOR_CYCLE);
            writeAuditLog($pdo,$customerId,$cardNumber,'PURCHASE_OK',['scan_id'=>$purchaseScanId,'gross'=>$purchaseAmountGross,'net'=>$purchaseAmountNet,'paid'=>$paidAmount,'discount'=>$displayDiscountAmount,'counts_for_cycle'=>$countsForCycle,'applied'=>$engine['applied_text'],'issued'=>$engine['issued'],'business_date'=>$dtMeta['business_date'],'location_id'=>$locationId,'location_name'=>$locationName]);

            if ($engine['issued']['turnover5']>0) {
                $pdo->prepare("INSERT INTO vouchers (customer_id,code,voucher_type,percent_value,min_spent,used,created_at) VALUES (:customer_id,:code,'percent',5,0,0,:created_at)")->execute(['customer_id'=>$customerId,'code'=>generateVoucherCode('PCT5'),'created_at'=>$dtMeta['created_at']]);
            }
            if ($engine['issued']['fixed5']>0) {
                $pdo->prepare("INSERT INTO vouchers (customer_id,code,voucher_type,percent_value,min_spent,used,created_at) VALUES (:customer_id,:code,'fixed',5,0,0,:created_at)")->execute(['customer_id'=>$customerId,'code'=>generateVoucherCode('EUR5'),'created_at'=>$dtMeta['created_at']]);
            }
            if ($engine['issued']['fixed50']>0) {
                $pdo->prepare("INSERT INTO vouchers (customer_id,code,voucher_type,percent_value,min_spent,used,created_at) VALUES (:customer_id,:code,'fixed',50,0,0,:created_at)")->execute(['customer_id'=>$customerId,'code'=>generateVoucherCode('EUR50'),'created_at'=>$dtMeta['created_at']]);
            }
            if ($engine['issued']['fixed150']>0) {
                $pdo->prepare("INSERT INTO vouchers (customer_id,code,voucher_type,percent_value,min_spent,used,created_at) VALUES (:customer_id,:code,'fixed',150,0,0,:created_at)")->execute(['customer_id'=>$customerId,'code'=>generateVoucherCode('EUR150'),'created_at'=>$dtMeta['created_at']]);
            }

            $pdo->prepare("UPDATE customers SET total_spent=:total_spent,total_purchases=:total_purchases,cycle_spent_100=:cycle_spent_100,cycle_purchases_10=:cycle_purchases_10,cycle_purchases_50=:cycle_purchases_50,cycle_purchases_100=:cycle_purchases_100,updated_at=:updated_at WHERE id=:id")->execute(['total_spent'=>$customerAfter['total_spent'],'total_purchases'=>$customerAfter['total_purchases'],'cycle_spent_100'=>$customerAfter['cycle_spent_100'],'cycle_purchases_10'=>$customerAfter['cycle_purchases_10'],'cycle_purchases_50'=>$customerAfter['cycle_purchases_50'],'cycle_purchases_100'=>$customerAfter['cycle_purchases_100'],'updated_at'=>$dtMeta['created_at'],'id'=>$customerId]);

            $photoPath = savePhotoIfAny('sale_photo');

            if ($calcItems) {
                saveDetailedArchiveIfPossible($pdo,['customer_id'=>$customerId,'customer_name'=>$customerName,'purchase_scan_id'=>$purchaseScanId,'business_date'=>$dtMeta['business_date'],'created_at'=>$dtMeta['created_at']],$calcItems,$photoPath,['purchase_amount'=>$purchaseAmountGross,'paid_amount'=>$paidAmount,'discount_amount'=>$displayDiscountAmount,'discount_percent'=>$displayDiscountPercent],$engine,$cardNumber);
            }

            $pdo->commit();

            $result = ['customer_name'=>$customerName,'card_number'=>$cardNumber,'purchase_amount'=>$purchaseAmountGross,'purchase_amount_net'=>$purchaseAmountNet,'paid_amount'=>$paidAmount,'discount_amount'=>$displayDiscountAmount,'discount_percent'=>$displayDiscountPercent,'new_total_spent'=>$customerAfter['total_spent'],'new_total_purchases'=>$customerAfter['total_purchases'],'issued_percent_vouchers'=>$engine['issued']['turnover5'],'issued_5_euro'=>$engine['issued']['fixed5'],'issued_50_euro'=>$engine['issued']['fixed50'],'issued_150_euro'=>$engine['issued']['fixed150'],'applied_text'=>$engine['applied_text'],'last_purchase'=>$paidAmount,'remaining_to_bonus'=>max(0,round(100-(float)$customerAfter['cycle_spent_100'],2)),'calc_items'=>$calcItems,'summary_text'=>$calcItems?renderSummaryText($calcItems,$purchaseAmountGross,$paidAmount):'','business_date'=>$dtMeta['business_date'],'counts_for_cycle'=>($purchaseAmountNet>=ANTISCAM_MIN_PURCHASE_FOR_CYCLE),'min_for_cycle'=>ANTISCAM_MIN_PURCHASE_FOR_CYCLE,'location_id'=>$locationId,'location_name'=>$locationName];
            $message = 'OK';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

$hasReward =
    $result['issued_percent_vouchers'] > 0 ||
    $result['issued_5_euro'] > 0 ||
    $result['issued_50_euro'] > 0 ||
    $result['issued_150_euro'] > 0;

// Текущ бизнес ден за историята
$currentDtMeta   = businessDateTime();
$currentBizDate  = $currentDtMeta['business_date'];
?>
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta name="theme-color" content="#6366f1">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Сканиране">
<link rel="manifest" href="/loyalty/manifest_scan_<?= (int)$locationId ?>.json">
<link rel="apple-touch-icon" href="/loyalty/icon_scan_192.png">
<title>Сканиране — <?= $locationName !== '' ? h($locationName) : 'Без обект' ?></title>
<style>
/* ═══════════════════════════════════════════════════════════════
   NEON GLASS DESIGN SYSTEM — scan.php (S79.VISUAL)
   Prototype за RunMyStore.ai loyalty модул
   ═══════════════════════════════════════════════════════════════ */
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700;800;900&display=swap');

:root{
  /* Neon Glass — indigo primary */
  --bg:#030712;
  --surface:rgba(15,23,42,.85);
  --surface-2:rgba(30,41,59,.55);
  --accent:#6366f1;
  --accent-light:#818cf8;
  --accent-glow:rgba(99,102,241,.35);

  /* Retained variable NAMES (JS inline uses these) — re-skinned */
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
  background:var(--bg);
  color:var(--text);
  padding-bottom:30px;
  min-height:100vh;
  position:relative;
  overflow-x:hidden;
}
body::before{
  content:'';
  position:fixed;
  top:-200px; left:50%;
  transform:translateX(-50%);
  width:700px; height:500px;
  background:radial-gradient(ellipse,rgba(99,102,241,.12) 0%,transparent 70%);
  pointer-events:none; z-index:0;
}
body::after{
  content:'';
  position:fixed;
  bottom:-300px; left:50%;
  transform:translateX(-50%);
  width:900px; height:600px;
  background:radial-gradient(ellipse,rgba(99,102,241,.06) 0%,transparent 70%);
  pointer-events:none; z-index:0;
}

/* ── Wrapper ── */
.wrap{width:100%;max-width:520px;margin:0 auto;position:relative;z-index:1}

/* ── Top bar ── */
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
.topbar-loc{font-size:13px;font-weight:700;color:var(--text2)}
.topbar-right{font-size:11px;font-weight:700;color:var(--text3);font-variant-numeric:tabular-nums}

/* ── Section box (glass) ── */
.sbox{
  background:var(--surface);
  backdrop-filter:blur(20px);
  -webkit-backdrop-filter:blur(20px);
  border:1px solid var(--border);
  border-radius:20px;
  padding:16px;
  margin:12px 10px 0;
  box-shadow:var(--shadow),inset 0 1px 0 rgba(255,255,255,.04);
  position:relative;
}

/* ── QR камера ── */
#reader{
  width:100%;max-width:260px;margin:0 auto 10px;
  overflow:hidden;border-radius:16px;
  border:1px solid var(--border2);
  background:rgba(3,7,18,.6);
  box-shadow:0 0 30px rgba(99,102,241,.15),inset 0 0 0 1px rgba(99,102,241,.1);
}
#reader video{border-radius:16px !important}

.scan-status{
  text-align:center;padding:10px 12px;border-radius:12px;
  background:rgba(99,102,241,.06);border:1px solid var(--border);
  font-size:13px;font-weight:700;color:var(--text2);margin-bottom:10px;
  letter-spacing:.2px;
}
.scan-status.ok{
  background:rgba(34,197,94,.1);border-color:rgba(74,222,128,.3);
  color:var(--green);
  box-shadow:0 0 20px rgba(74,222,128,.2);
}

.card-input-row{display:flex;gap:8px;align-items:center}
.card-input-row input{
  flex:1;height:52px;
  border:1px solid var(--border2);border-radius:14px;
  background:rgba(99,102,241,.05);
  font-size:19px;font-weight:800;text-align:center;
  color:var(--text);outline:none;padding:0 12px;
  font-family:'Montserrat',sans-serif;letter-spacing:1px;
  transition:all .2s;
}
.card-input-row input:focus{
  border-color:var(--accent);
  background:rgba(99,102,241,.1);
  box-shadow:0 0 0 3px var(--accent-glow),0 0 24px rgba(99,102,241,.3);
}
.card-input-row input::placeholder{color:var(--text3);font-weight:500;font-size:14px;letter-spacing:.3px}

/* ── Клиент блок ── */
.client-block{
  display:flex;align-items:center;justify-content:space-between;gap:10px;
  padding:12px 14px;
  background:linear-gradient(135deg,rgba(99,102,241,.1),rgba(99,102,241,.03));
  border-radius:14px;border:1px solid var(--border2);
  margin-bottom:10px;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.05);
}
.client-name{font-size:18px;font-weight:900;color:var(--text)}
.client-card{font-size:12px;font-weight:700;color:var(--text2);letter-spacing:.5px;margin-top:2px}
.client-card-badge{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.6px;color:#E8B800;margin-bottom:4px}
.client-card-badge::before{content:'';width:8px;height:8px;border-radius:50%;background:#E8B800;box-shadow:0 0 8px #E8B800;animation:cardDot 1.4s ease-in-out infinite}
.client-block.has-card{padding:15px 16px;border:1.5px solid rgba(232,184,0,.55);background:linear-gradient(135deg,rgba(232,184,0,.14),rgba(232,184,0,.04));animation:cardGlow 2.2s ease-in-out infinite}
.client-block.has-card .client-name{font-size:20px}
@keyframes cardGlow{0%,100%{box-shadow:0 0 0 1px rgba(232,184,0,.25),0 0 14px rgba(232,184,0,.18),inset 0 1px 0 rgba(255,255,255,.05)}50%{box-shadow:0 0 0 1px rgba(232,184,0,.6),0 0 26px rgba(232,184,0,.45),inset 0 1px 0 rgba(255,255,255,.08)}}
@keyframes cardDot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}
.client-bonus{
  font-size:13px;font-weight:900;padding:6px 13px;
  border-radius:999px;
  background:linear-gradient(135deg,var(--accent),var(--accent-light));
  color:#fff;
  border:1px solid rgba(255,255,255,.1);
  white-space:nowrap;flex-shrink:0;
  box-shadow:0 4px 16px var(--accent-glow);
}
.client-bonus.welcome{
  background:linear-gradient(135deg,#E8B800,#c9a000);
  color:#1a1a1a;
  border-color:rgba(232,184,0,.4);
  box-shadow:0 4px 16px rgba(232,184,0,.3);
}
.client-empty{
  font-size:14px;font-weight:700;color:var(--text3);
  text-align:center;padding:14px;letter-spacing:.3px;
}

/* ── Бутон Калкулатор ── */
.calc-open-btn{
  width:100%;height:56px;border-radius:16px;
  border:1px solid rgba(255,255,255,.08);
  background:linear-gradient(135deg,#E8B800,#c9a000);
  font-size:17px;font-weight:900;color:#1a1a1a;cursor:pointer;
  box-shadow:0 8px 24px rgba(232,184,0,.35),inset 0 1px 0 rgba(255,255,255,.3);
  display:flex;align-items:center;justify-content:center;gap:8px;
  font-family:'Montserrat',sans-serif;letter-spacing:.3px;
  transition:all .2s;
}
.calc-open-btn:hover{transform:translateY(-1px);box-shadow:0 12px 32px rgba(232,184,0,.5)}
.calc-open-btn:active{transform:translateY(0) scale(.98)}
.calc-open-btn.has-items{
  background:linear-gradient(135deg,#22c55e,#16a34a);
  color:#fff;
  box-shadow:0 8px 24px rgba(34,197,94,.4),inset 0 1px 0 rgba(255,255,255,.2);
}

/* ── Артикули от калкулатора ── */
.items-preview{
  background:rgba(99,102,241,.05);
  border:1px solid var(--border);
  border-radius:14px;padding:12px 14px;margin-bottom:10px;
}
.items-preview-title{
  font-size:10px;font-weight:800;text-transform:uppercase;
  letter-spacing:.8px;color:var(--text2);margin-bottom:8px;
}
.item-preview-row{
  display:flex;align-items:baseline;justify-content:space-between;
  padding:6px 0;border-bottom:1px solid rgba(99,102,241,.08);font-size:13px;
}
.item-preview-row:last-child{border-bottom:none}
.item-preview-name{
  font-weight:700;color:var(--text);flex:1;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-right:8px;
}
.item-preview-right{font-weight:900;color:var(--text);white-space:nowrap}
.item-preview-disc{font-size:11px;color:var(--red);font-weight:800;margin-left:5px}
.ip-tot-row{display:flex;justify-content:space-between;font-size:13px;font-weight:800;padding:5px 0;color:var(--text2)}
.ip-tot-row:first-child{border-top:1px solid rgba(99,102,241,.18);margin-top:8px;padding-top:9px}
.ip-tot-row.disc{color:var(--red)}

/* ── За плащане (hero block) ── */
.pay-block{
  background:linear-gradient(135deg,rgba(99,102,241,.15),rgba(99,102,241,.05));
  border:1px solid var(--border2);
  border-radius:18px;padding:18px;text-align:center;margin-bottom:12px;
  box-shadow:0 0 40px rgba(99,102,241,.1),inset 0 1px 0 rgba(255,255,255,.05);
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
.pay-label{
  font-size:11px;font-weight:800;text-transform:uppercase;
  letter-spacing:1px;color:var(--text2);margin-bottom:6px;
}
.pay-amount{
  font-size:56px;font-weight:900;line-height:1;
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
  margin-top:8px;font-size:13px;font-weight:800;color:var(--accent-light);
  letter-spacing:.2px;
}
.pay-empty{
  font-size:15px;font-weight:700;color:var(--text3);
  padding:10px 0;letter-spacing:.3px;
}

/* ── Бутон Запиши ── */
.submit-btn{
  width:100%;height:62px;border-radius:18px;
  border:1px solid rgba(255,255,255,.08);
  background:linear-gradient(135deg,var(--accent),var(--accent-light));
  font-size:20px;font-weight:900;color:#fff;cursor:pointer;
  box-shadow:0 10px 32px var(--accent-glow),inset 0 1px 0 rgba(255,255,255,.2);
  font-family:'Montserrat',sans-serif;letter-spacing:.4px;
  transition:all .2s;
}
.submit-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 14px 40px rgba(99,102,241,.6)}
.submit-btn:active:not(:disabled){transform:translateY(0) scale(.98)}
.submit-btn:disabled{opacity:.35;cursor:default;transform:none;box-shadow:none}

/* ── Резултат след запис ── */
.result-banner{
  background:linear-gradient(135deg,rgba(34,197,94,.15),rgba(34,197,94,.05));
  border:1px solid rgba(74,222,128,.3);
  border-radius:18px;padding:18px 14px;margin-bottom:0;text-align:center;
  box-shadow:0 0 40px rgba(74,222,128,.15);
}
.result-check{font-size:46px;margin-bottom:8px;filter:drop-shadow(0 0 20px rgba(74,222,128,.5))}
.result-name{font-size:22px;font-weight:900;color:var(--text);margin-bottom:4px}
.result-amount{
  font-size:40px;font-weight:900;color:var(--green);margin-bottom:8px;
  filter:drop-shadow(0 0 20px rgba(74,222,128,.4));
}
.result-reward{
  display:inline-block;margin-top:8px;padding:8px 18px;
  background:linear-gradient(135deg,#E8B800,#c9a000);
  border-radius:999px;
  font-size:14px;font-weight:900;color:#1a1a1a;
  box-shadow:0 4px 16px rgba(232,184,0,.4);
}
.result-error{
  background:rgba(239,68,68,.1);border:1px solid rgba(248,113,113,.3);
  border-radius:16px;padding:14px;text-align:center;
  font-size:14px;font-weight:700;color:var(--red);
}

/* ── Прогрес ── */
.progress-section{margin:12px 10px 0}
.progress-toggle{
  width:100%;padding:12px 16px;
  background:var(--surface);
  backdrop-filter:blur(20px);
  border:1px solid var(--border);border-radius:14px;
  font-size:13px;font-weight:800;color:var(--text2);
  cursor:pointer;text-align:left;display:flex;justify-content:space-between;
  box-shadow:var(--shadow);
  font-family:'Montserrat',sans-serif;letter-spacing:.2px;
  transition:all .2s;
}
.progress-toggle:hover{background:rgba(99,102,241,.08);border-color:var(--border2)}
.progress-body{
  display:none;
  background:var(--surface);
  backdrop-filter:blur(20px);
  border:1px solid var(--border);
  border-top:none;border-radius:0 0 14px 14px;padding:12px 16px;
}
.progress-body.open{display:block}
.prog-row{
  display:flex;justify-content:space-between;align-items:center;
  padding:8px 0;border-bottom:1px solid rgba(99,102,241,.08);font-size:13px;
}
.prog-row:last-child{border-bottom:none}
.prog-label{color:var(--text2);font-weight:700}
.prog-value{font-weight:900;color:var(--text)}
.prog-bar-wrap{
  width:100%;height:8px;
  background:rgba(99,102,241,.1);
  border-radius:999px;overflow:hidden;margin-top:4px;
}
.prog-bar-fill{
  height:100%;border-radius:999px;
  background:linear-gradient(90deg,var(--accent),var(--accent-light),#E8B800);
  box-shadow:0 0 12px var(--accent-glow);
  transition:width .5s cubic-bezier(.4,0,.2,1);
}

/* ── История ── */
.history-section{margin:16px 10px 0}
.history-head{
  display:flex;justify-content:space-between;align-items:center;
  margin-bottom:12px;padding:0 2px;
}
.history-title{font-size:16px;font-weight:900;color:var(--text);letter-spacing:.2px}
.history-refresh{
  border:1px solid var(--border2);
  background:rgba(99,102,241,.06);
  border-radius:10px;padding:7px 14px;font-size:12px;font-weight:800;
  color:var(--text2);cursor:pointer;
  font-family:'Montserrat',sans-serif;
  transition:all .2s;
}
.history-refresh:hover{background:rgba(99,102,241,.12);color:var(--text)}
.day-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.day-tab{
  padding:7px 14px;border-radius:999px;
  border:1px solid var(--border2);
  background:rgba(99,102,241,.04);
  font-size:12px;font-weight:800;color:var(--text2);cursor:pointer;
  transition:all .15s;font-family:'Montserrat',sans-serif;
}
.day-tab:hover{background:rgba(99,102,241,.1);color:var(--text)}
.day-tab.active{
  background:linear-gradient(135deg,var(--accent),var(--accent-light));
  border-color:transparent;color:#fff;
  box-shadow:0 4px 16px var(--accent-glow);
}
.day-tab:active{transform:scale(.95)}

.hist-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px}
.hist-stat{
  background:var(--surface);
  backdrop-filter:blur(20px);
  border:1px solid var(--border);
  border-radius:12px;padding:11px 8px;text-align:center;
  box-shadow:var(--shadow);
}
.hist-stat .hk{
  font-size:9px;text-transform:uppercase;color:var(--text3);
  font-weight:800;letter-spacing:.6px;
}
.hist-stat .hv{font-size:17px;font-weight:900;margin-top:4px;color:var(--text)}
.hist-stat.red .hv{color:var(--red)}
.hist-stat.green .hv{color:var(--green)}

/* Таблица покупки */
.hist-table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:14px}
.hist-table th{
  text-align:left;padding:9px 8px;font-size:10px;font-weight:800;
  text-transform:uppercase;color:var(--text3);letter-spacing:.5px;
  border-bottom:1px solid var(--border2);white-space:nowrap;
}
.hist-table td{
  padding:10px 8px;
  border-bottom:1px solid rgba(99,102,241,.08);
  vertical-align:top;color:var(--text);
}
.hist-table tr:hover td{background:rgba(99,102,241,.04)}
.hist-items-mini{font-size:11px;color:var(--text2);line-height:1.6;margin-top:2px}
.hist-empty{
  text-align:center;padding:22px;color:var(--text3);
  font-size:14px;font-weight:700;letter-spacing:.3px;
}
.hist-loading{text-align:center;padding:16px;color:var(--text3);font-size:13px}

/* Обобщение артикули */
.day-summary{
  background:linear-gradient(135deg,rgba(232,184,0,.1),rgba(232,184,0,.03));
  border:1px solid rgba(232,184,0,.25);
  border-radius:16px;padding:14px;margin-top:6px;
}
.day-summary-title{
  font-size:11px;font-weight:900;text-transform:uppercase;
  letter-spacing:.6px;color:#E8B800;margin-bottom:10px;
}
.day-sum-table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:10px}
.day-sum-table th{
  text-align:left;padding:6px;font-size:10px;font-weight:800;
  text-transform:uppercase;color:var(--text3);
  border-bottom:1px solid rgba(232,184,0,.2);
}
.day-sum-table td{padding:7px 6px;border-bottom:1px solid rgba(232,184,0,.1);color:var(--text)}
.day-sum-table tr:last-child td{border-bottom:none}
.day-totals{
  background:rgba(232,184,0,.05);
  border:1px solid rgba(232,184,0,.2);
  border-radius:10px;padding:11px 13px;
}
.day-totals table{width:100%;font-size:14px}
.day-totals td{padding:4px}
.day-totals .tl{color:var(--text2);font-weight:700}
.day-totals .tv{text-align:right;font-weight:900;color:var(--text)}
.day-totals .td{color:var(--red);font-size:16px}

/* ══ КАЛКУЛАТОР МОДАЛ ══ */
.calc-modal{
  position:fixed;inset:0;
  background:rgba(3,7,18,.75);
  backdrop-filter:blur(8px);
  -webkit-backdrop-filter:blur(8px);
  display:none;align-items:flex-end;justify-content:center;
  padding:0;z-index:200;
}
.calc-modal.show{display:flex}
.calc-shell{
  width:100%;max-width:520px;
  height:92vh;
  display:flex;flex-direction:column;
  background:linear-gradient(180deg,rgba(15,23,42,.98),rgba(3,7,18,.98));
  backdrop-filter:blur(30px);
  border-top:1px solid var(--border2);
  border-radius:24px 24px 0 0;
  overflow:hidden;
  box-shadow:0 -20px 60px rgba(0,0,0,.6),0 0 40px rgba(99,102,241,.2);
}
.calc-topbar{
  flex-shrink:0;
  background:linear-gradient(135deg,rgba(232,184,0,.15),rgba(232,184,0,.05));
  border-bottom:1px solid rgba(232,184,0,.2);
  padding:14px 16px;
  display:flex;align-items:center;justify-content:space-between;
}
.calc-topbar-title{
  font-size:17px;font-weight:900;color:#E8B800;
  letter-spacing:.2px;text-shadow:0 0 20px rgba(232,184,0,.3);
}
.calc-topbar-total{
  font-size:18px;font-weight:900;color:#fff;
  background:linear-gradient(135deg,#E8B800,#c9a000);
  padding:5px 14px;border-radius:999px;
  box-shadow:0 4px 16px rgba(232,184,0,.3);
}
.calc-scroll{flex:1;overflow-y:auto;padding:12px 10px 0}

.calc-entry{
  background:var(--surface);
  backdrop-filter:blur(20px);
  border:1px solid var(--border2);
  border-radius:16px;padding:13px 12px;margin-bottom:12px;
  box-shadow:var(--shadow);
}
/* РЕД 1: Код + Производител + Цена */
.ce-row1{display:grid;grid-template-columns:74px 1fr 90px;gap:7px;margin-bottom:9px}
.ce-label{
  font-size:10px;font-weight:800;text-transform:uppercase;
  letter-spacing:.5px;color:var(--text3);margin-bottom:4px;
}
.ce-input{
  width:100%;height:50px;
  border:1px solid var(--border2);border-radius:11px;
  background:rgba(99,102,241,.05);
  font-size:19px;font-weight:900;text-align:center;color:var(--text);outline:none;
  font-family:'Montserrat',sans-serif;
  transition:all .15s;
}
.ce-input::placeholder{color:var(--text3);font-weight:500;font-size:14px}
.ce-input:focus{
  border-color:#E8B800;
  background:rgba(232,184,0,.08);
  box-shadow:0 0 0 3px rgba(232,184,0,.15);
}
.ce-select{
  width:100%;height:50px;
  border:1px solid var(--border2);border-radius:11px;
  background:rgba(99,102,241,.05) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath fill='%23a5b4fc' d='M5 6L0 0h10z'/%3E%3C/svg%3E") no-repeat right 10px center;
  font-size:13px;font-weight:700;color:var(--text2);
  outline:none;padding:0 22px 0 6px;
  appearance:none;-webkit-appearance:none;
  text-align:center;cursor:pointer;
  font-family:'Montserrat',sans-serif;
  transition:all .15s;
}
.ce-select:focus{border-color:#E8B800}
.ce-select.chosen{color:var(--text);font-weight:900}
.ce-select option{background:#0f172a;color:var(--text)}

/* РЕД 2: Количество */
.ce-row2{display:flex;gap:5px;margin-bottom:9px;align-items:center}
.ce-row-lbl{
  font-size:10px;font-weight:800;text-transform:uppercase;
  letter-spacing:.5px;color:var(--text3);white-space:nowrap;margin-right:3px;
}
.qty-b{
  flex:1;height:42px;border-radius:10px;
  border:1px solid var(--border);
  background:rgba(99,102,241,.04);
  font-size:14px;font-weight:900;color:var(--text3);
  cursor:pointer;transition:all .12s;
  font-family:'Montserrat',sans-serif;
}
.qty-b:hover{background:rgba(99,102,241,.08);color:var(--text2)}
.qty-b.active{
  background:linear-gradient(135deg,var(--accent),var(--accent-light));
  border-color:transparent;color:#fff;
  box-shadow:0 4px 14px var(--accent-glow);
}
.qty-b:active{transform:scale(.93)}
.qty-custom{
  width:50px;height:42px;
  border:1px solid var(--border);border-radius:10px;
  background:rgba(99,102,241,.04);
  font-size:15px;font-weight:900;text-align:center;color:var(--text);outline:none;
  font-family:'Montserrat',sans-serif;
}
.qty-custom::placeholder{color:var(--text3);font-weight:500;font-size:12px}
.qty-custom:focus{border-color:var(--accent);background:rgba(99,102,241,.08)}

/* РЕД 3: Отстъпка */
.ce-row3{display:flex;gap:5px;margin-bottom:11px;align-items:center}
.disc-b{
  flex:1;height:42px;border-radius:10px;
  border:1px solid var(--border);
  background:rgba(99,102,241,.04);
  font-size:13px;font-weight:900;color:var(--text3);
  cursor:pointer;transition:all .12s;
  font-family:'Montserrat',sans-serif;
}
.disc-b:hover{background:rgba(239,68,68,.08);color:var(--red)}
.disc-b.active{
  background:linear-gradient(135deg,#ef4444,#dc2626);
  border-color:transparent;color:#fff;
  box-shadow:0 4px 14px rgba(239,68,68,.35);
}
.disc-b:active{transform:scale(.93)}

/* Бутон добави */
.ce-add{
  width:100%;height:54px;border-radius:13px;
  border:1px solid rgba(255,255,255,.08);
  background:linear-gradient(135deg,#E8B800,#c9a000);
  font-size:17px;font-weight:900;color:#1a1a1a;cursor:pointer;
  box-shadow:0 6px 20px rgba(232,184,0,.35),inset 0 1px 0 rgba(255,255,255,.25);
  display:flex;align-items:center;justify-content:center;gap:7px;
  font-family:'Montserrat',sans-serif;letter-spacing:.3px;
  transition:all .2s;
}
.ce-add:hover{transform:translateY(-1px);box-shadow:0 10px 28px rgba(232,184,0,.5)}
.ce-add:active{transform:translateY(0) scale(.98)}

/* Списък артикули */
.calc-list-label{
  font-size:10px;font-weight:800;text-transform:uppercase;
  letter-spacing:.6px;color:var(--text3);margin-bottom:8px;padding:0 4px;
}
.calc-item-row{
  background:rgba(99,102,241,.05);
  border:1px solid var(--border);
  border-radius:12px;padding:11px 12px;margin-bottom:8px;
  display:flex;align-items:center;gap:8px;
  transition:background .15s;
}
.calc-item-row:hover{background:rgba(99,102,241,.08)}
.calc-item-info{flex:1;min-width:0}
.calc-item-name{
  font-size:13px;font-weight:900;color:var(--text);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.calc-item-sub{font-size:11px;color:var(--text3);margin-top:3px;font-weight:600}
.calc-item-sub .disc-t{color:var(--red);font-weight:900}
.calc-item-prices{text-align:right;flex-shrink:0}
.calc-item-final{font-size:16px;font-weight:900;color:var(--text)}
.calc-item-orig{font-size:11px;color:var(--text3);text-decoration:line-through}
.calc-item-del{
  flex-shrink:0;width:34px;height:34px;border-radius:9px;
  border:1px solid rgba(239,68,68,.2);
  background:rgba(239,68,68,.08);color:var(--red);
  font-size:15px;font-weight:900;cursor:pointer;
  transition:all .15s;
}
.calc-item-del:hover{background:rgba(239,68,68,.18)}
.calc-item-del:active{transform:scale(.9)}
.calc-empty-hint{
  text-align:center;padding:18px;
  color:var(--text3);font-size:13px;font-weight:600;letter-spacing:.2px;
}

/* Bottom bar на калкулатора */
.calc-bottom{
  flex-shrink:0;
  background:linear-gradient(180deg,rgba(232,184,0,.06),rgba(15,23,42,.98));
  border-top:1px solid rgba(232,184,0,.2);
  padding:12px 12px 16px;
}
.calc-totals-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:7px;margin-bottom:10px}
.calc-tot{
  background:var(--surface);
  backdrop-filter:blur(20px);
  border:1px solid var(--border);
  border-radius:11px;padding:9px 6px;text-align:center;
}
.calc-tot .ctk{
  font-size:9px;text-transform:uppercase;color:var(--text3);
  font-weight:800;letter-spacing:.4px;
}
.calc-tot .ctv{font-size:15px;font-weight:900;margin-top:3px;color:var(--text)}
.calc-tot.gold{border-color:rgba(232,184,0,.3);background:rgba(232,184,0,.06)}
.calc-tot.gold .ctv{color:#E8B800}
.calc-tot.red{border-color:rgba(239,68,68,.2);background:rgba(239,68,68,.05)}
.calc-tot.red .ctv{color:var(--red)}

.calc-confirm-btn{
  width:100%;height:54px;border-radius:14px;
  border:1px solid rgba(255,255,255,.08);
  background:linear-gradient(135deg,var(--accent),var(--accent-light));
  font-size:18px;font-weight:900;color:#fff;cursor:pointer;
  box-shadow:0 8px 24px var(--accent-glow),inset 0 1px 0 rgba(255,255,255,.2);
  font-family:'Montserrat',sans-serif;letter-spacing:.3px;
  transition:all .2s;
}
.calc-confirm-btn:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 12px 32px rgba(99,102,241,.5)}
.calc-confirm-btn:active:not(:disabled){transform:translateY(0) scale(.98)}
.calc-confirm-btn:disabled{opacity:.35;cursor:default;transform:none;box-shadow:none}

.hidden{display:none!important}

/* ══ PRINT СТИЛОВЕ ── остават light за принтер ══ */
@media print {
  body { background:#fff !important; color:#000 !important; }
  body::before,body::after{display:none}
  body * { visibility: hidden; }
  #printZone, #printZone * { visibility: visible; color:#000 !important; }
  #printZone {
    display: block !important;
    position: fixed;
    top: 0; left: 0;
    width: 100%;
    padding: 10px;
    background:#fff;
  }
  .pr-header { text-align:center; margin-bottom:12px; border-bottom:2px solid #000; padding-bottom:8px; }
  .pr-title { font-size:18px; font-weight:900; }
  .pr-sub { font-size:12px; color:#555; margin-top:3px; }
  .pr-table { width:100%; border-collapse:collapse; margin:10px 0; font-size:13px; }
  .pr-table th { border-bottom:1px solid #000; padding:5px 4px; text-align:left; font-size:11px; text-transform:uppercase; }
  .pr-table td { padding:6px 4px; border-bottom:1px solid #ddd; }
  .pr-table td.right { text-align:right; font-weight:700; }
  .pr-totals { margin-top:10px; border-top:2px solid #000; padding-top:8px; }
  .pr-tot-row { display:flex; justify-content:space-between; font-size:14px; padding:3px 0; }
  .pr-tot-row.big { font-size:18px; font-weight:900; }
  .pr-tot-row.red { color:#c00; }
  .pr-footer { margin-top:16px; text-align:center; font-size:11px; color:#888; border-top:1px solid #ddd; padding-top:8px; }
  .pr-sale-block { margin-bottom:12px; padding-bottom:10px; border-bottom:1px dashed #ccc; }
  .pr-sale-head { display:flex; justify-content:space-between; font-size:13px; font-weight:700; margin-bottom:6px; }
}

/* ── Бутон цял екран ── */
.fullscreen-btn{
  display:block;width:100%;margin-top:12px;
  height:50px;border-radius:13px;
  border:1px solid var(--border2);
  background:var(--surface);
  backdrop-filter:blur(20px);
  font-size:15px;font-weight:800;
  color:var(--text2);cursor:pointer;
  box-shadow:var(--shadow);
  font-family:'Montserrat',sans-serif;letter-spacing:.2px;
  transition:all .2s;
}
.fullscreen-btn:hover{background:rgba(99,102,241,.08);color:var(--text);border-color:var(--accent)}
.fullscreen-btn:active{transform:scale(.98)}

/* ── Fullscreen overlay ── */
.fs-overlay{
  position:fixed;inset:0;z-index:300;
  background:var(--bg);
  display:flex;flex-direction:column;
}
.fs-overlay::before{
  content:'';position:fixed;top:-200px;left:50%;
  transform:translateX(-50%);width:700px;height:500px;
  background:radial-gradient(ellipse,rgba(99,102,241,.08) 0%,transparent 70%);
  pointer-events:none;
}
.fs-bar{
  flex-shrink:0;position:relative;z-index:1;
  background:linear-gradient(135deg,rgba(99,102,241,.15),rgba(99,102,241,.05));
  backdrop-filter:blur(20px);
  border-bottom:1px solid var(--border2);
  padding:14px 16px;
  display:flex;align-items:center;justify-content:space-between;
  box-shadow:0 4px 24px rgba(0,0,0,.3);
}
.fs-close{
  border:1px solid var(--border2);border-radius:11px;
  background:rgba(99,102,241,.1);
  padding:9px 18px;font-size:14px;font-weight:900;
  color:var(--text);cursor:pointer;
  font-family:'Montserrat',sans-serif;
  transition:all .15s;
}
.fs-close:hover{background:rgba(99,102,241,.2)}
.fs-close:active{transform:scale(.95)}
.fs-body{
  flex:1;overflow-y:auto;position:relative;z-index:1;
  padding:16px 14px 30px;
}

/* Покупка в fullscreen */
.fs-sale{
  background:var(--surface);
  backdrop-filter:blur(20px);
  border:1px solid var(--border);border-radius:16px;
  padding:15px;margin-bottom:14px;
  box-shadow:var(--shadow);
}
.fs-sale-head{
  display:flex;justify-content:space-between;align-items:baseline;
  margin-bottom:11px;padding-bottom:10px;
  border-bottom:1px solid var(--border2);
}
.fs-sale-time{font-size:22px;font-weight:900;color:var(--text)}
.fs-sale-client{font-size:14px;font-weight:700;color:var(--text2);margin-top:3px}
.fs-sale-amount{
  font-size:22px;font-weight:900;color:var(--accent-light);text-align:right;
  filter:drop-shadow(0 0 12px rgba(99,102,241,.3));
}
.fs-sale-disc{font-size:13px;font-weight:800;color:var(--red);text-align:right}

/* Артикули в покупката */
.fs-item{
  display:flex;justify-content:space-between;align-items:baseline;
  padding:9px 0;border-bottom:1px solid rgba(99,102,241,.08);
}
.fs-item:last-child{border-bottom:none}
.fs-item-left{font-size:18px;font-weight:900;color:var(--text)}
.fs-item-code{font-size:14px;font-weight:700;color:var(--text2);margin-top:2px}
.fs-item-right{text-align:right;flex-shrink:0;padding-left:10px}
.fs-item-qty{font-size:22px;font-weight:900;color:var(--text)}
.fs-item-price{font-size:14px;font-weight:700;color:var(--text2)}
.fs-item-disc{font-size:13px;font-weight:800;color:var(--red)}

/* Обобщение в fullscreen */
.fs-summary{
  background:linear-gradient(135deg,rgba(232,184,0,.1),rgba(232,184,0,.03));
  border:1px solid rgba(232,184,0,.3);
  border-radius:16px;padding:15px;margin-top:8px;
}
.fs-sum-title{
  font-size:13px;font-weight:900;text-transform:uppercase;
  letter-spacing:.6px;color:#E8B800;margin-bottom:11px;
}
.fs-sum-row{
  display:flex;justify-content:space-between;
  padding:8px 0;border-bottom:1px solid rgba(232,184,0,.15);
  font-size:16px;
}
.fs-sum-row:last-child{border-bottom:none}
.fs-sum-name{font-weight:700;color:var(--text)}
.fs-sum-qty{font-size:20px;font-weight:900;color:var(--text);text-align:right}
.fs-sum-price{font-size:14px;font-weight:700;color:var(--text2);text-align:right}

.fs-totals{
  margin-top:14px;
  background:var(--surface);
  backdrop-filter:blur(20px);
  border:1px solid var(--border);border-radius:12px;padding:13px;
}
.fs-tot-row{
  display:flex;justify-content:space-between;
  padding:7px 0;border-bottom:1px solid rgba(99,102,241,.08);font-size:15px;
}
.fs-tot-row:last-child{border-bottom:none}
.fs-tot-label{font-weight:700;color:var(--text2)}
.fs-tot-value{font-weight:900;color:var(--text)}
.fs-tot-value.big{font-size:22px;color:var(--red);filter:drop-shadow(0 0 10px rgba(248,113,113,.3))}

.fs-no-items{
  text-align:center;padding:24px;
  color:var(--text3);font-size:15px;font-weight:700;letter-spacing:.3px;
}

/* Scrollbar styling */
.calc-scroll::-webkit-scrollbar,.fs-body::-webkit-scrollbar{width:6px}
.calc-scroll::-webkit-scrollbar-track,.fs-body::-webkit-scrollbar-track{background:transparent}
.calc-scroll::-webkit-scrollbar-thumb,.fs-body::-webkit-scrollbar-thumb{
  background:rgba(99,102,241,.3);border-radius:3px;
}
.calc-scroll::-webkit-scrollbar-thumb:hover,.fs-body::-webkit-scrollbar-thumb:hover{
  background:rgba(99,102,241,.5);
}

@media(max-width:380px){
  .ce-row1{grid-template-columns:62px 1fr 80px}
  .pay-amount{font-size:44px}
  .sbox{margin:10px 8px 0;padding:14px}
}
</style>
</head>
<body>
<div class="wrap">

<!-- TOP BAR -->
<div class="topbar">
  <div>
    <div class="topbar-left">
      <?= $locationName !== '' ? '📍 '.h($locationName) : '⚠ Без обект' ?>
    </div>
  </div>
  <div class="topbar-right" id="topTime"></div>
</div>

<!-- ══ РАБОТНА ЗОНА ══ -->
<div class="sbox">
  <audio id="scan-beep" preload="auto">
    <source src="https://actions.google.com/sounds/v1/alarms/beep_short.ogg" type="audio/ogg">
  </audio>

  <!-- Камера -->
  <div id="reader"></div>
  <div class="scan-status" id="scanStatus">Готово за сканиране</div>

  <!-- Поле за карта -->
  <div class="card-input-row" style="margin-bottom:10px">
    <input type="text" id="card_number" placeholder="Сканирай или въведи карта" autocomplete="off">
  </div>

  <!-- Клиент блок -->
  <div id="clientBlock">
    <div class="client-empty">Сканирай карта за да започнеш</div>
  </div>

  <!-- Бутон Калкулатор -->
  <button class="calc-open-btn" id="openCalcBtn" style="margin-bottom:10px">
    🧮 Калкулатор
  </button>

  <!-- Артикули от калкулатора (след потвърждение) -->
  <div class="items-preview hidden" id="itemsPreview">
    <div class="items-preview-title">📦 Артикули в покупката</div>
    <div id="itemsPreviewBody"></div>
    <div id="itemsPreviewTotals"></div>
  </div>

  <!-- За плащане -->
  <div class="pay-block">
    <div class="pay-label">За плащане</div>
    <div id="payAmount" class="pay-empty">— въведи от калкулатора —</div>
    <div id="payLoyalty" class="pay-loyalty hidden"></div>
  </div>

  <!-- Грешка -->
  <div class="result-error hidden" id="errorBlock"></div>

  <!-- Резултат след запис -->
  <?php if ($message): ?>
  <div class="result-banner" id="resultBanner">
    <div class="result-check">✅</div>
    <div class="result-name"><?= h($result['customer_name']) ?></div>
    <div class="result-amount"><?= euro_raw($result['paid_amount']) ?> €</div>
    <?php if ($hasReward): ?>
      <div class="result-reward">
        🎁
        <?php if ($result['issued_percent_vouchers']>0) echo '5% бонус  '; ?>
        <?php if ($result['issued_5_euro']>0)          echo '5€ ваучер  '; ?>
        <?php if ($result['issued_50_euro']>0)         echo '50€ ваучер  '; ?>
        <?php if ($result['issued_150_euro']>0)        echo '150€ ваучер'; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="result-error"><?= h($error) ?></div>
  <?php endif; ?>

  <!-- Скрити полета за формата -->
  <form method="post" action="" id="mainForm" style="display:none">
    <input type="hidden" name="card_number"          id="fCard">
    <input type="hidden" name="purchase_amount_gross" id="fGross">
    <input type="hidden" name="purchase_amount"       id="fNet">
    <input type="hidden" name="calc_payload"          id="fPayload">
  </form>

  <!-- Бутон Запиши -->
  <button class="submit-btn" id="submitBtn" disabled style="margin-top:0">
    ✓ Запиши покупката
  </button>
  <!-- Печат на текуща покупка -->
  <button class="fullscreen-btn" id="printSaleBtn" onclick="printSale()" disabled style="margin-top:8px">
    🖨️ Печат на покупката
  </button>
  <!-- Голям бутон Обнови -->
  <button onclick="location.reload()" style="display:block;width:100%;height:54px;margin-top:12px;border-radius:14px;border:2px solid var(--border2);background:#f5f5f5;font-size:18px;font-weight:900;color:var(--text2);cursor:pointer;">
    ↺ Обнови страницата
  </button>
</div>

<!-- ══ ПРОГРЕС (само информативно) ══ -->
<div class="progress-section">
  <button class="progress-toggle" id="progToggle" onclick="toggleProgress()">
    <span>📊 Прогрес на наградите</span>
    <span id="progArrow">▼</span>
  </button>
  <div class="progress-body" id="progBody">
    <div id="progContent" style="color:var(--text3);text-align:center;padding:10px;font-size:13px">
      Сканирай карта за да видиш прогреса
    </div>
  </div>
</div>

<!-- ══ ИСТОРИЯ ══ -->
<div class="history-section">
  <div class="history-head">
    <div class="history-title">📋 История</div>
    <button class="history-refresh" onclick="loadHistory()">↺ Обнови</button>
  </div>

  <!-- Ден табове -->
  <div class="day-tabs" id="dayTabs">
    <?php
    $tzH = new DateTimeZone('Europe/Sofia');
    for ($i = 0; $i < 30; $i++) {
        $dt = new DateTime('now', $tzH);
        $dt->modify("-{$i} days");
        $bizDate = $dt->format('Y-m-d');
        $label   = $i === 0 ? 'Днес' : ($i === 1 ? 'Вчера' : $dt->format('d.m'));
        $active  = $i === 0 ? ' active' : '';
        echo '<button class="day-tab'.$active.'" data-date="'.h($bizDate).'" onclick="selectDay(this)">'.h($label).'</button>';
    }
    ?>
  </div>

  <!-- Статистика -->
  <div class="hist-stats">
    <div class="hist-stat green"><div class="hk">Продажби</div><div class="hv" id="hstCount">—</div></div>
    <div class="hist-stat green"><div class="hk">Взето</div><div class="hv" id="hstTotal">—</div></div>
    <div class="hist-stat red"><div class="hk">Отстъпка</div><div class="hv" id="hstDisc">—</div></div>
  </div>

  <!-- Покупки -->
  <div style="overflow-x:auto;margin-bottom:12px">
    <table class="hist-table" id="histTable">
      <thead><tr>
        <th>Час</th>
        <th>Клиент / Карта</th>
        <th>Взето</th>
        <th>Отст.</th>
        <th>Артикули</th>
      </tr></thead>
      <tbody id="histBody">
        <tr><td colspan="5" class="hist-loading">Зареждане...</td></tr>
      </tbody>
    </table>
  </div>


  <!-- Бутон за цял екран — винаги видим -->
  <button class="fullscreen-btn" id="fullscreenBtn" onclick="openFullscreen()">
    📋 Виж артикулите и обобщението за деня
  </button>
  <button class="fullscreen-btn" onclick="printHistory()" style="margin-top:8px">
    🖨️ Печат на историята за деня
  </button>
</div>

</div><!-- /wrap -->

<!-- ══ PRINT ЗОНА ══ -->
<div id="printZone" style="display:none">
  <div id="printContent"></div>
</div>

<!-- ══ FULLSCREEN ИСТОРИЯ OVERLAY ══ -->
<div class="fs-overlay hidden" id="fsOverlay">
  <div class="fs-bar">
    <div id="fsTitle" style="font-size:15px;font-weight:900;color:#1a1a1a">История</div>
    <button class="fs-close" onclick="closeFullscreen()">← Назад</button>
  </div>
  <div class="fs-body" id="fsBody">
    <div style="text-align:center;padding:30px;color:#aaa">Зареждане...</div>
  </div>
  <!-- Бутон Назад долу -->
  <div style="flex-shrink:0;padding:12px 14px;background:var(--white);border-top:1px solid var(--border)">
    <button onclick="closeFullscreen()" style="
      width:100%;height:52px;border-radius:14px;border:none;
      background:#f0f4f8;font-size:17px;font-weight:900;
      color:var(--text2);cursor:pointer;">
      ← Назад към сканиране
    </button>
  </div>
</div>

<!-- ══ КАЛКУЛАТОР МОДАЛ ══ -->
<div class="calc-modal" id="calcModal">
  <div class="calc-shell">
    <div class="calc-topbar">
      <div class="calc-topbar-title">🧮 Калкулатор</div>
      <div class="calc-topbar-total" id="calcHeaderTotal">0.00 лв</div>
    </div>

    <div class="calc-scroll" id="calcScroll">
      <div class="calc-entry">
        <!-- РЕД 1 -->
        <div class="ce-row1">
          <div>
            <div class="ce-label">Код</div>
            <input id="ceCode" class="ce-input" type="text" inputmode="numeric" placeholder="330" autocomplete="off" enterkeyhint="next">
          </div>
          <div>
            <div class="ce-label">Производител</div>
            <select id="ceBrand" class="ce-select">
              <option value="">— без —</option>
              <option>Статера</option><option>Лорд</option><option>Спико</option>
              <option>Дафи</option><option>Ареал</option><option>DX</option>
              <option>Ивон</option><option>Иватакс</option><option>Петков</option>
              <option>Роял Тайгър</option><option>Китайско</option>
            </select>
          </div>
          <div>
            <div class="ce-label">Цена (лв)</div>
            <input id="cePrice" class="ce-input" type="number" inputmode="decimal" step="0.01" placeholder="0.00" autocomplete="off" enterkeyhint="done">
          </div>
        </div>
        <!-- РЕД 2: Количество -->
        <div class="ce-row2">
          <span class="ce-row-lbl">Бр:</span>
          <button class="qty-b active" data-q="1">1</button>
          <button class="qty-b" data-q="2">2</button>
          <button class="qty-b" data-q="3">3</button>
          <button class="qty-b" data-q="4">4</button>
          <button class="qty-b" data-q="5">5</button>
          <button class="qty-b" data-q="10">10</button>
          <input id="ceQtyCustom" class="qty-custom" type="number" inputmode="numeric" min="1" placeholder="др." enterkeyhint="done">
        </div>
        <!-- РЕД 3: Отстъпка -->
        <div class="ce-row3">
          <span class="ce-row-lbl">Отст:</span>
          <button class="disc-b" data-d="5">5%</button>
          <button class="disc-b" data-d="10">10%</button>
          <button class="disc-b" data-d="15">15%</button>
          <button class="disc-b" data-d="20">20%</button>
          <button class="disc-b" data-d="30">30%</button>
          <button class="disc-b" data-d="40">40%</button>
          <button class="disc-b" data-d="50">50%</button>
        </div>
        <!-- Добави -->
        <button class="ce-add" id="ceAddBtn"><span style="font-size:20px">+</span> Добави артикул</button>
      </div>

      <!-- Добавени артикули -->
      <div id="calcListLabel" class="calc-list-label" style="display:none">Добавени артикули</div>
      <div id="calcItemsList">
        <div class="calc-empty-hint">Въведи код и цена, после натисни + Добави</div>
      </div>
    </div>

    <div class="calc-bottom">
      <div class="calc-totals-row">
        <div class="calc-tot"><div class="ctk">Без отст.</div><div class="ctv" id="ctBase">0.00 лв</div></div>
        <div class="calc-tot red"><div class="ctk">Отстъпка</div><div class="ctv" id="ctDisc">—</div></div>
        <div class="calc-tot gold"><div class="ctk">За плащане</div><div class="ctv" id="ctFinal">0.00 лв</div></div>
      </div>
      <button class="calc-confirm-btn" id="calcConfirmBtn" disabled>Потвърди и затвори</button>
    </div>
  </div>
</div>

<script src="https://unpkg.com/html5-qrcode"></script>
<script>
/* ══════════════════════════════════════════════════════
   GLOBALS
   ══════════════════════════════════════════════════════ */
const LOCATION_ID     = <?= (int)$locationId ?>;
const CURRENT_BIZ_DATE = <?= json_encode($currentBizDate) ?>;
const PAGE_URL        = window.location.href;

let calcItems = [];
let calcQty   = 1;
let calcDisc  = 0;
let histDate  = CURRENT_BIZ_DATE;
let histLoading = false;
let previewRid  = 0;

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') }
function fmtTime(s){ return s ? String(s).slice(11,16) : '—' }

/* ── Часовник в topbar ── */
function tickClock(){
  const now = new Date();
  const el  = document.getElementById('topTime');
  if(el) el.textContent = now.toLocaleTimeString('bg-BG',{hour:'2-digit',minute:'2-digit'});
}
tickClock(); setInterval(tickClock, 10000);

/* ══════════════════════════════════════════════════════
   QR СКЕНЕР
   ══════════════════════════════════════════════════════ */
const html5QrCode = new Html5Qrcode('reader');
let lastScan = '', lastScanTs = 0;

html5QrCode.start(
  {facingMode:'environment'},
  {fps:10, qrbox:{width:200,height:200}, aspectRatio:1, formatsToSupport:[Html5QrcodeSupportedFormats.QR_CODE]},
  (text) => {
    const now = Date.now();
    if(text === lastScan && now - lastScanTs < 1500) return;
    lastScan = text; lastScanTs = now;
    const beep = document.getElementById('scan-beep');
    if(beep){ beep.currentTime=0; beep.play().catch(()=>{}); }
    const card = text.includes(':') ? text.split(':')[0] : text;
    document.getElementById('card_number').value = card;
    setScanStatus('✅ Сканирано: ' + card, true);
    loadPreview(card);
  },
  ()=>{}
).then(()=> setScanStatus('📷 Камерата е активна'))
 .catch(()=> setScanStatus('Камерата не стартира'));

function setScanStatus(msg, ok=false){
  const el = document.getElementById('scanStatus');
  el.textContent = msg;
  el.className = 'scan-status' + (ok?' ok':'');
}

document.getElementById('card_number').addEventListener('input', function(){
  const v = this.value.trim();
  if(v.length >= 4) loadPreview(v);
  else renderClientEmpty();
});

/* ══════════════════════════════════════════════════════
   PREVIEW — зарежда клиент + бонуси + loyalty
   ══════════════════════════════════════════════════════ */
async function loadPreview(card){
  const rid = ++previewRid;
  renderClientLoading();

  const gross = document.getElementById('fGross').value;
  const net   = document.getElementById('fNet').value;

  try {
    const url = new URL(PAGE_URL);
    url.searchParams.set('ajax','preview');
    url.searchParams.set('card', card);
    url.searchParams.set('amount', net   || '0');
    url.searchParams.set('gross',  gross || net || '0');
    url.searchParams.set('_ts', Date.now());

    const res  = await fetch(url, {cache:'no-store'});
    const data = await res.json();
    if(rid !== previewRid) return;

    if(!data.ok){
      renderClientError(data.error || 'Картата не е намерена');
      return;
    }

    renderClientBlock(data);
    renderPayBlock(data);
    renderProgress(data);
  } catch(e){
    if(rid !== previewRid) return;
    renderClientError('Грешка при зареждане');
  }
}

function renderClientEmpty(){
  document.getElementById('clientBlock').innerHTML =
    '<div class="client-empty">Сканирай карта за да започнеш</div>';
  document.getElementById('payAmount').className = 'pay-empty';
  document.getElementById('payAmount').textContent = '— въведи от калкулатора —';
  document.getElementById('payLoyalty').classList.add('hidden');
  document.getElementById('submitBtn').disabled = true;
}
function renderClientLoading(){
  document.getElementById('clientBlock').innerHTML =
    '<div class="client-empty">Зареждане...</div>';
}
function renderClientError(msg){
  document.getElementById('clientBlock').innerHTML =
    `<div class="client-empty" style="color:var(--red)">${esc(msg)}</div>`;
}

function renderClientBlock(data){
  const bonus = data.current_active_bonuses || [];
  const bonusHtml = bonus.length
    ? `<div class="client-bonus">${esc(bonus[0])}</div>`
    : '';
  document.getElementById('clientBlock').innerHTML = `
    <div class="client-block has-card">
      <div>
        <div class="client-card-badge">💳 Лоялна карта</div>
        <div class="client-name">${esc(data.customer_name)}</div>
        <div class="client-card">№ ${esc(data.card_number)}</div>
      </div>
      ${bonusHtml}
    </div>`;
}

function renderPayBlock(data){
  const el     = document.getElementById('payAmount');
  const elLoy  = document.getElementById('payLoyalty');
  const submitBtn = document.getElementById('submitBtn');

  const gross = parseFloat(document.getElementById('fGross').value || 0);
  if(gross <= 0){
    el.className   = 'pay-empty';
    el.textContent = '— въведи от калкулатора —';
    elLoy.classList.add('hidden');
    submitBtn.disabled = true;
    return;
  }

  const paid = parseFloat(data.paid_amount || 0);
  el.className   = 'pay-amount';
  el.textContent = paid.toFixed(2) + ' €';

  const applied = data.applied_text || [];
  if(applied.length){
    elLoy.textContent = '🎁 Loyalty: ' + applied.join(' + ');
    elLoy.classList.remove('hidden');
  } else {
    elLoy.classList.add('hidden');
  }

  renderItemsTotals(data.paid_amount);
  submitBtn.disabled = !document.getElementById('card_number').value.trim();
}

function renderProgress(data){
  const prog = data.current_progress || [];
  if(!prog.length) return;

  let html = '';
  prog.forEach(p => {
    if(p.key === 'spent100'){
      html += `<div class="prog-row">
        <span class="prog-label">До 5% бонус</span>
        <span class="prog-value">${esc(p.value)}</span>
      </div>`;
    } else {
      const label = p.key==='p10'?'До 5€ (10 покупки)':p.key==='p50'?'До 50€ (50 покупки)':'До 150€ (100 покупки)';
      const current = p.key==='p10'? (10 - parseInt(p.value)):
                      p.key==='p50'? (50 - parseInt(p.value)):
                      (100 - parseInt(p.value));
      const total   = p.key==='p10'?10:p.key==='p50'?50:100;
      const pct     = Math.max(0,Math.min(100,Math.round((current/total)*100)));
      html += `<div class="prog-row" style="flex-direction:column;align-items:stretch">
        <div style="display:flex;justify-content:space-between;margin-bottom:3px">
          <span class="prog-label">${label}</span>
          <span class="prog-value">${esc(p.value)}</span>
        </div>
        <div class="prog-bar-wrap"><div class="prog-bar-fill" style="width:${pct}%"></div></div>
      </div>`;
    }
  });
  document.getElementById('progContent').innerHTML = html;
}

/* ══════════════════════════════════════════════════════
   КАЛКУЛАТОР
   ══════════════════════════════════════════════════════ */
const ceCode     = document.getElementById('ceCode');
const cePrice    = document.getElementById('cePrice');
const ceBrand    = document.getElementById('ceBrand');
const ceQtyCustom= document.getElementById('ceQtyCustom');

/* Количество бутони */
document.querySelectorAll('.qty-b').forEach(btn => {
  btn.addEventListener('click', () => {
    calcQty = parseInt(btn.dataset.q);
    ceQtyCustom.value = '';
    syncQtyBtns();
  });
});
ceQtyCustom.addEventListener('input', () => {
  const v = parseInt(ceQtyCustom.value);
  if(v >= 1){ calcQty = v; syncQtyBtns(); }
});
ceQtyCustom.addEventListener('keydown', e => { if(e.key==='Enter'){ e.preventDefault(); ceQtyCustom.blur(); } });
function syncQtyBtns(){
  document.querySelectorAll('.qty-b').forEach(b => {
    b.classList.toggle('active', parseInt(b.dataset.q)===calcQty && !ceQtyCustom.value);
  });
}

/* Отстъпка бутони */
document.querySelectorAll('.disc-b').forEach(btn => {
  btn.addEventListener('click', () => {
    const d = parseInt(btn.dataset.d);
    calcDisc = calcDisc === d ? 0 : d;
    syncDiscBtns();
  });
});
function syncDiscBtns(){
  document.querySelectorAll('.disc-b').forEach(b => {
    b.classList.toggle('active', parseInt(b.dataset.d)===calcDisc && calcDisc!==0);
  });
}

/* Автофокус Код → Цена */
ceCode.addEventListener('keydown', e => {
  if(e.key==='Enter'){ e.preventDefault(); cePrice.focus(); cePrice.select(); }
});
cePrice.addEventListener('keydown', e => {
  if(e.key==='Enter'){ e.preventDefault(); cePrice.blur(); }
});

/* Бутон + Добави */
document.getElementById('ceAddBtn').addEventListener('click', addCalcItem);

function addCalcItem(){
  const code  = ceCode.value.trim();
  const price = parseFloat(cePrice.value);
  const brand = ceBrand.value;

  if(!code){ flash(ceCode); ceCode.focus(); return; }
  if(!price||price<=0){ flash(cePrice); cePrice.focus(); return; }

  const base  = Math.round(calcQty * price * 100) / 100;
  const final = Math.round(base * (1 - calcDisc/100) * 100) / 100;

  calcItems.push({ id: Date.now(), code, brand, qty: calcQty, price, disc: calcDisc, base, final });

  // Нулиране след добавяне
  ceCode.value = ''; cePrice.value = '';
  calcQty = 1; calcDisc = 0;
  ceQtyCustom.value = ''; ceBrand.value = '';
  ceBrand.classList.remove('chosen');
  syncQtyBtns(); syncDiscBtns();

  renderCalcItems();
  updateCalcTotals();
  document.getElementById('calcScroll').scrollTo({top:0,behavior:'smooth'});
  setTimeout(()=>ceCode.focus(), 80);
}

ceBrand.addEventListener('change', () => ceBrand.classList.toggle('chosen', !!ceBrand.value));

function flash(el){
  el.style.borderColor='var(--red)'; el.style.background='#fff5f5';
  setTimeout(()=>{ el.style.borderColor=''; el.style.background=''; }, 1200);
}

function renderCalcItems(){
  const label  = document.getElementById('calcListLabel');
  const list   = document.getElementById('calcItemsList');
  const confBtn= document.getElementById('calcConfirmBtn');

  label.style.display = calcItems.length ? 'block' : 'none';
  confBtn.disabled = calcItems.length === 0;

  if(!calcItems.length){
    list.innerHTML = '<div class="calc-empty-hint">Въведи код и цена, после натисни + Добави</div>';
    return;
  }

  list.innerHTML = calcItems.map(item => {
    const name = [item.code, item.brand].filter(Boolean).join(' · ');
    const hasDisc = item.disc > 0;
    return `<div class="calc-item-row">
      <div class="calc-item-info">
        <div class="calc-item-name">${esc(name)}</div>
        <div class="calc-item-sub">
          ${item.qty} бр × ${item.price.toFixed(2)} лв
          ${hasDisc?`<span class="disc-t"> −${item.disc}%</span>`:''}
        </div>
      </div>
      <div class="calc-item-prices">
        ${hasDisc?`<div class="calc-item-orig">${item.base.toFixed(2)} лв</div>`:''}
        <div class="calc-item-final">${item.final.toFixed(2)} лв</div>
      </div>
      <button class="calc-item-del" onclick="delCalcItem(${item.id})">✕</button>
    </div>`;
  }).join('');
}

window.delCalcItem = id => {
  calcItems = calcItems.filter(i=>i.id!==id);
  renderCalcItems(); updateCalcTotals();
  // Обнови и бутона + preview ако калкулаторът вече е бил потвърден
  if(calcItems.length === 0){
    const btn = document.getElementById('openCalcBtn');
    btn.classList.remove('has-items');
    btn.innerHTML = '🧮 Калкулатор';
    document.getElementById('itemsPreview').classList.add('hidden');
    document.getElementById('fGross').value = '';
    document.getElementById('fNet').value   = '';
    document.getElementById('fPayload').value = '';
    document.getElementById('payAmount').className   = 'pay-empty';
    document.getElementById('payAmount').textContent = '— въведи от калкулатора —';
    document.getElementById('payLoyalty').classList.add('hidden');
    document.getElementById('submitBtn').disabled = true;
  }
};

function updateCalcTotals(){
  const base  = calcItems.reduce((s,i)=>s+i.base,  0);
  const final = calcItems.reduce((s,i)=>s+i.final, 0);
  const disc  = base - final;
  document.getElementById('ctBase').textContent  = base.toFixed(2)  + ' лв';
  document.getElementById('ctDisc').textContent  = disc > 0 ? '−'+disc.toFixed(2)+' лв' : '—';
  document.getElementById('ctFinal').textContent = final.toFixed(2) + ' лв';
  document.getElementById('calcHeaderTotal').textContent = final.toFixed(2) + ' лв';
}

/* Отвори калкулатора — винаги, независимо дали вече има артикули */
document.getElementById('openCalcBtn').addEventListener('click', ()=>{
  document.getElementById('calcModal').classList.add('show');
  renderCalcItems();   // покажи вече добавените артикули
  updateCalcTotals();
  setTimeout(()=>ceCode.focus(), 150);
});

/* Потвърди от калкулатора */
document.getElementById('calcConfirmBtn').addEventListener('click', confirmCalc);

function confirmCalc(){
  if(!calcItems.length) return;

  const gross = calcItems.reduce((s,i)=>s+i.base,  0);
  const net   = calcItems.reduce((s,i)=>s+i.final, 0);

  document.getElementById('fGross').value   = gross.toFixed(2);
  document.getElementById('fNet').value     = net.toFixed(2);
  document.getElementById('fPayload').value = JSON.stringify(calcItems);
  window._calcItems = [...calcItems]; // за печат

  // Активирай бутона за печат
  const pb = document.getElementById('printSaleBtn');
  if(pb) pb.disabled = false;

  // Обнови бутона — показва броя и сумата, но ПРОДЪЛЖАВА да отваря калкулатора
  const btn = document.getElementById('openCalcBtn');
  btn.classList.add('has-items');
  btn.innerHTML = `✏️ ${calcItems.length} арт. — ${net.toFixed(2)} лв &nbsp;(промени)`;

  // Покажи артикулите над "За плащане"
  renderItemsPreview();

  // Затвори модала
  document.getElementById('calcModal').classList.remove('show');

  // Обнови preview с loyalty
  const card = document.getElementById('card_number').value.trim();
  if(card) loadPreview(card);
  else {
    const el = document.getElementById('payAmount');
    el.className = 'pay-amount';
    el.textContent = net.toFixed(2) + ' €';
    document.getElementById('submitBtn').disabled = true;
  }
}

function renderItemsPreview(){
  const preview = document.getElementById('itemsPreview');
  const body    = document.getElementById('itemsPreviewBody');
  if(!calcItems.length){ preview.classList.add('hidden'); return; }

  preview.classList.remove('hidden');
  body.innerHTML = calcItems.map(item => {
    const name = [item.code, item.brand].filter(Boolean).join(' · ');
    const hasDisc = item.disc > 0;
    return `<div class="item-preview-row">
      <span class="item-preview-name">${esc(name)} × ${item.qty}</span>
      <span class="item-preview-right">
        ${item.final.toFixed(2)} лв
        ${hasDisc?`<span class="item-preview-disc">−${item.disc}%</span>`:''}
      </span>
    </div>`;
  }).join('');
  renderItemsTotals();
}

function renderItemsTotals(paid){
  const wrap = document.getElementById('itemsPreviewTotals');
  if(!wrap) return;
  const gross = parseFloat(document.getElementById('fGross').value || 0);
  const net   = parseFloat(document.getElementById('fNet').value   || 0);
  if(gross <= 0){ wrap.innerHTML=''; return; }
  const pay     = (paid===undefined||paid===null||paid==='') ? net : parseFloat(paid);
  const totDisc = Math.max(0, gross - pay);
  const pct     = gross>0 ? Math.round((totDisc/gross)*100) : 0;
  let rows = `<div class="ip-tot-row"><span>Общо без отстъпка</span><span>${gross.toFixed(2)} лв</span></div>`;
  if(totDisc>0) rows += `<div class="ip-tot-row disc"><span>Обща отстъпка</span><span>−${totDisc.toFixed(2)} лв (${pct}%)</span></div>`;
  wrap.innerHTML = rows;
}

/* ══════════════════════════════════════════════════════
   SUBMIT
   ══════════════════════════════════════════════════════ */
document.getElementById('submitBtn').addEventListener('click', ()=>{
  const card  = document.getElementById('card_number').value.trim();
  const gross = document.getElementById('fGross').value;

  if(!card){ alert('Сканирай карта първо.'); return; }
  if(!gross||parseFloat(gross)<=0){ alert('Потвърди калкулатора първо.'); return; }

  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.textContent = '⏳ Записване...';
  document.getElementById('fCard').value = card;

  // Timeout — ако формата не се прати за 15 сек, отключваме
  const timeout = setTimeout(()=>{
    btn.disabled = false;
    btn.textContent = '✓ Запиши покупката';
    alert('⚠️ Времето изтече — провери дали е записано преди да опиташ отново!');
  }, 15000);

  document.getElementById('mainForm').addEventListener('submit', ()=>{
    clearTimeout(timeout);
  }, { once: true });

  document.getElementById('mainForm').submit();
});

/* ══════════════════════════════════════════════════════
   ПРОГРЕС TOGGLE
   ══════════════════════════════════════════════════════ */
function toggleProgress(){
  const body  = document.getElementById('progBody');
  const arrow = document.getElementById('progArrow');
  const open  = body.classList.toggle('open');
  arrow.textContent = open ? '▲' : '▼';
}

/* ══════════════════════════════════════════════════════
   ИСТОРИЯ ЗА ДЕНЯ
   ══════════════════════════════════════════════════════ */
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
  const tbody = document.getElementById('histBody');
  tbody.innerHTML = '<tr><td colspan="5" class="hist-loading">Зареждане...</td></tr>';

  try {
    const url = new URL(PAGE_URL);
    url.searchParams.set('ajax','day_history');
    url.searchParams.set('loc', LOCATION_ID);
    url.searchParams.set('date', histDate);
    url.searchParams.set('_ts', Date.now());

    const res  = await fetch(url, {cache:'no-store'});
    const data = await res.json();

    if(!data.ok){
      tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:16px;color:var(--red);font-weight:700">${esc(data.error||'Грешка')}</td></tr>`;
      histLoading=false; return;
    }

    const purchases  = data.purchases || [];
    const dayFinal   = parseFloat(data.day_final   || 0);
    const dayDiscount= parseFloat(data.day_discount|| 0);
    const dayGross   = parseFloat(data.day_gross   || 0);
    const discPct    = dayGross > 0 ? ((dayDiscount/dayGross)*100).toFixed(1) : '0.0';

    // Запази за fullscreen
    window._histPurchases = purchases;
    window._histDayItems  = data.day_items || [];
    window._histDayFinal  = dayFinal;
    window._histDayDisc   = dayDiscount;
    window._histDayGross  = dayGross;

    document.getElementById('hstCount').textContent = purchases.length;
    document.getElementById('hstTotal').textContent = dayFinal.toFixed(2) + ' €';
    document.getElementById('hstDisc').textContent  = dayDiscount > 0 ? '−'+dayDiscount.toFixed(2)+' € ('+discPct+'%)' : '—';

    if(!purchases.length){
      tbody.innerHTML = '<tr><td colspan="5" class="hist-empty">Няма покупки за този ден</td></tr>';
    } else {
      tbody.innerHTML = purchases.map(p => {
        const items = p.parsed_items || [];
        const gross = (parseFloat(p.amount)+parseFloat(p.discount_amount||0)).toFixed(2);
        let itemsHtml = '<span style="color:var(--text3)">—</span>';
        if(items.length){
          itemsHtml = items.map(it => {
            const parts = [it.code!=='—'?it.code:'',it.model].filter(Boolean);
            const label = parts.join(' · ') || it.code;
            return `<div>${esc(label)} ×<b>${it.qty}</b> ${parseFloat(it.unit_price).toFixed(2)}€</div>`;
          }).join('');
        }
        return `<tr>
          <td style="font-weight:800;white-space:nowrap">${fmtTime(p.created_at)}</td>
          <td>
            <div style="font-weight:800">${esc((p.customer_name||'').trim()||'—')}</div>
            <div style="font-size:11px;color:var(--text3)">${esc(p.card_number||'—')}</div>
          </td>
          <td style="font-weight:900;white-space:nowrap">
            ${parseFloat(p.amount).toFixed(2)} €
            <div style="font-size:11px;color:var(--text3);text-decoration:line-through">${gross} €</div>
          </td>
          <td style="color:var(--red);font-weight:700;white-space:nowrap">
            ${parseFloat(p.discount_amount||0)>0?'−'+parseFloat(p.discount_amount).toFixed(2)+' €':'—'}
          </td>
          <td><div class="hist-items-mini">${itemsHtml}</div></td>
        </tr>`;
      }).join('');
    }

  } catch(e){
    document.getElementById('histBody').innerHTML =
      '<tr><td colspan="5" style="text-align:center;padding:16px;color:var(--red);font-weight:700">Грешка при зареждане</td></tr>';
  }
  histLoading = false;
}

/* ── Авто-обновяване на историята ── */
loadHistory();
setInterval(()=>{ if(!histLoading) loadHistory(); }, 120000);

/* ══════════════════════════════════════════════════════
   FULLSCREEN ИСТОРИЯ
   ══════════════════════════════════════════════════════ */
function openFullscreen(){
  const overlay = document.getElementById('fsOverlay');
  overlay.classList.remove('hidden');
  renderFullscreen();
}
function closeFullscreen(){
  document.getElementById('fsOverlay').classList.add('hidden');
}
window.closeFullscreen = closeFullscreen;

function renderFullscreen(){
  const body = document.getElementById('fsBody');
  const title = document.getElementById('fsTitle');

  // Вземаме данните от вече заредената история
  const purchases = window._histPurchases || [];
  const dayItems  = window._histDayItems  || [];
  const dayFinal  = window._histDayFinal  || 0;
  const dayDisc   = window._histDayDisc   || 0;
  const dayGross  = window._histDayGross  || 0;
  const discPct   = dayGross > 0 ? ((dayDisc/dayGross)*100).toFixed(1) : '0.0';

  // Форматираме датата за заглавието
  const tabs = document.querySelectorAll('.day-tab');
  let dateLabel = 'Днес';
  tabs.forEach(t => { if(t.classList.contains('active')) dateLabel = t.textContent; });
  title.textContent = '📋 ' + dateLabel + ' — ' + purchases.length + ' продажби';

  if(!purchases.length){
    body.innerHTML = '<div class="fs-no-items">Няма покупки за този ден</div>';
    return;
  }

  let html = '';

  // ── Всяка покупка ──
  purchases.forEach((p, idx) => {
    const time   = p.created_at ? String(p.created_at).slice(11,16) : '—';
    const name   = (p.customer_name||'').trim() || '—';
    const card   = p.card_number || '—';
    const amount = parseFloat(p.amount||0);
    const disc   = parseFloat(p.discount_amount||0);
    const gross  = amount + disc;
    const items  = p.parsed_items || [];

    html += `<div class="fs-sale">
      <div class="fs-sale-head">
        <div>
          <div class="fs-sale-time">${esc(time)}</div>
          <div class="fs-sale-client">${esc(name)} · ${esc(card)}</div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px">
          <div class="fs-sale-amount">${amount.toFixed(2)} лв</div>
          ${disc > 0 ? `<div class="fs-sale-disc">отст. −${disc.toFixed(2)} лв</div>` : ''}
          <button onclick="deletePurchase(${p.id})" style="border:none;background:#ffebee;color:var(--red);border-radius:7px;padding:4px 10px;font-size:12px;font-weight:800;cursor:pointer">✕ Изтрий</button>
        </div>
      </div>`;

    if(items.length){
      items.forEach(it => {
        const parts = [];
        if(it.model) parts.push(it.model);
        const model = parts.join('');
        html += `<div class="fs-item">
          <div>
            <div class="fs-item-left">${it.code && it.code!=='—' ? esc(it.code) : '—'}</div>
            ${model ? `<div class="fs-item-code">${esc(model)}</div>` : ''}
            ${it.disc > 0 ? `<div class="fs-item-disc">−${it.disc}%</div>` : ''}
          </div>
          <div class="fs-item-right">
            <div class="fs-item-qty">× ${it.qty}</div>
            <div class="fs-item-price">${parseFloat(it.unit_price).toFixed(2)} лв/бр</div>
          </div>
        </div>`;
      });
    } else {
      html += `<div style="color:var(--text3);font-size:14px;padding:8px 0;font-weight:700">Без детайли за артикули</div>`;
    }

    html += '</div>';
  });

  // ── Обобщение артикули за деня ──
  if(dayItems.length){
    html += `<div class="fs-summary">
      <div class="fs-sum-title">📦 Всички артикули за деня</div>`;

    dayItems.forEach(item => {
      const base = parseFloat(item.line_base||0);
      html += `<div class="fs-sum-row">
        <div>
          <div class="fs-sum-name">${esc(item.code||'—')}${item.model?' · '+esc(item.model):''}</div>
        </div>
        <div style="text-align:right">
          <div class="fs-sum-qty">× ${item.qty}</div>
          <div class="fs-sum-price">${parseFloat(item.unit_price).toFixed(2)} лв/бр</div>
        </div>
      </div>`;
    });

    html += `<div class="fs-totals">
      <div class="fs-tot-row">
        <span class="fs-tot-label">Общо без отстъпка</span>
        <span class="fs-tot-value">${dayGross.toFixed(2)} лв</span>
      </div>
      <div class="fs-tot-row">
        <span class="fs-tot-label">Реално взето</span>
        <span class="fs-tot-value">${dayFinal.toFixed(2)} лв</span>
      </div>
      <div class="fs-tot-row">
        <span class="fs-tot-label">Обща отстъпка</span>
        <span class="fs-tot-value big">−${dayDisc.toFixed(2)} лв (${discPct}%)</span>
      </div>
    </div></div>`;
  }

  body.innerHTML = html;
}

/* ── Авто-изчистване на резултата след 8 секунди ── */
const resultBanner = document.getElementById('resultBanner');
if(resultBanner){
  setTimeout(()=>{
    resultBanner.style.transition='opacity .8s';
    resultBanner.style.opacity='0';
    setTimeout(()=>resultBanner.remove(), 800);
  }, 8000);
}

/* ── Init калкулатор ── */
syncQtyBtns(); syncDiscBtns();

/* ══ ПЕЧАТ ══ */
function esc2(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') }

function buildPrintHeader(title, subtitle){
  const loc = <?= json_encode($locationName, JSON_UNESCAPED_UNICODE) ?>;
  return `<div class="pr-header">
    <div class="pr-title">Ени Тихолов${loc ? ' — '+esc2(loc) : ''}</div>
    <div class="pr-sub">${esc2(title)}</div>
    ${subtitle ? `<div class="pr-sub">${esc2(subtitle)}</div>` : ''}
  </div>`;
}

// Печат на текущата покупка (от калкулатора)
function printSale(){
  const items = window._calcItems || [];
  if(!items.length){ alert('Няма артикули в покупката.'); return; }

  const base  = items.reduce((s,i)=>s+i.base,  0);
  const final = items.reduce((s,i)=>s+i.final, 0);
  const disc  = base - final;
  const card  = document.getElementById('card_number')?.value?.trim() || '';

  let rows = items.map(i => {
    const name = [i.code, i.brand].filter(Boolean).join(' · ');
    return `<tr>
      <td>${esc2(name)}</td>
      <td class="right">${i.qty}</td>
      <td class="right">${i.price.toFixed(2)} €</td>
      ${i.disc > 0 ? `<td class="right">−${i.disc}%</td>` : '<td></td>'}
      <td class="right">${i.final.toFixed(2)} €</td>
    </tr>`;
  }).join('');

  document.getElementById('printContent').innerHTML = `
    ${buildPrintHeader('Покупка', card ? 'Карта: ' + card : new Date().toLocaleString('bg-BG'))}
    <table class="pr-table">
      <thead><tr><th>Артикул</th><th>Бр.</th><th>Цена</th><th>Отст.</th><th>Сума</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>
    <div class="pr-totals">
      ${disc > 0 ? `<div class="pr-tot-row red"><span>Отстъпка</span><span>−${disc.toFixed(2)} €</span></div>` : ''}
      <div class="pr-tot-row big"><span>ЗА ПЛАЩАНЕ</span><span>${final.toFixed(2)} €</span></div>
    </div>
    <div class="pr-footer">${new Date().toLocaleString('bg-BG')}</div>`;
  window.print();
}

// Печат на историята за деня
function printHistory(){
  if(!window._histPurchases){ alert('Зареди историята първо.'); return; }
  const purchases  = window._histPurchases || [];
  const dayItems   = window._histDayItems  || [];
  const dayFinal   = window._histDayFinal  || 0;
  const dayDisc    = window._histDayDisc   || 0;
  const dayGross   = window._histDayGross  || 0;
  const discPct    = dayGross > 0 ? ((dayDisc/dayGross)*100).toFixed(1) : '0.0';

  let tabs = document.querySelectorAll('.day-tab');
  let dateLabel = 'Днес';
  tabs.forEach(t => { if(t.classList.contains('active')) dateLabel = t.textContent; });

  let aggRows = dayItems.map(item => `<tr>
    <td>${esc2(item.code||'—')}</td>
    <td>${esc2(item.model||'—')}</td>
    <td class="right">${item.qty}</td>
    <td class="right">${parseFloat(item.unit_price||0).toFixed(2)} €</td>
    <td class="right">${parseFloat(item.line_base||0).toFixed(2)} €</td>
  </tr>`).join('');

  let salesHtml = purchases.map(p => {
    const items = p.parsed_items || [];
    let itemRows = items.map(it => {
      const name = [it.code, it.model].filter(b=>b&&b!=='—').join(' · ');
      return `<tr>
        <td>${esc2(name||'—')}</td>
        <td class="right">${it.qty}</td>
        <td class="right">${parseFloat(it.unit_price||0).toFixed(2)} €</td>
        <td></td>
        <td class="right">${(parseFloat(it.unit_price||0)*parseInt(it.qty||1)).toFixed(2)} €</td>
      </tr>`;
    }).join('');
    const amount = parseFloat(p.amount||0);
    const disc   = parseFloat(p.discount_amount||0);
    return `<div class="pr-sale-block">
      <div class="pr-sale-head">
        <span>${esc2(p.created_at?String(p.created_at).slice(11,16):'—')} · ${esc2((p.customer_name||'').trim()||p.card_number||'—')}</span>
        <span>${amount.toFixed(2)} €${disc>0?' (−'+disc.toFixed(2)+' €)':''}</span>
      </div>
      ${itemRows ? `<table class="pr-table">
        <thead><tr><th>Артикул</th><th>Бр.</th><th>Цена</th><th>Отст.</th><th>Сума</th></tr></thead>
        <tbody>${itemRows}</tbody>
      </table>` : ''}
    </div>`;
  }).join('');

  document.getElementById('printContent').innerHTML = `
    ${buildPrintHeader('История за деня', dateLabel + ' · ' + purchases.length + ' продажби')}
    <div style="font-size:11px;font-weight:900;text-transform:uppercase;margin:10px 0 5px">Артикули за деня</div>
    <table class="pr-table">
      <thead><tr><th>Артикул</th><th>Модел</th><th>Бр.</th><th>Цена</th><th>Общо</th></tr></thead>
      <tbody>${aggRows||'<tr><td colspan="5" style="color:#999">Няма данни</td></tr>'}</tbody>
    </table>
    <div class="pr-totals">
      <div class="pr-tot-row"><span>Общо без отстъпка</span><span>${dayGross.toFixed(2)} €</span></div>
      <div class="pr-tot-row red"><span>Обща отстъпка</span><span>−${dayDisc.toFixed(2)} € (${discPct}%)</span></div>
      <div class="pr-tot-row big"><span>РЕАЛНО ВЗЕТО</span><span>${dayFinal.toFixed(2)} €</span></div>
    </div>
    <div style="font-size:11px;font-weight:900;text-transform:uppercase;margin:14px 0 5px;border-top:1px solid #ddd;padding-top:10px">Продажби по час</div>
    ${salesHtml}
    <div class="pr-footer">Ени Тихолов · Отпечатано: ${new Date().toLocaleString('bg-BG')}</div>`;
  window.print();
}
window.printSale    = printSale;
window.printHistory = printHistory;

/* ══ ИЗТРИВАНЕ НА ПОКУПКА ══ */
window.deletePurchase = async function(id) {
  if (!confirm('Сигурен ли си? Покупката ще се изтрие!')) return;
  try {
    const url = new URL(PAGE_URL);
    url.searchParams.set('ajax','delete_purchase');
    const res  = await fetch(url, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id})
    });
    const data = await res.json();
    if (data.ok) {
      closeFullscreen();
      loadHistory();
    } else {
      alert('Грешка: ' + (data.error||'?'));
    }
  } catch(e) { alert('Грешка: ' + e.message); }
};
</script>
</body>
</html>