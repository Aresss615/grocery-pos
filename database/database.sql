-- ============================================================
-- J&J GROCERY POS - Database Schema (Philippine Version)
-- Currency: PHP (₱)
-- VAT: 12%
-- Payment Methods: Cash, GCash, Credit/Debit Card
-- Roles: admin, cashier, manager, inventory_checker
-- ============================================================

-- Drop existing tables
DROP TABLE IF EXISTS sale_items;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS cash_remittals;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS users;

-- ============================================================
-- USERS TABLE - Updated with manager and inventory_checker
-- ============================================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'cashier', 'manager', 'inventory_checker') DEFAULT 'cashier',
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CATEGORIES TABLE
-- ============================================================
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SUPPLIERS TABLE
-- ============================================================
CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    contact VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PRODUCTS TABLE - Multi-tier pricing for sari-sari & bulk
-- ============================================================
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    barcode VARCHAR(50) UNIQUE,
    description TEXT,
    category_id INT,
    supplier_id INT,
    
    -- Regular retail pricing
    price_retail DECIMAL(10, 2) NOT NULL,
    
    -- Sari-sari store special pricing
    price_sarisar DECIMAL(10, 2),
    
    -- Bulk/Dozen pricing
    price_bulk DECIMAL(10, 2),
    bulk_unit VARCHAR(50) DEFAULT 'Dozen',
    
    -- Stock (optional - can be NULL for track-less items)
    quantity INT,
    min_quantity INT DEFAULT 5,
    
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    INDEX idx_barcode (barcode),
    INDEX idx_category (category_id),
    INDEX idx_supplier (supplier_id),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SALES TABLE (Philippine-specific)
-- ============================================================
CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cashier_id INT NOT NULL,
    subtotal DECIMAL(12, 2) NOT NULL,
    tax_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(12, 2) NOT NULL,
    payment_method ENUM('cash', 'gcash', 'card') DEFAULT 'cash',
    amount_paid DECIMAL(12, 2),
    change_amount DECIMAL(12, 2) DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cashier_id) REFERENCES users(id),
    INDEX idx_cashier (cashier_id),
    INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SALE ITEMS TABLE
-- ============================================================
CREATE TABLE sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    subtotal DECIMAL(12, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_sale (sale_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CASH REMITTALS TABLE - Manager cash handling
-- ============================================================
CREATE TABLE cash_remittals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cashier_id INT NOT NULL,
    manager_id INT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    notes TEXT,
    status ENUM('pending', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cashier_id) REFERENCES users(id),
    FOREIGN KEY (manager_id) REFERENCES users(id),
    INDEX idx_cashier (cashier_id),
    INDEX idx_manager (manager_id),
    INDEX idx_status (status),
    INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- INSERT DEFAULT USERS
-- All passwords: demo123 (bcrypted)
-- ============================================================
INSERT INTO users (name, username, password, role) VALUES
('Juan Santos', 'admin', '$2y$10$YYf.VDdLQMVp6FJ3rWfhLOWj8ykuDqLKCYSY0IQvI.0Bi9A2VdMJq', 'admin'),
('Maria Reyes', 'cashier', '$2y$10$8j7hS.dFPmQYVVqVVqJOLulTcVFpKVRb0sHqXaKIGVRZ2mkqBrfKG', 'cashier'),
('Pedro Santos', 'manager', '$2y$10$8j7hS.dFPmQYVVqVVqJOLulTcVFpKVRb0sHqXaKIGVRZ2mkqBrfKG', 'manager'),
('Rosa Lopez', 'inventory', '$2y$10$8j7hS.dFPmQYVVqVVqJOLulTcVFpKVRb0sHqXaKIGVRZ2mkqBrfKG', 'inventory_checker');

-- ============================================================
-- INSERT DEFAULT CATEGORIES
-- ============================================================
INSERT INTO categories (name, description) VALUES
('Grains', 'Rice, cereals, and grain products'),
('Oils', 'Cooking oils and fats'),
('Canned', 'Canned and preserved goods'),
('Dairy', 'Dairy products and cheeses'),
('Meat', 'Meat and protein products'),
('Fruits', 'Fresh fruits'),
('Vegetables', 'Fresh vegetables'),
('Cleaning', 'Cleaning and household supplies'),
('Beverages', 'Drinks and beverages');

-- ============================================================
-- INSERT DEFAULT SUPPLIERS
-- ============================================================
INSERT INTO suppliers (name, contact, email, address) VALUES
('Golden Quality Suppliers', '+63 2-1234-5678', 'info@goldenquality.com.ph', 'Manila, Metro Manila'),
('Fresh Farms Distributor', '+63 2-8765-4321', 'orders@freshfarms.com.ph', 'Quezon City, NCR'),
('Metro Wholesale', '+63 2-5555-6666', 'sales@metrowholesale.com.ph', 'Makati, Metro Manila'),
('Countryside Trading', '+63 42-123-4567', 'contact@countrysidetrading.com.ph', 'Cavite, Calabarzon');

-- ============================================================
-- INSERT SAMPLE PRODUCTS (Multi-tier pricing)
-- Retail, Sari-Sari Store, and Bulk pricing
-- ============================================================
INSERT INTO products (name, barcode, description, category_id, supplier_id, price_retail, price_sarisar, price_bulk, bulk_unit, quantity) VALUES
-- Rice & Grains
('Premium White Rice (2kg)', '4800123456789', 'High quality white rice', 1, 1, 150.00, 140.00, 1350.00, 'Box/10', 50),
('Jasmine Rice (5kg)', '4800987654321', 'Fragrant jasmine rice', 1, 1, 350.00, 320.00, 3100.00, 'Box/10', 40),

-- Cooking Oil  
('Pure Coconut Oil (1L)', '4800345678901', 'Organic coconut oil', 2, 2, 280.00, 260.00, 2500.00, 'Box/12', 35),
('Vegetable Oil (2L)', '4800456789012', 'Multi-purpose vegetable oil', 2, 2, 320.00, 295.00, 2850.00, 'Box/10', 45),

-- Canned Goods
('Canned Tuna (180g)', '4800567890123', 'Premium tuna in oil', 3, 3, 45.00, 42.00, 480.00, 'Box/12', 100),
('Condensed Milk (335ml)', '4800678901234', 'Sweetened condensed milk', 3, 3, 35.00, 32.00, 380.00, 'Box/12', 80),

-- Dairy Products
('Purefoods Hotdog (450g)', '4800789012345', 'Pork hotdog', 5, 4, 120.00, 110.00, 1100.00, 'Box/12', 60),
('Processed Cheese (400g)', '4800890123456', 'Cheese block', 4, 4, 180.00, 165.00, 1700.00, 'Box/12', 40),

-- Fresh Produce
('Bananas (1kg)', '4800901234567', 'Fresh bananas', 6, 2, 50.00, 45.00, NULL, NULL, 100),
('Tomatoes (1kg)', '4801012345678', 'Fresh red tomatoes', 7, 2, 60.00, 55.00, NULL, NULL, 80),

-- Detergent Sample (with tie/box unit)
('Detergent Powder (500g)', '4801102345679', 'Multi-purpose detergent', 8, 1, 55.00, 50.00, 520.00, 'Tie/10', 120);

-- ============================================================
-- DATABASE SETUP COMPLETE
-- Default credentials: admin / admin123
-- ============================================================
