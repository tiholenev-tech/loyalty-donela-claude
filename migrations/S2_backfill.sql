-- ═══════════════════════════════════════════════════════════════════
-- S2 BACKFILL — calc_sales → purchase_scans
-- 
-- Цел: 1367 загубени продажби (24.04 → 11.05) да влязат в purchase_scans
-- Безопасност: idempotent (повторно пускане → 0 dupлikата)
-- Verified чрез S1_discovery.sql:
--   - 0 records ПРЕДИ 23.04 18:18 без match (double-write работеше)
--   - 0 records СЛЕД 23.04 18:18 вече в purchase_scans (нула dup risk)
-- ═══════════════════════════════════════════════════════════════════

SELECT '═══ COUNT преди INSERT ═══' AS info;
SELECT COUNT(*) AS purchase_scans_before FROM purchase_scans;

-- ═══════════════════════════════════════════════════════════════════
-- ИНСЕРТ
-- ═══════════════════════════════════════════════════════════════════
INSERT INTO purchase_scans (
    customer_id, has_card, store_id, amount, discount_amount,
    discount_label, multiplier, awarded_purchases, created_at,
    location_id, location_name, calc_payload, payment_method
)
SELECT
    NULL                AS customer_id,
    0                   AS has_card,
    NULL                AS store_id,
    cs.final_total      AS amount,
    cs.discount         AS discount_amount,
    NULL                AS discount_label,
    1                   AS multiplier,
    0                   AS awarded_purchases,
    cs.created_at       AS created_at,
    cs.location_id      AS location_id,
    cs.location_name    AS location_name,
    cs.items_json       AS calc_payload,
    'cash'              AS payment_method
FROM calc_sales cs
WHERE cs.created_at > '2026-04-23 18:18:32'
  AND NOT EXISTS (
    SELECT 1 FROM purchase_scans ps
    WHERE ABS(TIMESTAMPDIFF(SECOND, ps.created_at, cs.created_at)) <= 2
      AND ABS(ps.amount - cs.final_total) < 0.01
      AND ps.has_card = 0
  );

SELECT '═══ Резултат ═══' AS info;
SELECT ROW_COUNT() AS rows_inserted;

SELECT '═══ COUNT след INSERT ═══' AS info;
SELECT COUNT(*) AS purchase_scans_after FROM purchase_scans;

SELECT '═══ Verification: общата сума на новите records (трябва ~19833.46) ═══' AS info;
SELECT 
    COUNT(*) AS no_card_records_since_24_04,
    SUM(amount) AS total_eur_since_24_04
FROM purchase_scans
WHERE has_card = 0 
  AND created_at > '2026-04-23 18:18:32';

SELECT '═══ Per-day проверка (calc_sales vs purchase_scans no_card след backfill) ═══' AS info;
SELECT 
    DATE(cs.created_at) AS d,
    COUNT(DISTINCT cs.id) AS calc_cnt,
    (SELECT COUNT(*) FROM purchase_scans ps 
     WHERE DATE(ps.created_at)=DATE(cs.created_at) AND ps.has_card=0) AS ps_no_card_cnt
FROM calc_sales cs
WHERE cs.created_at >= '2026-04-20'
GROUP BY DATE(cs.created_at)
ORDER BY d;
