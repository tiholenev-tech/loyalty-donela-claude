-- ════════════════════════════════════════════════════════════════
-- Списък с марки/производители (за падащото меню в калкулатора)
-- Дотук менюто беше твърдо изписано в кода; вече е динамично от тук.
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS brands (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(80) NOT NULL,
  created_at DATETIME NULL DEFAULT NULL,
  UNIQUE KEY uniq_name (name)
);

-- Seed: досегашният твърд списък
INSERT IGNORE INTO brands (name, created_at) VALUES
  ('Статера', NOW()), ('Лорд', NOW()), ('Спико', NOW()), ('Дафи', NOW()),
  ('Ареал', NOW()), ('DX', NOW()), ('Ивон', NOW()), ('Иватакс', NOW()),
  ('Петков', NOW()), ('Роял Тайгър', NOW()), ('Китайско', NOW()),
  ('Чорапи', NOW()), ('Чорапогащи', NOW());

-- Seed: всички марки, които вече са ползвани в историята
INSERT IGNORE INTO brands (name, created_at)
SELECT DISTINCT TRIM(brand), NOW()
FROM item_variants
WHERE brand IS NOT NULL AND TRIM(brand) <> '';
