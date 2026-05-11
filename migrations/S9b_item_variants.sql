-- ═══════════════════════════════════════════════════════════════════
-- S9b ITEM VARIANTS — multi-variant auto-fill памет
--
-- Цел: ако код 119 е продаван като Дафи 3.80€ И като Статера 4.50€
-- → касиерката избира кой вариант да попълни
--
-- Източник: всички JSON-и в purchase_scans.calc_payload
-- Логика: уникални комбинации (code, brand, price) с use_count
-- Idempotent: TRUNCATE + re-populate
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS item_variants (
  code VARCHAR(50) NOT NULL,
  brand VARCHAR(100) NOT NULL DEFAULT '',
  price DECIMAL(10,2) NOT NULL,
  use_count INT NOT NULL DEFAULT 1,
  last_seen DATETIME DEFAULT NULL,
  PRIMARY KEY (code, brand, price),
  INDEX idx_code (code),
  INDEX idx_use_count (use_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT '═══ item_variants готова. Backfill започва... ═══' AS info;

TRUNCATE TABLE item_variants;

INSERT INTO item_variants (code, brand, price, use_count, last_seen)
SELECT 
    jt.code,
    COALESCE(NULLIF(jt.brand, ''), NULLIF(jt.model, ''), '') AS brand,
    jt.price,
    COUNT(*) AS use_count,
    MAX(ps.created_at) AS last_seen
FROM purchase_scans ps
CROSS JOIN JSON_TABLE(
    ps.calc_payload,
    '$[*]' COLUMNS(
        code VARCHAR(50) PATH '$.code',
        price DECIMAL(10,2) PATH '$.price',
        brand VARCHAR(100) PATH '$.brand',
        model VARCHAR(100) PATH '$.model'
    )
) jt
WHERE ps.calc_payload IS NOT NULL
  AND ps.calc_payload != ''
  AND ps.deleted_at IS NULL
  AND JSON_VALID(ps.calc_payload)
  AND jt.code IS NOT NULL
  AND jt.code != ''
  AND jt.price > 0
GROUP BY jt.code, COALESCE(NULLIF(jt.brand, ''), NULLIF(jt.model, ''), ''), jt.price;

-- ── RESULTS ────────────────────────────────────────────────────
SELECT '═══ Общо записи (уникални code+brand+price комбинации) ═══' AS info;
SELECT COUNT(*) AS total_variants FROM item_variants;

SELECT '═══ TOP 10 кодове с НАЙ-МНОГО варианти (multi-brand или multi-price) ═══' AS info;
SELECT 
    code,
    COUNT(*) AS variant_count,
    GROUP_CONCAT(CONCAT(IF(brand='','(без)',brand), ' ', price, '€') ORDER BY use_count DESC SEPARATOR ' | ') AS variants
FROM item_variants
GROUP BY code
HAVING variant_count > 1
ORDER BY variant_count DESC
LIMIT 10;

SELECT '═══ Проверка код 119 (Тиховият пример) ═══' AS info;
SELECT brand, price, use_count, last_seen
FROM item_variants
WHERE code = '119'
ORDER BY use_count DESC;

SELECT '═══ Колко кодове имат >1 вариант ═══' AS info;
SELECT 
    SUM(CASE WHEN cnt = 1 THEN 1 ELSE 0 END) AS single_variant_codes,
    SUM(CASE WHEN cnt > 1 THEN 1 ELSE 0 END) AS multi_variant_codes
FROM (SELECT code, COUNT(*) AS cnt FROM item_variants GROUP BY code) sub;
