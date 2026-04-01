-- ============================================================
-- J&J Grocery POS — Migration v3
-- Adds: multiple barcodes, flexible pricing tiers, z-reads,
--        user profile fields, void tracking, drawer sessions
-- Safe to run multiple times (IF NOT EXISTS / IF NOT EXISTS).
-- ============================================================

-- ── 1. Users: add email, phone, employee_id, last_login ──────
ALTER TABLE users ADD COLUMN email VARCHAR(100) NULL AFTER name;
ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER email;
ALTER TABLE users ADD COLUMN employee_id VARCHAR(20) NULL AFTER phone;
ALTER TABLE users ADD COLUMN last_login TIMESTAMP NULL AFTER active;

-- ── 2. Multiple barcodes per product ──────────────────────────
CREATE TABLE IF NOT EXISTS product_barcodes (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    product_id    INT NOT NULL,
    barcode       VARCHAR(50) NOT NULL,
    unit_label    VARCHAR(30) NOT NULL DEFAULT 'pcs',
    qty_multiplier DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uk_barcode (barcode),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed from existing products.barcode column (skip duplicates)
INSERT IGNORE INTO product_barcodes (product_id, barcode, unit_label, qty_multiplier)
SELECT id, barcode, 'pcs', 1.0000
FROM products
WHERE barcode IS NOT NULL AND TRIM(barcode) != '';

-- ── 3. Flexible pricing tiers per product ─────────────────────
CREATE TABLE IF NOT EXISTS product_price_tiers (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    product_id    INT NOT NULL,
    tier_name     VARCHAR(50) NOT NULL,
    price         DECIMAL(10,2) NOT NULL,
    unit_label    VARCHAR(30) NOT NULL DEFAULT 'pcs',
    qty_multiplier DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    sort_order    TINYINT NOT NULL DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed from existing three-column pricing
INSERT IGNORE INTO product_price_tiers (product_id, tier_name, price, unit_label, qty_multiplier, sort_order)
SELECT id, 'Retail', price_retail, 'pcs', 1.0000, 1
FROM products WHERE price_retail > 0;

INSERT IGNORE INTO product_price_tiers (product_id, tier_name, price, unit_label, qty_multiplier, sort_order)
SELECT id, 'Pack', price_sarisar, 'pack', 1.0000, 2
FROM products WHERE price_sarisar > 0 AND price_sarisar IS NOT NULL;

INSERT IGNORE INTO product_price_tiers (product_id, tier_name, price, unit_label, qty_multiplier, sort_order)
SELECT id,
       COALESCE(NULLIF(TRIM(bulk_unit), ''), 'Bulk'),
       price_bulk,
       LOWER(COALESCE(NULLIF(TRIM(bulk_unit), ''), 'bulk')),
       1.0000, 3
FROM products WHERE price_bulk > 0 AND price_bulk IS NOT NULL;

-- ── 4. Void tracking on sales ─────────────────────────────────
ALTER TABLE sales ADD COLUMN voided TINYINT(1) NOT NULL DEFAULT 0 AFTER notes;
ALTER TABLE sales ADD COLUMN void_reason VARCHAR(255) NULL AFTER voided;
ALTER TABLE sales ADD COLUMN voided_by INT NULL AFTER void_reason;
ALTER TABLE sales ADD COLUMN voided_at TIMESTAMP NULL AFTER voided_by;

-- ── 5. Cash drawer sessions ───────────────────────────────────
CREATE TABLE IF NOT EXISTS cash_drawer_sessions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    cashier_id     INT NOT NULL,
    register_no    VARCHAR(10) NOT NULL DEFAULT 'REG-01',
    opening_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    closing_amount DECIMAL(12,2) NULL,
    status         ENUM('open','closed') NOT NULL DEFAULT 'open',
    opened_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed_at      TIMESTAMP NULL,
    manager_id     INT NULL,
    notes          TEXT,
    FOREIGN KEY (cashier_id) REFERENCES users(id),
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_cashier (cashier_id),
    INDEX idx_status  (status),
    INDEX idx_date    (opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. Register reads (X-Read / Z-Read) ──────────────────────
CREATE TABLE IF NOT EXISTS register_reads (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    read_type          ENUM('x_read','z_read') NOT NULL,
    register_no        VARCHAR(10) NOT NULL DEFAULT 'REG-01',
    cashier_id         INT NULL,
    generated_by       INT NULL,
    read_date          DATE NOT NULL,
    period_start       DATETIME NULL,
    period_end         DATETIME NULL,
    total_transactions INT NOT NULL DEFAULT 0,
    total_gross        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_vat          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_discounts    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_net          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cash_sales         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gcash_sales        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    card_sales         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    void_count         INT NOT NULL DEFAULT 0,
    void_amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    opening_fund       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    actual_cash        DECIMAL(12,2) NULL,
    cash_variance      DECIMAL(12,2) NULL,
    notes              TEXT,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cashier_id)   REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_type (read_type),
    INDEX idx_date (read_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Done ──────────────────────────────────────────────────────
-- SELECT 'Migration v3 applied successfully' AS status;
