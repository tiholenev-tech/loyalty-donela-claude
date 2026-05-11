-- ═══════════════════════════════════════════════════════════════════
-- S3 ITEM MEMORY — auto-fill памет за касиерския екран
--
-- Цел: При въвеждане на код 36 → kalkulator знае price=45.00, brand='Adidas'
-- Източник: всички JSON-и в purchase_scans.calc_payload
-- Логика: per-code MOST_FREQUENT price + MOST_FREQUENT brand
-- Tie-breaker: последна употреба (MAX created_at)
-- Idempotent: TRUNCATE + re-populate
-- ═══════════════════════════════════════════════════════════════════

-- ── 1. CREATE TABLE ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS item_memory (
  code VARCHAR(50) NOT NULL PRIMARY KEY,
  price DECIMAL(10,2) DEFAULT NULL,
  brand VARCHAR(100) DEFAULT NULL,
  last_seen DATETIME DEFAULT NULL,
  use_count INT DEFAULT 0,
  INDEX idx_last_seen (last_seen),
  INDEX idx_use_count (use_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT '═══ Table готова. Backfill започва... ═══' AS info;

-- ── 2. TRUNCATE (idempotent) ───────────────────────────────────────
TRUNCATE TABLE item_memory;

-- ── 3. BACKFILL with JSON parsing ──────────────────────────────────
-- Extract всички items от JSON arrays на purchase_scans
-- JSON формати:
--   calc_sales backfill: {code, brand, qty, price, disc, base, final}
--   purchase_scans original: {code, model, qty, price, discount, base, final}
-- За brand: COALESCE(brand, model)

INSERT INTO item_memory (code, price, brand, last_seen, use_count)
WITH all_items AS (
    SELECT 
        jt.code,
        jt.price,
        COALESCE(NULLIF(jt.brand, ''), NULLIF(jt.model, '')) AS brand,
        ps.created_at
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
),
-- Most-frequent price per code
price_ranks AS (
    SELECT 
        code, 
        price,
        ROW_NUMBER() OVER (
            PARTITION BY code 
            ORDER BY COUNT(*) DESC, MAX(created_at) DESC
        ) AS rn
    FROM all_items
    WHERE price > 0
    GROUP BY code, price
),
top_price AS (
    SELECT code, price FROM price_ranks WHERE rn = 1
),
-- Most-frequent brand per code
brand_ranks AS (
    SELECT 
        code, 
        brand,
        ROW_NUMBER() OVER (
            PARTITION BY code 
            ORDER BY COUNT(*) DESC, MAX(created_at) DESC
        ) AS rn
    FROM all_items
    WHERE brand IS NOT NULL AND brand != ''
    GROUP BY code, brand
),
top_brand AS (
    SELECT code, brand FROM brand_ranks WHERE rn = 1
),
-- Aggregate per code (last_seen + use_count)
aggregated AS (
    SELECT 
        code,
        MAX(created_at) AS last_seen,
        COUNT(*) AS use_count
    FROM all_items
    GROUP BY code
)
SELECT 
    a.code,
    tp.price,
    tb.brand,
    a.last_seen,
    a.use_count
FROM aggregated a
LEFT JOIN top_price tp ON tp.code = a.code
LEFT JOIN top_brand tb ON tb.code = a.code
ORDER BY a.use_count DESC;

-- ── 4. RESULTS ────────────────────────────────────────────────────
SELECT '═══ Общо записи в item_memory ═══' AS info;
SELECT COUNT(*) AS total_items FROM item_memory;

SELECT '═══ TOP 20 най-продавани кодове (по use_count) ═══' AS info;
SELECT 
    code,
    price,
    brand,
    use_count,
    DATE(last_seen) AS last_seen_date
FROM item_memory
ORDER BY use_count DESC
LIMIT 20;

SELECT '═══ Колко items имат brand? ═══' AS info;
SELECT 
    SUM(CASE WHEN brand IS NOT NULL AND brand != '' THEN 1 ELSE 0 END) AS with_brand,
    SUM(CASE WHEN brand IS NULL OR brand = '' THEN 1 ELSE 0 END) AS without_brand
FROM item_memory;

SELECT '═══ Brand distribution (TOP 10) ═══' AS info;
SELECT 
    COALESCE(NULLIF(brand, ''), '(нямa)') AS brand,
    COUNT(*) AS items_with_this_brand
FROM item_memory
GROUP BY brand
ORDER BY items_with_this_brand DESC
LIMIT 10;
