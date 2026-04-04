<?php
/**
 * J&J Grocery POS - Helper Functions
 * Utility functions for the application (Philippines locale)
 */

require_once __DIR__ . '/constants.php';

/**
 * Format amount in Philippine Peso
 */
function formatCurrency($amount) {
    return CURRENCY_SYMBOL . number_format($amount, 2);
}

/**
 * Format date for display
 */
function formatDate($date_string) {
    if (empty($date_string)) return '';
    $timestamp = strtotime($date_string);
    return date(DATE_FORMAT_DISPLAY, $timestamp);
}

/**
 * Format datetime for display
 */
function formatDateTime($datetime_string) {
    if (empty($datetime_string)) return '';
    $timestamp = strtotime($datetime_string);
    return date(DATETIME_FORMAT_DISPLAY, $timestamp);
}

/**
 * Calculate VAT (Philippine 12%) — legacy helper, adds VAT on top.
 */
function calculateVAT($amount) {
    return $amount * VAT_RATE;
}

/**
 * Calculate total with VAT — legacy helper.
 */
function calculateTotal($subtotal) {
    $vat = calculateVAT($subtotal);
    return $subtotal + $vat;
}

// ========================================
// Business Settings (DB-driven)
// ========================================

/**
 * Fetch business_settings row from DB (single-row table, id=1).
 * Results are cached in a static variable for the request lifetime.
 * Returns associative array or defaults if table doesn't exist yet.
 */
function getBusinessSettings($db = null) {
    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = [
        'business_name'    => 'J&J Grocery',
        'business_logo'    => null,
        'business_address' => '',
        'tin'              => '',
        'vat_registered'   => 1,
        'vat_rate'         => 0.12,
        'vat_inclusive'    => 1,
        'receipt_prefix'   => 'JJ-',
        'next_receipt_number' => 1,
        'currency_symbol'  => '₱',
        'day_closed'       => null,
        'feature_loyalty'    => 0,
        'feature_gcash'      => 1,
        'feature_card'       => 1,
        'feature_discounts'  => 1,
        'feature_held_carts' => 1,
    ];

    if (!$db) {
        // Try the global $db
        global $db;
    }
    if (!$db) return $defaults;

    $row = $db->selectOne("SELECT * FROM business_settings WHERE id = 1");
    $cache = $row ?: $defaults;
    return $cache;
}

/**
 * Check if business is VAT-registered (from DB settings).
 */
function isVATRegistered($db = null) {
    $settings = getBusinessSettings($db);
    return (int)$settings['vat_registered'] === 1;
}

/**
 * Get the active VAT rate from business settings.
 */
function getVATRate($db = null) {
    $settings = getBusinessSettings($db);
    return (float)$settings['vat_rate'];
}

/**
 * VAT-inclusive back-computation.
 * Given a selling price that already includes VAT, extract the VAT amount.
 * Formula: vat = price * (rate / (1 + rate))
 */
function calculateVATInclusive($price, $db = null) {
    if (!isVATRegistered($db)) return 0.00;
    $rate = getVATRate($db);
    return round($price * ($rate / (1 + $rate)), 2);
}

/**
 * VAT-exclusive computation.
 * Given a net price, calculate the VAT to add on top.
 * Formula: vat = price * rate
 */
function calculateVATExclusive($price, $db = null) {
    if (!isVATRegistered($db)) return 0.00;
    $rate = getVATRate($db);
    return round($price * $rate, 2);
}

/**
 * Compute VAT amount for a given price, respecting the inclusive/exclusive setting.
 */
function computeVAT($price, $db = null) {
    $settings = getBusinessSettings($db);
    if ((int)$settings['vat_registered'] === 0) return 0.00;
    if ((int)$settings['vat_inclusive'] === 1) {
        return calculateVATInclusive($price, $db);
    }
    return calculateVATExclusive($price, $db);
}

/**
 * Get net amount (price minus VAT) for VAT-inclusive pricing.
 */
function getNetAmount($price, $db = null) {
    return round($price - computeVAT($price, $db), 2);
}

// ========================================
// Receipt Number Generation
// ========================================

/**
 * Atomically generate the next receipt number.
 * Uses SELECT FOR UPDATE to prevent gaps under concurrency.
 * Returns the formatted receipt string, e.g. "JJ-000042".
 *
 * MUST be called inside a transaction.
 */
function generateReceiptNumber($db) {
    // Lock the row
    $row = $db->fetchOne("SELECT prefix, next_number FROM receipt_counter WHERE id = 1 FOR UPDATE");
    if (!$row) {
        // Fallback if table is empty (shouldn't happen after migration)
        return 'RCT-' . str_pad(time() % 1000000, 6, '0', STR_PAD_LEFT);
    }

    $prefix = $row['prefix'];
    $number = (int)$row['next_number'];
    $receipt = $prefix . str_pad($number, 6, '0', STR_PAD_LEFT);

    // Increment
    $db->execute("UPDATE receipt_counter SET next_number = next_number + 1 WHERE id = 1");

    return $receipt;
}

/**
 * Sanitize input
 */
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Generate random barcode
 */
function generateBarcode() {
    return str_pad(mt_rand(1, 999999999), 12, '0', STR_PAD_LEFT);
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Check user role
 */
function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

/**
 * Check if the current user has a specific permission (v4+ permission system).
 * Reads from $_SESSION['permissions'] which is populated on login.
 */
function hasPermission($permission) {
    return in_array($permission, $_SESSION['permissions'] ?? []);
}

/**
 * Check if user has access to a feature.
 * v4+: delegates to the permission system first.
 * Falls back to the legacy role-based matrix for backward compatibility.
 */
function hasAccess($feature) {
    // Map legacy feature names → permission keys
    $featureToPermission = [
        'pos'          => 'pos',
        'products'     => 'products',
        'inventory'    => 'inventory',
        'master-data'  => 'master_data',
        'users'        => 'users',
        'manager'      => 'manager_portal',
        'dashboard'    => 'dashboard',
        'reports'      => 'reports',
        'sales-report' => 'reports',
        'settings'     => 'settings',
        'audit-trail'  => 'audit_trail',
    ];

    // If permissions are loaded in session, use them
    if (isset($_SESSION['permissions']) && !empty($_SESSION['permissions'])) {
        $perm = $featureToPermission[$feature] ?? $feature;
        return hasPermission($perm);
    }

    // Legacy fallback (before migration or if permissions aren't cached)
    $role = $_SESSION['role'] ?? null;
    $access = [
        'cashier' => ['pos'],
        'inventory_checker' => ['inventory'],
        'manager' => ['inventory', 'products', 'master-data', 'manager', 'sales-report', 'reports'],
        'admin' => ['pos', 'users', 'inventory', 'products', 'master-data', 'manager', 'dashboard', 'sales-report', 'reports']
    ];

    if (!isset($access[$role])) {
        return in_array($feature, ['dashboard']);
    }

    return in_array($feature, $access[$role] ?? []);
}

/**
 * Get current user
 */
function getCurrentUser() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['user_name'] ?? 'User',
        'username' => $_SESSION['username'] ?? null,
        'role' => $_SESSION['role'] ?? null
    ];
}

/**
 * Set user session.
 * v4+: also stores role_id and permissions array.
 */
function setUserSession($user, $db = null) {
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['role_id']    = $user['role_id'] ?? null;
    $_SESSION['login_time'] = time();

    // Load permissions from role_permissions table
    $_SESSION['permissions'] = [];
    if ($db && !empty($user['role_id'])) {
        $perms = $db->fetchAll(
            "SELECT rp.permission FROM role_permissions rp WHERE rp.role_id = ?",
            [(int)$user['role_id']]
        );
        $_SESSION['permissions'] = array_column($perms, 'permission');
    }

    // Rotate session ID on login to prevent session fixation
    session_regenerate_id(true);
}

/**
 * Enforce session timeout — call on every protected page.
 * Redirects to login if the session has been idle > SESSION_TIMEOUT minutes.
 */
function checkSessionTimeout() {
    if (!isLoggedIn()) return;
    $timeout_seconds = SESSION_TIMEOUT * 60;
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $timeout_seconds) {
        session_unset();
        session_destroy();
        redirect(BASE_URL . '/index.php?reason=timeout');
    }
    // Refresh the activity timestamp on each request
    $_SESSION['login_time'] = time();
}

// ========================================
// CSRF Protection
// ========================================

/**
 * Generate (or retrieve) a CSRF token for the current session.
 */
function getCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden CSRF input field inside a form.
 */
function csrfInput() {
    $token = getCsrfToken();
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Validate the CSRF token from a POST request.
 * Returns true on success, false on failure.
 */
function validateCsrf() {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Validate CSRF and die with an error response if invalid.
 * Use at the top of any POST handler.
 */
function requireCsrf($json = false) {
    if (!validateCsrf()) {
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid request (CSRF)']);
            exit;
        }
        http_response_code(403);
        die('Invalid or missing security token. Please go back and try again.');
    }
}

/**
 * Redirect to URL
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Redirect with message
 */
function redirectWithMessage($url, $message, $type = 'success') {
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
    redirect($url);
}

/**
 * Get and clear message
 */
function getMessage() {
    $message = $_SESSION['message'] ?? '';
    $type = $_SESSION['message_type'] ?? 'info';
    
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
    
    return ['message' => $message, 'type' => $type];
}

/**
 * Display message alert HTML
 */
function displayMessage() {
    $msg = getMessage();
    if (!empty($msg['message'])) {
        $type = $msg['type'];
        $icon = ['success' => '✓', 'error' => '✕', 'warning' => '⚠', 'info' => 'ℹ'][$type] ?? 'ℹ';
        echo <<<HTML
        <div class="alert alert-{$type}">
            <span class="alert-icon">{$icon}</span>
            <div class="alert-content">
                <div class="alert-message">{$msg['message']}</div>
            </div>
        </div>
        HTML;
    }
}

/**
 * Get receipt text (for printing)
 */
function getReceiptText($sale, $items) {
    $receipt = "========== J&J GROCERY ==========\n";
    $receipt .= "Receipt #" . str_pad($sale['id'], 6, '0', STR_PAD_LEFT) . "\n";
    $receipt .= "Date: " . formatDateTime($sale['created_at']) . "\n";
    $receipt .= "================================\n\n";
    
    $receipt .= "Item                   Qty   Price\n";
    $receipt .= "--------------------------------\n";
    
    foreach ($items as $item) {
        $name = substr($item['name'], 0, 18);
        $qty = $item['quantity'];
        $subtotal = $item['unit_price'] * $qty;
        $receipt .= sprintf("%-20s %3d %s\n", $name, $qty, formatCurrency($subtotal));
    }
    
    $receipt .= "\n--------------------------------\n";
    $receipt .= sprintf("Subtotal:           %s\n", formatCurrency($sale['subtotal']));
    $receipt .= sprintf("VAT (12%%):          %s\n", formatCurrency($sale['tax_amount']));
    $receipt .= sprintf("TOTAL:              %s\n", formatCurrency($sale['total_amount']));
    $receipt .= sprintf("Paid:               %s\n", formatCurrency($sale['amount_paid']));
    $receipt .= sprintf("Change:             %s\n", formatCurrency($sale['change_amount']));
    $receipt .= "\nPayment: " . ucfirst(str_replace('_', ' ', $sale['payment_method'])) . "\n";
    $receipt .= "\n====== Thank You! =====\n";

    return $receipt;
}

// ========================================
// Activity Logging
// ========================================

/**
 * Log a sensitive action to the activity_log table.
 * Silently skips if the table does not exist yet (pre-migration).
 *
 * @param object      $db        Database instance
 * @param string      $action    Short action key (e.g. 'sale', 'delete_user', 'refund')
 * @param string      $details   Human-readable description
 * @param int|null    $target_id ID of the affected record (optional)
 * @param string      $severity  'info', 'warning', or 'critical' (default: 'info')
 * @param string|null $old_value Previous value (for change tracking)
 * @param string|null $new_value New value (for change tracking)
 */
function logActivity($db, string $action, string $details, ?int $target_id = null, string $severity = 'info', ?string $old_value = null, ?string $new_value = null) {
    $user_id = $_SESSION['user_id'] ?? null;
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Silently skip if table doesn't exist yet
    $check = $db->connection->query("SHOW TABLES LIKE 'activity_log'");
    if (!$check || $check->num_rows === 0) return;

    $db->execute(
        "INSERT INTO activity_log (user_id, action, severity, details, target_id, ip_address, old_value, new_value, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$user_id, $action, $severity, $details, $target_id, $ip, $old_value, $new_value, date('Y-m-d H:i:s')]
    );
}

?>
