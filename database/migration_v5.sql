-- ============================================================
-- J&J Grocery POS — Migration v5
-- Adds: shift_closures table for end-of-shift remittance tracking.
-- Run AFTER migration_v4.sql
-- ============================================================

-- ── Business Settings additions ──────────────────────────────────────────
ALTER TABLE business_settings ADD COLUMN business_logo VARCHAR(255) NULL AFTER business_name;

-- ── Feature Toggles ─────────────────────────────────────────
ALTER TABLE business_settings ADD COLUMN feature_loyalty    TINYINT(1) NOT NULL DEFAULT 0 AFTER business_logo;
ALTER TABLE business_settings ADD COLUMN feature_gcash      TINYINT(1) NOT NULL DEFAULT 1 AFTER feature_loyalty;
ALTER TABLE business_settings ADD COLUMN feature_card       TINYINT(1) NOT NULL DEFAULT 1 AFTER feature_gcash;
ALTER TABLE business_settings ADD COLUMN feature_discounts  TINYINT(1) NOT NULL DEFAULT 1 AFTER feature_card;
ALTER TABLE business_settings ADD COLUMN feature_held_carts TINYINT(1) NOT NULL DEFAULT 1 AFTER feature_discounts;

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
