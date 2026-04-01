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

### Phase 3–6: See PLAN.md

## Progress Log

### 2026-04-01 — Phase 1 Start

**migration_v4.sql** — Created. New tables: `business_settings`, `roles`, `role_permissions`, `receipt_counter`, `journal_entries`, `ledger_accounts`, `refunds`, `pos_held_carts`, `remittances`. ALTERs: `users` (add `role_id`), `sales` (receipt_number, customer, discount, refund fields), `sale_items` (per-item discount), `products` (rename `price_sarisar` → `price_wholesale`), `product_price_tiers` (add `price_mode`), `activity_log` (severity, old/new value). Seeds system roles + permissions + chart of accounts.

**constants.php** — Added `VAT_INCLUSIVE` fallback constant, permission key constants (`PERM_DASHBOARD`, `PERM_POS`, etc.), comments noting DB is authoritative for VAT config.

**helpers.php** — Done. Added: `hasPermission()`, `getBusinessSettings()` (cached), `isVATRegistered()`, `getVATRate()`, `calculateVATInclusive()`, `calculateVATExclusive()`, `computeVAT()`, `getNetAmount()`, `generateReceiptNumber()` (atomic with SELECT FOR UPDATE). Updated `hasAccess()` to delegate to permissions first with legacy fallback. Updated `setUserSession()` to accept `$db` and load permissions. Updated `logActivity()` with severity + old/new value params.

**database.php** — Added `beginTransaction()`, `commit()`, `rollback()` methods to `Database` class.

**navbar.php** — Switched all `hasRole()` checks to `hasAccess()` calls, which now delegate to the permission system. Each nav item checks its corresponding permission. Custom roles with the right permissions now see the correct menu items.

**auth/login.php** — Updated user SELECT to include `role_id`. Passes `$db` to `setUserSession()` so permissions are loaded from `role_permissions` table on login.

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
