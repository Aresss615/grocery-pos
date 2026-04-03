# Findings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve all critical bugs and implement priority features from findings.md across POS, products, inventory, users, manager, and business-settings modules.

**Architecture:** Vanilla PHP/MySQL with self-contained pages (inline SQL + HTML + JS). No build step — edits are live immediately via `php -S localhost:8000`. All new DB columns go into `database/migration_v5.sql`.

**Tech Stack:** PHP 8+, MySQL 8+, vanilla JS (ES6), CSS custom properties for theming, `mysqli` via `config/database.php` `Database` class.

---

> **Sub-plan recommendation:** This spec covers 9 independent subsystems (bugs, POS UX, print/shift, business settings, products, users/suppliers, BIR compliance, loyalty card, reports). Tasks 1–5 (critical bugs) should be done first as a unit. Phases 2–9 are fully independent and could each become their own plan for focused execution.

---

## File Map

| File | Role in this plan |
|---|---|
| `pages/pos.php` | Fix cart bug, dark-mode, UX overhaul, qty shortcut, list layout, refresh button |
| `pages/products.php` | Fix dark-mode tags, add tier-barcode field |
| `pages/users.php` | Add address field |
| `pages/master-data.php` | Add more supplier fields, feature-toggle UI, loyalty card management |
| `pages/manager.php` | Auto-print + end-of-shift cashier closure |
| `pages/inventory.php` | Branded printable export |
| `pages/reports.php` | Business-analyst reports expansion |
| `templates/navbar.php` | Fix dark-mode logout, show dynamic logo |
| `api/settings.php` | Logo upload, feature toggles, business name save |
| `api/products-refresh.php` (new) | Product refresh endpoint for POS |
| `api/shift-summary.php` (new) | End-of-shift totals + closure |
| `api/loyalty.php` (new) | Loyalty card lookup and creation |
| `api/sales-analytics.php` | New report endpoints |
| `database/migration_v5.sql` (new) | All new columns/tables for this plan |
| `public/images/` | Store uploaded logo |

---

## Print Helper — Use in all tasks that open a print window

All print windows must use Blob URLs, never `document.write()`, to avoid XSS and deprecation issues.

```javascript
function openPrintWindow(htmlString) {
    const blob = new Blob([htmlString], { type: 'text/html' });
    const url  = URL.createObjectURL(blob);
    const win  = window.open(url, '_blank', 'width=400,height=600');
    if (win) {
        win.addEventListener('afterprint', () => URL.revokeObjectURL(url));
        win.focus();
    }
}
```

Reference this function in every task below that needs to open a print window.

---

## PHASE 1 — Critical Bugs

---

### Task 1: Fix POS cart rendering stops after first item

**Root cause:** `renderCart()` calls `const ce = document.getElementById('ce')` on every invocation. After the first item is added, `body.innerHTML = cart.map(...)` removes `ce` from the DOM. On the next call `getElementById('ce')` returns `null`, and `ce.style.display = 'none'` throws a silent JS TypeError before `body.innerHTML` can update.

**Files:**
- Modify: `pages/pos.php`

- [ ] **Step 1: Locate the `ce` declaration inside `renderCart`**

Open `pages/pos.php`. Find around line 755–759:
```javascript
function renderCart() {
    const body = document.getElementById('cb2');
    const tots = document.getElementById('tots');
    const cnt  = document.getElementById('ccnt');
    const ce   = document.getElementById('ce');   // BUG: returns null after first render
```

- [ ] **Step 2: Hoist `cartEmptyEl` to module-level state**

Find the module-level state block (~line 582–592):
```javascript
let cart        = [];
let priceMode   = 'retail';
let curCat      = 'all';
let payMeth     = 'cash';
let lastSale    = null;
let txnDiscount = { type:'none', value:0 };
let heldCarts   = [];
let itemDiscKey = null;
let itemDiscType = 'percent';
let txnDiscType  = 'percent';
```
Add one line at the end of that block:
```javascript
let cartEmptyEl = null;   // cached after DOM ready
```

- [ ] **Step 3: Initialize `cartEmptyEl` after DOM is ready**

Find the line near the bottom of the script where `renderCart()` is first called (~line 1206):
```javascript
renderCart();
```
Add the initialization immediately before that line:
```javascript
cartEmptyEl = document.getElementById('ce');
```

- [ ] **Step 4: Replace `const ce = document.getElementById('ce')` inside `renderCart`**

In `renderCart()`, change:
```javascript
    const ce   = document.getElementById('ce');
```
to:
```javascript
    const ce   = cartEmptyEl;
```

- [ ] **Step 5: Verify manually**

```bash
php -S localhost:8000
```
1. Open http://localhost:8000/pages/pos.php
2. Click any product — it appears in cart ✓
3. Click a second product — cart updates showing both items ✓
4. Click the same product again — qty increments ✓
5. Open browser console — no TypeError ✓

- [ ] **Step 6: Commit**

```bash
git add pages/pos.php
git commit -m "fix(pos): cart stops rendering after first item due to null ce reference"
```

---

### Task 2: Fix dark-mode — navbar logout button invisible

**Files:**
- Modify: `templates/navbar.php`

- [ ] **Step 1: Find the logout link selector**

```bash
grep -n "logout\|Logout\|ph-exit\|nav-logout" templates/navbar.php | head -20
```

- [ ] **Step 2: Identify the colour problem**

Read the surrounding CSS in `templates/navbar.php`. The link likely has a hardcoded dark text colour or no dark-mode override.

- [ ] **Step 3: Add dark-mode CSS override**

In the `<style>` block of `templates/navbar.php`, add (adjust selector to match what Step 1 found):
```css
[data-theme="dark"] .nav-logout,
[data-theme="dark"] a.logout-btn,
[data-theme="dark"] .ph-exit {
    color: #F1F5F9;
    border-color: rgba(241,245,249,.25);
}
[data-theme="dark"] .nav-logout:hover,
[data-theme="dark"] a.logout-btn:hover,
[data-theme="dark"] .ph-exit:hover {
    background: rgba(255,255,255,.1);
}
```

- [ ] **Step 4: Verify**

1. Go to any page, toggle dark mode.
2. Logout link is clearly visible with white/light text ✓

- [ ] **Step 5: Commit**

```bash
git add templates/navbar.php
git commit -m "fix(navbar): logout link invisible in dark mode"
```

---

### Task 3: Fix dark-mode — products retail/wholesale tags

**Files:**
- Modify: `pages/products.php`

- [ ] **Step 1: Find tag class names**

```bash
grep -n "retail\|wholesale\|tag\|badge" pages/products.php | head -30
```

- [ ] **Step 2: Add dark-mode overrides for those classes**

In the `<style>` block of `pages/products.php` (adjust selectors to what Step 1 found):
```css
[data-theme="dark"] .tag-retail,
[data-theme="dark"] .badge-retail {
    background: rgba(46,125,50,.35);
    color: #86efac;
    border-color: rgba(46,125,50,.5);
}
[data-theme="dark"] .tag-wholesale,
[data-theme="dark"] .badge-wholesale {
    background: rgba(21,101,192,.35);
    color: #93c5fd;
    border-color: rgba(21,101,192,.5);
}
```

- [ ] **Step 3: Verify**

1. Open http://localhost:8000/pages/products.php, toggle dark mode.
2. Retail and wholesale tags are visible and readable ✓

- [ ] **Step 4: Commit**

```bash
git add pages/products.php
git commit -m "fix(products): retail/wholesale tags invisible in dark mode"
```

---

### Task 4: Fix POS logo not appearing

**Files:**
- Modify: `pages/pos.php`

- [ ] **Step 1: Check what the header currently renders**

```bash
grep -n "ph-logo\|biz_logo\|business_logo\|IMG_URL" pages/pos.php | head -10
```

- [ ] **Step 2: Check if `business_logo` column exists yet**

```bash
grep -n "business_logo" database/migration_v4.sql database/database.sql
```
If the column does not exist, note that it will be added by migration_v5.sql (Task 12). For now, handle its absence gracefully.

- [ ] **Step 3: Update PHP vars at top of pos.php**

In the PHP block after `$biz = getBusinessSettings($db);`, add:
```php
$biz_logo_url = '';
if (!empty($biz['business_logo'])) {
    $logo_file = ROOT_PATH . '/public/images/' . basename($biz['business_logo']);
    if (file_exists($logo_file)) {
        $biz_logo_url = IMG_URL . '/' . htmlspecialchars(basename($biz['business_logo']));
    }
}
```

- [ ] **Step 4: Update the `.ph-logo` HTML**

Find the `.ph-logo` element in the HTML section and replace it:
```php
<div class="ph-logo">
    <?php if ($biz_logo_url): ?>
    <img src="<?php echo $biz_logo_url; ?>" alt="logo"
         style="height:30px;width:auto;object-fit:contain;vertical-align:middle;margin-right:6px">
    <?php endif; ?>
    <?php echo $biz_name; ?><small><?php echo APP_VERSION; ?></small>
</div>
```

- [ ] **Step 5: Verify**

Without a logo uploaded, the business name renders cleanly. After uploading a logo via settings (Task 12), the logo appears. ✓

- [ ] **Step 6: Commit**

```bash
git add pages/pos.php
git commit -m "fix(pos): logo not showing in header — conditional render from business_settings"
```

---

### Task 5: Fix remaining dark-mode issues in POS UI

**Files:**
- Modify: `pages/pos.php`

- [ ] **Step 1: Find hardcoded colours not using CSS variables**

```bash
grep -n "#ffffff\|#FFFFFF\|#1A202C\|#fff\b\|white\b\|black\b" pages/pos.php | grep -v "var(--\|rgba(\|/\*" | head -30
```

- [ ] **Step 2: Replace each hardcoded colour with the correct CSS variable**

Use:
- `var(--bg)` for page background
- `var(--surface)` for card/panel backgrounds
- `var(--text)` for body text
- `var(--border)` for borders
- `var(--muted)` for secondary text

For elements inside modals, check that modal backgrounds use `var(--surface)` not a hardcoded colour.

- [ ] **Step 3: Verify all modals in dark mode**

Trigger each modal: discount modal, hold-cart modal, payment modal. All text should be readable against a dark background. ✓

- [ ] **Step 4: Commit**

```bash
git add pages/pos.php
git commit -m "fix(pos): remaining hardcoded colours not respecting dark mode CSS variables"
```

---

## PHASE 2 — POS UX Overhaul

---

### Task 6: Merge barcode + search into single smart field with list results

**Context:** One field handles both: type a barcode + Enter to add the item directly; type text to see a dropdown list of matching products; use arrow keys to navigate the list; Enter on a highlighted item adds it to cart.

**Files:**
- Modify: `pages/pos.php`

- [ ] **Step 1: Add CSS for the search-results dropdown**

In the `<style>` block of `pages/pos.php`, after the `.pp-search` rule:
```css
.pp-search { position: relative; }
.sr-list { position:absolute; top:100%; left:0; right:0; background:var(--surface);
           border:1.5px solid var(--border); border-top:none;
           border-radius:0 0 var(--r) var(--r); max-height:260px; overflow-y:auto;
           z-index:200; box-shadow:var(--sh-lg); }
.sr-item { padding:8px 12px; cursor:pointer; display:flex; gap:8px; align-items:center;
           border-bottom:1px solid var(--border); font-size:.82rem; }
.sr-item:last-child { border-bottom:none; }
.sr-item:hover, .sr-item.focused { background:var(--card-hover); }
.sr-item .sr-name  { flex:1; font-weight:600; color:var(--text); }
.sr-item .sr-price { color:var(--primary); font-weight:700; white-space:nowrap; }
.sr-item .sr-stk   { font-size:.7rem; color:var(--muted); }
```

- [ ] **Step 2: Replace both separate input fields with one unified field**

Remove the header `#barcodeInput` and the `.pp-search input#ps`. Replace `.pp-search` HTML with:
```html
<div class="pp-search">
    <input type="text" id="smartSearch" autocomplete="off" autocorrect="off" spellcheck="false"
           placeholder="Scan barcode or type product name…  [Enter] to add"
           oninput="onSmartInput(this.value)"
           onkeydown="onSmartKey(event)">
    <div class="sr-list" id="srList" style="display:none"></div>
</div>
```

- [ ] **Step 3: Add module-level search state variables**

In the state block (~line 582):
```javascript
let srFocusIdx = -1;
let srResults  = [];
```

- [ ] **Step 4: Add the smart-field JS functions**

```javascript
function onSmartInput(val) {
    const q = val.trim();
    srFocusIdx = -1;
    if (!q) { hideSrList(); return; }
    const list = PRODS.filter(p =>
        p.name.toLowerCase().includes(q.toLowerCase()) ||
        (p.barcode && p.barcode.includes(q))
    ).slice(0, 12);
    srResults = list;
    const ul = document.getElementById('srList');
    if (!list.length) { hideSrList(); return; }
    ul.innerHTML = list.map((p, i) => {
        const pr  = getPrice(p);
        const stk = p.quantity !== null ? `${p.quantity} left` : '';
        return `<div class="sr-item" data-idx="${i}" onmousedown="srSelect(${i})">
            <span class="sr-name">${esc(p.name)}</span>
            <span class="sr-stk">${stk}</span>
            <span class="sr-price">&#8369;${f2(pr)}</span>
        </div>`;
    }).join('');
    ul.style.display = '';
}

function onSmartKey(e) {
    const ul      = document.getElementById('srList');
    const visible = ul.style.display !== 'none' && srResults.length > 0;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (visible) { srFocusIdx = Math.min(srFocusIdx + 1, srResults.length - 1); updateSrFocus(); }
        return;
    }
    if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (visible) { srFocusIdx = Math.max(srFocusIdx - 1, -1); updateSrFocus(); }
        return;
    }
    if (e.key === 'Enter') {
        e.preventDefault();
        if (visible && srFocusIdx >= 0) {
            srSelect(srFocusIdx);
        } else {
            const v = e.target.value.trim();
            if (v) addByBarcode(v);
        }
        e.target.value = '';
        hideSrList();
        return;
    }
    if (e.key === 'Escape') { hideSrList(); e.target.value = ''; }
}

function updateSrFocus() {
    document.querySelectorAll('.sr-item').forEach((el, i) =>
        el.classList.toggle('focused', i === srFocusIdx)
    );
}

function srSelect(idx) {
    const p = srResults[idx];
    if (p) addToCart(p.id);
    const field = document.getElementById('smartSearch');
    field.value = '';
    hideSrList();
    field.focus();
}

function hideSrList() {
    srResults  = [];
    srFocusIdx = -1;
    document.getElementById('srList').style.display = 'none';
}
```

- [ ] **Step 5: Remove old barcode and search event listeners**

Delete the old `document.getElementById('barcodeInput').addEventListener(...)` and the `#ps` oninput handler. Update `renderProds()` to read from `#smartSearch` instead of `#ps`:
```javascript
const q = (document.getElementById('smartSearch').value || '').toLowerCase().trim();
```

- [ ] **Step 6: Focus smart search on page load**

After the existing `renderCart(); renderProds();` calls at the bottom of the script:
```javascript
document.getElementById('smartSearch').focus();
```

- [ ] **Step 7: Verify**

1. Type `"rice"` — dropdown appears ✓
2. Press ↓ twice — second item highlighted ✓
3. Press Enter — item added, field cleared, focus returns ✓
4. Type exact barcode + Enter — item added directly ✓
5. Console — no errors ✓

- [ ] **Step 8: Commit**

```bash
git add pages/pos.php
git commit -m "feat(pos): unified barcode+search field with keyboard-navigable results list"
```

---

### Task 7: Qty shortcut — set quantity before adding item

**Files:**
- Modify: `pages/pos.php`

- [ ] **Step 1: Add qty input to the POS header bar**

In the `.ph-right` div of the HTML:
```html
<div style="display:flex;align-items:center;gap:5px">
    <label style="color:rgba(255,255,255,.6);font-size:.72rem;white-space:nowrap">Qty [*]</label>
    <input type="number" id="qtyInput" min="1" max="9999" value="1"
           style="width:56px;padding:5px 7px;border-radius:6px;border:2px solid rgba(255,255,255,.3);
                  background:rgba(255,255,255,.14);color:#fff;font-family:var(--font);font-size:.85rem;
                  text-align:center;outline:none"
           onkeydown="onQtyKey(event)">
</div>
```

- [ ] **Step 2: Add `pendingQty` to module-level state**

```javascript
let pendingQty = 1;
```

- [ ] **Step 3: Add qty key handler**

```javascript
function onQtyKey(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        pendingQty = Math.max(1, parseInt(e.target.value) || 1);
        document.getElementById('smartSearch').focus();
    }
    if (e.key === 'Escape') {
        e.target.value  = 1;
        pendingQty = 1;
        document.getElementById('smartSearch').focus();
    }
}
```

- [ ] **Step 4: Register `*` key as shortcut to focus qty**

In the global `document.addEventListener('keydown', ...)` handler, add at the top:
```javascript
if (e.key === '*' && document.activeElement !== document.getElementById('qtyInput')) {
    e.preventDefault();
    const qi = document.getElementById('qtyInput');
    qi.value = '';
    qi.focus();
    qi.select();
    return;
}
```

- [ ] **Step 5: Update `addToCart` to consume `pendingQty`**

Replace the existing `addToCart(pid)` function body:
```javascript
function addToCart(pid) {
    const p   = PRODS.find(x => x.id === pid);
    if (!p) return;
    const pr  = getPrice(p);
    if (pr <= 0) { toast('No price for ' + priceMode + ' mode', 'warn'); return; }
    const qty = pendingQty;
    pendingQty = 1;
    document.getElementById('qtyInput').value = 1;

    const matchTier = (p.tiers || []).find(t => {
        const m = t.price_mode || 'both';
        return m === priceMode || m === 'both';
    });
    const unitLabel = matchTier ? (matchTier.unit_label || 'pcs') : 'pcs';
    const key = `${pid}_${priceMode}`;
    const ex  = cart.find(i => i.key === key);

    if (ex) {
        const newQty = ex.qty + qty;
        if (p.quantity !== null && newQty > p.quantity) {
            toast('Not enough stock (' + p.quantity + ' left)', 'err'); return;
        }
        ex.qty = newQty;
    } else {
        if (p.quantity !== null && qty > p.quantity) {
            toast('Not enough stock (' + p.quantity + ' left)', 'err'); return;
        }
        cart.push({ key, pid, name: p.name, price: pr, mode: priceMode,
                    unit: unitLabel, qty, stock: p.quantity,
                    discount_type: 'none', discount_value: 0 });
    }
    renderCart();
    toast(`${p.name} \xd7${qty} added`, 'ok');
}
```

- [ ] **Step 6: Verify**

1. Press `*` → qty field focused ✓
2. Type `5` → Enter → focus returns to smart search ✓
3. Click a product → added with qty 5, qty field resets to 1 ✓
4. Scan barcode → added with qty 1 ✓

- [ ] **Step 7: Commit**

```bash
git add pages/pos.php
git commit -m "feat(pos): qty shortcut (* key) to set quantity before adding item"
```

---

### Task 8: Switch product panel to list view; widen cart panel; show unit labels

**Files:**
- Modify: `pages/pos.php`

- [ ] **Step 1: Change product grid to list layout**

Find `.pg` CSS (~line 177) and replace:
```css
.pg { flex:1; overflow-y:auto; padding:6px 8px; display:flex; flex-direction:column; gap:3px; align-content:start; }
```

- [ ] **Step 2: Update `.pc` card to horizontal list-item style**

Replace the existing `.pc` and `.pc .pc-*` CSS block:
```css
.pc { background:var(--surface); border:1.5px solid var(--border); border-radius:var(--r);
      padding:7px 12px; cursor:pointer; transition:var(--t);
      display:flex; flex-direction:row; align-items:center; gap:10px;
      user-select:none; position:relative; }
.pc:hover  { border-color:var(--primary); background:var(--card-hover); box-shadow:var(--sh); }
.pc:active { transform:scale(.99); }
.pc .pc-icon  { font-size:1.1rem; flex-shrink:0; width:24px; text-align:center; }
.pc .pc-name  { flex:1; font-size:.82rem; font-weight:600; color:var(--text); }
.pc .pc-price { font-size:.88rem; font-weight:800; color:var(--primary); white-space:nowrap; }
.pc .pc-stk   { font-size:.68rem; color:var(--muted); white-space:nowrap; position:static; }
.pc .pc-stk.low  { color:#E65100; font-weight:700; }
.pc .pc-stk.out  { color:#C62828; font-weight:700; }
.pc.out-of-stock { opacity:.42; pointer-events:none; }
```

- [ ] **Step 3: Widen cart panel**

Find `.cart { width:355px; ...}` and change to:
```css
.cart { width:420px; flex-shrink:0; display:flex; flex-direction:column; background:var(--cart-bg); color:var(--cart-text); }
```
(`.pp` is `flex:1` so it automatically fills remaining space.)

- [ ] **Step 4: Show unit label in cart items**

In `renderCart()`, update the `.ci-mt` line:
```javascript
<div class="ci-mt">&#8369;${f2(i.price)} \xd7 ${i.qty} ${i.unit || 'pcs'} (${i.mode})</div>
```

- [ ] **Step 5: Verify**

1. Products appear in vertical list rows ✓
2. Cart panel is noticeably wider ✓
3. Cart items show "₱25.00 × 2 pcs (retail)" ✓

- [ ] **Step 6: Commit**

```bash
git add pages/pos.php
git commit -m "feat(pos): list-view products, wider cart, unit labels in cart items"
```

---

### Task 9: Refresh price list button

**Files:**
- Create: `api/products-refresh.php`
- Modify: `pages/pos.php`

- [ ] **Step 1: Create `api/products-refresh.php`**

```php
<?php
session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

$db = new Database();

$products = $db->fetchAll(
    "SELECT p.id, p.name, p.barcode, p.price_retail, p.price_wholesale,
            p.quantity, p.min_quantity, p.category_id, c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON p.category_id = c.id
     WHERE p.active = 1 ORDER BY c.name, p.name"
);

$extra_barcodes = [];
try {
    $eb = $db->fetchAll("SELECT product_id, barcode, unit_label FROM product_barcodes");
    foreach ($eb as $row) {
        $extra_barcodes[$row['product_id']][] = ['barcode'=>$row['barcode'], 'unit'=>$row['unit_label']];
    }
} catch (Exception $e) {}

$extra_tiers = [];
try {
    $et = $db->fetchAll(
        "SELECT product_id, tier_name, price, unit_label, qty_multiplier, sort_order, price_mode
         FROM product_price_tiers ORDER BY product_id, sort_order"
    );
    foreach ($et as $row) $extra_tiers[$row['product_id']][] = $row;
} catch (Exception $e) {}

foreach ($products as &$p) {
    $pid = $p['id'];
    $p['extra_barcodes'] = $extra_barcodes[$pid] ?? [];
    if (!empty($extra_tiers[$pid])) {
        $p['tiers'] = $extra_tiers[$pid];
    } else {
        $p['tiers'] = [];
        if ($p['price_retail']    > 0) $p['tiers'][] = ['tier_name'=>'Retail',    'price'=>(float)$p['price_retail'],    'unit_label'=>'pcs', 'price_mode'=>'retail'];
        if ($p['price_wholesale'] > 0) $p['tiers'][] = ['tier_name'=>'Wholesale', 'price'=>(float)$p['price_wholesale'], 'unit_label'=>'pcs', 'price_mode'=>'wholesale'];
    }
    $p['price_retail']    = (float)$p['price_retail'];
    $p['price_wholesale'] = (float)$p['price_wholesale'];
    $p['quantity']        = $p['quantity'] !== null ? (int)$p['quantity'] : null;
    $p['min_quantity']    = (int)($p['min_quantity'] ?? 5);
    $p['id']              = (int)$p['id'];
    $p['category_id']     = (int)$p['category_id'];
}
unset($p);

header('Content-Type: application/json');
echo json_encode($products, JSON_HEX_TAG);
```

- [ ] **Step 2: Add refresh button to POS header**

In the `.ph-right` div:
```html
<button class="ph-theme" onclick="refreshPriceList()" title="Reload product list from server">&#8635; Refresh</button>
```

- [ ] **Step 3: Add `refreshPriceList()` JS**

```javascript
function refreshPriceList() {
    fetch(`${BASE}/api/products-refresh.php`)
        .then(r => r.json())
        .then(data => {
            if (!Array.isArray(data)) { toast('Refresh failed', 'err'); return; }
            PRODS.length = 0;
            data.forEach(p => PRODS.push(p));
            renderProds();
            toast('Price list updated', 'ok');
        })
        .catch(() => toast('Refresh failed — check connection', 'err'));
}
```

- [ ] **Step 4: Verify**

1. In a second browser tab, change a product price on the Products page.
2. Return to POS, click Refresh → updated price shows without reloading ✓

- [ ] **Step 5: Commit**

```bash
git add api/products-refresh.php pages/pos.php
git commit -m "feat(pos): refresh price list button reloads products without page reload"
```

---

## PHASE 3 — Auto-Print & End-of-Shift

---

### Task 10: Auto-print receipt — default YES, offer skip

**Files:**
- Modify: `pages/pos.php`
- Modify: `pages/manager.php`

- [ ] **Step 1: Add the `openPrintWindow` helper to pos.php**

In `pages/pos.php`, in the JS block, add the print helper (replaces any existing `window.open` print code):
```javascript
function openPrintWindow(htmlString) {
    const blob = new Blob([htmlString], { type: 'text/html' });
    const url  = URL.createObjectURL(blob);
    const win  = window.open(url, '_blank', 'width=400,height=600');
    if (win) {
        win.addEventListener('afterprint', () => URL.revokeObjectURL(url));
        win.focus();
    } else {
        URL.revokeObjectURL(url);
    }
}
```

- [ ] **Step 2: Add skip-toast CSS**

```css
.toast-skip { position:fixed; bottom:28px; left:50%; transform:translateX(-50%);
              background:#1E293B; color:#F1F5F9; padding:12px 20px; border-radius:10px;
              font-size:.84rem; font-weight:600; box-shadow:var(--sh-lg); z-index:9999;
              display:flex; align-items:center; gap:14px; }
.toast-skip button { padding:5px 14px; border-radius:6px; border:1.5px solid rgba(255,255,255,.3);
                     background:transparent; color:#F1F5F9; cursor:pointer;
                     font-family:var(--font); font-size:.8rem; }
.toast-skip button:hover { background:rgba(255,255,255,.15); }
```

- [ ] **Step 3: Add `triggerReceiptPrint` function**

```javascript
function triggerReceiptPrint(receiptHtml) {
    openPrintWindow(receiptHtml);
    showSkipToast('Printing receipt\u2026');
}

function showSkipToast(msg) {
    let el = document.getElementById('skipToast');
    if (el) el.remove();
    el = document.createElement('div');
    el.id = 'skipToast';
    el.className = 'toast-skip';
    el.innerHTML = `<span>${msg}</span><button onclick="document.getElementById('skipToast').remove()">Done</button>`;
    document.body.appendChild(el);
    setTimeout(() => { const t = document.getElementById('skipToast'); if (t) t.remove(); }, 5000);
}
```

- [ ] **Step 4: Remove the old print confirm dialog**

Find:
```javascript
if (confirm('Print receipt?')) { ... }
```
Replace with a direct call:
```javascript
triggerReceiptPrint(receiptHtml);
```

- [ ] **Step 5: Add `openPrintWindow` to manager.php**

Copy the same `openPrintWindow` function into the JS block of `pages/manager.php`. Then find any `confirm('Print ...')` dialogs for X-Read, Z-Read, Remittance and replace with `openPrintWindow(data.html)`.

- [ ] **Step 6: Verify**

1. Complete a sale — print dialog opens, toast appears ✓
2. Close the print dialog manually — toast clears after 5 seconds ✓
3. In manager, run X-Read — print dialog opens automatically ✓

- [ ] **Step 7: Commit**

```bash
git add pages/pos.php pages/manager.php
git commit -m "feat(pos/manager): auto-print receipts and reads — no confirmation dialog"
```

---

### Task 11: End-of-shift cashier closure (cash remittance confirmation)

**Files:**
- Create: `database/migration_v5.sql`
- Create: `api/shift-summary.php`
- Modify: `pages/pos.php`

- [ ] **Step 1: Create `database/migration_v5.sql`**

```sql
-- ============================================================
-- J&J Grocery POS — Migration v5
-- Adds: shift_closures, logo, user address, supplier fields,
--       tier barcodes, feature toggles, BIR fields,
--       loyalty cards, cost_price on products.
-- ============================================================

-- ── Shift Closures ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS shift_closures (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    cashier_id      INT NOT NULL,
    cashier_name    VARCHAR(200) NOT NULL,
    shift_start     DATETIME NOT NULL,
    shift_end       DATETIME NOT NULL,
    expected_cash   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    declared_cash   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gcash_total     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    card_total      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cashier_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Apply:
```bash
mysql -u root -p grocery_pos < database/migration_v5.sql
```

- [ ] **Step 2: Create `api/shift-summary.php`**

```php
<?php
session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

header('Content-Type: application/json');
$db    = new Database();
$uid   = (int)$_SESSION['user_id'];
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $declared = (float)($_POST['declared_cash'] ?? 0);
    $notes    = sanitize($_POST['notes'] ?? '');

    $totals = $db->fetchOne(
        "SELECT COALESCE(SUM(CASE WHEN payment_method='cash'  THEN total_amount ELSE 0 END),0) AS cash_sales,
                COALESCE(SUM(CASE WHEN payment_method='gcash' THEN total_amount ELSE 0 END),0) AS gcash_sales,
                COALESCE(SUM(CASE WHEN payment_method='card'  THEN total_amount ELSE 0 END),0) AS card_sales,
                MIN(created_at) AS shift_start
         FROM sales WHERE DATE(created_at)=? AND cashier_id=? AND voided=0",
        [$today, $uid], "si"
    );

    $expected = (float)($totals['cash_sales'] ?? 0);
    $user     = getCurrentUser();

    $db->execute(
        "INSERT INTO shift_closures (cashier_id, cashier_name, shift_start, shift_end, expected_cash, declared_cash, gcash_total, card_total, notes)
         VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?)",
        [$uid, $user['name'], $totals['shift_start'] ?? date('Y-m-d H:i:s'),
         $expected, $declared, $totals['gcash_sales']??0, $totals['card_sales']??0, $notes],
        "issdddds"
    );

    logActivity('shift_close', "Declared: {$declared}, Expected: {$expected}", 'info');

    $biz      = getBusinessSettings($db);
    $variance = $declared - $expected;
    $sym      = htmlspecialchars($biz['currency_symbol'] ?? '&#8369;');
    $name     = htmlspecialchars($biz['business_name'] ?? 'J&J Grocery');
    $uname    = htmlspecialchars($user['name'] ?? '');

    $varSign  = $variance >= 0 ? '+' : '';
    $printHtml = '<!DOCTYPE html><html><head><style>'
        . 'body{font-family:monospace;font-size:12px;width:280px;margin:0 auto;padding:10px}'
        . '.c{text-align:center}.b{font-weight:bold}.line{border-top:1px dashed #000;margin:4px 0}'
        . '.row{display:flex;justify-content:space-between}'
        . '</style></head><body>'
        . "<div class='c b'>{$name}</div>"
        . "<div class='c'>END OF SHIFT REPORT</div>"
        . "<div class='line'></div>"
        . "<div class='row'><span>Date:</span><span>{$today}</span></div>"
        . "<div class='row'><span>Cashier:</span><span>{$uname}</span></div>"
        . "<div class='line'></div>"
        . "<div class='row'><span>Cash Sales:</span><span>{$sym}" . number_format($totals['cash_sales']??0,2) . "</span></div>"
        . "<div class='row'><span>GCash Sales:</span><span>{$sym}" . number_format($totals['gcash_sales']??0,2) . "</span></div>"
        . "<div class='row'><span>Card Sales:</span><span>{$sym}" . number_format($totals['card_sales']??0,2) . "</span></div>"
        . "<div class='line'></div>"
        . "<div class='row b'><span>Cash Declared:</span><span>{$sym}" . number_format($declared,2) . "</span></div>"
        . "<div class='row'><span>Variance:</span><span>{$sym}{$varSign}" . number_format($variance,2) . "</span></div>"
        . "<div class='line'></div>"
        . "<div class='c' style='margin-top:10px'>Cashier signature: ___________</div>"
        . "<div class='c'>Supervisor: ___________</div>"
        . '</body></html>';

    echo json_encode(['success' => true, 'print_html' => $printHtml]);
    exit;
}

// GET: today's totals for this cashier
$totals = $db->fetchOne(
    "SELECT COALESCE(SUM(CASE WHEN payment_method='cash'  THEN total_amount ELSE 0 END),0) AS cash_sales,
            COALESCE(SUM(CASE WHEN payment_method='gcash' THEN total_amount ELSE 0 END),0) AS gcash_sales,
            COALESCE(SUM(CASE WHEN payment_method='card'  THEN total_amount ELSE 0 END),0) AS card_sales
     FROM sales WHERE DATE(created_at)=? AND cashier_id=? AND voided=0",
    [$today, $uid], "si"
);
echo json_encode($totals ?? ['cash_sales'=>0,'gcash_sales'=>0,'card_sales'=>0]);
```

- [ ] **Step 3: Add "End Shift" button to POS header**

In the `.ph-right` div, before the logout link:
```html
<button class="ph-theme" onclick="openEndShift()"
        style="background:rgba(230,81,0,.2);border-color:rgba(230,81,0,.5)">End Shift</button>
```

- [ ] **Step 4: Add end-shift modal HTML to pos.php**

Before closing `</body>`:
```html
<div id="endShiftModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);
     z-index:5000;align-items:center;justify-content:center">
  <div style="background:var(--surface);border-radius:var(--r-lg);padding:24px 28px;
              width:340px;box-shadow:var(--sh-lg)">
    <h3 style="margin-bottom:14px;font-size:1rem;color:var(--text)">End of Shift</h3>
    <div id="esStats" style="font-size:.82rem;color:var(--muted);margin-bottom:14px;line-height:1.8"></div>
    <label style="font-size:.82rem;font-weight:600;color:var(--text)">Cash on hand (&#8369;):</label>
    <input type="number" id="esCash" min="0" step="0.01" placeholder="0.00"
           style="width:100%;margin:6px 0 12px;padding:8px 10px;border:1.5px solid var(--border);
                  border-radius:var(--r);background:var(--bg);color:var(--text);font-size:.95rem"
           oninput="updateVariance(this.value)">
    <div id="esVariance" style="font-size:.85rem;font-weight:700;margin-bottom:12px;min-height:18px"></div>
    <label style="font-size:.82rem;font-weight:600;color:var(--text)">Notes (optional):</label>
    <textarea id="esNotes" rows="2"
              style="width:100%;margin:6px 0 14px;padding:8px 10px;border:1.5px solid var(--border);
                     border-radius:var(--r);background:var(--bg);color:var(--text);
                     font-size:.82rem;resize:none"></textarea>
    <div style="display:flex;gap:8px">
      <button onclick="closeEndShift()"
              style="flex:1;padding:9px;border-radius:var(--r);border:1.5px solid var(--border);
                     background:var(--surface);color:var(--text);cursor:pointer;font-family:var(--font)">Cancel</button>
      <button onclick="submitEndShift()"
              style="flex:1;padding:9px;border-radius:var(--r);border:none;background:var(--primary);
                     color:#fff;cursor:pointer;font-family:var(--font);font-weight:700">Confirm Remittance</button>
    </div>
  </div>
</div>
```

- [ ] **Step 5: Add end-shift JS to pos.php**

```javascript
let esExpectedCash = 0;

function openEndShift() {
    fetch(`${BASE}/api/shift-summary.php`)
        .then(r => r.json())
        .then(d => {
            esExpectedCash = parseFloat(d.cash_sales) || 0;
            const total = (parseFloat(d.cash_sales)||0) + (parseFloat(d.gcash_sales)||0) + (parseFloat(d.card_sales)||0);
            document.getElementById('esStats').innerHTML =
                `Total sales: <b>&#8369;${f2(total)}</b><br>` +
                `Cash: <b>&#8369;${f2(d.cash_sales)}</b> ` +
                `GCash: <b>&#8369;${f2(d.gcash_sales)}</b> ` +
                `Card: <b>&#8369;${f2(d.card_sales)}</b>`;
            document.getElementById('esCash').value = '';
            document.getElementById('esVariance').textContent = '';
            document.getElementById('esNotes').value = '';
            document.getElementById('endShiftModal').style.display = 'flex';
            document.getElementById('esCash').focus();
        });
}

function updateVariance(val) {
    const declared  = parseFloat(val) || 0;
    const variance  = declared - esExpectedCash;
    const el = document.getElementById('esVariance');
    if (val === '') { el.textContent = ''; return; }
    el.textContent = variance === 0 ? 'Balanced'
        : variance > 0 ? `+&#8369;${f2(variance)} over`
        : `&#8369;${f2(Math.abs(variance))} short`;
    el.style.color = variance === 0 ? 'var(--success)' : variance > 0 ? 'var(--warning)' : 'var(--primary)';
}

function closeEndShift() {
    document.getElementById('endShiftModal').style.display = 'none';
}

function submitEndShift() {
    const declared = parseFloat(document.getElementById('esCash').value);
    if (isNaN(declared) || declared < 0) { toast('Enter cash on hand', 'warn'); return; }
    const notes = document.getElementById('esNotes').value;
    const params = new URLSearchParams({
        declared_cash: declared,
        notes,
        csrf_token: '<?php echo $csrf_token; ?>'
    });
    fetch(`${BASE}/api/shift-summary.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            closeEndShift();
            toast('Shift closed. Remittance recorded.', 'ok');
            if (d.print_html) openPrintWindow(d.print_html);
        } else {
            toast(d.error || 'Error closing shift', 'err');
        }
    });
}
```

- [ ] **Step 6: Verify**

1. Open POS → click "End Shift" → modal shows today's cash breakdown ✓
2. Enter cash amount → variance updates live ✓
3. Click Confirm → shift_closures row inserted, print dialog opens ✓
4. Enter wrong amount → shows "₱X.XX short" in red ✓

- [ ] **Step 7: Commit**

```bash
git add database/migration_v5.sql api/shift-summary.php pages/pos.php
git commit -m "feat(pos): end-of-shift cashier closure with cash remittance and auto-print"
```

---

## PHASE 4 — Business Settings & White-Label

---

### Task 12: Logo upload + business name edit via Settings

**Files:**
- Modify: `database/migration_v5.sql`
- Modify: `api/settings.php`
- Modify: `pages/master-data.php`
- Modify: `templates/navbar.php`
- Modify: `config/helpers.php`

- [ ] **Step 1: Add `business_logo` to migration_v5.sql**

Append to `database/migration_v5.sql`:
```sql
-- ── Business Settings additions ──────────────────────────────
ALTER TABLE business_settings ADD COLUMN business_logo VARCHAR(255) NULL AFTER business_name;
```

Run:
```bash
mysql -u root -p grocery_pos < database/migration_v5.sql
```

- [ ] **Step 2: Add `business_logo` to `getBusinessSettings()` defaults**

In `config/helpers.php`, find the `$defaults` array in `getBusinessSettings()` and add:
```php
'business_logo' => null,
```

- [ ] **Step 3: Add logo upload handler in `api/settings.php`**

Find the `update_business` POST action handler. After saving text fields, add:
```php
if (!empty($_FILES['business_logo']['tmp_name'])) {
    $file    = $_FILES['business_logo'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        echo json_encode(['success'=>false,'error'=>'Invalid image type']); exit;
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        echo json_encode(['success'=>false,'error'=>'Logo must be under 2MB']); exit;
    }
    $dest_dir  = ROOT_PATH . '/public/images/';
    $filename  = 'logo_' . time() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest_dir . $filename)) {
        echo json_encode(['success'=>false,'error'=>'Upload failed']); exit;
    }
    // Remove old logo
    $old = $db->fetchOne("SELECT business_logo FROM business_settings WHERE id=1");
    if (!empty($old['business_logo'])) {
        $op = $dest_dir . basename($old['business_logo']);
        if (file_exists($op)) @unlink($op);
    }
    $db->execute("UPDATE business_settings SET business_logo=? WHERE id=1", [$filename], "s");
}
```
Also ensure the form tag on the settings endpoint accepts file uploads (add `enctype` to the form in `pages/master-data.php`).

- [ ] **Step 4: Add logo upload UI to master-data.php business settings tab**

Make the business settings form `enctype="multipart/form-data"`. Add the logo field:
```html
<div class="mb-3">
    <label class="form-label fw-semibold">Business Logo</label>
    <?php if (!empty($biz['business_logo'])): ?>
    <div class="mb-2">
        <img src="<?php echo IMG_URL . '/' . htmlspecialchars($biz['business_logo']); ?>"
             alt="Current logo" style="height:48px;object-fit:contain;border:1px solid #dee2e6;border-radius:4px;padding:4px">
    </div>
    <?php endif; ?>
    <input type="file" name="business_logo" accept="image/png,image/jpeg,image/gif,image/svg+xml,image/webp" class="form-control">
    <div class="form-text">PNG/JPG/SVG/WebP, max 2 MB. Shown on receipts, exports, and the navbar.</div>
</div>
```

- [ ] **Step 5: Show dynamic logo in navbar**

In `templates/navbar.php`, at the top of the file (after `session_start` and requires), add:
```php
$_biz_nav  = getBusinessSettings();
$_nav_logo = !empty($_biz_nav['business_logo'])
    ? IMG_URL . '/' . htmlspecialchars(basename($_biz_nav['business_logo']))
    : null;
$_nav_name = htmlspecialchars($_biz_nav['business_name'] ?? APP_NAME);
```
Then in the brand/logo area of the navbar HTML:
```html
<a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo BASE_URL; ?>/pages/dashboard.php">
    <?php if ($_nav_logo): ?>
        <img src="<?php echo $_nav_logo; ?>" alt="logo" style="height:36px;width:auto;object-fit:contain">
    <?php else: ?>
        <span style="font-size:1.5rem">&#128722;</span>
    <?php endif; ?>
    <span class="fw-bold"><?php echo $_nav_name; ?></span>
</a>
```

- [ ] **Step 6: Verify**

1. Open Master Data → Business Settings tab.
2. Upload a PNG logo, click Save.
3. Reload any page — navbar shows the uploaded logo ✓
4. POS header shows the logo ✓
5. Old logo file is removed from `public/images/` ✓

- [ ] **Step 7: Commit**

```bash
git add api/settings.php pages/master-data.php templates/navbar.php config/helpers.php database/migration_v5.sql
git commit -m "feat(settings): logo upload and business name edit for white-label distribution"
```

---

### Task 13: Feature toggles in Business Settings

**Files:**
- Modify: `database/migration_v5.sql`
- Modify: `api/settings.php`
- Modify: `pages/master-data.php`
- Modify: `config/helpers.php`
- Modify: `pages/pos.php`

- [ ] **Step 1: Add feature-flag columns to migration_v5.sql**

Append to `database/migration_v5.sql`:
```sql
-- ── Feature Toggles ─────────────────────────────────────────
ALTER TABLE business_settings ADD COLUMN feature_loyalty    TINYINT(1) NOT NULL DEFAULT 0 AFTER business_logo;
ALTER TABLE business_settings ADD COLUMN feature_gcash      TINYINT(1) NOT NULL DEFAULT 1 AFTER feature_loyalty;
ALTER TABLE business_settings ADD COLUMN feature_card       TINYINT(1) NOT NULL DEFAULT 1 AFTER feature_gcash;
ALTER TABLE business_settings ADD COLUMN feature_discounts  TINYINT(1) NOT NULL DEFAULT 1 AFTER feature_card;
ALTER TABLE business_settings ADD COLUMN feature_held_carts TINYINT(1) NOT NULL DEFAULT 1 AFTER feature_discounts;
```

Run:
```bash
mysql -u root -p grocery_pos < database/migration_v5.sql
```

- [ ] **Step 2: Add defaults in `getBusinessSettings()`**

```php
'feature_loyalty'    => 0,
'feature_gcash'      => 1,
'feature_card'       => 1,
'feature_discounts'  => 1,
'feature_held_carts' => 1,
```

- [ ] **Step 3: Add feature toggles UI to master-data.php**

In the business settings form, after existing fields:
```html
<hr>
<h6 class="fw-bold mb-3">Features</h6>
<div class="form-check form-switch mb-2">
    <input class="form-check-input" type="checkbox" name="feature_loyalty" id="ftLoyalty"
           value="1" <?php echo $biz['feature_loyalty'] ? 'checked' : ''; ?>>
    <label class="form-check-label" for="ftLoyalty">Loyalty Card</label>
</div>
<div class="form-check form-switch mb-2">
    <input class="form-check-input" type="checkbox" name="feature_gcash" id="ftGcash"
           value="1" <?php echo $biz['feature_gcash'] ? 'checked' : ''; ?>>
    <label class="form-check-label" for="ftGcash">GCash Payments</label>
</div>
<div class="form-check form-switch mb-2">
    <input class="form-check-input" type="checkbox" name="feature_card" id="ftCard"
           value="1" <?php echo $biz['feature_card'] ? 'checked' : ''; ?>>
    <label class="form-check-label" for="ftCard">Card Payments</label>
</div>
<div class="form-check form-switch mb-2">
    <input class="form-check-input" type="checkbox" name="feature_discounts" id="ftDisc"
           value="1" <?php echo $biz['feature_discounts'] ? 'checked' : ''; ?>>
    <label class="form-check-label" for="ftDisc">Discounts (item &amp; transaction)</label>
</div>
<div class="form-check form-switch mb-2">
    <input class="form-check-input" type="checkbox" name="feature_held_carts" id="ftHeld"
           value="1" <?php echo $biz['feature_held_carts'] ? 'checked' : ''; ?>>
    <label class="form-check-label" for="ftHeld">Held Carts</label>
</div>
```

- [ ] **Step 4: Save feature flags in api/settings.php**

In the `update_business` handler, add:
```php
$db->execute(
    "UPDATE business_settings SET
        feature_loyalty=?, feature_gcash=?, feature_card=?,
        feature_discounts=?, feature_held_carts=?
     WHERE id=1",
    [
        isset($_POST['feature_loyalty'])    ? 1 : 0,
        isset($_POST['feature_gcash'])      ? 1 : 0,
        isset($_POST['feature_card'])       ? 1 : 0,
        isset($_POST['feature_discounts'])  ? 1 : 0,
        isset($_POST['feature_held_carts']) ? 1 : 0,
    ],
    "iiiii"
);
```

- [ ] **Step 5: Honour flags in pos.php**

After loading `$biz`, add:
```php
$feat_gcash      = (int)($biz['feature_gcash']      ?? 1);
$feat_card       = (int)($biz['feature_card']        ?? 1);
$feat_discounts  = (int)($biz['feature_discounts']   ?? 1);
$feat_held_carts = (int)($biz['feature_held_carts']  ?? 1);
$feat_loyalty    = (int)($biz['feature_loyalty']     ?? 0);
```
Conditionally hide buttons: wrap GCash payment button with `<?php if ($feat_gcash): ?>`, Card button with `<?php if ($feat_card): ?>`, discount buttons with `<?php if ($feat_discounts): ?>`, held-cart button with `<?php if ($feat_held_carts): ?>`.

- [ ] **Step 6: Verify**

1. Toggle GCash off in settings → reload POS → GCash payment button absent ✓
2. Toggle off Discounts → discount buttons absent in cart ✓
3. Toggle back on → buttons reappear ✓

- [ ] **Step 7: Commit**

```bash
git add database/migration_v5.sql api/settings.php pages/master-data.php config/helpers.php pages/pos.php
git commit -m "feat(settings): feature toggles for loyalty, GCash, card, discounts, held carts"
```

---

## PHASE 5 — Products & Inventory

---

### Task 14: Pricing tier own barcode + box-barcode auto-tier + items-per-unit

**Files:**
- Modify: `database/migration_v5.sql`
- Modify: `pages/products.php`
- Modify: `api/products-refresh.php`
- Modify: `pages/pos.php`
- Modify: `api/sales.php`

- [ ] **Step 1: Add tier columns to migration**

Append to `database/migration_v5.sql`:
```sql
-- ── Product Price Tiers: barcode + items per unit ────────────
ALTER TABLE product_price_tiers ADD COLUMN barcode        VARCHAR(100) NULL AFTER sort_order;
ALTER TABLE product_price_tiers ADD COLUMN items_per_unit INT NOT NULL DEFAULT 1 AFTER barcode;
```

Run:
```bash
mysql -u root -p grocery_pos < database/migration_v5.sql
```

- [ ] **Step 2: Add barcode and items_per_unit to tier editor in products.php**

Find the tier editing rows in `pages/products.php`. Add two fields per tier:
```html
<input type="text"   name="tiers[N][barcode]"
       placeholder="Tier barcode (optional)" class="form-control form-control-sm"
       value="<?php echo htmlspecialchars($tier['barcode'] ?? ''); ?>">
<input type="number" name="tiers[N][items_per_unit]"
       placeholder="Units per pack" min="1" value="<?php echo (int)($tier['items_per_unit'] ?? 1); ?>"
       class="form-control form-control-sm">
```

- [ ] **Step 3: Save new fields in the tier save handler**

In the API endpoint that handles `product_price_tiers` INSERT/UPDATE, add:
```php
$barcode        = sanitize($tier['barcode'] ?? '');
$items_per_unit = max(1, (int)($tier['items_per_unit'] ?? 1));
```
Include `barcode, items_per_unit` in the INSERT/UPDATE SQL.

- [ ] **Step 4: Include new fields in products-refresh.php query**

Update the tiers query:
```sql
SELECT product_id, tier_name, price, unit_label, qty_multiplier, sort_order,
       price_mode, barcode, items_per_unit
FROM product_price_tiers ORDER BY product_id, sort_order
```

- [ ] **Step 5: Extend `addByBarcode()` to match tier barcodes**

In `pages/pos.php`, replace `addByBarcode(bc)`:
```javascript
function addByBarcode(bc) {
    const b = bc.trim();
    // 1. Main product barcode
    let p = PRODS.find(x => x.barcode === b);
    if (p) { addToCart(p.id); return; }
    // 2. Extra barcodes
    p = PRODS.find(x => x.extra_barcodes && x.extra_barcodes.some(e => e.barcode === b));
    if (p) { addToCart(p.id); return; }
    // 3. Tier barcode
    for (const prod of PRODS) {
        const tier = (prod.tiers || []).find(t => t.barcode === b);
        if (tier) {
            const savedMode = priceMode;
            if (tier.price_mode && tier.price_mode !== 'both') priceMode = tier.price_mode;
            addToCart(prod.id);
            priceMode = savedMode;
            return;
        }
    }
    // 4. Server fallback
    fetch(`${BASE}/api/search-product.php?q=${encodeURIComponent(b)}`)
        .then(r => r.json())
        .then(data => {
            if (!Array.isArray(data) || !data.length) { toast('Barcode not found: ' + b, 'err'); return; }
            const prod = data[0];
            if (!PRODS.find(x => x.id === prod.id)) PRODS.push(prod);
            addToCart(prod.id);
        })
        .catch(() => toast('Barcode not found: ' + b, 'err'));
}
```

- [ ] **Step 6: Pass `items_per_unit` from cart to sale submission**

In `addToCart`, store `items_per_unit` from the matched tier:
```javascript
const matchTier     = (p.tiers || []).find(t => { const m = t.price_mode||'both'; return m===priceMode||m==='both'; });
const unitLabel     = matchTier ? (matchTier.unit_label || 'pcs') : 'pcs';
const itemsPerUnit  = matchTier ? (parseInt(matchTier.items_per_unit) || 1) : 1;

cart.push({ key, pid, name: p.name, price: pr, mode: priceMode,
            unit: unitLabel, items_per_unit: itemsPerUnit, qty, stock: p.quantity,
            discount_type: 'none', discount_value: 0 });
```

In the sale POST payload, include `items_per_unit` per item. In `api/sales.php`, use it:
```php
$deduct = $item['qty'] * max(1, (int)($item['items_per_unit'] ?? 1));
$db->execute("UPDATE products SET quantity = quantity - ? WHERE id = ?", [$deduct, $item['product_id']], "ii");
```

- [ ] **Step 7: Verify**

1. Create a product with a "Box" tier, barcode `BOX001`, items_per_unit = 12 ✓
2. Scan `BOX001` in POS → item added with box tier price ✓
3. Complete sale of 2 boxes → inventory decreases by 24 ✓

- [ ] **Step 8: Commit**

```bash
git add database/migration_v5.sql pages/products.php api/products-refresh.php pages/pos.php api/sales.php
git commit -m "feat(products): tier barcodes, box scan auto-selects tier, items-per-unit inventory deduction"
```

---

### Task 15: Inventory branded printable export

**Files:**
- Modify: `pages/inventory.php`

- [ ] **Step 1: Add PHP vars for branding at top of inventory.php**

```php
$biz_name     = htmlspecialchars($biz['business_name']    ?? 'J&J Grocery');
$biz_address  = htmlspecialchars($biz['business_address'] ?? '');
$biz_logo_url = !empty($biz['business_logo'])
    ? IMG_URL . '/' . htmlspecialchars(basename($biz['business_logo'])) : '';
```

- [ ] **Step 2: Add "Print Report" button next to existing export**

```html
<button onclick="printInventoryReport()" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-printer"></i> Print Report
</button>
```

- [ ] **Step 3: Add `printInventoryReport()` JS**

```javascript
function printInventoryReport() {
    const headers = Array.from(document.querySelectorAll('#inventoryTable thead th'))
        .map(th => `<th>${th.innerText.trim()}</th>`).join('');
    const rows = Array.from(document.querySelectorAll('#inventoryTable tbody tr'))
        .map(tr => `<tr>${Array.from(tr.querySelectorAll('td')).map(td => `<td>${td.innerText.trim()}</td>`).join('')}</tr>`)
        .join('');

    const bizName    = <?php echo json_encode($biz_name); ?>;
    const bizAddr    = <?php echo json_encode($biz_address); ?>;
    const logoUrl    = <?php echo json_encode($biz_logo_url); ?>;
    const today      = new Date().toLocaleDateString('en-PH', {year:'numeric',month:'long',day:'numeric'});

    const html = `<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Inventory Report</title>
<style>
body{font-family:Arial,sans-serif;margin:15mm;color:#111;font-size:11px}
.hdr{display:flex;align-items:center;gap:14px;border-bottom:2px solid #1E3A8A;padding-bottom:10px;margin-bottom:14px}
.hdr img{height:48px;object-fit:contain}
.hdr-t h1{margin:0;font-size:17px;color:#1E3A8A}.hdr-t p{margin:2px 0;color:#555;font-size:10px}
.title{font-size:13px;font-weight:bold;color:#1E3A8A;margin-bottom:10px}
table{width:100%;border-collapse:collapse}
th{background:#1E3A8A;color:#fff;padding:5px 7px;font-size:10px;text-align:left}
td{padding:4px 7px;border-bottom:1px solid #e0e0e0;font-size:10px}
tr:nth-child(even) td{background:#f5f7fa}
.footer{margin-top:16px;font-size:9px;color:#888;text-align:center;border-top:1px solid #ddd;padding-top:6px}
</style></head><body>
<div class="hdr">
    ${logoUrl ? `<img src="${logoUrl}" alt="logo">` : ''}
    <div class="hdr-t"><h1>${bizName}</h1><p>${bizAddr}</p></div>
</div>
<div class="title">INVENTORY REPORT &mdash; As of ${today}</div>
<table><thead><tr>${headers}</tr></thead><tbody>${rows}</tbody></table>
<div class="footer">Generated by J&amp;J Grocery POS &bull; ${today}</div>
</body></html>`;

    openPrintWindow(html);
}
```

- [ ] **Step 4: Add `openPrintWindow` helper to inventory.php JS**

Copy the same `openPrintWindow` function defined in the Phase 3 print helper section.

- [ ] **Step 5: Verify**

1. Open Inventory → click Print Report ✓
2. Print preview: logo top-left, business name/address, "INVENTORY REPORT — As of [date]", styled table ✓
3. Table data matches the on-screen inventory list ✓

- [ ] **Step 6: Commit**

```bash
git add pages/inventory.php
git commit -m "feat(inventory): branded printable export with logo and corporate layout"
```

---

## PHASE 6 — Users & Suppliers

---

### Task 16: Add address field to users

**Files:**
- Modify: `database/migration_v5.sql`
- Modify: `pages/users.php`

- [ ] **Step 1: Add column to migration**

Append to `database/migration_v5.sql`:
```sql
-- ── Users: address ───────────────────────────────────────────
ALTER TABLE users ADD COLUMN address TEXT NULL AFTER phone;
```

Run:
```bash
mysql -u root -p grocery_pos < database/migration_v5.sql
```

- [ ] **Step 2: Add address textarea to add/edit user modals**

In both the Add User and Edit User modals in `pages/users.php`, after the phone field:
```html
<div class="mb-3">
    <label class="form-label">Address</label>
    <textarea name="address" id="userAddress" rows="2" class="form-control"
              placeholder="Street, Barangay, City, Province"></textarea>
</div>
```

- [ ] **Step 3: Save address in the `add` and `edit` action handlers**

```php
$address = sanitize($_POST['address'] ?? '');
```
Add `address` column to both the INSERT (add) and UPDATE (edit) queries.

- [ ] **Step 4: Pre-fill address in edit modal JS**

In the JS that opens the edit modal, add:
```javascript
document.getElementById('userAddress').value = user.address || '';
```

- [ ] **Step 5: Verify**

1. Add a new user with address → row inserted with address ✓
2. Edit that user → address pre-filled in modal ✓

- [ ] **Step 6: Commit**

```bash
git add database/migration_v5.sql pages/users.php
git commit -m "feat(users): add address field to user profile"
```

---

### Task 17: More supplier fields + branded supplier export

**Files:**
- Modify: `database/migration_v5.sql`
- Modify: `pages/master-data.php`
- Modify: `api/master-data.php`

- [ ] **Step 1: Add supplier columns to migration**

Append to `database/migration_v5.sql`:
```sql
-- ── Suppliers: extra fields ───────────────────────────────────
ALTER TABLE suppliers ADD COLUMN contact_person VARCHAR(200) NULL AFTER phone;
ALTER TABLE suppliers ADD COLUMN email          VARCHAR(200) NULL AFTER contact_person;
ALTER TABLE suppliers ADD COLUMN address        TEXT         NULL AFTER email;
ALTER TABLE suppliers ADD COLUMN tin            VARCHAR(20)  NULL AFTER address;
ALTER TABLE suppliers ADD COLUMN payment_terms  VARCHAR(100) NULL AFTER tin;
ALTER TABLE suppliers ADD COLUMN notes          TEXT         NULL AFTER payment_terms;
```

Run:
```bash
mysql -u root -p grocery_pos < database/migration_v5.sql
```

- [ ] **Step 2: Add extra fields to supplier modals in master-data.php**

In the Add/Edit Supplier modal, add fields for Contact Person, Email, Address, TIN, Payment Terms, Notes — following the same `<div class="mb-3">` form-group pattern already in the page.

- [ ] **Step 3: Save extra fields in api/master-data.php supplier handlers**

```php
$contact_person = sanitize($_POST['contact_person'] ?? '');
$email          = sanitize($_POST['email'] ?? '');
$address        = sanitize($_POST['address'] ?? '');
$tin            = sanitize($_POST['tin'] ?? '');
$payment_terms  = sanitize($_POST['payment_terms'] ?? '');
$notes          = sanitize($_POST['notes'] ?? '');
```
Add all six fields to the INSERT/UPDATE query.

- [ ] **Step 4: Add "Print Suppliers" button**

Next to the suppliers table, add:
```html
<button onclick="printSuppliersReport()" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-printer"></i> Print Suppliers
</button>
```

- [ ] **Step 5: Add `printSuppliersReport()` JS**

Same structure as `printInventoryReport()` from Task 15 — replace `#inventoryTable` with the suppliers table ID and the title with "SUPPLIERS REPORT". Copy the full function with the corrected table reference.

- [ ] **Step 6: Verify**

1. Add supplier with all new fields → saved ✓
2. Edit supplier → all new fields pre-filled ✓
3. Click Print Suppliers → branded report with logo, date, supplier table ✓

- [ ] **Step 7: Commit**

```bash
git add database/migration_v5.sql pages/master-data.php api/master-data.php
git commit -m "feat(suppliers): add contact/email/address/TIN/terms fields + branded print export"
```

---

## PHASE 7 — BIR Compliance (Research-First)

---

### Task 18: Research Philippine POS legal requirements

**This task is research-only — no code yet.**

- [ ] **Step 1: Search official BIR and government sources**

Search for:
- "BIR Revenue Regulations 10-2015 electronic sales reporting"
- "BIR Revenue Memorandum Order 10-2020 point of sale"
- "BIR official receipt format requirements Philippines"
- "BIR ATP authority to print requirements"
- "BIR CAS (Computerized Accounting System) accreditation Philippines 2024"

- [ ] **Step 2: Document required OR fields**

Standard BIR Official Receipt must include (verify against sources):
- Business name, registered address, TIN
- ATP (Authority to Print) number issued by BIR
- Permit number of the accredited printer
- Sequential serial/receipt number (no gaps allowed — Z-Read ensures this)
- Date and time of issue
- Description of each item: name, qty, unit price, amount
- VAT breakdown: VATable Sales, VAT (12%), VAT-Exempt Sales, Zero-Rated Sales
- Total amount due
- "THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX" (for non-VAT persons)
- Name of cashier or POS terminal ID

- [ ] **Step 3: Write findings to a reference file**

Save research findings to `docs/bir-compliance-notes.md`. Include: regulation references, required fields checklist, Z-Read requirements, X-Read requirements.

---

### Task 19: BIR-compliant receipt layout

**Prerequisite:** Task 18 must be completed first.

**Files:**
- Modify: `database/migration_v5.sql`
- Modify: `api/settings.php`
- Modify: `pages/master-data.php`
- Modify: `pages/pos.php`

- [ ] **Step 1: Add BIR fields to migration_v5.sql**

Append to `database/migration_v5.sql`:
```sql
-- ── BIR / Receipt fields ─────────────────────────────────────
ALTER TABLE business_settings ADD COLUMN bir_atp_number    VARCHAR(50)  NULL AFTER tin;
ALTER TABLE business_settings ADD COLUMN bir_permit_number VARCHAR(50)  NULL AFTER bir_atp_number;
ALTER TABLE business_settings ADD COLUMN receipt_footer_1  VARCHAR(200) NULL AFTER bir_permit_number;
ALTER TABLE business_settings ADD COLUMN receipt_footer_2  VARCHAR(200) NULL AFTER receipt_footer_1;
```

Run:
```bash
mysql -u root -p grocery_pos < database/migration_v5.sql
```

- [ ] **Step 2: Add BIR fields to business settings form in master-data.php**

```html
<hr>
<h6 class="fw-bold mb-3">BIR / Receipt Settings</h6>
<div class="mb-3">
    <label class="form-label">ATP Number (Authority to Print)</label>
    <input type="text" name="bir_atp_number" class="form-control"
           value="<?php echo htmlspecialchars($biz['bir_atp_number'] ?? ''); ?>">
</div>
<div class="mb-3">
    <label class="form-label">BIR Permit Number</label>
    <input type="text" name="bir_permit_number" class="form-control"
           value="<?php echo htmlspecialchars($biz['bir_permit_number'] ?? ''); ?>">
</div>
<div class="mb-3">
    <label class="form-label">Receipt Footer Line 1</label>
    <input type="text" name="receipt_footer_1" class="form-control"
           value="<?php echo htmlspecialchars($biz['receipt_footer_1'] ?? 'Thank you for your purchase!'); ?>">
</div>
<div class="mb-3">
    <label class="form-label">Receipt Footer Line 2</label>
    <input type="text" name="receipt_footer_2" class="form-control"
           value="<?php echo htmlspecialchars($biz['receipt_footer_2'] ?? ''); ?>">
</div>
```

- [ ] **Step 3: Save BIR fields in api/settings.php**

In the `update_business` handler:
```php
$bir_atp    = sanitize($_POST['bir_atp_number']    ?? '');
$bir_permit = sanitize($_POST['bir_permit_number'] ?? '');
$footer1    = sanitize($_POST['receipt_footer_1']  ?? '');
$footer2    = sanitize($_POST['receipt_footer_2']  ?? '');
$db->execute(
    "UPDATE business_settings SET bir_atp_number=?, bir_permit_number=?, receipt_footer_1=?, receipt_footer_2=? WHERE id=1",
    [$bir_atp, $bir_permit, $footer1, $footer2], "ssss"
);
```

- [ ] **Step 4: Pass BIR fields to receipt builder in pos.php**

After `$biz = getBusinessSettings($db);`, add:
```php
$biz_atp     = htmlspecialchars($biz['bir_atp_number']    ?? '');
$biz_permit  = htmlspecialchars($biz['bir_permit_number'] ?? '');
$biz_footer1 = htmlspecialchars($biz['receipt_footer_1']  ?? 'Thank you for your purchase!');
$biz_footer2 = htmlspecialchars($biz['receipt_footer_2']  ?? '');
```
Emit them as JS constants just like `VAT_RATE` etc.

- [ ] **Step 5: Replace receipt HTML builder with BIR-compliant layout**

Replace the existing `buildReceiptHtml` JS function in `pages/pos.php` with a layout that includes all required fields from Task 18 research. The outer print container HTML (not JS) should be a self-contained HTML string that is passed to `openPrintWindow(html)`.

Key sections to include:
1. Header: logo, business name, address, TIN, ATP number
2. Title: "OFFICIAL RECEIPT"
3. Receipt number, date/time, cashier name
4. Item lines: description, qty × unit price, line total
5. Separator line
6. VATable Sales, VAT (12%), VAT-Exempt Sales, Zero-Rated Sales
7. Total Due, Cash tendered, Change
8. Footer: ATP number, BIR Permit, footer lines 1 & 2
9. "THIS SERVES AS YOUR OFFICIAL RECEIPT" (or required BIR disclaimer per Task 18)

- [ ] **Step 6: Verify**

1. Complete a test transaction.
2. Receipt auto-prints.
3. Check printed receipt against Task 18 required-fields checklist — all items present ✓

- [ ] **Step 7: Commit**

```bash
git add database/migration_v5.sql api/settings.php pages/master-data.php pages/pos.php
git commit -m "feat(receipt): BIR-compliant receipt with ATP, TIN, VAT breakdown per PH regulations"
```

---

## PHASE 8 — Loyalty Card

---

### Task 20: Loyalty card feature (guarded by feature toggle)

**Prerequisite:** Task 13 (feature_loyalty toggle) must be complete.

**Files:**
- Modify: `database/migration_v5.sql`
- Create: `api/loyalty.php`
- Modify: `pages/pos.php`
- Modify: `pages/master-data.php`
- Modify: `api/sales.php`

- [ ] **Step 1: Add loyalty tables and columns to migration_v5.sql**

Append to `database/migration_v5.sql`:
```sql
-- ── Loyalty Cards ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS loyalty_cards (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    card_number VARCHAR(30)  NOT NULL UNIQUE,
    holder_name VARCHAR(200) NOT NULL,
    phone       VARCHAR(30)  NULL,
    points      INT          NOT NULL DEFAULT 0,
    total_spent DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sales ADD COLUMN loyalty_card_id INT NULL AFTER customer_name;
ALTER TABLE sales ADD COLUMN points_earned   INT NOT NULL DEFAULT 0 AFTER loyalty_card_id;
```

Run:
```bash
mysql -u root -p grocery_pos < database/migration_v5.sql
```

- [ ] **Step 2: Create `api/loyalty.php`**

```php
<?php
session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
header('Content-Type: application/json');

$db     = new Database();
$action = $_REQUEST['action'] ?? '';

if ($action === 'lookup') {
    $q = sanitize($_GET['q'] ?? '');
    if (strlen($q) < 4) { echo json_encode(['found'=>false]); exit; }
    $card = $db->fetchOne(
        "SELECT id, card_number, holder_name, phone, points, total_spent
         FROM loyalty_cards WHERE (card_number=? OR phone=?) AND active=1",
        [$q, $q], "ss"
    );
    echo json_encode($card ? ['found'=>true, 'card'=>$card] : ['found'=>false]);
    exit;
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $name  = sanitize($_POST['holder_name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    if (!$name) { echo json_encode(['success'=>false,'error'=>'Name required']); exit; }
    $number = 'LC' . date('Ymd') . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
    try {
        $db->execute(
            "INSERT INTO loyalty_cards (card_number, holder_name, phone) VALUES (?,?,?)",
            [$number, $name, $phone], "sss"
        );
        $id   = $db->lastInsertId();
        $card = $db->fetchOne("SELECT * FROM loyalty_cards WHERE id=?", [$id], "i");
        echo json_encode(['success'=>true, 'card'=>$card]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false, 'error'=>'Could not create card']);
    }
    exit;
}

echo json_encode(['error'=>'Unknown action']);
```

- [ ] **Step 3: Add loyalty field to POS cart panel (conditional on feature flag)**

In `pages/pos.php`, after the customer name input in the cart, add (PHP gated):
```php
<?php if ($feat_loyalty): ?>
<div class="cart-cust" id="loyaltyArea">
    <input type="text" id="loyaltyInput" autocomplete="off"
           placeholder="Loyalty card # or phone  [F3]"
           oninput="lookupLoyalty(this.value)">
    <div id="loyaltyInfo" style="font-size:.72rem;color:var(--cart-sub);margin-top:3px;min-height:16px"></div>
</div>
<?php endif; ?>
```

- [ ] **Step 4: Add loyalty JS to pos.php**

```javascript
let loyaltyCard = null;

function lookupLoyalty(q) {
    const el = document.getElementById('loyaltyInfo');
    if (q.length < 4) { loyaltyCard = null; el.textContent = ''; return; }
    fetch(`${BASE}/api/loyalty.php?action=lookup&q=${encodeURIComponent(q)}`)
        .then(r => r.json())
        .then(d => {
            if (d.found) {
                loyaltyCard = d.card;
                el.innerHTML = `&#10003; <b>${esc(d.card.holder_name)}</b> &mdash; ${d.card.points} pts`;
            } else {
                loyaltyCard = null;
                el.textContent = 'Card not found';
            }
        });
}
```

In the global `keydown` handler, add:
```javascript
if (e.key === 'F3') {
    e.preventDefault();
    const li = document.getElementById('loyaltyInput');
    if (li) { li.focus(); li.select(); }
}
```

- [ ] **Step 5: Include loyalty card ID in sale payload**

In the sale POST payload assembly, add:
```javascript
loyalty_card_id: loyaltyCard ? loyaltyCard.id : null,
```

In `api/sales.php`, after inserting the sale, if `loyalty_card_id` is set:
```php
if (!empty($data['loyalty_card_id'])) {
    $pts = (int)floor((float)$data['total_amount']);
    $db->execute(
        "UPDATE loyalty_cards SET points = points + ?, total_spent = total_spent + ? WHERE id=?",
        [$pts, $data['total_amount'], (int)$data['loyalty_card_id']], "idi"
    );
    $db->execute("UPDATE sales SET loyalty_card_id=?, points_earned=? WHERE id=?",
        [(int)$data['loyalty_card_id'], $pts, $sale_id], "iii"
    );
}
```

After sale success, reset loyalty state:
```javascript
loyaltyCard = null;
if (document.getElementById('loyaltyInput')) document.getElementById('loyaltyInput').value = '';
if (document.getElementById('loyaltyInfo'))  document.getElementById('loyaltyInfo').textContent = '';
```

- [ ] **Step 6: Add loyalty card management tab to Master Data**

In `pages/master-data.php`, add a "Loyalty Cards" tab:
- Table: Card Number | Holder Name | Phone | Points | Total Spent | Active
- Search by name/number/phone
- "Issue New Card" button → modal with Name + Phone fields → calls `api/loyalty.php?action=create`

- [ ] **Step 7: Verify**

1. Feature toggle ON → loyalty input appears in POS ✓
2. Feature toggle OFF → loyalty input absent ✓
3. Type a card number → holder name + points shown ✓
4. Complete sale with card → loyalty_cards.points increases by total_amount ✓
5. Issue new card from Master Data → card created with unique LC number ✓

- [ ] **Step 8: Commit**

```bash
git add database/migration_v5.sql api/loyalty.php pages/pos.php pages/master-data.php api/sales.php
git commit -m "feat: loyalty card system with points earning, POS integration, and management UI"
```

---

## PHASE 9 — Reports Expansion

---

### Task 21: Business-analyst reports

**Files:**
- Modify: `pages/reports.php`
- Modify: `api/sales-analytics.php`
- Modify: `database/migration_v5.sql`
- Modify: `pages/products.php`

- [ ] **Step 1: Add `cost_price` column to products**

Append to `database/migration_v5.sql`:
```sql
-- ── Products: cost price for margin reporting ─────────────
ALTER TABLE products ADD COLUMN cost_price DECIMAL(10,2) NULL AFTER price_wholesale;
```

Run:
```bash
mysql -u root -p grocery_pos < database/migration_v5.sql
```
Also add a "Cost Price" input field to the product add/edit form in `pages/products.php`.

- [ ] **Step 2: Add "Dead Stock" report endpoint**

In `api/sales-analytics.php`:
```php
if ($action === 'slow_stock') {
    $days = max(1, (int)($_GET['days'] ?? 30));
    $rows = $db->fetchAll(
        "SELECT p.id, p.name, p.quantity, p.min_quantity, p.price_retail,
                COALESCE(SUM(si.quantity), 0) AS units_sold,
                MAX(s.created_at) AS last_sold_at
         FROM products p
         LEFT JOIN sale_items si ON si.product_id = p.id
         LEFT JOIN sales s ON s.id = si.sale_id AND s.voided = 0
                           AND s.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
         WHERE p.active = 1
         GROUP BY p.id
         HAVING units_sold < 3
         ORDER BY units_sold ASC, p.quantity DESC
         LIMIT 50",
        [$days], "i"
    );
    echo json_encode($rows); exit;
}
```

- [ ] **Step 3: Add "Top Products" report endpoint**

```php
if ($action === 'top_products') {
    $from = sanitize($_GET['from'] ?? date('Y-m-01'));
    $to   = sanitize($_GET['to']   ?? date('Y-m-d'));
    $rows = $db->fetchAll(
        "SELECT p.name,
                SUM(si.quantity)              AS units_sold,
                SUM(si.quantity * si.unit_price) AS revenue
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id AND s.voided = 0
                      AND DATE(s.created_at) BETWEEN ? AND ?
         JOIN products p ON p.id = si.product_id
         GROUP BY si.product_id
         ORDER BY revenue DESC LIMIT 20",
        [$from, $to], "ss"
    );
    echo json_encode($rows); exit;
}
```

- [ ] **Step 4: Add "Hourly Heatmap" report endpoint**

```php
if ($action === 'hourly_heatmap') {
    $from = sanitize($_GET['from'] ?? date('Y-m-01'));
    $to   = sanitize($_GET['to']   ?? date('Y-m-d'));
    $rows = $db->fetchAll(
        "SELECT DAYOFWEEK(created_at)-1 AS dow,
                HOUR(created_at)        AS hr,
                COUNT(*)                AS txns,
                SUM(total_amount)       AS revenue
         FROM sales
         WHERE voided = 0 AND DATE(created_at) BETWEEN ? AND ?
         GROUP BY dow, hr",
        [$from, $to], "ss"
    );
    echo json_encode($rows); exit;
}
```

- [ ] **Step 5: Add "Margin Analysis" report endpoint**

```php
if ($action === 'margin') {
    $from = sanitize($_GET['from'] ?? date('Y-m-01'));
    $to   = sanitize($_GET['to']   ?? date('Y-m-d'));
    $rows = $db->fetchAll(
        "SELECT p.name,
                SUM(si.quantity)                   AS units_sold,
                SUM(si.quantity * si.unit_price)   AS revenue,
                SUM(si.quantity * COALESCE(p.cost_price, 0)) AS cogs,
                CASE WHEN SUM(si.quantity * si.unit_price) > 0
                     THEN ROUND(
                         (SUM(si.quantity * si.unit_price) - SUM(si.quantity * COALESCE(p.cost_price,0)))
                         / SUM(si.quantity * si.unit_price) * 100, 1)
                     ELSE 0 END AS margin_pct
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id AND s.voided = 0
                      AND DATE(s.created_at) BETWEEN ? AND ?
         JOIN products p ON p.id = si.product_id
         GROUP BY si.product_id
         ORDER BY margin_pct ASC LIMIT 20",
        [$from, $to], "ss"
    );
    echo json_encode($rows); exit;
}
```

- [ ] **Step 6: Wire up report tabs in reports.php**

For each of the four reports, add a tab in `pages/reports.php`:

**Dead Stock tab:**
- Input: "Days to look back" (default 30)
- Fetch button calls `api/sales-analytics.php?action=slow_stock&days=N`
- Table columns: Product | In Stock | Units Sold (last N days) | Last Sold
- Red highlight rows where `units_sold === 0` (dead stock)
- Print button using `openPrintWindow(html)` with branded layout

**Top Products tab:**
- Date range pickers (from/to)
- Fetch button calls `?action=top_products&from=X&to=Y`
- Table: Rank | Product | Units Sold | Revenue
- Print button

**Hourly Heatmap tab:**
- Date range pickers
- Fetch button calls `?action=hourly_heatmap&from=X&to=Y`
- Render a CSS grid: 7 rows (Mon–Sun) × 24 columns (hours 0–23)
- Cell background: `rgba(211,47,47, ${opacity})` where `opacity = revenue / maxRevenue`
- Legend: "Busier = darker"

**Margin Analysis tab:**
- Date range pickers
- Fetch button calls `?action=margin&from=X&to=Y`
- Table: Product | Units Sold | Revenue | COGS | Margin %
- Red highlight rows where `margin_pct < 10`
- Note at top: "Set cost price on each product to enable this report"

- [ ] **Step 7: Verify**

1. Reports → Dead Stock → run → table shows products with 0 sales in last 30 days ✓
2. Top Products → current month → top 20 by revenue listed ✓
3. Heatmap → 7×24 grid appears, busier cells visibly darker ✓
4. Margin → rows sorted by lowest margin, red rows for < 10% ✓
5. Print button on each tab produces branded printable output ✓

- [ ] **Step 8: Commit**

```bash
git add pages/reports.php api/sales-analytics.php database/migration_v5.sql pages/products.php
git commit -m "feat(reports): dead stock, top products, hourly heatmap, margin analysis reports"
```

---

## Final Steps

After all phases are complete:

- [ ] **Run the complete migration cleanly**

```bash
mysql -u root -p grocery_pos < database/migration_v5.sql
# Expected: no errors, all ALTER TABLE statements succeed
```

- [ ] **Smoke-test core flows**

1. Login as cashier → POS opens ✓
2. Scan barcode → item in cart ✓
3. Complete cash sale → receipt prints ✓
4. End shift → remittance recorded ✓
5. Login as manager → Z-Read → prints ✓
6. Login as admin → settings → upload logo → logo appears everywhere ✓

- [ ] **Final commit**

```bash
git add database/migration_v5.sql
git commit -m "chore(migration): finalise migration_v5 with all phase columns"
```

---

## Self-Review

**Spec coverage check:**

| Finding | Task |
|---|---|
| POS cart stops adding after first item | Task 1 ✓ |
| Logout invisible in dark mode | Task 2 ✓ |
| Products retail/wholesale tags dark mode | Task 3 ✓ |
| POS logo not appearing | Task 4 ✓ |
| Other POS dark mode issues | Task 5 ✓ |
| Single barcode+search field, list view, arrow keys | Task 6 ✓ |
| Qty shortcut (* key) | Task 7 ✓ |
| Product list view, wider cart, unit labels | Task 8 ✓ |
| Refresh price list button | Task 9 ✓ |
| Auto-print receipt — no confirmation | Task 10 ✓ |
| End-of-shift cashier cash remittance | Task 11 ✓ |
| Logo + business name editable (white-label) | Task 12 ✓ |
| Feature toggles (loyalty, GCash, etc.) | Task 13 ✓ |
| Tier barcodes + box scanning + items-per-unit | Task 14 ✓ |
| Inventory branded printable export | Task 15 ✓ |
| User address field | Task 16 ✓ |
| More supplier fields + branded export | Task 17 ✓ |
| PH POS law research (BIR, ATP, receipts) | Task 18 ✓ |
| BIR-compliant receipt layout | Task 19 ✓ |
| Loyalty card feature | Task 20 ✓ |
| Reports expansion (business analyst-level) | Task 21 ✓ |

**Deferred by design:**
- "Run for decades" — addressed by migration versioning, InnoDB, atomic receipt counter; no separate task needed.
- Mobile PDF — the Blob URL print approach (Task 10) triggers the browser's "Save as PDF" on mobile automatically.
- `api/products.php` endpoint name — Tasks 3 and 14 reference the tier save handler; implementer should `grep -r "product_price_tiers" api/` to find the correct file name.
