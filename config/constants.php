<?php
/**
 * J&J Grocery POS - Application Constants
 * Global configuration for the entire application
 */

// ========================================
// Database Configuration
// ========================================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); // XAMPP default
define('DB_NAME', 'grocery_pos');
define('DB_PORT', 3306);

// ========================================
// Application Configuration
// ========================================
define('APP_NAME', 'J&J Grocery POS');
define('APP_VERSION', '1.0.0');
define('APP_TIMEZONE', 'Asia/Manila');
define('APP_DEBUG', false);

// ========================================
// Currency & Localization (PHILIPPINES)
// ========================================
define('CURRENCY_SYMBOL', '₱');
define('CURRENCY_CODE', 'PHP');
define('LOCALE', 'ph_PH');
define('DATE_FORMAT', 'Y-m-d');
define('DATE_FORMAT_DISPLAY', 'd/m/Y');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');
define('DATETIME_FORMAT_DISPLAY', 'd/m/Y g:i A');

// ========================================
// Business Rules (PHILIPPINES)
// ========================================
define('VAT_RATE', 0.12); // 12% VAT for Philippines
define('DISCOUNT_PERCENTAGE', 0); // Default discount
define('SESSION_TIMEOUT', 480); // 8 hours in minutes

// ========================================
// Payment Methods (Philippines-specific)
// ========================================
define('PAYMENT_METHODS', [
    'cash' => 'Cash',
    'gcash' => 'GCash',
    'card' => 'Credit/Debit Card'
]);

// ========================================
// User Roles
// ========================================
define('ROLE_ADMIN', 'admin');
define('ROLE_CASHIER', 'cashier');
define('ROLE_MANAGER', 'manager');
define('ROLE_INVENTORY', 'inventory_checker');

// ========================================
// File Paths (Relative to web root)
// ========================================
define('BASE_URL', '/grocery-pos');
define('CONFIG_PATH', __DIR__);
define('ROOT_PATH', dirname(__DIR__));
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('PAGES_PATH', ROOT_PATH . '/pages');
define('TEMPLATES_PATH', ROOT_PATH . '/templates');
define('DATABASE_PATH', ROOT_PATH . '/database');
define('AUTH_PATH', ROOT_PATH . '/auth');
define('API_PATH', ROOT_PATH . '/api');

// CSS/JS/API URLs
define('CSS_URL', BASE_URL . '/public/css');
define('JS_URL', BASE_URL . '/public/js');
define('IMG_URL', BASE_URL . '/public/images');
define('API_URL', BASE_URL . '/api');

// ========================================
// Color Palette (J&J Grocery Logo)
// ========================================
define('COLOR_PRIMARY', '#E53935');      // Red
define('COLOR_SECONDARY', '#1E3A8A');    // Blue
define('COLOR_ACCENT', '#FFFAF0');       // Cream
define('COLOR_SUCCESS', '#388E3C');      // Green
define('COLOR_WARNING', '#FBC02D');      // Yellow
define('COLOR_DANGER', '#C62828');       // Dark Red
define('COLOR_INFO', '#2196F3');         // Light Blue

// ========================================
// Sample Admin User (Philippines-specific name)
// ========================================
define('DEFAULT_ADMIN_USERNAME', 'admin');
define('DEFAULT_ADMIN_PASSWORD', 'admin123');
define('DEFAULT_ADMIN_NAME', 'Juan Santos'); // Filipino name

// ========================================
// Error Messages
// ========================================
define('ERROR_DB_CONNECTION', 'Hindi ma-konekta ang database. Pakikipag-ugnayan sa IT support.');
define('ERROR_INVALID_CREDENTIALS', 'Invalid username o password.');
define('ERROR_UNAUTHORIZED', 'Walang pahintulot para gumawa ng action na ito.');
define('ERROR_SESSION_TIMEOUT', 'Ang session ay nag-expire na. Mangyaring mag-login ulit.');

// ========================================
// Success Messages
// ========================================
define('SUCCESS_LOGIN', 'Login successful! Welcome back.');
define('SUCCESS_LOGOUT', 'Logout successful. See you next time!');
define('SUCCESS_PRODUCT_ADDED', 'Product added successfully.');
define('SUCCESS_PRODUCT_UPDATED', 'Product updated successfully.');
define('SUCCESS_PRODUCT_DELETED', 'Product deleted successfully.');

// Set Timezone
date_default_timezone_set(APP_TIMEZONE);

?>
