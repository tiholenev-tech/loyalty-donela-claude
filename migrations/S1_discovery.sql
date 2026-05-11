-- ═══════════════════════════════════════════════════════════════════
-- S1 DISCOVERY — преди да правим S2 backfill
-- Цел: разбираме структурата на calc_sales за безопасен mapping
-- Безопасно: само SELECT-и, нула write операции
-- ═══════════════════════════════════════════════════════════════════

SELECT '═══ 1. DESCRIBE calc_sales ═══' AS info;
DESCRIBE calc_sales;

SELECT '═══ 2. DESCRIBE purchase_scans ═══' AS info;
DESCRIBE purchase_scans;

SELECT '═══ 3. Sample 1 calc_sales (latest) — vertical ═══' AS info;
SELECT * FROM calc_sales ORDER BY created_at DESC LIMIT 1 \G

SELECT '═══ 4. Sample 1 purchase_scans WHERE has_card=0 (latest) — vertical ═══' AS info;
SELECT * FROM purchase_scans WHERE has_card=0 ORDER BY created_at DESC LIMIT 1 \G

SELECT '═══ 5. DRY RUN — колко записа ще INSERT-нем (по date filter) ═══' AS info;
SELECT 
  COUNT(*) AS will_insert,
  MIN(created_at) AS first_record,
  MAX(created_at) AS last_record,
  SUM(final_total) AS total_eur
FROM calc_sales
WHERE created_at > '2026-04-23 18:18:32';

SELECT '═══ 6. Sanity ПРЕДИ 23.04 18:18 — calc_sales които НЕ са в purchase_scans ═══' AS info;
SELECT 
  COUNT(*) AS gap_before_date,
  MIN(created_at) AS earliest_gap,
  MAX(created_at) AS latest_gap
FROM calc_sales cs
WHERE cs.created_at <= '2026-04-23 18:18:32'
  AND NOT EXISTS (
    SELECT 1 FROM purchase_scans ps
    WHERE ABS(TIMESTAMPDIFF(SECOND, ps.created_at, cs.created_at)) <= 2
      AND ABS(ps.amount - cs.final_total) < 0.01
      AND ps.has_card = 0
  );

SELECT '═══ 7. Sanity СЛЕД 23.04 18:18 — calc_sales които вече са в purchase_scans (duplicate risk) ═══' AS info;
SELECT 
  COUNT(*) AS already_exist,
  MIN(cs.created_at) AS earliest,
  MAX(cs.created_at) AS latest
FROM calc_sales cs
WHERE cs.created_at > '2026-04-23 18:18:32'
  AND EXISTS (
    SELECT 1 FROM purchase_scans ps
    WHERE ABS(TIMESTAMPDIFF(SECOND, ps.created_at, cs.created_at)) <= 2
      AND ABS(ps.amount - cs.final_total) < 0.01
      AND ps.has_card = 0
  );

SELECT '═══ 8. items_json sample — да видим JSON структурата ═══' AS info;
SELECT 
  id,
  created_at,
  final_total,
  LEFT(items_json, 500) AS items_json_preview
FROM calc_sales
WHERE items_json IS NOT NULL AND items_json != ''
ORDER BY created_at DESC
LIMIT 2;

SELECT '═══ 9. calc_payload sample от purchase_scans (структура) ═══' AS info;
SELECT 
  id,
  created_at,
  amount,
  LEFT(calc_payload, 500) AS payload_preview
FROM purchase_scans
WHERE has_card = 0 
  AND calc_payload IS NOT NULL
ORDER BY created_at DESC
LIMIT 2;
