# J&J Grocery POS — System Overhaul Master Plan

**Version:** 1.0
**Date:** March 31, 2026
**Scope:** Full system redesign — POS, Products, Inventory, Manager, Reports, Users, Master Data

---

## TABLE OF CONTENTS

1. [System Architecture](#1-system-architecture)
2. [Business Configuration (VAT/Non-VAT)](#2-business-configuration)
3. [Database Schema — New & Modified Tables](#3-database-schema)
4. [Module 1: Products Overhaul](#4-products)
5. [Module 2: POS Terminal — Full Rework](#5-pos-terminal)
6. [Module 3: Inventory — Sync with Products](#6-inventory)
7. [Module 4: Master Data — UI Fix](#7-master-data)
8. [Module 5: Users & Custom Roles](#8-users-and-roles)
9. [Module 6: Manager Portal — BIR-Compliant](#9-manager-portal)
10. [Module 7: Reports — Full Expansion](#10-reports)
11. [Migration Strategy](#11-migration)
12. [Build Order & Dependencies](#12-build-order)
13. [Keyboard Shortcut Map](#13-keyboard-shortcuts)

---

## 1. SYSTEM ARCHITECTURE

### Data Flow (Transaction Lifecycle)

```
POS Terminal (sale)
    │
    ├──► sale record (sales table)
    ├──► sale_items (line items)
    ├──► stock decremented (products table)
    ├──► journal_entries (auto-generated double-entry)
    │        │
    │        └──► ledger_accounts (aggregated balances)
    ├──► audit_log (immutable action record)
    └──► receipt generated (sequential, non-resettable)
             │
             └──► X-Read (snapshot, anytime)
                  Z-Read (end-of-day, locks further sales)
                      │
                      └──► Monthly Reports (aggregated)
```

### Core Principles

- **No Deletion** — Sales are never deleted. Only void, refund, or adjustment.
- **Sequential Receipt Numbers** — Non-resettable, gap-free, stored in `receipt_counter` table.
- **Immutable Audit Trail** — Every sensitive action is logged. Logs cannot be edited or deleted.
- **Double-Entry Accounting** — Every transaction auto-generates journal entries (Debit Cash / Credit Sales Revenue / Credit VAT Payable).
- **VAT/Non-VAT Toggle** — Business-level setting. VAT-inclusive means back-computation from selling price.

---

## 2. BUSINESS CONFIGURATION

### New: `business_settings` Table

Stores store-level config. Single row (id=1).

| Setting | Description | Default |
|---------|-------------|---------|
| `business_name` | Store name on receipts | J&J Grocery |
| `business_address` | Address line | — |
| `tin` | Tax Identification Number | — |
| `vat_registered` | `1` = VAT registered, `0` = Non-VAT | 1 |
| `vat_rate` | Decimal (0.12 = 12%) | 0.12 |
| `vat_inclusive` | `1` = prices already include VAT | 1 |
| `receipt_prefix` | e.g. "JJ-" | JJ- |
| `next_receipt_number` | Auto-incrementing, never reset | 1 |
| `currency_symbol` | ₱ | ₱ |
| `day_closed` | Date of last Z-Read (prevents double-close) | NULL |

### VAT Logic

**When `vat_registered = 1` AND `vat_inclusive = 1`:**
```
Selling Price: ₱50.00
VAT Amount:    ₱50.00 × (12/112) = ₱5.36
Net Amount:    ₱50.00 - ₱5.36 = ₱44.64
```

**When `vat_registered = 1` AND `vat_inclusive = 0`:**
```
Net Price:     ₱50.00
VAT Amount:    ₱50.00 × 0.12 = ₱6.00
Total:         ₱56.00
```

**When `vat_registered = 0`:**
```
No VAT computation. Total = Selling Price.
VAT columns show 0 or N/A on receipts.
```

---

## 3. DATABASE SCHEMA

### NEW Tables

#### `business_settings`
```sql
CREATE TABLE business_settings (
    id INT PRIMARY KEY DEFAULT 1,
    business_name VARCHAR(200) NOT NULL DEFAULT 'J&J Grocery',
    business_address TEXT,
    tin VARCHAR(20),
    vat_registered TINYINT(1) NOT NULL DEFAULT 1,
    vat_rate DECIMAL(5,4) NOT NULL DEFAULT 0.1200,
    vat_inclusive TINYINT(1) NOT NULL DEFAULT 1,
    receipt_prefix VARCHAR(10) NOT NULL DEFAULT 'JJ-',
    next_receipt_number INT UNSIGNED NOT NULL DEFAULT 1,
    currency_symbol VARCHAR(5) NOT NULL DEFAULT '₱',
    day_closed DATE NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHECK (id = 1)
) ENGINE=InnoDB;
```

#### `roles` (replaces ENUM-based role system)
```sql
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    slug VARCHAR(50) NOT NULL UNIQUE,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- System roles (cannot be deleted)
INSERT INTO roles (name, slug, is_system) VALUES
('Admin', 'admin', 1),
('Manager', 'manager', 1),
('Cashier', 'cashier', 1),
('Inventory Checker', 'inventory_checker', 1);
```

#### `role_permissions`
```sql
CREATE TABLE role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission VARCHAR(50) NOT NULL,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    UNIQUE KEY uk_role_perm (role_id, permission)
) ENGINE=InnoDB;
```

**Permission keys:**
```
dashboard, pos, products, inventory, master_data,
users, manager_portal, reports, settings, audit_trail
```

#### `receipt_counter` (atomic, gap-free receipt numbers)
```sql
CREATE TABLE receipt_counter (
    id INT PRIMARY KEY DEFAULT 1,
    prefix VARCHAR(10) NOT NULL DEFAULT 'JJ-',
    next_number INT UNSIGNED NOT NULL DEFAULT 1,
    CHECK (id = 1)
) ENGINE=InnoDB;
```

#### `journal_entries`
```sql
CREATE TABLE journal_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_date DATE NOT NULL,
    reference_type ENUM('sale','void','refund','adjustment','remittance') NOT NULL,
    reference_id INT,
    description VARCHAR(255) NOT NULL,
    account_code VARCHAR(20) NOT NULL,
    account_name VARCHAR(100) NOT NULL,
    debit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    credit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_date (entry_date),
    INDEX idx_ref (reference_type, reference_id),
    INDEX idx_account (account_code)
) ENGINE=InnoDB;
```

#### `ledger_accounts`
```sql
CREATE TABLE ledger_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_code VARCHAR(20) NOT NULL UNIQUE,
    account_name VARCHAR(100) NOT NULL,
    account_type ENUM('asset','liability','equity','revenue','expense') NOT NULL,
    balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Seed chart of accounts
INSERT INTO ledger_accounts (account_code, account_name, account_type) VALUES
('1010', 'Cash on Hand', 'asset'),
('1011', 'Cash - GCash', 'asset'),
('1012', 'Cash - Card Payments', 'asset'),
('2010', 'VAT Payable', 'liability'),
('4010', 'Sales Revenue', 'revenue'),
('4020', 'Sales Discounts', 'revenue'),
('4030', 'Sales Returns & Refunds', 'revenue');
```

#### `refunds`
```sql
CREATE TABLE refunds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    original_sale_id INT NOT NULL,
    receipt_number VARCHAR(30) NOT NULL,
    refund_amount DECIMAL(12,2) NOT NULL,
    reason TEXT NOT NULL,
    items JSON,
    processed_by INT NOT NULL,
    approved_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (original_sale_id) REFERENCES sales(id),
    FOREIGN KEY (processed_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sale (original_sale_id),
    INDEX idx_date (created_at)
) ENGINE=InnoDB;
```

#### `pos_held_carts` (max 3 paused carts per register)
```sql
CREATE TABLE pos_held_carts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cashier_id INT NOT NULL,
    register_no VARCHAR(10) NOT NULL DEFAULT 'REG-01',
    label VARCHAR(50),
    cart_data JSON NOT NULL,
    price_mode ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
    customer_name VARCHAR(100),
    held_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cashier_id) REFERENCES users(id),
    INDEX idx_cashier (cashier_id)
) ENGINE=InnoDB;
```

#### `remittances` (replaces/enhances cash_remittals)
```sql
CREATE TABLE remittances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cashier_id INT NOT NULL,
    manager_id INT NOT NULL,
    register_no VARCHAR(10) NOT NULL DEFAULT 'REG-01',
    
    -- Expected (system-computed from sales)
    expected_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    
    -- Actual (from denomination count)
    actual_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    
    -- Breakdown
    bills_1000 INT NOT NULL DEFAULT 0,
    bills_500 INT NOT NULL DEFAULT 0,
    bills_200 INT NOT NULL DEFAULT 0,
    bills_100 INT NOT NULL DEFAULT 0,
    bills_50 INT NOT NULL DEFAULT 0,
    bills_20 INT NOT NULL DEFAULT 0,
    coins DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    
    -- Variance
    over_short DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    
    -- Status
    status ENUM('pending','approved','flagged') NOT NULL DEFAULT 'pending',
    notes TEXT,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (cashier_id) REFERENCES users(id),
    FOREIGN KEY (manager_id) REFERENCES users(id),
    INDEX idx_date (created_at),
    INDEX idx_cashier (cashier_id)
) ENGINE=InnoDB;
```

### MODIFIED Tables

#### `users` — Change role from ENUM to FK
```sql
-- Add role_id column
ALTER TABLE users ADD COLUMN role_id INT NULL AFTER role;

-- Migrate existing role values to role_id
UPDATE users u
JOIN roles r ON r.slug = u.role
SET u.role_id = r.id;

-- Eventually: DROP old role ENUM column (after code migration)
-- ALTER TABLE users DROP COLUMN role;
```

During transition, both `role` (ENUM) and `role_id` (FK) will coexist. The code checks `role_id` first, falls back to `role`.

#### `sales` — Add receipt_number, customer, discount fields
```sql
ALTER TABLE sales ADD COLUMN receipt_number VARCHAR(30) NULL AFTER id;
ALTER TABLE sales ADD COLUMN customer_name VARCHAR(100) NULL AFTER notes;
ALTER TABLE sales ADD COLUMN discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none' AFTER discount_amount;
ALTER TABLE sales ADD COLUMN discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_type;
ALTER TABLE sales ADD COLUMN refunded TINYINT(1) NOT NULL DEFAULT 0 AFTER voided;
ALTER TABLE sales ADD COLUMN refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER refunded;
ALTER TABLE sales ADD COLUMN price_mode ENUM('retail','wholesale') NOT NULL DEFAULT 'retail' AFTER notes;

-- Unique index on receipt_number (must be gap-free)
ALTER TABLE sales ADD UNIQUE INDEX uk_receipt (receipt_number);
```

#### `sale_items` — Add per-item discount
```sql
ALTER TABLE sale_items ADD COLUMN discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none' AFTER discount_amount;
ALTER TABLE sale_items ADD COLUMN discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_type;
ALTER TABLE sale_items ADD COLUMN price_tier VARCHAR(50) NULL AFTER discount_value;
```

#### `products` — Rename price columns for clarity
```sql
-- Rename for semantic clarity
ALTER TABLE products CHANGE price_sarisar price_wholesale DECIMAL(10,2);

-- price_retail stays as-is (retail/normal price)
-- price_wholesale = bulk/wholesale price
-- price_bulk is removed or repurposed (old "dozen" price becomes a custom tier)
```

#### `audit_log` — Enhanced (rename from activity_log)
```sql
-- Extend existing activity_log or create enhanced version
ALTER TABLE activity_log ADD COLUMN severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info' AFTER action;
ALTER TABLE activity_log ADD COLUMN old_value TEXT NULL;
ALTER TABLE activity_log ADD COLUMN new_value TEXT NULL;
```

---

## 4. PRODUCTS (Module 1)

### Pricing Model Change

**Before:** Retail / Sari-Sari (Pack) / Bulk — confusing  
**After:** Two base prices + unlimited custom tiers

| Column | Purpose |
|--------|---------|
| `price_retail` | Default selling price for walk-in customers |
| `price_wholesale` | Price for bulk/wholesale buyers |
| `product_price_tiers` table | Additional named tiers (e.g., "Dozen", "Case/24", "Half Box") |

### POS Integration

- **Retail Mode** → uses `price_retail` from base product. Custom tiers that are tagged `mode=retail` also appear.
- **Wholesale Mode** → uses `price_wholesale` from base product. Custom tiers tagged `mode=wholesale` also appear.

### Product Price Tiers Table — Enhanced

```sql
ALTER TABLE product_price_tiers 
ADD COLUMN price_mode ENUM('retail','wholesale','both') NOT NULL DEFAULT 'both' AFTER sort_order;
```

This lets a tier like "Case of 24" appear in wholesale mode only, while "Per Piece" appears in retail.

### UI Changes (products.php)

- Rename "Sari-Sari" label → "Wholesale" everywhere
- Product form shows: Retail Price, Wholesale Price, then a dynamic tier table
- Each tier row has: Name, Price, Unit Label, Mode (Retail/Wholesale/Both)
- Filter bar: add a "Price Mode" dropdown to see retail vs wholesale prices

---

## 5. POS TERMINAL (Module 2) — Full Rework

### Layout

```
┌─────────────────────────────────────────────────────────────┐
│ HEADER BAR                                                   │
│ [Logo] [Barcode Scanner Input] [Retail/Wholesale] [Clock]    │
│ [Held Carts: 0/3] [Cashier Name] [Theme] [Exit]            │
├────────────────────────────┬────────────────────────────────┤
│ PRODUCT GRID               │ CART                           │
│                            │ ┌────────────────────────────┐ │
│ [Category Tabs]            │ │ Cart Items (scrollable)    │ │
│ [Search Bar]               │ │                            │ │
│                            │ │ Item Name         ₱50.00  │ │
│ ┌──┐ ┌──┐ ┌──┐ ┌──┐     │ │  2 × ₱25.00  [Qty] [Del]  │ │
│ │  │ │  │ │  │ │  │     │ │                            │ │
│ └──┘ └──┘ └──┘ └──┘     │ │ Customer: [optional]       │ │
│ ┌──┐ ┌──┐ ┌──┐ ┌──┐     │ ├────────────────────────────┤ │
│ │  │ │  │ │  │ │  │     │ │ Subtotal:         ₱100.00  │ │
│ └──┘ └──┘ └──┘ └──┘     │ │ Discount:          -₱5.00  │ │
│                            │ │ VAT (12%):        ₱10.71  │ │
│                            │ │ ──────────────────────────│ │
│                            │ │ TOTAL:            ₱95.00  │ │
│                            │ ├────────────────────────────┤ │
│                            │ │ [CASH F1] [GCASH F2] [CARD]│ │
│                            │ └────────────────────────────┘ │
├────────────────────────────┴────────────────────────────────┤
│ SHORTCUT BAR (see §13 for full map)                          │
└─────────────────────────────────────────────────────────────┘
```

### Barcode Scanner Behavior

The scanner input field at the top is ALWAYS focused (auto-refocus after every action).

**How it works:**

1. Scanner hardware sends characters rapidly (< 50ms between chars) and ends with Enter.
2. Manual typing is slower (> 50ms between chars).

**Detection logic:**
```
- Track timing between keystrokes
- If full string received in < 300ms total → SCANNER → auto-add to cart immediately
- If typing is slow (> 100ms between keys) → MANUAL → wait for Enter key
- After adding, clear input and refocus
```

**After scan/enter:**
1. Look up barcode in client-side product cache
2. If found → add to cart (or increment qty if already in cart)
3. If not found → server-side fallback via `api/search-product.php`
4. If still not found → show toast "Barcode not found"

### Cart Features

#### Quantity Editing
- Click quantity number → opens inline input, type new qty, press Enter
- `+` / `-` buttons on each item
- Keyboard: select item with ↑↓ arrows, then type number + Enter to set qty
- **Shortcut: Ctrl+Q** → focus quantity input of selected cart item

#### Void Individual Item
- Click ✕ on item → removes from cart with confirmation
- **Shortcut: Ctrl+Delete** → void selected item
- Voided items before checkout are just removed (no audit needed)
- Voided items AFTER checkout → logged in audit trail

#### Per-Item Discount
- Click discount icon on item → modal: Percent or Fixed amount
- Shows original and discounted price
- **Shortcut: Ctrl+D** on selected item

#### Transaction-Level Discount
- Button in totals area → modal: Percent or Fixed
- Applied AFTER item subtotals are summed
- Both per-item and transaction discounts can coexist

#### Customer Name (Optional)
- Small input field above totals area
- Optional — if filled, prints on receipt
- Useful for wholesale orders

### Price Mode Toggle (Retail / Wholesale)

- Toggle in header bar: **Retail** | **Wholesale**
- Switching mode recalculates ALL items in cart using the other price column
- If an item has no wholesale price → falls back to retail price
- Custom tiers filtered by mode
- Visual indicator: Retail = normal, Wholesale = blue accent border

### Held Carts (Pause/Resume)

**Max 3 held carts per cashier.**

- **Hold current cart:** Shortcut `Ctrl+H` or button
  - Saves cart to `pos_held_carts` table (JSON blob of items, prices, customer, mode)
  - Cart area clears for new transaction
  - Header shows "Held: 1/3", "Held: 2/3", etc.

- **Resume held cart:** Shortcut `Ctrl+R` or button
  - Shows list of held carts with: label, item count, total, time held
  - Click to resume → loads cart back, deletes from held table
  - If current cart has items → must hold or clear first

- **Delete held cart:** Swipe or delete button on held list

### Refresh Products Button

- Button in product panel: "🔄 Refresh Products"
- **Shortcut: Ctrl+F5** (won't conflict with browser F5)
- Fetches latest product data from server without full page reload
- Shows toast "Products updated — X products loaded"

### Receipt Generation

On successful checkout:

1. Atomically increment `receipt_counter.next_number` (SELECT FOR UPDATE + UPDATE)
2. Generate receipt number: `JJ-000001`, `JJ-000002`, etc.
3. Save to `sales.receipt_number`
4. Generate journal entries automatically
5. Show receipt modal with print option

**Receipt Format:**
```
═══════════════════════════════
      J&J GROCERY
    [Address Line]
   TIN: XXX-XXX-XXX-XXX
    VAT Registered
═══════════════════════════════
Receipt #: JJ-000042
Date: 03/31/2026 2:15 PM
Cashier: Maria Reyes
Customer: Juan Dela Cruz
Mode: Retail
─────────────────────────────── 
Qty  Item              Amount
───────────────────────────────
 2   Rice 2kg          ₱300.00
 1   Cooking Oil       ₱280.00
       Disc: -10%      -₱28.00
 3   Canned Tuna       ₱135.00
───────────────────────────────
Subtotal:              ₱687.00
Transaction Discount:   -₱0.00
───────────────────────────────
VAT-Inclusive Total:   ₱687.00
  (VATable Sales:      ₱613.39)
  (VAT 12%:            ₱73.61)
───────────────────────────────
Amount Paid:           ₱700.00
Change:                 ₱13.00
Payment: CASH
═══════════════════════════════
  Thank you for shopping
       at J&J Grocery!
  This serves as your
     Official Receipt.
═══════════════════════════════
```

For **Non-VAT** businesses, the VAT section is replaced with:
```
Non-VAT Registered
Total:                 ₱687.00
```

### Z-Read Day Lock

After Z-Read is generated:
- `business_settings.day_closed` is set to today's date
- POS Terminal checks on load: if `day_closed = TODAY` → show message "Day has been closed. No more transactions today."
- New sales are blocked until the next calendar day

---

## 6. INVENTORY (Module 3)

### Changes

- Sync with new product pricing model (retail + wholesale columns)
- Show both prices in table
- Stock update form stays the same
- Add: "Last Sold" column (from most recent sale_items entry)
- Add: "Reorder Suggestion" — if qty < min_quantity, show suggested reorder amount

### UI

- Keep existing filter bar (search, category, supplier, stock status)
- Replace "Retail / Pack / Bulk" column → "Retail / Wholesale"
- Add per-tier pricing pills if product has custom tiers
- CSV export includes new column structure

---

## 7. MASTER DATA (Module 4) — UI Fix

### Current Issues
- Modal forms are basic
- Category/Supplier lists lack visual hierarchy
- No search or pagination

### Redesign

**Categories Tab:**
- Card grid layout instead of plain table
- Each card shows: category name, product count, edit/delete actions
- Search bar at top
- "Add Category" as prominent button

**Suppliers Tab:**
- Card-based or enhanced table with better spacing
- Show: name, contact, email, product count
- Quick-edit inline or modal
- Search + filter

**New Tab: Business Settings**
- Business Name, Address, TIN
- VAT toggle (Registered / Non-VAT)
- VAT Rate input
- VAT Inclusive toggle
- Receipt prefix
- Save button

This replaces hardcoded `constants.php` values — the system reads from `business_settings` table.

---

## 8. USERS & CUSTOM ROLES (Module 5)

### How Custom Roles Work

**System roles** (admin, manager, cashier, inventory_checker) are pre-seeded and cannot be deleted.

**Custom roles** can be created by admin with any name and any combination of permissions.

### Permission System

Available permissions (checkboxes when creating/editing a role):

| Permission Key | Label | Description |
|---------------|-------|-------------|
| `dashboard` | Dashboard | View dashboard stats |
| `pos` | POS Terminal | Process sales |
| `products` | Products | Manage products |
| `inventory` | Inventory | View/edit stock levels |
| `master_data` | Master Data | Categories, suppliers, settings |
| `users` | User Management | Add/edit/remove users |
| `manager_portal` | Manager Portal | X-Read, Z-Read, remittance |
| `reports` | Reports & Analytics | View all reports |
| `settings` | System Settings | Business settings, config |
| `audit_trail` | Audit Trail | View audit logs |

### UI — Role Management Page

**New tab or section in Users page:**

1. **Roles List** — table showing all roles with permission badges
2. **Create Role** — modal:
   - Role Name (text input)
   - Permissions (checkbox grid, grouped logically)
   - Save
3. **Edit Role** — same modal, pre-filled
4. **Delete Role** — only if no users assigned to it. System roles cannot be deleted.

### User Form Update

When adding/editing a user:
- Role dropdown now pulls from `roles` table instead of hardcoded ENUM
- Shows: Admin, Manager, Cashier, Inventory Checker, [Custom Roles...]

### Navigation Update

`navbar.php` checks permissions instead of role names:
```php
// Before: if (hasRole('admin'))
// After:  if (hasPermission('products'))
```

New helper function:
```php
function hasPermission($permission) {
    // Check role_permissions table via session-cached permissions array
    return in_array($permission, $_SESSION['permissions'] ?? []);
}
```

On login, fetch and cache the user's permissions:
```php
$perms = $db->fetchAll(
    "SELECT rp.permission FROM role_permissions rp WHERE rp.role_id = ?",
    [$user['role_id']]
);
$_SESSION['permissions'] = array_column($perms, 'permission');
```

---

## 9. MANAGER PORTAL (Module 6) — BIR-Compliant

### Tab Structure

| Tab | Description |
|-----|-------------|
| X-Read | Real-time sales snapshot (no reset) |
| Z-Read | End-of-day closing report (locks day) |
| Remittance | Cash denomination count + over/short |
| Journal | Auto-generated accounting journal |
| Ledger | Account balances from journal entries |
| Cashier Summary | Per-cashier sales breakdown |
| Void Log | All voided transactions |
| Refunds | Process and view refunds |
| Audit Trail | Immutable system-wide action log |
| Read History | Past X-Read and Z-Read records |

### X-Read (Enhanced)

Same as current but add:
- Discount total
- Refund total
- Expected cash (cash sales - change given)
- Non-resetting transaction counter (from receipt_counter)

### Z-Read (Enhanced)

**Generates and locks:**
- All fields from X-Read
- Resets daily counters (but receipt numbers NEVER reset)
- Sets `business_settings.day_closed = CURDATE()`
- Cannot be run twice on same day
- Generates journal entry: summary of day's financials

**Z-Read Report Fields (BIR-style):**
```
═══════════════════════════════════════
         Z-READ / END OF DAY
         J&J GROCERY  REG-01
═══════════════════════════════════════
Date:           March 31, 2026
Generated by:   Pedro Santos (Manager)
Time:           8:45 PM
───────────────────────────────────────
SALES SUMMARY
  Beginning Receipt #:   JJ-000035
  Ending Receipt #:      JJ-000067
  Total Transactions:    33
───────────────────────────────────────
GROSS SALES:             ₱45,230.00
  Less: Discounts        -₱1,250.00
  Less: Voids            -₱580.00
  Less: Refunds          -₱0.00
───────────────────────────────────────
NET SALES:               ₱43,400.00
  VATable Sales:         ₱38,750.00
  VAT (12%):             ₱4,650.00
  Non-VATable:           ₱0.00
───────────────────────────────────────
PAYMENT BREAKDOWN
  Cash:                  ₱32,100.00
  GCash:                 ₱8,200.00
  Card:                  ₱3,100.00
───────────────────────────────────────
CASH ACCOUNTABILITY
  Cash Sales:            ₱32,100.00
  Less: Change Given     -₱3,450.00
  Expected Cash in Drawer: ₱28,650.00
───────────────────────────────────────
VOID SUMMARY
  Void Count:            2
  Void Amount:           ₱580.00
───────────────────────────────────────
Generated: 03/31/2026 8:45:12 PM
Counter: Z-Read #00042 (non-resettable)
═══════════════════════════════════════
```

### Journal System (NEW)

Auto-generated journal entries for every sale:

**Example — Sale #42, ₱100 cash, VAT-inclusive:**
```
Date: 2026-03-31
Ref: SALE-42

  Debit   Cash on Hand (1010)        ₱100.00
  Credit  Sales Revenue (4010)                   ₱89.29
  Credit  VAT Payable (2010)                     ₱10.71
```

**Example — Void of Sale #42:**
```
Date: 2026-03-31
Ref: VOID-42

  Debit   Sales Revenue (4010)       ₱89.29
  Debit   VAT Payable (2010)         ₱10.71
  Credit  Cash on Hand (1010)                   ₱100.00
```

**Example — Discount applied:**
```
  Debit   Sales Discounts (4020)     ₱10.00
  Credit  Cash on Hand (1010)                    ₱10.00
```

### Journal Page UI

- Date range filter
- Table: Date | Ref | Description | Account | Debit | Credit
- Filter by account code
- Running balance column
- Export to CSV

### General Ledger Page UI

- Shows each account with current balance
- Click account → drills into journal entries for that account
- Accounts: Cash, GCash, Card, Sales Revenue, VAT Payable, Discounts, Refunds
- Monthly summary view

### Remittance System (Enhanced)

**Flow:**
1. Cashier shift ends
2. Manager opens Remittance tab
3. Selects cashier
4. System shows EXPECTED cash (cash sales - change = expected drawer amount)
5. Manager counts physical cash using denomination grid (₱1000, ₱500, ₱200, ₱100, ₱50, ₱20, coins)
6. System calculates ACTUAL amount from denomination
7. Shows: Over/Short = Actual - Expected
8. Manager submits with notes
9. Over/Short is logged and can be reviewed in reports

### Refund Processing (NEW)

**Flow:**
1. Manager enters original receipt number
2. System pulls up the sale and its items
3. Manager selects which items to refund (partial or full)
4. Enter reason (required)
5. Confirm → creates refund record, journal entry (reverse), audit log entry
6. Original sale is marked `refunded = 1` with refund amount

**Rules:**
- Requires manager or admin role
- Cannot refund a voided sale
- Cannot refund more than original amount
- Refund reason is mandatory
- Stock is NOT automatically returned (manual inventory adjustment needed)

### Audit Trail (Enhanced)

**Logged actions:**

| Action | Severity | Details Logged |
|--------|----------|----------------|
| User login | info | username, IP |
| User logout | info | username |
| Sale completed | info | receipt #, total, payment method |
| Sale voided | critical | receipt #, amount, reason, who voided |
| Refund processed | critical | receipt #, refund amount, reason |
| Item voided from cart | warning | product name, price |
| Price override | warning | original price, new price, product |
| Discount applied | info | type, amount, receipt # |
| Z-Read generated | critical | Z-Read #, totals |
| X-Read generated | info | user, time |
| User created/edited | warning | user details |
| User deleted | critical | user details |
| Role created/edited | warning | role name, permissions |
| Product added/edited | info | product name |
| Product deleted | warning | product name |
| Remittance recorded | info | amount, variance |
| Settings changed | critical | old value, new value |

**UI:**
- Full searchable table with date range
- Filter by severity (info/warning/critical)
- Filter by action type
- Filter by user
- Entries are READ-ONLY — no edit or delete buttons
- Export to CSV

---

## 10. REPORTS (Module 7) — Full Expansion

### Tab Structure

| Tab | Description |
|-----|-------------|
| Overview | KPI cards + daily trend + payment pie chart |
| Daily Sales | Day-by-day breakdown table |
| By Cashier | Revenue per cashier with chart |
| By Product | Top products, qty sold, revenue |
| By Category | Category-level revenue breakdown |
| By Payment | Cash vs GCash vs Card analysis |
| VAT Report | Daily VAT breakdown (BIR format) |
| Discount Report | All discounts given, by type |
| Void Report | All voided transactions |
| Refund Report | All refunds with reasons |
| Hourly Analysis | Sales by hour of day (heatmap) |
| Monthly Summary | Month-by-month comparison |
| Inventory Value | Stock × price = total inventory value |
| Profit Estimation | Revenue - COGS estimate (if cost data available) |
| Cash Flow | Cash in/out summary |

### New Report Details

**Daily Sales Report:**
- Date | Transactions | Gross | Discounts | Voids | Refunds | Net | VAT | Cash | GCash | Card
- Totals row at bottom
- Export CSV

**Discount Report:**
- Date | Receipt # | Customer | Discount Type | Amount | Applied By
- Summary: total discounts given, average discount per transaction

**Hourly Analysis:**
- Bar chart showing revenue by hour (6 AM - 10 PM)
- Helps identify peak hours
- Day-of-week filter

**Monthly Summary:**
- Table: Month | Transactions | Gross Sales | Net Sales | VAT | Avg Transaction
- Year-over-year comparison if data exists
- Monthly trend line chart

**Inventory Valuation:**
- Product | Qty | Retail Price | Total Value
- Grand total at bottom
- Useful for insurance and accounting

**Cash Flow:**
- Period summary
- Cash In: Cash sales (actual received)
- Cash Out: Change given
- Net Cash: Cash In - Cash Out
- Compare with remittance records

---

## 11. MIGRATION STRATEGY

### Migration SQL File: `database/migration_v4.sql`

This single file contains ALL schema changes. Safe to run multiple times (IF NOT EXISTS / IF NOT EXISTS).

**Order of operations:**
1. Create `business_settings`
2. Create `roles` + `role_permissions`
3. Create `receipt_counter`
4. Create `journal_entries`
5. Create `ledger_accounts` + seed data
6. Create `refunds`
7. Create `pos_held_carts`
8. Create `remittances`
9. Alter `users` — add `role_id`
10. Alter `sales` — add new columns
11. Alter `sale_items` — add discount fields
12. Alter `products` — rename columns
13. Alter `activity_log` — add severity columns
14. Migrate existing role data
15. Seed default role permissions

---

## 12. BUILD ORDER & DEPENDENCIES

### Phase 1: Foundation (Build First)
```
1. migration_v4.sql        — All schema changes
2. business_settings       — VAT config, receipt numbering
3. roles + permissions     — New auth system
4. helpers.php updates     — hasPermission(), VAT helpers, receipt number generator
```

### Phase 2: Products + Inventory
```
5. products.php rework     — Retail/Wholesale pricing, tier UI
6. inventory.php sync      — Match new product structure
```

### Phase 3: POS Terminal (Biggest Module)
```
7. pos.php full rewrite    — New layout, all features
8. api/sales.php update    — Receipt numbering, journal auto-generation
9. api/search-product.php  — Ensure wholesale price support
10. pos_held_carts CRUD    — Hold/resume API endpoints
```

### Phase 4: Manager Portal
```
11. manager.php expansion  — Journal, Ledger, Refunds, Audit Trail tabs
12. Journal auto-generation — Hook into sales API
13. Ledger aggregation     — From journal entries
14. Remittance rework      — Expected vs actual
15. Refund processing      — New API + UI
16. Audit trail page       — Enhanced logging
```

### Phase 5: Reports + Users + Master Data
```
17. reports.php expansion  — All new report tabs
18. users.php rework       — Role management UI
19. master-data.php rework — Better UI + business settings tab
20. navbar.php update      — Permission-based menu
```

### Phase 6: Testing & Polish
```
21. Cross-module testing
22. Receipt printing test
23. Z-Read → day lock test
24. Edge cases (no stock, no price, refund limits)
25. Mobile responsiveness check
```

---

## 13. KEYBOARD SHORTCUT MAP

All shortcuts use **Ctrl+Key** to avoid conflicts with browser shortcuts (F1-F12 can trigger browser help, bookmarks, etc.).

### POS Terminal Shortcuts

| Shortcut | Action | Context |
|----------|--------|---------|
| `Ctrl+1` | Pay with Cash | Cart has items |
| `Ctrl+2` | Pay with GCash | Cart has items |
| `Ctrl+3` | Pay with Card | Cart has items |
| `Ctrl+H` | Hold current cart | Cart has items |
| `Ctrl+R` | Resume held cart | Held carts exist |
| `Ctrl+Q` | Focus qty input of selected item | Item selected in cart |
| `Ctrl+D` | Apply discount to selected item | Item selected in cart |
| `Ctrl+Shift+D` | Apply transaction discount | Cart has items |
| `Ctrl+Delete` | Remove selected item from cart | Item selected in cart |
| `Ctrl+Shift+C` | Clear entire cart (with confirm) | Cart has items |
| `Ctrl+Shift+F5` | Refresh product data | Anytime |
| `Ctrl+B` | Focus barcode/search input | Anytime |
| `↑` / `↓` | Navigate cart items | Cart focused |
| `Escape` | Close any open modal | Modal open |
| `Ctrl+P` | Print last receipt | After checkout |
| `Ctrl+M` | Toggle Retail/Wholesale mode | Anytime |
| `Ctrl+N` | Focus customer name field | Anytime |

### Why Not F-keys?

- `F1` = browser Help in Chrome/Firefox/Edge
- `F3` = browser Find
- `F5` = browser Refresh (DANGEROUS in POS)
- `F7` = Caret browsing toggle in Firefox
- `F11` = Fullscreen toggle
- `F12` = DevTools

Using `Ctrl+Number` for payments and `Ctrl+Letter` for actions avoids ALL browser shortcut conflicts.

### Browser Lockdown Recommendations

For production POS terminals:
1. Run Chrome in **kiosk mode**: `chrome --kiosk --app=http://localhost/grocery-pos/pages/pos.php`
2. This disables address bar, tabs, and most browser shortcuts
3. `Ctrl+W` and `Alt+F4` still work — use OS-level policies to block if needed

---

## SUMMARY OF ALL NEW FILES

```
database/migration_v4.sql          — Schema migration
config/helpers.php                 — Updated with new functions
config/constants.php               — Points to business_settings table
pages/pos.php                      — FULL REWRITE
pages/products.php                 — Updated pricing model
pages/inventory.php                — Synced with products
pages/master-data.php              — UI overhaul + settings tab
pages/users.php                    — Role management
pages/manager.php                  — Expanded (journal, ledger, refunds, audit)
pages/reports.php                  — All new report tabs
templates/navbar.php               — Permission-based navigation
api/sales.php                      — Receipt numbering + journal generation
api/refunds.php                    — NEW: refund processing
api/held-carts.php                 — NEW: hold/resume cart
api/journal.php                    — NEW: journal entry queries
api/ledger.php                     — NEW: ledger queries
api/audit.php                      — NEW: audit log queries
api/roles.php                      — NEW: role CRUD
api/settings.php                   — NEW: business settings CRUD
```

---

**END OF PLAN — Ready to build on your signal.**
