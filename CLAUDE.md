# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

J&J Grocery POS — a vanilla PHP/MySQL point-of-sale system for a Philippine grocery store. No framework. Philippine-specific: 12% VAT, GCash payments, peso (₱) currency.

## Development Environment

```bash
# Start MySQL
brew services start mysql

# Run dev server (from project root)
php -S localhost:8000
```

Mac dev uses Homebrew PHP + Homebrew MySQL (not XAMPP). App runs at `http://localhost:8000`.

### Database Setup

```bash
mysql -u root -p grocery_pos < database/database.sql
mysql -u root -p grocery_pos < database/migration_v2.sql
mysql -u root -p grocery_pos < database/migration_v3.sql
mysql -u root -p grocery_pos < database/migration_v4.sql
```

Migrations must run in order (v2 → v3 → v4). DB credentials are in `config/constants.php`.

Default logins: `admin`/`admin123`, `cashier`/`cashier123`.

## Architecture

### Request Flow

- `index.php` — login page entry point
- `pages/*.php` — full-page views (each includes config, checks auth, renders HTML+inline JS)
- `api/*.php` — JSON endpoints called via AJAX from pages (return `json_encode` responses)
- `auth/login.php` / `auth/logout.php` — session management

Pages are self-contained: each PHP file includes its own SQL queries, HTML, CSS, and JavaScript inline. There is no routing layer — URLs map directly to files.

### Key Files

- `config/constants.php` — DB credentials, `BASE_URL`, `VAT_RATE`, permission key constants, CSS/JS URL constants
- `config/database.php` — `Database` class wrapping `mysqli` with `select()`, `fetchOne()`, `fetchAll()`, `execute()`, `beginTransaction()`, `commit()`, `rollback()`
- `config/helpers.php` — auth helpers (`hasPermission()`, `hasAccess()`, `hasRole()`), VAT calculation, receipt number generation, activity logging
- `templates/navbar.php` — permission-based navigation (uses `hasAccess()`)

### Database Class Usage

```php
$db = new Database();
$rows = $db->select("SELECT * FROM products WHERE category_id = ?", [$catId], "i");
$one = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
$db->execute("INSERT INTO products (name, price) VALUES (?, ?)", [$name, $price], "sd");
```

Third param is `mysqli` type string: `s`=string, `i`=int, `d`=double.

### Permission System (v4)

Auth uses a permission-based system, not just roles:

- `roles` table defines roles; `role_permissions` maps roles → permissions
- On login, permissions are loaded into `$_SESSION['permissions']`
- **Use `hasAccess($feature)` for auth checks** — it delegates to `hasPermission()` with legacy fallback
- Available permissions: `dashboard`, `pos`, `products`, `inventory`, `master_data`, `users`, `manager_portal`, `reports`, `settings`, `audit_trail`

### VAT Logic

VAT configuration lives in `business_settings` table (not just constants). Use helper functions:
- `getBusinessSettings()` — cached read from DB
- `isVATRegistered()`, `getVATRate()` — read from settings
- `calculateVATInclusive()` / `calculateVATExclusive()` — compute VAT based on settings
- Constants in `constants.php` are fallbacks only

### Key Business Rules

- Never delete sales — only void or refund
- Receipt numbers are atomic: `SELECT FOR UPDATE` on `receipt_counter` table
- Z-Read locks the day (`business_settings.day_closed = CURDATE()`); POS rejects sales after lock
- Refunds use optimistic locking to prevent concurrent over-refunding
- Journal entries auto-generate on sale, void, and refund (double-entry accounting)

## Conventions

- All API endpoints validate CSRF tokens on POST (`validateCsrf()` or `requireCsrf(true)`)
- Auth checks use `hasAccess('feature')`, not `hasRole('admin')`
- SQL migrations use `ADD COLUMN` (not `ADD COLUMN IF NOT EXISTS` — that's MariaDB-only, incompatible with MySQL 8+/9+)
- `CREATE TABLE IF NOT EXISTS` is fine; `ALTER TABLE ADD COLUMN IF NOT EXISTS` is not
- Price tiers use `price_mode` field: `retail`, `wholesale`, or `both` (not the old pack/bulk system)
- `BASE_URL` is set to `''` (empty string) to avoid double-slash in URLs
- Activity logging includes severity level (`info`, `warning`, `critical`) and old/new values

## Current Status

Phases 1–4 and 6 are complete. Phase 5 is in progress — `pages/reports.php` expansion is the remaining TODO. Everything else (users, master-data, roles API, settings API) is done.
