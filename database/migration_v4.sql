-- ============================================================
-- J&J Grocery POS — Migration v4
-- System Overhaul: business settings, roles & permissions,
--   receipt counter, journal/ledger, refunds, held carts,
--   remittances, and column additions to existing tables.
-- Safe to run multiple times (IF NOT EXISTS / ADD COLUMN).
-- ============================================================

-- ── 1. Business Settings (single-row store config) ───────────
CREATE TABLE IF NOT EXISTS business_settings (
    id               INT PRIMARY KEY DEFAULT 1,
    business_name    VARCHAR(200) NOT NULL DEFAULT 'J&J Grocery',
    business_address TEXT,
    tin              VARCHAR(20),
    vat_registered   TINYINT(1) NOT NULL DEFAULT 1,
    vat_rate         DECIMAL(5,4) NOT NULL DEFAULT 0.1200,
    vat_inclusive    TINYINT(1) NOT NULL DEFAULT 1,
    receipt_prefix   VARCHAR(10) NOT NULL DEFAULT 'JJ-',
    next_receipt_number INT UNSIGNED NOT NULL DEFAULT 1,
    currency_symbol  VARCHAR(5) NOT NULL DEFAULT '₱',
    day_closed       DATE NULL,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default row if empty
INSERT IGNORE INTO business_settings (id, business_name) VALUES (1, 'J&J Grocery');

-- ── 2. Roles (replaces ENUM-based role system) ───────────────
CREATE TABLE IF NOT EXISTS roles (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(50) NOT NULL UNIQUE,
    slug       VARCHAR(50) NOT NULL UNIQUE,
    is_system  TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed system roles (IGNORE keeps it idempotent)
INSERT IGNORE INTO roles (name, slug, is_system) VALUES
('Admin', 'admin', 1),
('Manager', 'manager', 1),
('Cashier', 'cashier', 1),
('Inventory Checker', 'inventory_checker', 1);

-- ── 3. Role Permissions ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS role_permissions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    role_id    INT NOT NULL,
    permission VARCHAR(50) NOT NULL,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    UNIQUE KEY uk_role_perm (role_id, permission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default permissions for system roles
-- Admin gets everything
INSERT IGNORE INTO role_permissions (role_id, permission)
SELECT r.id, p.permission
FROM roles r
CROSS JOIN (
    SELECT 'dashboard' AS permission UNION ALL
    SELECT 'pos' UNION ALL
    SELECT 'products' UNION ALL
    SELECT 'inventory' UNION ALL
    SELECT 'master_data' UNION ALL
    SELECT 'users' UNION ALL
    SELECT 'manager_portal' UNION ALL
    SELECT 'reports' UNION ALL
    SELECT 'settings' UNION ALL
    SELECT 'audit_trail'
) p
WHERE r.slug = 'admin';

-- Manager permissions
INSERT IGNORE INTO role_permissions (role_id, permission)
SELECT r.id, p.permission
FROM roles r
CROSS JOIN (
    SELECT 'dashboard' AS permission UNION ALL
    SELECT 'products' UNION ALL
    SELECT 'inventory' UNION ALL
    SELECT 'master_data' UNION ALL
    SELECT 'manager_portal' UNION ALL
    SELECT 'reports'
) p
WHERE r.slug = 'manager';

-- Cashier permissions
INSERT IGNORE INTO role_permissions (role_id, permission)
SELECT r.id, p.permission
FROM roles r
CROSS JOIN (
    SELECT 'dashboard' AS permission UNION ALL
    SELECT 'pos'
) p
WHERE r.slug = 'cashier';

-- Inventory Checker permissions
INSERT IGNORE INTO role_permissions (role_id, permission)
SELECT r.id, p.permission
FROM roles r
CROSS JOIN (
    SELECT 'dashboard' AS permission UNION ALL
    SELECT 'inventory'
) p
WHERE r.slug = 'inventory_checker';

-- ── 4. Receipt Counter (atomic, gap-free) ────────────────────
CREATE TABLE IF NOT EXISTS receipt_counter (
    id          INT PRIMARY KEY DEFAULT 1,
    prefix      VARCHAR(10) NOT NULL DEFAULT 'JJ-',
    next_number INT UNSIGNED NOT NULL DEFAULT 1,
    CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO receipt_counter (id, prefix, next_number) VALUES (1, 'JJ-', 1);

-- ── 5. Journal Entries (double-entry accounting) ─────────────
CREATE TABLE IF NOT EXISTS journal_entries (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    entry_date      DATE NOT NULL,
    reference_type  ENUM('sale','void','refund','adjustment','remittance') NOT NULL,
    reference_id    INT,
    description     VARCHAR(255) NOT NULL,
    account_code    VARCHAR(20) NOT NULL,
    account_name    VARCHAR(100) NOT NULL,
    debit           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    credit          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_by      INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_date (entry_date),
    INDEX idx_ref (reference_type, reference_id),
    INDEX idx_account (account_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. Ledger Accounts (chart of accounts + running balances) ─
CREATE TABLE IF NOT EXISTS ledger_accounts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    account_code VARCHAR(20) NOT NULL UNIQUE,
    account_name VARCHAR(100) NOT NULL,
    account_type ENUM('asset','liability','equity','revenue','expense') NOT NULL,
    balance      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed chart of accounts
INSERT IGNORE INTO ledger_accounts (account_code, account_name, account_type) VALUES
('1010', 'Cash on Hand',             'asset'),
('1011', 'Cash - GCash',             'asset'),
('1012', 'Cash - Card Payments',     'asset'),
('2010', 'VAT Payable',              'liability'),
('4010', 'Sales Revenue',            'revenue'),
('4020', 'Sales Discounts',          'revenue'),
('4030', 'Sales Returns & Refunds',  'revenue');

-- ── 7. Refunds ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS refunds (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    original_sale_id INT NOT NULL,
    receipt_number   VARCHAR(30) NOT NULL,
    refund_amount    DECIMAL(12,2) NOT NULL,
    reason           TEXT NOT NULL,
    items            JSON,
    processed_by     INT NOT NULL,
    approved_by      INT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (original_sale_id) REFERENCES sales(id),
    FOREIGN KEY (processed_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sale (original_sale_id),
    INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 8. POS Held Carts (max 3 paused carts per register) ─────
CREATE TABLE IF NOT EXISTS pos_held_carts (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    cashier_id    INT NOT NULL,
    register_no   VARCHAR(10) NOT NULL DEFAULT 'REG-01',
    label         VARCHAR(50),
    cart_data     JSON NOT NULL,
    price_mode    ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
    customer_name VARCHAR(100),
    held_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cashier_id) REFERENCES users(id),
    INDEX idx_cashier (cashier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 9. Remittances (replaces/enhances cash_remittals) ────────
CREATE TABLE IF NOT EXISTS remittances (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    cashier_id    INT NOT NULL,
    manager_id    INT NOT NULL,
    register_no   VARCHAR(10) NOT NULL DEFAULT 'REG-01',

    -- Expected (system-computed from sales)
    expected_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    -- Actual (from denomination count)
    actual_cash   DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    -- Denomination breakdown
    bills_1000    INT NOT NULL DEFAULT 0,
    bills_500     INT NOT NULL DEFAULT 0,
    bills_200     INT NOT NULL DEFAULT 0,
    bills_100     INT NOT NULL DEFAULT 0,
    bills_50      INT NOT NULL DEFAULT 0,
    bills_20      INT NOT NULL DEFAULT 0,
    coins         DECIMAL(8,2) NOT NULL DEFAULT 0.00,

    -- Variance
    over_short    DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    -- Status
    status        ENUM('pending','approved','flagged') NOT NULL DEFAULT 'pending',
    notes         TEXT,
    approved_at   TIMESTAMP NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (cashier_id) REFERENCES users(id),
    FOREIGN KEY (manager_id) REFERENCES users(id),
    INDEX idx_date (created_at),
    INDEX idx_cashier (cashier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 10. ALTER users — add role_id FK ─────────────────────────
ALTER TABLE users ADD COLUMN role_id INT NULL AFTER role;

-- Migrate existing ENUM role values → role_id
UPDATE users u
JOIN roles r ON r.slug = u.role
SET u.role_id = r.id
WHERE u.role_id IS NULL;

-- Add FK constraint (skip if it already exists)
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND CONSTRAINT_NAME = 'fk_users_role_id'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_users_role_id FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── 11. ALTER sales — add receipt_number, customer, discount, refund fields ─
ALTER TABLE sales ADD COLUMN receipt_number  VARCHAR(30) NULL AFTER id;
ALTER TABLE sales ADD COLUMN customer_name   VARCHAR(100) NULL AFTER notes;
ALTER TABLE sales ADD COLUMN discount_type   ENUM('none','percent','fixed') NOT NULL DEFAULT 'none' AFTER discount_amount;
ALTER TABLE sales ADD COLUMN discount_value  DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_type;
ALTER TABLE sales ADD COLUMN refunded        TINYINT(1) NOT NULL DEFAULT 0 AFTER voided;
ALTER TABLE sales ADD COLUMN refund_amount   DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER refunded;
ALTER TABLE sales ADD COLUMN price_mode      ENUM('retail','wholesale') NOT NULL DEFAULT 'retail' AFTER notes;

-- Unique index on receipt_number (idempotent via IF NOT EXISTS workaround)
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sales'
      AND INDEX_NAME = 'uk_receipt'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE sales ADD UNIQUE INDEX uk_receipt (receipt_number)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── 12. ALTER sale_items — add per-item discount fields ──────
ALTER TABLE sale_items ADD COLUMN discount_type  ENUM('none','percent','fixed') NOT NULL DEFAULT 'none' AFTER discount_amount;
ALTER TABLE sale_items ADD COLUMN discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_type;
ALTER TABLE sale_items ADD COLUMN price_tier     VARCHAR(50) NULL AFTER discount_value;

-- ── 13. ALTER products — rename price_sarisar → price_wholesale ─
-- Check if old column exists before renaming
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'price_sarisar'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE products CHANGE price_sarisar price_wholesale DECIMAL(10,2)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── 14. ALTER product_price_tiers — add price_mode column ────
ALTER TABLE product_price_tiers ADD COLUMN price_mode ENUM('retail','wholesale','both') NOT NULL DEFAULT 'both' AFTER sort_order;

-- ── 15. ALTER activity_log — add severity, old_value, new_value ─
ALTER TABLE activity_log ADD COLUMN severity  ENUM('info','warning','critical') NOT NULL DEFAULT 'info' AFTER action;
ALTER TABLE activity_log ADD COLUMN old_value TEXT NULL;
ALTER TABLE activity_log ADD COLUMN new_value TEXT NULL;

-- ── Done ─────────────────────────────────────────────────────
-- SELECT 'Migration v4 applied successfully' AS status;
