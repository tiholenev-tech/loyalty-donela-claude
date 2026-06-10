-- ════════════════════════════════════════════════════════════════
-- Регистрация: в кой магазин е направена лоялната карта
-- Добавя reg_location_id / reg_location_name към customers.
-- Idempotent (MySQL 8 — през information_schema).
-- ════════════════════════════════════════════════════════════════
SET @c := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='reg_location_id');
SET @s := IF(@c=0,'ALTER TABLE customers ADD COLUMN reg_location_id INT NULL DEFAULT NULL',
  'SELECT "customers.reg_location_id вече го има" AS info');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='reg_location_name');
SET @s := IF(@c=0,'ALTER TABLE customers ADD COLUMN reg_location_name VARCHAR(100) NULL DEFAULT NULL',
  'SELECT "customers.reg_location_name вече го има" AS info');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SELECT column_name FROM information_schema.columns
WHERE table_schema=DATABASE() AND table_name='customers'
  AND column_name IN ('reg_location_id','reg_location_name');
