<?php
/**
 * J&J Grocery POS — Business Settings API v4
 * GET              → returns current business settings
 * POST action=save → updates business settings
 * Requires settings or master_data permission.
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']); exit;
}
if (!hasAccess('master_data') && !hasAccess('settings')) {
    echo json_encode(['error' => 'Access denied']); exit;
}

$db   = new Database();
$user = getCurrentUser();

// ── GET: Return current settings ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $settings = getBusinessSettings($db);
    if (!$settings) {
        echo json_encode(['error' => 'Business settings not found. Run migration_v4.sql.']);
        exit;
    }
    echo json_encode(['success' => true, 'settings' => $settings]);
    exit;
}

// ── POST: Update settings ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf(true);

    $action = $_POST['action'] ?? '';
    if ($action !== 'save') {
        echo json_encode(['error' => 'Unknown action']); exit;
    }

    // Read current settings for audit log
    $old = getBusinessSettings($db);

    $business_name    = trim($_POST['business_name']    ?? '');
    $business_address = trim($_POST['business_address'] ?? '');
    $tin              = trim($_POST['tin']               ?? '');
    $vat_registered   = intval($_POST['vat_registered']  ?? 1);
    $vat_rate         = floatval($_POST['vat_rate']      ?? 0.12);
    $vat_inclusive     = intval($_POST['vat_inclusive']   ?? 1);
    $receipt_prefix   = trim($_POST['receipt_prefix']    ?? 'JJ-');
    $currency_symbol  = trim($_POST['currency_symbol']   ?? '₱');

    // Validate
    if (empty($business_name)) {
        echo json_encode(['error' => 'Business name is required']); exit;
    }
    if ($vat_rate < 0 || $vat_rate > 1) {
        echo json_encode(['error' => 'VAT rate must be between 0 and 1 (e.g. 0.12 for 12%)']); exit;
    }

    $ok = $db->execute(
        "UPDATE business_settings SET
            business_name = ?, business_address = ?, tin = ?,
            vat_registered = ?, vat_rate = ?, vat_inclusive = ?,
            receipt_prefix = ?, currency_symbol = ?
         WHERE id = 1",
        [$business_name, $business_address, $tin,
         $vat_registered, $vat_rate, $vat_inclusive,
         $receipt_prefix, $currency_symbol]
    );

    if ($ok) {
        // Clear cached settings so next read gets fresh data
        global $__biz_settings_cache;
        $__biz_settings_cache = null;

        // Build change summary for audit
        $changes = [];
        if (($old['business_name'] ?? '') !== $business_name)       $changes[] = "name: {$old['business_name']} → $business_name";
        if (($old['vat_registered'] ?? 1) != $vat_registered)       $changes[] = "VAT registered: " . ($vat_registered ? 'Yes' : 'No');
        if (floatval($old['vat_rate'] ?? 0.12) != $vat_rate)        $changes[] = "VAT rate: {$old['vat_rate']} → $vat_rate";
        if (($old['vat_inclusive'] ?? 1) != $vat_inclusive)          $changes[] = "VAT inclusive: " . ($vat_inclusive ? 'Yes' : 'No');
        if (($old['receipt_prefix'] ?? '') !== $receipt_prefix)      $changes[] = "receipt prefix: {$old['receipt_prefix']} → $receipt_prefix";

        $detail = $changes ? implode('; ', $changes) : 'Settings saved (no changes)';
        logActivity($db, 'settings_changed', "Business settings updated: $detail", null, 'critical',
            json_encode($old), json_encode(['business_name'=>$business_name, 'vat_registered'=>$vat_registered, 'vat_rate'=>$vat_rate, 'vat_inclusive'=>$vat_inclusive])
        );

        echo json_encode(['success' => true, 'message' => 'Business settings saved.']);
    } else {
        echo json_encode(['error' => 'Failed to save settings.']);
    }
    exit;
}

echo json_encode(['error' => 'Method not allowed']);
