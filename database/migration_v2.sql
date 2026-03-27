-- ============================================================
-- J&J Grocery POS — Migration v2
-- Run this against an existing grocery_pos database to apply
-- all schema changes introduced in the v2 upgrade.
--
-- Safe to run multiple times (uses IF NOT EXISTS / IF EXISTS).
-- ============================================================

-- ── 1. activity_log table ─────────────────────────────────────────────────────
-- Tracks sensitive actions (sales, user edits, deletes, refunds, etc.)
CREATE TABLE IF NOT EXISTS activity_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT,
    action     VARCHAR(60)  NOT NULL,
    details    TEXT,
    target_id  INT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user   (user_id),
    INDEX idx_action (action),
    INDEX idx_date   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. sales.discount_amount ──────────────────────────────────────────────────
-- Adds optional discount column to the sales table.
-- Existing rows default to 0 so nothing breaks.
ALTER TABLE sales
    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0
    AFTER tax_amount;

-- ── 3. sale_items.discount_amount ────────────────────────────────────────────
-- Per-line discount support.
ALTER TABLE sale_items
    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0
    AFTER unit_price;

-- ── 4. login_attempts table ───────────────────────────────────────────────────
-- Enables brute-force detection on login (optional; PHP checks this table).
CREATE TABLE IF NOT EXISTS login_attempts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(50)  NOT NULL,
    ip_address  VARCHAR(45)  NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_ip       (ip_address),
    INDEX idx_time     (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Done ─────────────────────────────────────────────────────────────────────
-- SELECT 'Migration v2 applied successfully' AS status;
