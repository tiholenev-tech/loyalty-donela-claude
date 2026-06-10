-- ════════════════════════════════════════════════════════════════
-- Складово салдо по обект и месец (в продажни цени)
-- Ползва се от админ панела (таб „Салдо").
-- Реален оборот и отстъпки идват автоматично от purchase_scans;
-- тук се пазят само ръчните пера за всеки (обект, месец).
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS inventory_balance (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  location_id     INT            NOT NULL DEFAULT 0,
  period          CHAR(7)        NOT NULL,                 -- 'YYYY-MM'
  opening_balance DECIMAL(12,2)  NOT NULL DEFAULT 0,       -- начално салдо (валидно само ако opening_manual=1)
  opening_manual  TINYINT(1)     NOT NULL DEFAULT 0,       -- 1 = ръчно зададено начално; 0 = авто-пренос от мин. месец
  goods_received  DECIMAL(12,2)  NOT NULL DEFAULT 0,       -- получена стока за месеца (+)
  markup_total    DECIMAL(12,2)  NOT NULL DEFAULT 0,       -- увеличение на цени (+)
  markdown_total  DECIMAL(12,2)  NOT NULL DEFAULT 0,       -- намаление на цени (−)
  transfer_in     DECIMAL(12,2)  NOT NULL DEFAULT 0,       -- прехвърлена стока ВХОД от друг обект (+)
  transfer_out    DECIMAL(12,2)  NOT NULL DEFAULT 0,       -- прехвърлена стока ИЗХОД към друг обект (−)
  note            VARCHAR(255)   NULL,
  updated_at      DATETIME       NULL,
  UNIQUE KEY uniq_loc_period (location_id, period)
);
