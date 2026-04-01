# CLAUDE.md — J&J Grocery POS

## Project Overview

PHP/MySQL grocery POS system for a Philippine store. No framework — vanilla PHP with a custom `Database` class. Philippine-specific: 12% VAT, GCash payments, peso currency.

**Mac dev environment:** Homebrew PHP + Homebrew MySQL (not XAMPP). Run with `php -S localhost:8000` from project root. Start MySQL with `brew services start mysql`.

## Tech Stack

- **Backend:** PHP 8.x (vanilla, no framework)
- **Database:** MySQL via `mysqli` with prepared statements
- **Frontend:** Vanilla HTML/CSS/JS, dark/light theme support
- **Server:** Homebrew PHP (`php -S localhost:8000`) on Mac; XAMPP on Windows

## Key Directories

- `config/` — constants, helpers, database connection
- `pages/` — full-page PHP views (dashboard, pos, products, etc.)
- `api/` — JSON API endpoints (sales, inventory, master-data, etc.)
- `auth/` — login/logout handlers
- `templates/` — navbar, header, footer partials
- `database/` — SQL schema + migration files (v1 → v4)
- `public/` — static assets (css, js, images)

## Database

- Schema: `database/database.sql` (base)
- Migrations: `migration_v2.sql`, `migration_v3.sql`, `migration_v4.sql`
- Run migrations in order.
- **MySQL compatibility:** `ADD COLUMN IF NOT EXISTS` is MariaDB-only syntax. All migration files have been updated to use plain `ADD COLUMN` for MySQL 8+/9+ (Homebrew). Do not add `IF NOT EXISTS` back to `ALTER TABLE ADD COLUMN` statements.

## Auth System

- Bcrypt passwords, session-based auth, CSRF tokens
- Roles stored in `roles` table (v4+), permissions in `role_permissions`
- Session keys: `user_id`, `user_name`, `username`, `role`, `role_id`, `permissions`
- `hasPermission($perm)` is the new way to check access (v4+)
- `hasRole($role)` and `hasAccess($feature)` remain for backward compat

## Build Order (from PLAN.md)

### Phase 1: Foundation — DONE
1. `database/migration_v4.sql` — DONE
2. `config/constants.php` updates — DONE
3. `config/helpers.php` updates — DONE
4. `config/database.php` updates (transaction methods) — DONE
5. `templates/navbar.php` (permission-based nav) — DONE
6. `auth/login.php` (load permissions into session) — DONE

### Phase 2: Products + Inventory — DONE
5. `pages/products.php` rework — DONE
6. `pages/inventory.php` sync — DONE

### Phase 3: POS Terminal — DONE
7. `pages/pos.php` full rewrite — DONE
8. `api/sales.php` v4 — DONE
9. `api/search-product.php` update — DONE
10. `api/held-carts.php` (new) — DONE

### Phase 4: Manager Portal — DONE
11. `pages/manager.php` expansion (10 tabs) — DONE
12. Journal auto-generation (in api/sales.php) — DONE
13. Ledger aggregation (in manager.php) — DONE
14. Remittance rework (v4 denomination grid) — DONE
15. `api/refunds.php` (new) — DONE
16. Audit trail tab — DONE

### Phase 5: Reports + Users + Master Data — IN PROGRESS
17. `pages/reports.php` expansion — TODO
18. `pages/users.php` rework — DONE
19. `pages/master-data.php` rework — DONE
20. `api/roles.php` (new) — DONE
21. `api/settings.php` (new) — DONE
22. `navbar.php` update — DONE (Phase 1)

### Phase 6: See PLAN.md

## Progress Log

### 2026-04-01 — Phase 1 Start

**migration_v4.sql** — Created. New tables: `business_settings`, `roles`, `role_permissions`, `receipt_counter`, `journal_entries`, `ledger_accounts`, `refunds`, `pos_held_carts`, `remittances`. ALTERs: `users` (add `role_id`), `sales` (receipt_number, customer, discount, refund fields), `sale_items` (per-item discount), `products` (rename `price_sarisar` → `price_wholesale`), `product_price_tiers` (add `price_mode`), `activity_log` (severity, old/new value). Seeds system roles + permissions + chart of accounts.

**constants.php** — Added `VAT_INCLUSIVE` fallback constant, permission key constants (`PERM_DASHBOARD`, `PERM_POS`, etc.), comments noting DB is authoritative for VAT config.

**helpers.php** — Done. Added: `hasPermission()`, `getBusinessSettings()` (cached), `isVATRegistered()`, `getVATRate()`, `calculateVATInclusive()`, `calculateVATExclusive()`, `computeVAT()`, `getNetAmount()`, `generateReceiptNumber()` (atomic with SELECT FOR UPDATE). Updated `hasAccess()` to delegate to permissions first with legacy fallback. Updated `setUserSession()` to accept `$db` and load permissions. Updated `logActivity()` with severity + old/new value params.

**database.php** — Added `beginTransaction()`, `commit()`, `rollback()` methods to `Database` class.

**navbar.php** — Switched all `hasRole()` checks to `hasAccess()` calls, which now delegate to the permission system. Each nav item checks its corresponding permission. Custom roles with the right permissions now see the correct menu items.

**auth/login.php** — Updated user SELECT to include `role_id`. Passes `$db` to `setUserSession()` so permissions are loaded from `role_permissions` table on login.

### 2026-04-01 — Phase 3: POS Terminal

**api/sales.php** — Full v4 rewrite:
- Auth: `hasRole()` → `hasAccess('pos')`
- Price mode: 'retail'/'wholesale' (dropped 'pack'); resolves `price_wholesale` from DB, falls back to `price_retail`
- Per-item discounts: `discount_type` (percent/fixed/none) + `discount_value` per cart item; server validates and applies
- Transaction discount: `discount_type` + `discount_value` at sale level, applied after item subtotals summed
- VAT: reads `business_settings` via `getBusinessSettings()`; supports inclusive back-computation and exclusive add-on; Non-VAT businesses skip VAT
- Receipt number: calls `generateReceiptNumber($db)` inside transaction (atomic SELECT FOR UPDATE)
- Sale INSERT: includes `receipt_number`, `customer_name`, `price_mode`, `discount_amount`, `discount_type`, `discount_value`
- sale_items INSERT: includes `discount_amount`, `discount_type`, `discount_value`, `price_tier`
- Journal auto-generation: Debit cash account (1010/1011/1012), Credit Sales Revenue (4010), Credit VAT Payable (2010), Debit Sales Discounts (4020 if discount > 0); checks SHOW TABLES first (graceful skip if table missing)
- Ledger balance update: `UPDATE ledger_accounts SET balance = balance + ?` for affected accounts
- logActivity: includes severity='info', details include receipt number
- Response: returns `receipt_number`, `vat_inclusive`, `vat_registered`, `customer_name`, `discount`

**pages/pos.php** — Full v4 rewrite:
- Auth: `hasAccess('pos')` (permission-based)
- Day-lock check: reads `business_settings.day_closed`; if today, shows full-screen blocking overlay
- SQL: `price_wholesale` instead of `price_sarisar`/`price_bulk`; tier fetch includes `price_mode`
- Tier building: fallback creates 'retail' + 'wholesale' tiers from base prices if no explicit tiers
- VAT settings passed to JS as `VAT_RATE`, `VAT_INCLUSIVE`, `VAT_REGISTERED` constants from PHP
- Business info (`BIZ_NAME`, `BIZ_ADDRESS`, `BIZ_TIN`) passed to JS for receipt printing
- **Retail/Wholesale toggle** in header (replaces Retail/Pack/Bulk); `setPriceMode()` recalculates all cart prices on switch; wholesale mode turns header blue
- `getPrice(p, mode)`: resolves by `price_mode` attribute on tiers; falls back to `price_retail`/`price_wholesale`
- **Customer name** input above totals (optional, passed to checkout API)
- **Per-item discount** button (%) on each cart row → Item Discount modal (percent/fixed/remove)
- **Transaction discount** button below totals → Transaction Discount modal (percent/fixed/remove); shown as colored row with amount
- `computeTotals()`: JS function that recomputes subtotal, txnDiscAmt, vat, total client-side (matches server logic)
- **Held carts**: hold button `Ctrl+H`, resume modal `Ctrl+R`; header badge "Held N/3"; `loadHeldCarts()` fetches from API on load and after changes
- Receipt modal: shows `receipt_number` (JJ-XXXXXX), customer name, price mode, per-item discounts, VAT-inclusive breakdown (VATable Sales + VAT or Non-VAT notice), business name/address/TIN
- `doPay()`: payload includes `price_mode`, `discount_type`, `discount_value`, `customer_name`, per-item `discount_type`/`discount_value`
- Keyboard shortcuts updated: `Ctrl+1/2/3` (pay), `Ctrl+H` (hold), `Ctrl+R` (resume), `Ctrl+M` (toggle mode), `Ctrl+B` (focus scanner), `Ctrl+Shift+C` (clear cart)

**api/search-product.php** — Updated:
- Auth: `hasAccess('pos')` 
- SQL: `price_wholesale` instead of `price_sarisar`/`price_bulk`
- Tier fetch: adds `price_mode` column
- Fallback tier building: creates 'retail'/'wholesale' tiers (not 'Pack'/'Bulk')

**api/held-carts.php** — New file:
- GET: lists held carts for current cashier (decoded `cart_data` JSON)
- POST action=hold: saves cart + txn_discount + customer_name as JSON blob; enforces max 3 per cashier
- POST action=resume: fetches row, deletes it, returns `cart`, `txn_discount`, `price_mode`, `customer_name`
- POST action=delete: deletes by id + cashier_id (ownership check)
- CSRF validation on all POST actions

### 2026-04-01 — Phase 2: Products + Inventory

**pages/products.php** — Phase 2 rework:
- Access check: `hasRole('admin')` → `hasAccess('products')` (permission-based, supports custom roles)
- SQL: replaced `price_sarisar, price_bulk` → `price_wholesale` in SELECT and INSERT to match v4 schema
- `$syncTiers`: added `price_mode` field (retail/wholesale/both); syncs first retail-mode tier → `price_retail`, first wholesale-mode tier → `price_wholesale`
- Tier INSERT now includes `price_mode` column
- Filter bar: added "Price Mode" dropdown (Retail/Wholesale/All); rows carry `data-mode` attribute for client-side filtering
- Table rows: tier pills now color-coded (green=retail, blue=wholesale, neutral=both) with R/W mode tag
- Tier editor in modal: added "Mode" column (select: Retail/Wholesale/Both)
- `addTierRow()` JS: added `mode` parameter, renders a `<select name="tier_mode[]">`
- `openAddModal()`: seeds "Retail" (mode=retail) and "Wholesale" (mode=wholesale) tiers instead of "Retail"/"Pack"
- `editProduct()`: passes `t.price_mode` to `addTierRow()`; fallback seeds Retail + Wholesale tiers if no tiers exist
- Added CSS: `.tier-pill--retail`, `.tier-pill--wholesale`, `.tier-pill--both`, `.tier-mode-tag`

**pages/inventory.php** — Phase 2 sync:
- Access check: dual `hasRole()` → `hasAccess('inventory')` (permission-based)
- SQL: removed `price_sarisar, price_bulk, bulk_unit`; added `price_wholesale`
- Added: tiers map fetch (`product_price_tiers`, color-coded by price_mode)
- Added: last sold date map (`MAX(s.created_at)` from sale_items JOIN sales WHERE voided=0)
- Added: reorder suggestion — if qty < min, shows "Order ~N" (targets 2× min level)
- Column header "Retail / Pack / Bulk" → "Retail / Wholesale"
- Pricing cell: shows retail (dark) + wholesale (blue) prices; tier pills below in color-coded badges
- Added "Last Sold" column showing formatted date or "—"
- CSV export: updated to 9 columns (added Wholesale Price, Last Sold); fixed Blob constructor bug (was `new Blob([str, {type:...}])` — missing closing bracket, now `new Blob([str], {type:...})`)

### 2026-04-01 — Phase 5: Users + Master Data + APIs

**pages/users.php** — Full v4 rewrite:
- Auth: `hasAccess('users')` (permission-based, replaces `hasRole('admin')`)
- **Users tab**: table with name, username, email, phone, emp ID, role (from `roles` table), joined date. Add/Edit/Password/Remove modals. Role dropdown now fetched from `roles` table (supports custom roles). INSERT/UPDATE sets both legacy `role` column and new `role_id`. Activity logging on create/edit/delete.
- **Roles tab**: card grid showing all roles with permission pills, user count, system badge. Create Role modal with name + permission checkbox grid (10 permissions). Edit role (pre-fills existing permissions). Delete custom roles (blocked if users assigned). System roles (admin/manager/cashier/inventory_checker) cannot be deleted.
- Role badge colors: admin=danger, manager=warning, cashier=success, inventory_checker=info, custom=indigo

**pages/master-data.php** — Full v4 rewrite:
- Auth: `hasAccess('master_data')` (permission-based)
- **Categories tab**: card grid layout (replaces plain table). Each card shows name + product count badge. Search bar with live filter. Add/Edit modals. Delete with product count warning.
- **Suppliers tab**: card grid layout. Each card shows name, contact, email, product count. Search + filter. Add/Edit/Delete.
- **Business Settings tab** (new): Store info (name, TIN, address), VAT configuration (registered toggle, inclusive toggle, rate input), receipt options (prefix, currency symbol). Saves via AJAX to `api/settings.php`. Toggle switches for boolean settings. Activity logged as 'critical' with old/new values.
- All tabs use `history.replaceState` for bookmarkable tab URLs

**api/settings.php** — New file:
- GET: returns current `business_settings` row via `getBusinessSettings()`
- POST `action=save`: updates business_name, address, TIN, vat_registered, vat_rate, vat_inclusive, receipt_prefix, currency_symbol. Validates business_name required, vat_rate 0–1. Clears cached settings. Logs change summary to activity_log with severity 'critical' and old/new values.
- Auth: `hasAccess('master_data')` or `hasAccess('settings')`

**api/roles.php** — New file:
- GET: lists all roles with permissions array + user_count. GET `?id=N` for single role.
- POST `action=create`: creates custom role with name, slug (auto-generated), permissions. Validates no duplicate name/slug. Logs as 'warning'.
- POST `action=update`: updates role name + replaces permissions. System roles keep their slug. Refreshes session permissions if current user affected. Logs as 'warning'.
- POST `action=delete`: deletes custom role only if no users assigned. System roles blocked. Logs as 'critical'.
- Auth: `hasAccess('users')`
- All available permissions: dashboard, pos, products, inventory, master_data, users, manager_portal, reports, settings, audit_trail

**api/master-data.php** — Updated auth from `hasRole('admin')` to `hasAccess('master_data')`.

### 2026-04-01 — Phase 4: Manager Portal

**pages/manager.php** — Full v4 expansion (1494 lines, 10 tabs):
- Auth: `hasAccess('manager_portal')` (permission-based)
- **X-Read tab**: Real-time sales snapshot — receipt range, gross sales, discounts, refunds, VAT, payment breakdown (cash/gcash/card), cash accountability (cash sales - change given), void summary. Chart.js doughnut for payment mix. Generate X-Read button (saves to register_reads, can run multiple times).
- **Z-Read tab**: End-of-day report — same data as X-Read plus voids deducted from net sales. Locks day via `business_settings.day_closed = CURDATE()`. Confirmation dialog. Cannot run twice per day. Logs as 'critical' severity.
- **Remittance tab**: v4 denomination grid (₱1000/500/200/100/50/20 + coins). Cashier selection dropdown with auto-populated expected cash (cash_sales - change_given). Real-time recalculation of actual total and over/short variance. Color-coded (green=exact/over, red=short). Saves to `remittances` table with fallback to legacy `cash_remittals`.
- **Cashier Summary tab**: Per-cashier breakdown — txn count, gross, discounts, VAT, cash/gcash/card, expected cash, avg transaction. Grand totals row.
- **Void Log tab**: Today's voided transactions — receipt #, cashier, amount, payment method, sale time, voided by, voided at, reason. Count + total badge in tab.
- **Journal tab**: Date picker (GET param `jdate`). Entries grouped by reference (sale/void/refund/adjustment). Color-coded ref badges. Debit/credit columns with account codes. Total debits/credits footer.
- **Ledger tab**: Accounts grouped by type (Assets/Liabilities/Equity/Revenue/Expenses). Card layout showing account code, name, type badge, balance, entry count. Live running totals from journal_entries.
- **Refunds tab**: Receipt lookup via AJAX to api/refunds.php. Shows sale details, items, refundable balance. Refund amount input (max capped). Reason textarea (required). Recent refunds sidebar with amount, date, processor, reason excerpt.
- **Audit Trail tab**: Severity filter (All/Info/Warning/Critical) via GET param. Client-side search. Table: time, user, severity badge, action (monospace), details, IP. Read-only, last 200 entries.
- **Read History tab**: Past X-Read/Z-Read records — type badge, date, transactions, gross, discounts, VAT, cash/gcash/card, voids, generated by, time.
- Quick stats bar at top: gross sales, transactions, VAT collected, expected cash, discounts, voids
- Full dark/light theme support with CSS custom properties
- Tab switching with `history.replaceState` for bookmarkable tabs

**api/refunds.php** — New file (215 lines):
- GET `?receipt=JJ-000042`: looks up sale by receipt number, returns sale info + items + already_refunded + refundable balance. Validates not voided, not fully refunded.
- POST `action=process_refund`: creates refund record in `refunds` table, marks sale as refunded (partial or full), auto-generates journal entries (Debit Sales Revenue 4010, Debit VAT Payable 2010, Credit Cash account 1010/1011/1012, Credit Sales Returns & Refunds 4030), updates ledger_accounts balances, logs activity as 'critical'. Full transaction with rollback on error.
- VAT handling: reads business_settings, supports inclusive back-computation and exclusive add-on
- Auth: `hasAccess('manager_portal')`

### 2026-04-01 — Dev Environment Setup (Mac)

**index.php** — Fixed CSS link from hardcoded `href="css/style.css"` to use `CSS_URL` constant (`public/css/main.css` + `public/css/theme.css`).

**config/constants.php** — Fixed `BASE_URL` from `'/'` to `''` to prevent double-slash in generated URLs (e.g. `//public/css`).

**database/migration_v2.sql, migration_v3.sql, migration_v4.sql** — Replaced all `ADD COLUMN IF NOT EXISTS` with `ADD COLUMN` for MySQL 8+/9+ compatibility.

## Errors / Issues

- **`ADD COLUMN IF NOT EXISTS` not supported in MySQL 8+/9+** — this is MariaDB syntax only. Fixed in all migration files by removing `IF NOT EXISTS` from `ALTER TABLE ADD COLUMN` statements. (`CREATE TABLE IF NOT EXISTS` is fine and still used.)
- **`BASE_URL` double-slash bug** — `constants.php` had `BASE_URL = '/'` causing `CSS_URL = '//public/css'`. Fixed by setting `BASE_URL = ''`.
- **`index.php` CSS path** — was hardcoded as `href="css/style.css"` (wrong path). Fixed to use `CSS_URL` constant pointing to `public/css/main.css` and `public/css/theme.css`.

## Conventions

- All SQL migrations are idempotent (safe to re-run)
- `hasPermission()` checks `$_SESSION['permissions']` array (cached on login)
- VAT logic reads from `business_settings` table, falls back to constants
- Receipt numbers are atomic: `SELECT FOR UPDATE` + `UPDATE` on `receipt_counter`
- Never delete sales — only void/refund
