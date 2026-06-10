-- ════════════════════════════════════════════════════════════════
-- FIX: липсваща колона `deleted_at` (soft delete)
-- ════════════════════════════════════════════════════════════════
-- Симптом: админ панелът (Табло/Статистики) гърми с
--   SQLSTATE[42S22]: Unknown column 'deleted_at' in 'where clause'
-- Причина: целият код (kalkulator.php, papu4koo82.php, eni_check.php,
--   миграции S3/S9b) филтрира `WHERE deleted_at IS NULL`, но колоната
--   липсва на продукшън → всяка заявка се проваля.
--
-- Скриптът е IDEMPOTENT — безопасен е да се пусне няколко пъти.
-- Неразрушителен: само ADD COLUMN (nullable, default NULL) → всички
-- съществуващи редове остават "неизтрити" (коректно).
-- ════════════════════════════════════════════════════════════════

-- ── purchase_scans.deleted_at ──
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name   = 'purchase_scans'
             AND column_name  = 'deleted_at');
SET @s := IF(@c = 0,
  'ALTER TABLE purchase_scans ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL',
  'SELECT "purchase_scans.deleted_at вече съществува — пропуснато" AS info');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── customers.deleted_at (stats_all също го ползва: SELECT ... WHERE deleted_at IS NULL) ──
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name   = 'customers'
             AND column_name  = 'deleted_at');
SET @s := IF(@c = 0,
  'ALTER TABLE customers ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL',
  'SELECT "customers.deleted_at вече съществува — пропуснато" AS info');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Контрол: покажи че колоните вече ги има
SELECT table_name, column_name, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND column_name = 'deleted_at'
  AND table_name IN ('purchase_scans','customers');
