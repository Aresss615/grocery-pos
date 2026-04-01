# CLAUDE.md — J&J Grocery POS

## Project Overview

PHP/MySQL grocery POS system for a Philippine store. Uses XAMPP (Apache + MySQL), no framework — vanilla PHP with a custom `Database` class. Philippine-specific: 12% VAT, GCash payments, peso currency.

## Tech Stack

- **Backend:** PHP 8.x (vanilla, no framework)
- **Database:** MySQL via `mysqli` with prepared statements
- **Frontend:** Vanilla HTML/CSS/JS, dark/light theme support
- **Server:** XAMPP (localhost)

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
- Run migrations in order. All use `IF NOT EXISTS` / `IF NOT EXISTS` for idempotency.

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

### Phase 2–6: See PLAN.md

## Progress Log

### 2026-04-01 — Phase 1 Start

**migration_v4.sql** — Created. New tables: `business_settings`, `roles`, `role_permissions`, `receipt_counter`, `journal_entries`, `ledger_accounts`, `refunds`, `pos_held_carts`, `remittances`. ALTERs: `users` (add `role_id`), `sales` (receipt_number, customer, discount, refund fields), `sale_items` (per-item discount), `products` (rename `price_sarisar` → `price_wholesale`), `product_price_tiers` (add `price_mode`), `activity_log` (severity, old/new value). Seeds system roles + permissions + chart of accounts.

**constants.php** — Added `VAT_INCLUSIVE` fallback constant, permission key constants (`PERM_DASHBOARD`, `PERM_POS`, etc.), comments noting DB is authoritative for VAT config.

**helpers.php** — Done. Added: `hasPermission()`, `getBusinessSettings()` (cached), `isVATRegistered()`, `getVATRate()`, `calculateVATInclusive()`, `calculateVATExclusive()`, `computeVAT()`, `getNetAmount()`, `generateReceiptNumber()` (atomic with SELECT FOR UPDATE). Updated `hasAccess()` to delegate to permissions first with legacy fallback. Updated `setUserSession()` to accept `$db` and load permissions. Updated `logActivity()` with severity + old/new value params.

**database.php** — Added `beginTransaction()`, `commit()`, `rollback()` methods to `Database` class.

**navbar.php** — Switched all `hasRole()` checks to `hasAccess()` calls, which now delegate to the permission system. Each nav item checks its corresponding permission. Custom roles with the right permissions now see the correct menu items.

**auth/login.php** — Updated user SELECT to include `role_id`. Passes `$db` to `setUserSession()` so permissions are loaded from `role_permissions` table on login.

## Errors / Issues

_(none so far)_

## Conventions

- All SQL migrations are idempotent (safe to re-run)
- `hasPermission()` checks `$_SESSION['permissions']` array (cached on login)
- VAT logic reads from `business_settings` table, falls back to constants
- Receipt numbers are atomic: `SELECT FOR UPDATE` + `UPDATE` on `receipt_counter`
- Never delete sales — only void/refund
