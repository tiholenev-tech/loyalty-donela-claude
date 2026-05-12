# 📘 LOYALTY DONELA — БИБЛИЯ
**Проект:** Лоялна програма Ени Тихолов
**URL:** https://loyalty.donela.bg
**Собственик:** Тихол (за магазин на жена му Ени)
**Версия:** 2.0
**Последно обновяване:** 12.05.2026

> 📋 **За нов чат:** прикачи този файл + кажи „Продължаваме loyalty.donela.bg. Прочети BIBLE-та, после [задача]."

---

## § A — ЦЕЛ И КОНТЕКСТ

### A.1 Какво е това
Малка дигитална loyalty програма за **един магазин** (магазинът на Ени Тихолов в Плевен). Клиентите получават карта (физическа или дигитална), с QR код. При покупка касиерът сканира картата → продажбата се записва → клиентът получава точки и автоматични ваучери при milestone-и.

### A.2 Тон и философия
- **Не е RunMyStore** — макар Тихол да го мигрира в RunMyStore като tenant #47 през септември 2026
- **Безплатна простота** за магазина — без AI, само PHP+MySQL, никакви външни услуги
- **Прозрачност за клиента** — той винаги вижда колко е похарчил, колко му трябва за следваща награда, какви ваучери има
- **Без претрупване** — Ени не е технически човек, всичко в admin панела трябва да е ясно от пръв поглед

### A.3 Кой е Ени
- Собственичка на 1 магазин (засега) в Плевен
- Не е developer
- Иска **бързо да види оборота** на магазина, кой са топ клиентите, и кой не идва

### A.4 Кой е Тихол
- Съпруг на Ени, разработчик на проекта
- Не пише код директно — пуска команди в droplet console
- Пие вино вечер 🍷, иска кратки ясни инструкции
- Не обича да го питат „готов ли си" / „дали искаш" — иска изпълнение, не колаборация
- Сигнал за раздразнение: „ти луд ли си", „много ме дразниш", all-caps

---

## § B — ИНФРАСТРУКТУРА

### B.1 Сървър (DigitalOcean)
- **Droplet:** `runmystore` (споделен с другия проект — RunMyStore)
- **IP:** `164.90.217.120`
- **OS:** Ubuntu 24
- **SSH:** `ssh root@164.90.217.120` (паролата я знае Тихол)

### B.2 Файлова система
**Корен на loyalty:** `/var/www/donela.bg/public_html/loyalty/`

**Apache vhost:** `/etc/apache2/sites-available/loyalty.donela.bg.conf` — SSL активен.

### B.3 База данни (MySQL 8)
- **Host:** `localhost`
- **DB:** `doney5ne_loyalty`
- **User:** `doney5ne_loyalty_user`
- **Pass:** `0okm9ijnsklad`
- Connection: `/var/www/donela.bg/public_html/loyalty/config.php`

### B.4 GIT (от 11.05.2026)
- **Repository:** `github.com/tiholenev-tech/loyalty-donela-claude` (private)
- **Branch:** `main`
- **Workflow:** Claude push → Тих pull в droplet
- **PAT:** в memories на Claude
- **Backup tags:** `pre-SXX-name` за всеки голям етап → rollback с `git reset --hard <tag>`

### B.5 Достъп от Claude
- **GitHub API + git push** — основен метод за deployment
- **bash_tool** — само за DB queries и проверки
- Тих пастира 1 ред в droplet за всеки push: `cd /var/www/donela.bg/public_html/loyalty && git fetch origin && git reset --hard origin/main && php -l kalkulator.php`

### B.6 Контакт телефон (за клиенти)
**+359 898 697 197** (Тихол) — `tel:+359898697197` и `sms:+359898697197` в card.php

---

## § C — DATABASE SCHEMA (текуща, реална)

### C.1 customers
- `id` (PK), `first_name`, `last_name`, `phone`
- `birth_date` (DATE), `total_spent`, `total_purchases`
- `suspicious`, `ref_code`, `referred_by`, `created_at`

### C.2 loyalty_cards
- `id`, `customer_id` (FK), `card_number` (формат `ETXXXXXX`)

### C.3 purchase_scans (главна за продажби)
- `id`, `customer_id` (NULL ако без карта)
- `has_card` (0/1), `store_id`, `location_id`, `location_name`
- `amount` (НЕГАТИВНО позволено за връщания), `discount_amount`, `discount_label`
- `multiplier`, `awarded_purchases`
- `payment_method`, `given_amount`, `change_amount`
- `calc_payload` (MEDIUMTEXT JSON: `[{code,brand,qty,price,disc,base,final}]`)
- `deleted_at`, `edited_at`, `edited_by`, `created_at`
- **UNIQUE:** `(customer_id, created_at, amount)` срещу double-click
- **Текущи:** ~3400 продажби

### C.4 vouchers
- `id`, `customer_id`, `code`, `voucher_type` ('fixed'/'percent')
- `amount` или `percent_value`, `min_spent`, `status`
- `source` ('welcome'/'birthday'/'milestone'/'manual')
- `expires_at`, `issued_at`, `redeemed_at`, `created_at`

### C.5 item_memory (S3 — нов 11.05.2026)
- `code` (PK VARCHAR(50)), `price`, `brand`, `use_count`, `last_seen`
- За auto-fill: най-често използвана цена + brand за всеки код
- Backfill при S3: **842 уникални кодове** от purchase_scans история
- TOP код: 11685 (Дафи 1.99€, 427 продажби)

### C.6 item_variants (S9b — нов 11.05.2026)
- `code` (PK), `brand` (PK), `price` (PK), `use_count`, `last_seen`
- За multi-variant picker: ако код има няколко варианта (различни brand+price)
- Пример: код 119 → Дафи 1.80, Статера 2.20

### C.7 app_pins (създадена но НЕ се ползва за момента)
- Schema готова за hash-based PIN, но за обобщение/печат се ползва hardcoded 7878 (по решение на Тихол)

### C.8 Други таблици
- `locations`, `stores`, `push_subscriptions`, `banners`
- `loyalty_audit_log`, `reward_rules`, `loyalty_promotions`, `staff_accounts`
- `calc_sales` — LEGACY, не пиши (мигрирана в purchase_scans)

---

## § D — ФАЙЛОВЕ (роли)

### D.1 Главни PHP
| Файл | Роля |
|---|---|
| `papu4koo82.php` | **Admin панел** (admin/0okm9ijnsklad) |
| `card.php` | **Клиентска карта** (Neon Glass) |
| `kalkulator.php` | **Касиер екран** — custom numpad, parking, print, auto-fill, multi-variant picker |
| `lookup_code.php` | **AJAX endpoint** GET ?code=XXX → {ok,variants[]} (S4) |
| `register.php` | Регистрация → welcome ваучер |
| `find_card.php` | Търсене по телефон |
| `my-vouchers.php` | Списък ваучери |
| `index.php`, `manifest.php`, `sw.js` | Landing/PWA |
| `scan.php` | СТАР касиер екран (планира redirect) |

### D.2 Cron
| Файл | Когато |
|---|---|
| `cron_birthday.php` | 8:00 — рожденици → 20% ваучер за 14д |
| `cron_push_weekly.php` | Седмични push |

### D.3 Git workflow
Всеки commit прави Claude, force push разрешен (при rollback). Backup НЕ е `.bak_*` файлове — а **git tags** (`pre-S5-rewrite`, `pre-S6-keypad-redesign`, `pre-S6v2-overlay`).

---

## § E — ИСТОРИЯ НА СЕСИИТЕ

### E.1 Сесии 23-27.04.2026 (старите)
- Auth guard на papu4koo82.php (login + rate limit)
- Миграция calc_sales → purchase_scans (1589 записа)
- Soft delete, audit log, edit/delete на продажби
- card.php Neon Glass redesign
- cron_birthday.php + cron_push_weekly.php
- UX fixes: камера не авто-стартира, 2-ри save бутон, welcome ваучер fix

### E.2 Сесия 11-12.05.2026 (нощна — MEGA SESSION)

#### Git setup
- Тих създаде GitHub repo `tiholenev-tech/loyalty-donela-claude`
- Claude клонира + first commit (763 файла, 24MB)

#### S1-S5 Backend rewrite
- **S1 backup**: mysqldump → `/root/loyalty_pre_S5_20260511_2120.sql`
- **S2 backfill**: 1367 records calc_sales → purchase_scans (€19,833.46 възстановени) — idempotent INSERT NOT EXISTS check
- **S3 item_memory**: 842 уникални кодове backfill-нати
- **S4 lookup_code.php**: GET endpoint за auto-fill (variants[] sorted by use_count)
- **S5 kalkulator save rewrite**: пише в purchase_scans (не calc_sales), upsert в item_memory + item_variants, transaction

#### S9 + S9b Auto-fill памет
- **S9**: 3 trigger-а (blur, Enter, debounced input 800ms)
- **S9b multi-variant picker**: amber pill list за код с няколко варианта (Дафи/Статера)
- **S9b mobile fix**: pointerdown + preventDefault на mousedown/touchstart (за blur prevention)
- Movement detection: pick само ако пръстът не се е движил >8px (позволи скрол)

#### S6 Custom keypad (5+ опита)
След много отказани варианти (inline → overlay → various designs):
- **Final**: STICKY BOTTOM numpad от sale.php pattern (RunMyStore reference)
- 4×5 grid: 1-9, 0, ., ⌫, C, ЦЕНА, КОД, ПАРКИРАЙ, + Добави (span 2), Приключи (span 2)
- **Collapse**: FAB бутон долу-вдясно когато затворен, × close в numpad-а
- **Auto-open**: тап на codeInput/priceInput → отваря keypad
- ЦЕНА блокирана докато няма КОД
- Lookup на debounce 800ms (auto-fill при пауза)

#### S6 Layout + UX fixes
- DOM swap: items list НАГОРЕ (преди cardSection) — касиерката вижда какво добавя
- Camera × close + auto-close 1s след scan
- Карта pill в topbar + cardSection скрита по default (max-height:0 → .open)
- Numpad -20% (по-малки бутони, повече място)
- Код пълна ширина горе, голям шрифт 24px

#### S6 Parking
- localStorage `loyalty_parked_LOCID` array
- Parked bar над numpad-а с count
- Accordion list с "Зареди / × Изтрий"
- При "Зареди" → auto-park текуща + scroll до върха + close keypad

#### S6 Confirmation modal
- При "Приключи" → dark modal с голяма зелена сума
- 3 бутона: Не / **Печат** / Да
- Печат отваря нов прозорец със разписка → auto-print
- Format: ДОНЕЛА header, локация, дата, клиент, артикули, общо
- Поддържа минус артикули (символ ↺)
- Internal: string concat вместо template literal (избягва counter-script tag bug)

#### S6 PIN (hardcoded 7878)
- Защита САМО на: "Виж обобщението за преписване" + "Печат на история"
- 4 точки + 3×4 numpad
- **Без session cache** — пита всеки път (по решение на Тихол)
- НЕ за приложението (има си телефонен PIN)

#### Bug fixes
- **CRITICAL**: минус артикули в историята показваха се като +
  - Cause: `max(1, qty)` в history endpoint → принуждаваше qty≥1
  - Fix: `if($qty === 0) $qty = 1;` — позволи негативни

---

## § F — ВАУЧЕР СИСТЕМА

### F.1 Welcome
5% percent ваучер, 30 дни, `source='welcome'`, code `WELCOME5-{id}`

### F.2 Birthday (cron 8:00)
- Рожденици СЕГА + 7 дни напред
- 20% ваучер, 14 дни валидност
- `source='birthday'`, code `BD-{id}-{YYYY}` (unique по година)
- Push notification

### F.3 Milestone
Автоматично в reward_rules (10/50/100 покупки → bonus)

### F.4 Validity check (kalkulator)
```sql
WHERE customer_id = :cid
  AND status = 'active'
  AND (used IS NULL OR used = 0)
  AND (expires_at IS NULL OR expires_at > NOW())
```

---

## § G — ДИЗАЙН СИСТЕМА

### G.1 card.php (Neon Glass)
- **Standart:** chat.php от RunMyStore
- Tokens: `--hue1:255, --hue2:222, --radius:22px`
- Body dark `#08090d` + 2 radial glow + noise overlay
- Glass card: 3-layer linear gradient + backdrop-filter blur(12px)
- 4-span pattern: shine, shine-bottom, glow, glow-bottom
- 6Q hue variants (q1 red ... q6 blue)
- Шрифт: Montserrat 400-900

### G.2 papu4koo82.php (admin)
- Главно: Flat бяла тема (наследство)
- Само Статистики таб — Neon Glass override

### G.3 kalkulator.php (касиер) — UPDATED 12.05
- **Theme:** Neon Glass dark с indigo `#6366f1` accent
- **Layout reorder**: items list ГОРЕ, после card section, после форма, sticky numpad ДОЛУ
- **Sticky numpad**: dark glass, collapse FAB, sale.php pattern
- **Confirmation modal**: dark с голяма зелена сума, 3 бутона
- **PIN overlay**: dark с indigo gradient icon
- Code field: 24px monospace, центриран, пълна ширина
- Mobile-first 375px

### G.4 register.php
Glass дизайн със светли тонове (pastel radial gradients)

---

## § H — ПРАВИЛА ЗА CLAUDE

### H.1 Език
- **Само български**, кратко, директно
- All-caps от Тихол = акцент или раздразнение
- Не питай „готов ли си" / „дали искаш"
- Не давай 3 варианта — избери сам

### H.2 Решения
**Claude сам:** имена на скриптове, инструменти (sed/python), ред на операции, методи

**Питай Тихол:** UX (какво вижда), текстове в UI, цели на features

### H.3 Дизайн закон
- НЕ ПИТАЙ за дизайн промяна
- Чети Neon Glass от chat.php (RunMyStore)
- Прилагай 1:1: shine, glow, glass, conic, noise mask
- Промяна САМО на CSS/HTML wrappers
- НЕ пипа PHP logic, JS handlers

### H.4 Безопасност
- **Винаги git tag ПРЕДИ** голяма промяна (`pre-SXX-name`)
- **PHP lint после** (`php -l file.php`)
- **JS validation**: внимавай с template literal-и съдържащи `</script>` — използвай string concat + `'<scr'+'ipt>'` split
- Никога рискови команди без потвърждение
- При DB промяна — first SELECT preview

### H.5 Deploy метод (НОВ workflow 11.05.2026)
1. Claude прави промени в локалния клон
2. `git add` + `git commit` + `git push origin main --force` (при rewrite)
3. Тих пастира в droplet: `git fetch origin && git reset --hard origin/main && php -l file.php`
4. Hard refresh на телефона (incognito tab най-сигурен)
5. Тих тества → ако счупено: `git reset --hard <pre-SXX-tag> && git push origin main --force`

### H.6 Критика
60% положително + 40% критика — скрити рискове, edge cases, false positives.

### H.7 ЗАБРАНЕНО
- ❌ Промяна на logic при дизайн заявка
- ❌ Sed/awk за multi-line PHP/JS — само Python скриптове или str_replace
- ❌ Force push БЕЗ предходен backup tag
- ❌ Template literal-и съдържащи `</script>` (parser error)
- ❌ Coordinated промяна на много feature-и в 1 commit (риск от cascade failure)
- ❌ Hardcoded „лв"/„BGN" — винаги €
- ❌ Auto-fill при всеки input event ако кодовете са с десетична част (10520, 10520.1) — flash

---

## § I — ИЗВЕСТНИ БЪГОВЕ

### I.1 Решени (11-12.05.2026)
- ✅ 1367 загубени продажби в calc_sales — backfill в purchase_scans
- ✅ Auto-fill памет за артикули — item_memory + item_variants
- ✅ Multi-variant picker мобилен — pointerdown + movement detection
- ✅ Минус артикули в историята показваха се като + — fix `max(1,qty)`
- ✅ Native клавиатура излизаше — readonly + inputmode='none' + custom numpad
- ✅ Numpad покриваше всичко — collapse FAB + auto padding-bottom

### I.2 Решени (старите)
- ✅ byLocation showed 10% — fixed S1
- ✅ Камера авто-стартираше — fixed S4
- ✅ Welcome ваучер не записваше source/expires — fixed S4
- ✅ Save бутон скрит под скрол — fixed S4

### I.3 Предстоящи
- find_card.php — rewrite (P1)
- scan.php → 302 redirect към kalkulator (P1)
- Customer detail модал — deleted/edited маркировки (P2)
- OCR invoice scanning — отказан (P3)

### I.4 Технически дълг
- Vouchers `used` И `status='used'` — двойна истина
- calc_sales мъртва, оставена за архив
- DB credentials в config.php — не са в .env
- app_pins таблица създадена но не се ползва (текущо hardcoded 7878)

---

## § J — ROADMAP

### J.1 Малки (1-2 сесии)
- [ ] Топ артикули в Статистики (от calc_payload JSON)
- [ ] Customer Detail редизайн в admin
- [ ] find_card.php rewrite
- [ ] scan.php → 302 redirect
- [ ] cron_expire.php — маркира изтекли ваучери 3:00 AM
- [ ] Heatmap по час/ден от седмицата

### J.2 Средни (3-5 сесии)
- [ ] Refactor papu4koo82.php (130KB+) на includes/
- [ ] Birthday ваучер UI настройки (% и дни в admin)
- [ ] Loyalty промоции UI
- [ ] Multi-store support
- [ ] **Печат на single продажба** от историята (back-dated receipt)

### J.3 Голяма
- [ ] Миграция в RunMyStore (септември 2026) — Loyalty става tenant #47

---

## § K — БЪРЗИ КОМАНДИ

### K.1 DB достъп
```bash
mysql -u doney5ne_loyalty_user -p'0okm9ijnsklad' doney5ne_loyalty
```

### K.2 Backup на цялата база
```bash
mysqldump -u doney5ne_loyalty_user -p'0okm9ijnsklad' doney5ne_loyalty > /root/loyalty_backup_$(date +%Y%m%d).sql
```

### K.3 PHP lint
```bash
php -l /var/www/donela.bg/public_html/loyalty/файл.php
```

### K.4 GIT WORKFLOW (НОВ)
```bash
# Pull последна версия от GitHub:
cd /var/www/donela.bg/public_html/loyalty && git fetch origin && git reset --hard origin/main && php -l kalkulator.php

# Проверка кой commit е на droplet-а:
git log -1 --oneline

# Rollback към стабилно (backup tag):
git reset --hard pre-S6v2-overlay && git push origin main --force
```

### K.5 Crontab проверка
```bash
crontab -l | grep -E "birthday|push|expire"
```

### K.6 Apache restart (рядко)
```bash
systemctl reload apache2
```

---

## § L — ТЕХНИЧЕСКА АРХИТЕКТУРА (kalkulator.php) — НОВ

### L.1 Frontend архитектура

#### Custom Numpad
```
┌────┬────┬────┬────┐
│ 1  │ 2  │ 3  │ ⌫ │
├────┼────┼────┼────┤
│ 4  │ 5  │ 6  │ЦЕНА│
├────┼────┼────┼────┤
│ 7  │ 8  │ 9  │КОД │
├────┼────┼────┼────┤
│ .  │ 0  │ C  │ПАРК│
├────┴────┼────┴────┤
│ + Добави│Приключи │
└─────────┴─────────┘
```
- Position: fixed bottom, z-index 500
- Collapse: translateY(100%) → translateY(0)
- FAB toggle: position fixed bottom-right, z-index 600
- Body `padding-bottom: 300px` когато отворен (динамично)

#### Active field tracking
```js
let lpCtx = 'code';  // 'code' | 'price'
function lpSetCtx(ctx){
  // не може ЦЕНА без КОД
  if(ctx === 'price' && !codeInput.value.trim()){ ctx = 'code'; flash; vibrate; }
  // визуален hint
  lpBtnCod.classList.toggle('lp-active', ctx === 'code');
  lpBtnCena.classList.toggle('lp-active', ctx === 'price');
}
```

#### Auto-fill (debounce 800ms)
```js
codeInput.addEventListener('input', () => {
  if(_lookupTimer) clearTimeout(_lookupTimer);
  _lookupTimer = setTimeout(() => autoFillFromCode(codeInput.value), 800);
});
```

Endpoint:
```
GET /lookup_code.php?code=XXX
→ {"ok":true, "variants":[{"brand":"","price":1.99,"use_count":427,"last_seen":"..."}]}
```

Logic:
- variants.length === 0 → "Not found" badge
- variants.length === 1 → auto-fill price + brand
- variants.length > 1 → show variant picker (amber pills)

#### Variant picker (mobile fix)
```js
btn.addEventListener('mousedown', e => e.preventDefault());
btn.addEventListener('touchstart', e => e.preventDefault(), {passive: false});
btn.addEventListener('pointerdown', e => { _startX = e.clientX; _startY = e.clientY; });
btn.addEventListener('pointerup', e => {
  const dx = Math.abs(e.clientX - _startX);
  const dy = Math.abs(e.clientY - _startY);
  if(dx > 8 || dy > 8) return; // user scrolled — don't pick
  onPick(e);
});
```

#### Parking (localStorage)
```js
const PARK_KEY = 'loyalty_parked_' + LOCATION_ID;
// entry: { items, customer, customerId, cardNumber, time, total, base, discount }
```

#### Print Receipt (string concat, НЕ template literal)
```js
var html = '<!DOCTYPE html>...'
  + '<scr' + 'ipt>window.onload=function(){setTimeout(function(){window.print();},200);}</scr' + 'ipt>'
  + '</body></html>';
var w = window.open('', '_blank', 'width=380,height=600');
w.document.write(html);
w.document.close();
```
**Защо string concat:** template literal със `</script>` вътре чупи parser-а.

#### PIN overlay (hardcoded 7878)
```js
window.pinRequire = function(callback){
  pinCallback = callback;
  pinCurrent = ''; updateDots(); errEl.style.display = 'none';
  overlay.style.display = 'flex';
};
// при submit: pinCurrent === '7878' → callback() → close overlay
```

### L.2 Backend архитектура

#### Save endpoint (kalkulator.php save action)
```php
// 1. validate items + amount
// 2. INSERT INTO purchase_scans (calc_payload JSON, amount, ...)
// 3. UPSERT INTO item_memory (code, price, brand, use_count++)
// 4. UPSERT INTO item_variants (code+brand+price PRIMARY KEY, use_count++)
// 5. mark voucher used (ако има)
// 6. recompute customer totals
// 7. transaction
```

#### History endpoint
```php
if ($ajax === 'history') {
  // SELECT * FROM purchase_scans WHERE has_card=0 AND deleted_at IS NULL
  // parse calc_payload JSON → items[]
  // НЕ max(1, qty)! → allow negative за връщания
}
```

#### lookup_code.php
```php
SELECT brand, price, use_count, last_seen
  FROM item_variants
  WHERE code = :code
  ORDER BY use_count DESC, last_seen DESC
```

### L.3 Mobile-specific gotchas
- **inputmode='none' + readonly** — единствения reliable начин да блокираш native клавиатура на Capacitor/Chrome
- **touch-action: manipulation** — премахва 300ms tap delay
- **-webkit-tap-highlight-color: transparent** — премахва grey flash
- **pointerdown vs click** — pointerdown trigger-ва immediately, но позволи movement detection за скрол
- **CSS flex order** — не работи надежно в Capacitor WebView; правя реална DOM rearrangement вместо
- **Service Worker cache** — incognito tab най-сигурен за тестване

### L.4 Backup tags (12.05.2026)
- `pre-S5-rewrite` — преди backend rewrite
- `pre-S6-keypad-redesign` — преди първи keypad опит
- `pre-S6v2-overlay` — стабилно state с auto-fill + multi-variant

---

## § M — НА НОВ ЧАТ

В новия чат Claude да попита **САМО**:
1. Каква задача (1 ред)?
2. На кой commit е production-ът? (`git log -1 --oneline` в droplet)

Всичко друго е в тая БИБЛИЯ.

---

**Край на BIBLE-та v2.0.** Допълни по нужда. 🍷

> Поздрав към Тихол: цял ден работа на 11-12.05 в нощта — backfill 1367 загубени продажби (€19,833 възстановени), auto-fill памет 842 артикули, custom numpad от sale.php pattern, parking, print receipts, PIN защита. **Това е production-ready loyalty PoS система.**
