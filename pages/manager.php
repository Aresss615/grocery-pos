<?php
/**
 * J&J Grocery POS — Manager Portal v4
 * Tabs: X-Read | Z-Read | Remittance | Cashier Summary | Void Log |
 *       Journal | Ledger | Refunds | Audit Trail | Read History
 * Requires manager_portal permission (v4) or manager/admin role (legacy fallback).
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn()) redirect(BASE_URL . '/index.php');
checkSessionTimeout();
if (!hasAccess('manager_portal')) redirect(BASE_URL . '/pages/dashboard.php');

$db   = new Database();
$user = getCurrentUser();
$today = date('Y-m-d');
$now   = date('Y-m-d H:i:s');

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // ── X-Read / Z-Read ──────────────────────────────────────
    if ($action === 'xread' || $action === 'zread') {
        $read_type = $action === 'zread' ? 'z_read' : 'x_read';

        if ($read_type === 'z_read') {
            // Block duplicate Z-Read
            try {
                $existing = $db->fetchOne(
                    "SELECT id FROM register_reads WHERE read_type='z_read' AND read_date=?",
                    [$today]
                );
            } catch (Exception $e) { $existing = null; }
            if ($existing) {
                redirectWithMessage(BASE_URL . '/pages/manager.php?tab=zread',
                    "A Z-Read has already been generated for today ($today).", 'warning');
            }
        }

        // Sales totals
        $totals = $db->fetchOne(
            "SELECT COUNT(*)                                  AS total_transactions,
                    COALESCE(SUM(total_amount),   0)          AS total_gross,
                    COALESCE(SUM(tax_amount),     0)          AS total_vat,
                    COALESCE(SUM(discount_amount),0)          AS total_discounts,
                    COALESCE(SUM(CASE WHEN payment_method='cash'  THEN total_amount ELSE 0 END),0) AS cash_sales,
                    COALESCE(SUM(CASE WHEN payment_method='gcash' THEN total_amount ELSE 0 END),0) AS gcash_sales,
                    COALESCE(SUM(CASE WHEN payment_method='card'  THEN total_amount ELSE 0 END),0) AS card_sales,
                    COALESCE(SUM(CASE WHEN payment_method='cash'  THEN COALESCE(change_amount,0) ELSE 0 END),0) AS change_given,
                    MIN(receipt_number)                       AS first_receipt,
                    MAX(receipt_number)                       AS last_receipt,
                    MIN(created_at)                           AS period_start,
                    MAX(created_at)                           AS period_end
             FROM sales WHERE DATE(created_at) = ? AND voided = 0",
            [$today]
        );

        $voids = $db->fetchOne(
            "SELECT COUNT(*) AS void_count, COALESCE(SUM(total_amount),0) AS void_amount
             FROM sales WHERE DATE(created_at) = ? AND voided = 1",
            [$today]
        );

        try {
            $refunds_total = $db->fetchOne(
                "SELECT COALESCE(SUM(refund_amount),0) AS total FROM refunds WHERE DATE(created_at) = ?",
                [$today]
            );
            $refund_total_amt = $refunds_total['total'] ?? 0;
        } catch (Exception $e) { $refund_total_amt = 0; }

        $ok = $db->execute(
            "INSERT INTO register_reads
                (read_type, register_no, generated_by, read_date, period_start, period_end,
                 total_transactions, total_gross, total_vat, total_discounts, total_net,
                 cash_sales, gcash_sales, card_sales, void_count, void_amount, created_at)
             VALUES (?, 'REG-01', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $read_type, $user['id'], $today,
                $totals['period_start'] ?? $now,
                $totals['period_end']   ?? $now,
                $totals['total_transactions'] ?? 0,
                $totals['total_gross']        ?? 0,
                $totals['total_vat']          ?? 0,
                $totals['total_discounts']    ?? 0,
                round(($totals['total_gross'] ?? 0) - ($totals['total_vat'] ?? 0), 2),
                $totals['cash_sales']  ?? 0,
                $totals['gcash_sales'] ?? 0,
                $totals['card_sales']  ?? 0,
                $voids['void_count']   ?? 0,
                $voids['void_amount']  ?? 0,
                $now,
            ]
        );

        // Z-Read: lock the day in business_settings
        if ($read_type === 'z_read' && $ok) {
            try {
                $db->execute(
                    "UPDATE business_settings SET day_closed = ? WHERE id = 1",
                    [$today]
                );
            } catch (Exception $e) { /* graceful — table may not exist yet */ }

            logActivity($db, 'z_read_generated',
                "Z-Read generated for $today — Gross: ₱" . number_format($totals['total_gross'] ?? 0, 2) .
                ", Transactions: " . ($totals['total_transactions'] ?? 0),
                null, 'critical'
            );
        } else {
            logActivity($db, 'x_read_generated', "X-Read generated for $today", null, 'info');
        }

        $label = $read_type === 'z_read' ? 'Z-Read' : 'X-Read';
        $tab   = $read_type === 'z_read' ? 'zread'  : 'xread';
        if ($ok) {
            redirectWithMessage(BASE_URL . "/pages/manager.php?tab=$tab",
                "$label generated successfully for $today.", 'success');
        } else {
            redirectWithMessage(BASE_URL . "/pages/manager.php?tab=$tab",
                "Failed to save $label record.", 'error');
        }
    }

    // ── Record Remittance (v4 — uses remittances table) ───────
    if ($action === 'process_remittance') {
        $cashier_id    = intval($_POST['cashier_id']     ?? 0);
        $expected_cash = floatval($_POST['expected_cash']?? 0);
        $notes         = trim($_POST['notes']            ?? '');
        $b1000 = intval($_POST['b1000']  ?? 0);
        $b500  = intval($_POST['b500']   ?? 0);
        $b200  = intval($_POST['b200']   ?? 0);
        $b100  = intval($_POST['b100']   ?? 0);
        $b50   = intval($_POST['b50']    ?? 0);
        $b20   = intval($_POST['b20']    ?? 0);
        $coins = floatval($_POST['coins']?? 0);

        $actual = ($b1000*1000) + ($b500*500) + ($b200*200) + ($b100*100) + ($b50*50) + ($b20*20) + $coins;
        $over_short = round($actual - $expected_cash, 2);

        if ($cashier_id && $actual > 0) {
            $ok = $db->execute(
                "INSERT INTO remittances
                    (cashier_id, manager_id, register_no, expected_cash, actual_cash,
                     bills_1000, bills_500, bills_200, bills_100, bills_50, bills_20, coins,
                     over_short, status, notes)
                 VALUES (?, ?, 'REG-01', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
                [$cashier_id, $user['id'], $expected_cash, $actual,
                 $b1000, $b500, $b200, $b100, $b50, $b20, $coins,
                 $over_short, $notes]
            );
            if ($ok) {
                $status_note = $over_short == 0 ? 'exact' : ($over_short > 0 ? 'over ₱'.number_format(abs($over_short),2) : 'short ₱'.number_format(abs($over_short),2));
                logActivity($db, 'remittance_recorded',
                    "Remittance of ₱" . number_format($actual, 2) . " from cashier #$cashier_id ($status_note)",
                    $cashier_id, 'info'
                );
                redirectWithMessage(BASE_URL . '/pages/manager.php?tab=remittance',
                    "Remittance of ₱" . number_format($actual, 2) . " recorded. Over/Short: ₱" . number_format($over_short, 2), 'success');
            } else {
                redirectWithMessage(BASE_URL . '/pages/manager.php?tab=remittance',
                    "Failed to record remittance.", 'error');
            }
        } else {
            redirectWithMessage(BASE_URL . '/pages/manager.php?tab=remittance',
                "Please select a cashier and enter denomination amounts.", 'error');
        }
    }
}

// ── Active tab ────────────────────────────────────────────────
$active_tab = $_GET['tab'] ?? 'xread';

// ── Sales summary (today) ─────────────────────────────────────
try {
    $sales_summary = $db->fetchOne(
        "SELECT COUNT(*)                                  AS cnt,
                COALESCE(SUM(total_amount),   0)          AS gross,
                COALESCE(SUM(tax_amount),     0)          AS vat,
                COALESCE(SUM(discount_amount),0)          AS total_discounts,
                COALESCE(SUM(CASE WHEN payment_method='cash'  THEN total_amount ELSE 0 END),0) AS cash_sales,
                COALESCE(SUM(CASE WHEN payment_method='gcash' THEN total_amount ELSE 0 END),0) AS gcash_sales,
                COALESCE(SUM(CASE WHEN payment_method='card'  THEN total_amount ELSE 0 END),0) AS card_sales,
                COALESCE(SUM(CASE WHEN payment_method='cash' THEN COALESCE(change_amount,0) ELSE 0 END),0) AS change_given,
                MIN(receipt_number) AS first_receipt,
                MAX(receipt_number) AS last_receipt
         FROM sales WHERE DATE(created_at) = ? AND voided = 0",
        [$today]
    );
    $void_summary = $db->fetchOne(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS amount
         FROM sales WHERE DATE(created_at) = ? AND voided = 1",
        [$today]
    );
} catch (Exception $e) {
    $sales_summary = ['cnt'=>0,'gross'=>0,'vat'=>0,'total_discounts'=>0,'cash_sales'=>0,'gcash_sales'=>0,'card_sales'=>0,'change_given'=>0,'first_receipt'=>null,'last_receipt'=>null];
    $void_summary  = ['cnt'=>0,'amount'=>0];
}

$expected_cash_today = round(($sales_summary['cash_sales'] ?? 0) - ($sales_summary['change_given'] ?? 0), 2);

// ── Refund total today ────────────────────────────────────────
try {
    $refund_today = $db->fetchOne(
        "SELECT COALESCE(SUM(refund_amount),0) AS total, COUNT(*) AS cnt
         FROM refunds WHERE DATE(created_at) = ?", [$today]
    );
} catch (Exception $e) { $refund_today = ['total'=>0,'cnt'=>0]; }

// ── Cashier summary ───────────────────────────────────────────
try {
    $cashier_summary = $db->fetchAll(
        "SELECT u.id AS cashier_id, u.name AS cashier_name,
                COUNT(s.id)               AS txn_count,
                COALESCE(SUM(s.total_amount), 0)  AS total_sales,
                COALESCE(SUM(s.tax_amount),   0)  AS total_vat,
                COALESCE(SUM(s.discount_amount),0) AS total_discounts,
                COALESCE(SUM(CASE WHEN s.payment_method='cash'  THEN s.total_amount ELSE 0 END),0) AS cash,
                COALESCE(SUM(CASE WHEN s.payment_method='gcash' THEN s.total_amount ELSE 0 END),0) AS gcash,
                COALESCE(SUM(CASE WHEN s.payment_method='card'  THEN s.total_amount ELSE 0 END),0) AS card,
                COALESCE(SUM(CASE WHEN s.payment_method='cash' THEN COALESCE(s.change_amount,0) ELSE 0 END),0) AS change_given
         FROM sales s
         JOIN users u ON s.cashier_id = u.id
         WHERE DATE(s.created_at) = ? AND s.voided = 0
         GROUP BY s.cashier_id, u.name, u.id
         ORDER BY total_sales DESC",
        [$today]
    );
} catch (Exception $e) { $cashier_summary = []; }

// Build cashier expected cash map for remittance JS
$cashier_expected_map = [];
foreach ($cashier_summary as $cs) {
    $cashier_expected_map[$cs['cashier_id']] = [
        'cash_sales'   => (float)$cs['cash'],
        'change_given' => (float)$cs['change_given'],
        'expected'     => round((float)$cs['cash'] - (float)$cs['change_given'], 2),
    ];
}

// ── Void log ──────────────────────────────────────────────────
try {
    $void_log = $db->fetchAll(
        "SELECT s.id, s.receipt_number, s.total_amount, s.payment_method, s.created_at,
                s.voided_at, s.void_reason,
                uc.name AS cashier_name,
                uv.name AS voided_by_name
         FROM sales s
         JOIN users uc ON s.cashier_id = uc.id
         LEFT JOIN users uv ON s.voided_by = uv.id
         WHERE DATE(s.created_at) = ? AND s.voided = 1
         ORDER BY s.voided_at DESC",
        [$today]
    );
} catch (Exception $e) { $void_log = []; }

// ── Remittance history (today) ────────────────────────────────
try {
    $remittances = $db->fetchAll(
        "SELECT r.*, u.name AS cashier_name, m.name AS manager_name
         FROM remittances r
         LEFT JOIN users u ON r.cashier_id = u.id
         LEFT JOIN users m ON r.manager_id = m.id
         WHERE DATE(r.created_at) = ?
         ORDER BY r.created_at DESC",
        [$today]
    );
} catch (Exception $e) {
    // Fall back to legacy cash_remittals table if remittances doesn't exist yet
    try {
        $remittances_raw = $db->fetchAll(
            "SELECT cr.*, u.name AS cashier_name, m.name AS manager_name,
                    cr.amount AS actual_cash, 0 AS expected_cash, 0 AS over_short
             FROM cash_remittals cr
             LEFT JOIN users u ON cr.cashier_id = u.id
             LEFT JOIN users m ON cr.manager_id = m.id
             WHERE DATE(cr.created_at) = ?
             ORDER BY cr.created_at DESC",
            [$today]
        );
        $remittances = $remittances_raw;
    } catch (Exception $e2) { $remittances = []; }
}

// ── Cashiers list (for remittance form) ──────────────────────
try {
    $cashiers = $db->fetchAll(
        "SELECT u.id, u.name, u.username
         FROM users u
         LEFT JOIN roles r ON u.role_id = r.id
         WHERE u.active = 1 AND (r.slug IN ('cashier','admin','manager') OR u.role IN ('cashier','admin','manager'))
         ORDER BY u.name"
    );
} catch (Exception $e) {
    $cashiers = $db->fetchAll(
        "SELECT id, name, username FROM users WHERE active=1 ORDER BY name"
    );
}

// ── Z-Read check ──────────────────────────────────────────────
try {
    $zread_done_today = $db->fetchOne(
        "SELECT id, created_at FROM register_reads WHERE read_type='z_read' AND read_date=?",
        [$today]
    );
} catch (Exception $e) { $zread_done_today = null; }

// ── Past register reads ───────────────────────────────────────
try {
    $past_reads = $db->fetchAll(
        "SELECT rr.*, u.name AS generated_by_name
         FROM register_reads rr
         LEFT JOIN users u ON rr.generated_by = u.id
         ORDER BY rr.created_at DESC LIMIT 30"
    );
} catch (Exception $e) { $past_reads = []; }

// ── Journal entries ───────────────────────────────────────────
$journal_date = $_GET['jdate'] ?? $today;
try {
    $journal_entries_data = $db->fetchAll(
        "SELECT je.*, u.name AS created_by_name
           FROM journal_entries je
           LEFT JOIN users u ON je.created_by = u.id
          WHERE je.entry_date = ?
          ORDER BY je.id ASC",
        [$journal_date]
    );
    $journal_totals = $db->fetchOne(
        "SELECT COALESCE(SUM(debit),0) AS total_debit, COALESCE(SUM(credit),0) AS total_credit
         FROM journal_entries WHERE entry_date = ?",
        [$journal_date]
    );
} catch (Exception $e) { $journal_entries_data = []; $journal_totals = ['total_debit'=>0,'total_credit'=>0]; }

// Group journal entries by reference
$journal_grouped = [];
foreach ($journal_entries_data as $je) {
    $ref = $je['reference_type'] . '-' . $je['reference_id'];
    if (!isset($journal_grouped[$ref])) {
        $journal_grouped[$ref] = ['ref'=>$ref, 'description'=>$je['description'], 'time'=>$je['created_at'], 'lines'=>[]];
    }
    $journal_grouped[$ref]['lines'][] = $je;
}

// ── Ledger accounts ───────────────────────────────────────────
try {
    $ledger_accounts_data = $db->fetchAll(
        "SELECT la.*,
                (SELECT COUNT(*) FROM journal_entries je WHERE je.account_code = la.account_code) AS entry_count
         FROM ledger_accounts la
         ORDER BY la.account_code"
    );
} catch (Exception $e) { $ledger_accounts_data = []; }

// ── Recent refunds ────────────────────────────────────────────
try {
    $recent_refunds = $db->fetchAll(
        "SELECT r.*, u.name AS processed_by_name
         FROM refunds r
         LEFT JOIN users u ON r.processed_by = u.id
         ORDER BY r.created_at DESC LIMIT 50"
    );
} catch (Exception $e) { $recent_refunds = []; }

// ── Audit trail ───────────────────────────────────────────────
$audit_severity = $_GET['severity'] ?? '';
$audit_limit = 200;
try {
    if ($audit_severity) {
        $audit_log = $db->fetchAll(
            "SELECT al.*, u.name AS user_name
             FROM activity_log al
             LEFT JOIN users u ON al.user_id = u.id
             WHERE al.severity = ?
             ORDER BY al.created_at DESC LIMIT ?",
            [$audit_severity, $audit_limit]
        );
    } else {
        $audit_log = $db->fetchAll(
            "SELECT al.*, u.name AS user_name
             FROM activity_log al
             LEFT JOIN users u ON al.user_id = u.id
             ORDER BY al.created_at DESC LIMIT ?",
            [$audit_limit]
        );
    }
} catch (Exception $e) { $audit_log = []; }

// ── Business settings (for receipt info) ─────────────────────
$biz = getBusinessSettings($db);
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> — Manager Portal</title>
    <script>(function(){var t=localStorage.getItem('pos_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>/main.css">
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>/theme.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <style>
    /* ── Manager Portal Styles ──────────────────────────── */
    .read-summary { display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:var(--space-4);margin-bottom:var(--space-5); }
    .read-row { display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--c-border);font-size:.9rem; }
    .read-row:last-child { border-bottom:none;font-weight:700;font-size:1rem; }
    .read-row .label { color:var(--c-text-soft); }
    .read-row .value { font-weight:600;color:var(--c-text); }
    .read-row.total .value { color:var(--c-primary);font-size:1.1rem; }
    .denomination-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:var(--space-3); }
    .denom-item { display:flex;flex-direction:column;gap:4px; }
    .denom-item label { font-size:.78rem;font-weight:600;color:var(--c-text-soft); }
    .denom-item input { text-align:center;font-size:1rem;font-weight:700;padding:var(--space-2) var(--space-3);background:var(--c-bg);border:1.5px solid var(--c-border);border-radius:var(--radius-md);color:var(--c-text);width:100%; }
    .denom-item input:focus { border-color:var(--c-primary);outline:none; }
    .denom-subtotal { font-size:.75rem;color:var(--c-text-soft);text-align:center; }
    .cash-total-box { background:var(--c-primary-fade);border:1.5px solid rgba(211,47,47,.3);border-radius:var(--radius-lg);padding:var(--space-4) var(--space-5);margin-top:var(--space-4); }
    .cash-total-box .amount { font-size:2rem;font-weight:800;color:var(--c-primary); }
    .over-short-box { border-radius:var(--radius-lg);padding:var(--space-3) var(--space-5);margin-top:var(--space-3);font-size:1.1rem;font-weight:800;text-align:center; }
    .over-short-box.exact { background:#f0fdf4;color:#16a34a;border:1.5px solid #86efac; }
    .over-short-box.over  { background:#f0fdf4;color:#16a34a;border:1.5px solid #86efac; }
    .over-short-box.short { background:#fef2f2;color:#dc2626;border:1.5px solid #fca5a5; }
    [data-theme="dark"] .over-short-box.exact,[data-theme="dark"] .over-short-box.over { background:#052e16;border-color:#166534; }
    [data-theme="dark"] .over-short-box.short { background:#450a0a;border-color:#991b1b; }
    .zread-done-banner { background:var(--c-success-light);border:1px solid var(--c-success);border-radius:var(--radius-lg);padding:var(--space-4) var(--space-5);margin-bottom:var(--space-5);display:flex;align-items:center;gap:var(--space-3); }
    .tab-nav { display:flex;gap:4px;border-bottom:2px solid var(--c-border);margin-bottom:var(--space-5);flex-wrap:wrap; }
    .tab-nav .tab-btn { padding:var(--space-3) var(--space-4);background:none;border:none;border-bottom:3px solid transparent;cursor:pointer;font-size:.85rem;font-weight:600;color:var(--c-text-soft);font-family:var(--font-sans);transition:var(--transition);margin-bottom:-2px; }
    .tab-nav .tab-btn:hover { color:var(--c-text);background:rgba(211,47,47,.06);border-radius:var(--radius-md) var(--radius-md) 0 0; }
    .tab-nav .tab-btn.active { color:var(--c-primary);border-bottom-color:var(--c-primary); }
    .tab-pane { display:none; }
    .tab-pane.active { display:block; }
    .read-history-card { display:flex;align-items:center;gap:var(--space-3);padding:var(--space-3) var(--space-4);border:1px solid var(--c-border);border-radius:var(--radius-md);margin-bottom:var(--space-3);background:var(--c-surface);cursor:pointer;transition:var(--transition); }
    .read-history-card:hover { border-color:var(--c-primary); }
    .read-badge-x { background:#e3f2fd;color:#1565c0;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:700; }
    .read-badge-z { background:#fce4ec;color:#c62828;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:700; }
    [data-theme="dark"] .read-badge-x { background:#0c1a3a;color:#93c5fd; }
    [data-theme="dark"] .read-badge-z { background:#450a0a;color:#fca5a5; }
    [data-theme="dark"] .denom-item input { background:#111827; }
    .void-row td { color:var(--c-danger) !important; }
    .section-divider { display:flex;align-items:center;gap:var(--space-3);margin:var(--space-5) 0 var(--space-4); }
    .section-divider hr { flex:1;border:none;border-top:1px solid var(--c-border); }
    .section-divider span { font-size:.8rem;font-weight:700;color:var(--c-text-soft);text-transform:uppercase;letter-spacing:.05em;white-space:nowrap; }

    /* Journal / Ledger */
    .journal-group { border:1px solid var(--c-border);border-radius:var(--radius-md);margin-bottom:var(--space-4);overflow:hidden; }
    .journal-group-header { background:var(--c-surface);padding:var(--space-3) var(--space-4);display:flex;gap:var(--space-3);align-items:center;font-size:.85rem; }
    .journal-group-header strong { font-family:monospace;font-size:.9rem; }
    .journal-line { display:grid;grid-template-columns:1fr minmax(80px,auto) minmax(80px,auto);padding:var(--space-2) var(--space-4);font-size:.85rem;border-top:1px solid var(--c-border);gap:var(--space-3); }
    .journal-line .acct { color:var(--c-text-soft); }
    .journal-line .dr { color:#16a34a;text-align:right;font-weight:600; }
    .journal-line .cr { color:var(--c-primary);text-align:right;font-weight:600; }
    .ref-badge { font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:10px;text-transform:uppercase; }
    .ref-badge.sale     { background:#dcfce7;color:#166534; }
    .ref-badge.void     { background:#fce4ec;color:#c62828; }
    .ref-badge.refund   { background:#fef9c3;color:#854d0e; }
    .ref-badge.remittance,.ref-badge.adjustment { background:#e0e7ff;color:#3730a3; }
    [data-theme="dark"] .ref-badge.sale     { background:#052e16;color:#86efac; }
    [data-theme="dark"] .ref-badge.void     { background:#450a0a;color:#fca5a5; }
    [data-theme="dark"] .ref-badge.refund   { background:#422006;color:#fde68a; }
    [data-theme="dark"] .ref-badge.remittance,[data-theme="dark"] .ref-badge.adjustment { background:#1e1b4b;color:#a5b4fc; }

    .ledger-card { border:1px solid var(--c-border);border-radius:var(--radius-md);padding:var(--space-4);display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-3); }
    .ledger-card .l-code { font-family:monospace;font-size:.85rem;color:var(--c-text-soft); }
    .ledger-card .l-name { font-weight:600; }
    .ledger-card .l-type { font-size:.75rem;padding:2px 8px;border-radius:10px; }
    .l-type.asset    { background:#dcfce7;color:#166534; }
    .l-type.liability{ background:#fce4ec;color:#c62828; }
    .l-type.revenue  { background:#e0e7ff;color:#3730a3; }
    .l-type.expense  { background:#fef9c3;color:#854d0e; }
    .l-type.equity   { background:#f3f4f6;color:#374151; }
    [data-theme="dark"] .l-type.asset    { background:#052e16;color:#86efac; }
    [data-theme="dark"] .l-type.liability{ background:#450a0a;color:#fca5a5; }
    [data-theme="dark"] .l-type.revenue  { background:#1e1b4b;color:#a5b4fc; }
    [data-theme="dark"] .l-type.expense  { background:#422006;color:#fde68a; }
    [data-theme="dark"] .l-type.equity   { background:#1f2937;color:#9ca3af; }
    .ledger-balance { font-size:1.2rem;font-weight:800; }

    /* Audit trail */
    .sev-info     { background:#e0e7ff;color:#3730a3;padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:700; }
    .sev-warning  { background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:700; }
    .sev-critical { background:#fce4ec;color:#c62828;padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:700; }
    [data-theme="dark"] .sev-info     { background:#1e1b4b;color:#a5b4fc; }
    [data-theme="dark"] .sev-warning  { background:#422006;color:#fde68a; }
    [data-theme="dark"] .sev-critical { background:#450a0a;color:#fca5a5; }

    /* Refund modal */
    .refund-item-row { display:flex;align-items:center;gap:var(--space-3);padding:var(--space-2) 0;border-bottom:1px solid var(--c-border); }
    .refund-item-row:last-child { border-bottom:none; }

    @media(max-width:768px){
        .read-summary { grid-template-columns:1fr 1fr; }
        .denomination-grid { grid-template-columns:repeat(3,1fr); }
    }
    </style>
</head>
<body class="dashboard-page">
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard-container">

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>Manager Portal</h1>
                <p class="text-muted"><?php echo date('l, F j, Y'); ?> · Register REG-01</p>
            </div>
            <div style="display:flex;gap:var(--space-3);align-items:center;">
                <?php if ($zread_done_today): ?>
                <span class="badge" style="background:#fce4ec;color:#c62828;font-size:.8rem;padding:6px 14px;">
                    Z-Read done <?php echo date('g:i A', strtotime($zread_done_today['created_at'])); ?>
                </span>
                <?php endif; ?>
                <span style="font-size:.85rem;color:var(--c-text-soft);"><?php echo htmlspecialchars($user['name']); ?></span>
            </div>
        </div>

        <?php displayMessage(); ?>

        <!-- Quick stats -->
        <div class="stats-grid" style="margin-bottom:var(--space-5);">
            <div class="stat-card stat-success">
                <div class="stat-icon">💰</div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo formatCurrency($sales_summary['gross'] ?? 0); ?></div>
                    <div class="stat-label">Gross Sales Today</div>
                </div>
            </div>
            <div class="stat-card stat-info">
                <div class="stat-icon">🛒</div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($sales_summary['cnt'] ?? 0); ?></div>
                    <div class="stat-label">Transactions</div>
                </div>
            </div>
            <div class="stat-card stat-warning">
                <div class="stat-icon">🧾</div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo formatCurrency($sales_summary['vat'] ?? 0); ?></div>
                    <div class="stat-label">VAT Collected</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">💵</div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo formatCurrency($expected_cash_today); ?></div>
                    <div class="stat-label">Expected Cash</div>
                </div>
            </div>
            <?php if (($sales_summary['total_discounts'] ?? 0) > 0): ?>
            <div class="stat-card">
                <div class="stat-icon">🏷️</div>
                <div class="stat-info">
                    <div class="stat-value" style="color:var(--c-warning);"><?php echo formatCurrency($sales_summary['total_discounts']); ?></div>
                    <div class="stat-label">Discounts Given</div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (($void_summary['cnt'] ?? 0) > 0): ?>
            <div class="stat-card" style="border-left-color:var(--c-danger);">
                <div class="stat-icon">🚫</div>
                <div class="stat-info">
                    <div class="stat-value" style="color:var(--c-danger);"><?php echo (int)$void_summary['cnt']; ?></div>
                    <div class="stat-label">Voided Transactions</div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab Navigation -->
        <div class="tab-nav">
            <button class="tab-btn <?php echo $active_tab==='xread'?'active':''; ?>"       onclick="switchTab('xread',this)">📊 X-Read</button>
            <button class="tab-btn <?php echo $active_tab==='zread'?'active':''; ?>"       onclick="switchTab('zread',this)">📋 Z-Read</button>
            <button class="tab-btn <?php echo $active_tab==='remittance'?'active':''; ?>"  onclick="switchTab('remittance',this)">💰 Remittance</button>
            <button class="tab-btn <?php echo $active_tab==='cashiers'?'active':''; ?>"    onclick="switchTab('cashiers',this)">👥 Cashier Summary</button>
            <button class="tab-btn <?php echo $active_tab==='voids'?'active':''; ?>"       onclick="switchTab('voids',this)">
                🚫 Void Log <?php if (($void_summary['cnt']??0) > 0): ?><span style="background:var(--c-danger);color:#fff;border-radius:99px;font-size:.68rem;padding:1px 6px;margin-left:3px;"><?php echo (int)$void_summary['cnt']; ?></span><?php endif; ?>
            </button>
            <button class="tab-btn <?php echo $active_tab==='journal'?'active':''; ?>"     onclick="switchTab('journal',this)">📒 Journal</button>
            <button class="tab-btn <?php echo $active_tab==='ledger'?'active':''; ?>"      onclick="switchTab('ledger',this)">📈 Ledger</button>
            <button class="tab-btn <?php echo $active_tab==='refunds'?'active':''; ?>"     onclick="switchTab('refunds',this)">↩️ Refunds</button>
            <button class="tab-btn <?php echo $active_tab==='audit'?'active':''; ?>"       onclick="switchTab('audit',this)">🔍 Audit Trail</button>
            <button class="tab-btn <?php echo $active_tab==='history'?'active':''; ?>"     onclick="switchTab('history',this)">🗂️ Read History</button>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB: X-READ                                         -->
        <!-- ════════════════════════════════════════════════════ -->
        <div id="tab-xread" class="tab-pane <?php echo $active_tab==='xread'?'active':''; ?>">
            <div style="display:grid;grid-template-columns:1fr 340px;gap:var(--space-5);">
                <div class="card card-flat">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4);">
                        <h3 style="margin:0;">📊 X-Read — Today's Snapshot</h3>
                        <span style="font-size:.8rem;color:var(--c-text-soft);">As of <?php echo date('g:i A'); ?></span>
                    </div>
                    <p class="text-muted" style="margin-bottom:var(--space-4);font-size:.85rem;">
                        X-Read shows current sales totals without resetting any counters. Run this anytime.
                    </p>

                    <?php if ($sales_summary['first_receipt'] || $sales_summary['last_receipt']): ?>
                    <div class="section-divider"><hr><span>Receipt Range</span><hr></div>
                    <div style="margin-bottom:var(--space-4);">
                        <div class="read-row"><span class="label">Beginning Receipt</span><span class="value" style="font-family:monospace;"><?php echo htmlspecialchars($sales_summary['first_receipt'] ?? '—'); ?></span></div>
                        <div class="read-row"><span class="label">Ending Receipt</span><span class="value" style="font-family:monospace;"><?php echo htmlspecialchars($sales_summary['last_receipt'] ?? '—'); ?></span></div>
                    </div>
                    <?php endif; ?>

                    <div class="section-divider"><hr><span>Sales Totals</span><hr></div>
                    <div style="margin-bottom:var(--space-4);">
                        <div class="read-row"><span class="label">Total Transactions</span><span class="value"><?php echo number_format($sales_summary['cnt'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">Gross Sales</span><span class="value"><?php echo formatCurrency($sales_summary['gross'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">Less: Discounts</span><span class="value" style="color:var(--c-warning);">-<?php echo formatCurrency($sales_summary['total_discounts'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">Less: Refunds</span><span class="value" style="color:var(--c-warning);">-<?php echo formatCurrency($refund_today['total'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">VAT (12%)</span><span class="value"><?php echo formatCurrency($sales_summary['vat'] ?? 0); ?></span></div>
                        <div class="read-row total"><span class="label">Net Sales (excl. VAT)</span><span class="value"><?php echo formatCurrency(($sales_summary['gross'] ?? 0) - ($sales_summary['vat'] ?? 0)); ?></span></div>
                    </div>

                    <div class="section-divider"><hr><span>By Payment Method</span><hr></div>
                    <div style="margin-bottom:var(--space-4);">
                        <div class="read-row"><span class="label">💵 Cash</span><span class="value"><?php echo formatCurrency($sales_summary['cash_sales'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">📱 GCash</span><span class="value"><?php echo formatCurrency($sales_summary['gcash_sales'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">💳 Card</span><span class="value"><?php echo formatCurrency($sales_summary['card_sales'] ?? 0); ?></span></div>
                    </div>

                    <div class="section-divider"><hr><span>Cash Accountability</span><hr></div>
                    <div style="margin-bottom:var(--space-4);">
                        <div class="read-row"><span class="label">Cash Sales</span><span class="value"><?php echo formatCurrency($sales_summary['cash_sales'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">Less: Change Given</span><span class="value" style="color:var(--c-warning);">-<?php echo formatCurrency($sales_summary['change_given'] ?? 0); ?></span></div>
                        <div class="read-row total"><span class="label">Expected Cash in Drawer</span><span class="value"><?php echo formatCurrency($expected_cash_today); ?></span></div>
                    </div>

                    <?php if (($void_summary['cnt'] ?? 0) > 0): ?>
                    <div class="section-divider"><hr><span>Voids</span><hr></div>
                    <div>
                        <div class="read-row"><span class="label" style="color:var(--c-danger);">Voided Transactions</span><span class="value" style="color:var(--c-danger);"><?php echo (int)$void_summary['cnt']; ?></span></div>
                        <div class="read-row"><span class="label" style="color:var(--c-danger);">Total Void Amount</span><span class="value" style="color:var(--c-danger);"><?php echo formatCurrency($void_summary['amount'] ?? 0); ?></span></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div style="display:flex;flex-direction:column;gap:var(--space-4);">
                    <div class="card card-flat">
                        <h4 style="margin-bottom:var(--space-3);">Generate X-Read</h4>
                        <p style="font-size:.82rem;color:var(--c-text-soft);margin-bottom:var(--space-4);">
                            Saves a snapshot to the register log. Can be run multiple times.
                        </p>
                        <form method="POST">
                            <?php csrfInput(); ?>
                            <input type="hidden" name="action" value="xread">
                            <button type="submit" class="btn btn-primary btn-block">📊 Generate X-Read</button>
                        </form>
                    </div>
                    <div class="card card-flat">
                        <h4 style="margin-bottom:var(--space-3);">Payment Breakdown</h4>
                        <canvas id="xreadChart" height="180"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB: Z-READ                                         -->
        <!-- ════════════════════════════════════════════════════ -->
        <div id="tab-zread" class="tab-pane <?php echo $active_tab==='zread'?'active':''; ?>">
            <?php if ($zread_done_today): ?>
            <div class="zread-done-banner">
                <span style="font-size:1.5rem;">✅</span>
                <div>
                    <strong>Z-Read already completed for today.</strong><br>
                    <span style="font-size:.85rem;color:var(--c-text-soft);">Generated at <?php echo date('g:i A', strtotime($zread_done_today['created_at'])); ?> — only one Z-Read per business day.</span>
                </div>
            </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 340px;gap:var(--space-5);">
                <div class="card card-flat">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4);">
                        <h3 style="margin:0;">📋 Z-Read — End of Day Report</h3>
                        <span style="font-size:.8rem;color:var(--c-text-soft);"><?php echo date('F j, Y'); ?></span>
                    </div>
                    <p class="text-muted" style="margin-bottom:var(--space-4);font-size:.85rem;">
                        Z-Read is the official end-of-day report. It locks the business day — no more sales after Z-Read until tomorrow.
                    </p>

                    <?php if ($sales_summary['first_receipt'] || $sales_summary['last_receipt']): ?>
                    <div class="section-divider"><hr><span>Receipt Summary</span><hr></div>
                    <div style="margin-bottom:var(--space-4);">
                        <div class="read-row"><span class="label">Beginning Receipt #</span><span class="value" style="font-family:monospace;"><?php echo htmlspecialchars($sales_summary['first_receipt'] ?? '—'); ?></span></div>
                        <div class="read-row"><span class="label">Ending Receipt #</span><span class="value" style="font-family:monospace;"><?php echo htmlspecialchars($sales_summary['last_receipt'] ?? '—'); ?></span></div>
                        <div class="read-row"><span class="label">Total Transactions</span><span class="value"><?php echo number_format($sales_summary['cnt'] ?? 0); ?></span></div>
                    </div>
                    <?php endif; ?>

                    <div class="section-divider"><hr><span>Gross Sales</span><hr></div>
                    <div style="margin-bottom:var(--space-4);">
                        <div class="read-row"><span class="label">Gross Sales</span><span class="value"><?php echo formatCurrency($sales_summary['gross'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">Less: Discounts</span><span class="value" style="color:var(--c-warning);">-<?php echo formatCurrency($sales_summary['total_discounts'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">Less: Voids</span><span class="value" style="color:var(--c-danger);">-<?php echo formatCurrency($void_summary['amount'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">Less: Refunds</span><span class="value" style="color:var(--c-warning);">-<?php echo formatCurrency($refund_today['total'] ?? 0); ?></span></div>
                        <div class="read-row total"><span class="label">Net Sales</span><span class="value"><?php echo formatCurrency(($sales_summary['gross'] ?? 0) - ($sales_summary['total_discounts'] ?? 0) - ($void_summary['amount'] ?? 0) - ($refund_today['total'] ?? 0)); ?></span></div>
                    </div>

                    <div class="section-divider"><hr><span>VAT Breakdown</span><hr></div>
                    <div style="margin-bottom:var(--space-4);">
                        <?php
                        $net_sales_excl_vat = ($sales_summary['gross'] ?? 0) - ($sales_summary['vat'] ?? 0);
                        ?>
                        <div class="read-row"><span class="label">VATable Sales (net)</span><span class="value"><?php echo formatCurrency($net_sales_excl_vat); ?></span></div>
                        <div class="read-row total"><span class="label">VAT 12%</span><span class="value"><?php echo formatCurrency($sales_summary['vat'] ?? 0); ?></span></div>
                    </div>

                    <div class="section-divider"><hr><span>Payment Breakdown</span><hr></div>
                    <div style="margin-bottom:var(--space-4);">
                        <div class="read-row"><span class="label">💵 Cash</span><span class="value"><?php echo formatCurrency($sales_summary['cash_sales'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">📱 GCash</span><span class="value"><?php echo formatCurrency($sales_summary['gcash_sales'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">💳 Card</span><span class="value"><?php echo formatCurrency($sales_summary['card_sales'] ?? 0); ?></span></div>
                    </div>

                    <div class="section-divider"><hr><span>Cash Accountability</span><hr></div>
                    <div style="margin-bottom:var(--space-4);">
                        <div class="read-row"><span class="label">Cash Sales</span><span class="value"><?php echo formatCurrency($sales_summary['cash_sales'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">Less: Change Given</span><span class="value" style="color:var(--c-warning);">-<?php echo formatCurrency($sales_summary['change_given'] ?? 0); ?></span></div>
                        <div class="read-row total"><span class="label">Expected Cash in Drawer</span><span class="value"><?php echo formatCurrency($expected_cash_today); ?></span></div>
                    </div>

                    <?php if (($void_summary['cnt'] ?? 0) > 0): ?>
                    <div class="section-divider"><hr><span>Void Summary</span><hr></div>
                    <div>
                        <div class="read-row"><span class="label" style="color:var(--c-danger);">Void Count</span><span class="value" style="color:var(--c-danger);"><?php echo (int)$void_summary['cnt']; ?></span></div>
                        <div class="read-row"><span class="label" style="color:var(--c-danger);">Void Amount</span><span class="value" style="color:var(--c-danger);"><?php echo formatCurrency($void_summary['amount'] ?? 0); ?></span></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="card card-flat" style="align-self:start;">
                    <h4 style="margin-bottom:var(--space-3);">Generate Z-Read</h4>
                    <?php if ($zread_done_today): ?>
                        <p style="font-size:.85rem;color:var(--c-success);font-weight:600;">✅ Already completed for today.</p>
                    <?php else: ?>
                        <p style="font-size:.82rem;color:var(--c-text-soft);margin-bottom:var(--space-4);">
                            Saves the official end-of-day totals and <strong>locks further sales until tomorrow</strong>.
                            Only one Z-Read per day is allowed.
                        </p>
                        <form method="POST" onsubmit="return confirm('Generate Z-Read for <?php echo $today; ?>?\n\nThis will lock the POS for the rest of today. This action cannot be undone.');">
                            <?php csrfInput(); ?>
                            <input type="hidden" name="action" value="zread">
                            <button type="submit" class="btn btn-primary btn-block" style="background:var(--c-danger);">
                                📋 Generate Z-Read (EOD)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB: REMITTANCE (v4)                                -->
        <!-- ════════════════════════════════════════════════════ -->
        <div id="tab-remittance" class="tab-pane <?php echo $active_tab==='remittance'?'active':''; ?>">
            <div style="display:grid;grid-template-columns:1fr 320px;gap:var(--space-5);">

                <!-- Left: denomination form -->
                <div class="card card-flat">
                    <h3 style="margin-bottom:var(--space-2);">💰 Cash Remittance</h3>
                    <p class="text-muted" style="font-size:.85rem;margin-bottom:var(--space-4);">
                        Count the physical cash in the drawer. The system will show expected vs actual and compute over/short.
                    </p>
                    <form method="POST" id="remittanceForm">
                        <?php csrfInput(); ?>
                        <input type="hidden" name="action" value="process_remittance">
                        <input type="hidden" name="expected_cash" id="expectedCashInput" value="0">

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);margin-bottom:var(--space-4);">
                            <div>
                                <label class="form-label">Cashier</label>
                                <select name="cashier_id" class="form-select" required onchange="updateExpected(this.value)">
                                    <option value="">— Select cashier —</option>
                                    <?php foreach ($cashiers as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Expected Cash</label>
                                <div style="font-size:1.4rem;font-weight:800;color:var(--c-text);padding:var(--space-2) 0;" id="expectedCashDisplay">—</div>
                                <div style="font-size:.72rem;color:var(--c-text-soft);">Cash sales minus change given</div>
                            </div>
                        </div>

                        <div class="section-divider"><hr><span>Denomination Count</span><hr></div>
                        <div class="denomination-grid" style="margin-bottom:var(--space-4);">
                            <?php foreach ([1000,500,200,100,50,20] as $d): ?>
                            <div class="denom-item">
                                <label>₱<?php echo number_format($d); ?> Bills</label>
                                <input type="number" name="b<?php echo $d; ?>" id="b<?php echo $d; ?>" min="0" value="0" class="denom-qty" oninput="recalc()">
                                <div class="denom-subtotal" id="sub<?php echo $d; ?>">₱0.00</div>
                            </div>
                            <?php endforeach; ?>
                            <div class="denom-item">
                                <label>Coins &amp; Centavos (₱)</label>
                                <input type="number" name="coins" id="coins" min="0" step="0.01" value="0.00" oninput="recalc()">
                                <div class="denom-subtotal" id="subcoins">₱0.00</div>
                            </div>
                        </div>

                        <div class="cash-total-box" style="margin-bottom:var(--space-3);">
                            <div style="font-size:.8rem;color:var(--c-text-soft);margin-bottom:4px;">Actual Cash Counted</div>
                            <div class="amount" id="actualDisplay">₱0.00</div>
                            <div style="font-size:.75rem;color:var(--c-text-soft);margin-top:4px;" id="denomBreakdown">No bills entered</div>
                        </div>

                        <div id="overShortBox" class="over-short-box exact" style="display:none;">
                            <span id="overShortLabel">Over/Short: ₱0.00</span>
                        </div>

                        <div style="margin-top:var(--space-4);margin-bottom:var(--space-4);">
                            <label class="form-label">Notes (optional)</label>
                            <textarea name="notes" class="form-input" rows="2" placeholder="e.g., Morning shift, Drawer #1"></textarea>
                        </div>

                        <div style="display:flex;gap:var(--space-3);">
                            <button type="submit" class="btn btn-primary">✅ Record Remittance</button>
                            <button type="reset" class="btn btn-ghost" onclick="setTimeout(recalc,0)">Clear</button>
                        </div>
                    </form>
                </div>

                <!-- Right: today's remittances -->
                <div class="card card-flat" style="align-self:start;">
                    <h4 style="margin-bottom:var(--space-3);">Today's Remittances</h4>
                    <?php if (empty($remittances)): ?>
                        <p class="text-muted" style="font-size:.85rem;">No remittances recorded today.</p>
                    <?php else: ?>
                        <?php foreach ($remittances as $r): ?>
                        <?php $os = floatval($r['over_short'] ?? 0); ?>
                        <div style="padding:var(--space-3) 0;border-bottom:1px solid var(--c-border);">
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <div>
                                    <div style="font-weight:600;font-size:.9rem;"><?php echo htmlspecialchars($r['cashier_name'] ?? '—'); ?></div>
                                    <div style="color:var(--c-text-soft);font-size:.75rem;"><?php echo date('g:i A', strtotime($r['created_at'])); ?></div>
                                </div>
                                <div style="text-align:right;">
                                    <div style="font-weight:700;"><?php echo formatCurrency($r['actual_cash'] ?? $r['amount'] ?? 0); ?></div>
                                    <?php if (isset($r['over_short'])): ?>
                                    <div style="font-size:.75rem;color:<?php echo $os>0?'var(--c-success)':($os<0?'var(--c-danger)':'var(--c-text-soft)'); ?>">
                                        <?php echo $os >= 0 ? 'Over ₱'.number_format($os,2) : 'Short ₱'.number_format(abs($os),2); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB: CASHIER SUMMARY                                -->
        <!-- ════════════════════════════════════════════════════ -->
        <div id="tab-cashiers" class="tab-pane <?php echo $active_tab==='cashiers'?'active':''; ?>">
            <div class="card card-flat">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4);">
                    <h3 style="margin:0;">👥 Cashier Performance — <?php echo date('F j, Y'); ?></h3>
                </div>
                <?php if (empty($cashier_summary)): ?>
                    <p class="text-muted" style="text-align:center;padding:var(--space-6) 0;">No sales recorded today yet.</p>
                <?php else: ?>
                <div class="overflow-x">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Cashier</th>
                                <th>Transactions</th>
                                <th>Gross Sales</th>
                                <th>Discounts</th>
                                <th>VAT</th>
                                <th>Cash</th>
                                <th>GCash</th>
                                <th>Card</th>
                                <th>Exp. Cash</th>
                                <th>Avg / Txn</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $grand_txn = $grand_sales = 0;
                            foreach ($cashier_summary as $cs):
                                $grand_txn   += $cs['txn_count'];
                                $grand_sales += $cs['total_sales'];
                                $avg = $cs['txn_count'] > 0 ? $cs['total_sales'] / $cs['txn_count'] : 0;
                                $exp = round($cs['cash'] - $cs['change_given'], 2);
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($cs['cashier_name']); ?></strong></td>
                                <td><?php echo number_format($cs['txn_count']); ?></td>
                                <td><strong><?php echo formatCurrency($cs['total_sales']); ?></strong></td>
                                <td style="color:var(--c-warning);"><?php echo formatCurrency($cs['total_discounts']); ?></td>
                                <td><?php echo formatCurrency($cs['total_vat']); ?></td>
                                <td><?php echo formatCurrency($cs['cash']); ?></td>
                                <td><?php echo formatCurrency($cs['gcash']); ?></td>
                                <td><?php echo formatCurrency($cs['card']); ?></td>
                                <td style="font-weight:600;"><?php echo formatCurrency($exp); ?></td>
                                <td class="text-muted"><?php echo formatCurrency($avg); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:var(--c-primary-fade);font-weight:700;">
                                <td>Total</td>
                                <td><?php echo number_format($grand_txn); ?></td>
                                <td><?php echo formatCurrency($grand_sales); ?></td>
                                <td colspan="7"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB: VOID LOG                                       -->
        <!-- ════════════════════════════════════════════════════ -->
        <div id="tab-voids" class="tab-pane <?php echo $active_tab==='voids'?'active':''; ?>">
            <div class="card card-flat">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4);">
                    <h3 style="margin:0;">🚫 Void Log — <?php echo date('F j, Y'); ?></h3>
                    <?php if (!empty($void_log)): ?>
                    <span class="badge" style="background:var(--c-danger-light);color:var(--c-danger);">
                        <?php echo count($void_log); ?> voided · <?php echo formatCurrency($void_summary['amount'] ?? 0); ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php if (empty($void_log)): ?>
                    <div style="text-align:center;padding:var(--space-8) 0;">
                        <div style="font-size:2.5rem;margin-bottom:var(--space-3);">✅</div>
                        <p class="text-muted">No voided transactions today.</p>
                    </div>
                <?php else: ?>
                <div class="overflow-x">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Receipt #</th>
                                <th>Cashier</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Sale Time</th>
                                <th>Voided By</th>
                                <th>Voided At</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($void_log as $v): ?>
                            <tr class="void-row">
                                <td><code><?php echo htmlspecialchars($v['receipt_number'] ?? str_pad($v['id'],6,'0',STR_PAD_LEFT)); ?></code></td>
                                <td><?php echo htmlspecialchars($v['cashier_name']); ?></td>
                                <td><strong><?php echo formatCurrency($v['total_amount']); ?></strong></td>
                                <td><?php $mc=['cash'=>'badge-success','gcash'=>'badge-info','card'=>'badge-warning']; ?>
                                    <span class="badge <?php echo $mc[$v['payment_method']]??'badge-neutral'; ?>"><?php echo ucfirst($v['payment_method']); ?></span></td>
                                <td class="text-muted"><?php echo date('g:i A', strtotime($v['created_at'])); ?></td>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($v['voided_by_name'] ?? '—'); ?></td>
                                <td class="text-muted"><?php echo $v['voided_at'] ? date('g:i A', strtotime($v['voided_at'])) : '—'; ?></td>
                                <td style="color:var(--c-text-soft);font-size:.82rem;"><?php echo htmlspecialchars($v['void_reason'] ?? '—'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB: JOURNAL                                        -->
        <!-- ════════════════════════════════════════════════════ -->
        <div id="tab-journal" class="tab-pane <?php echo $active_tab==='journal'?'active':''; ?>">
            <div class="card card-flat">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--space-3);margin-bottom:var(--space-4);">
                    <h3 style="margin:0;">📒 Journal Entries</h3>
                    <form method="GET" style="display:flex;gap:var(--space-2);align-items:center;">
                        <input type="hidden" name="tab" value="journal">
                        <label style="font-size:.85rem;color:var(--c-text-soft);">Date:</label>
                        <input type="date" name="jdate" value="<?php echo htmlspecialchars($journal_date); ?>" class="form-input" style="width:160px;" onchange="this.form.submit()">
                    </form>
                </div>

                <?php if (empty($journal_grouped)): ?>
                    <p class="text-muted" style="text-align:center;padding:var(--space-6) 0;">No journal entries for <?php echo date('F j, Y', strtotime($journal_date)); ?>.</p>
                <?php else: ?>

                <?php foreach ($journal_grouped as $ref => $group): ?>
                <div class="journal-group">
                    <div class="journal-group-header">
                        <?php
                        $rt = explode('-', $ref)[0];
                        echo '<span class="ref-badge '.htmlspecialchars($rt).'">'.strtoupper($rt).'</span>';
                        ?>
                        <strong><?php echo htmlspecialchars($group['description']); ?></strong>
                        <span style="margin-left:auto;color:var(--c-text-soft);font-size:.78rem;"><?php echo date('g:i A', strtotime($group['time'])); ?></span>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr minmax(100px,auto) minmax(100px,auto);padding:var(--space-2) var(--space-4);font-size:.78rem;font-weight:700;color:var(--c-text-soft);border-top:1px solid var(--c-border);">
                        <span>Account</span><span style="text-align:right;">Debit</span><span style="text-align:right;">Credit</span>
                    </div>
                    <?php foreach ($group['lines'] as $line): ?>
                    <div class="journal-line">
                        <span class="acct"><?php echo htmlspecialchars($line['account_code']); ?> — <?php echo htmlspecialchars($line['account_name']); ?></span>
                        <span class="dr"><?php echo $line['debit'] > 0 ? formatCurrency($line['debit']) : '—'; ?></span>
                        <span class="cr"><?php echo $line['credit'] > 0 ? formatCurrency($line['credit']) : '—'; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <div style="display:flex;justify-content:flex-end;gap:var(--space-6);padding:var(--space-3) var(--space-4);background:var(--c-surface);border-radius:var(--radius-md);font-weight:700;font-size:.9rem;">
                    <span>Total Debits: <span style="color:#16a34a;"><?php echo formatCurrency($journal_totals['total_debit'] ?? 0); ?></span></span>
                    <span>Total Credits: <span style="color:var(--c-primary);"><?php echo formatCurrency($journal_totals['total_credit'] ?? 0); ?></span></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB: LEDGER                                         -->
        <!-- ════════════════════════════════════════════════════ -->
        <div id="tab-ledger" class="tab-pane <?php echo $active_tab==='ledger'?'active':''; ?>">
            <div class="card card-flat">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4);">
                    <h3 style="margin:0;">📈 General Ledger — Account Balances</h3>
                    <span style="font-size:.82rem;color:var(--c-text-soft);">Live running totals from journal entries</span>
                </div>

                <?php if (empty($ledger_accounts_data)): ?>
                    <p class="text-muted" style="text-align:center;padding:var(--space-6) 0;">No ledger accounts found. Run migration_v4.sql to seed accounts.</p>
                <?php else: ?>

                <?php
                $type_groups = ['asset'=>'Assets', 'liability'=>'Liabilities', 'equity'=>'Equity', 'revenue'=>'Revenue', 'expense'=>'Expenses'];
                $grouped = [];
                foreach ($ledger_accounts_data as $acct) {
                    $grouped[$acct['account_type']][] = $acct;
                }
                foreach ($type_groups as $type => $label):
                    if (empty($grouped[$type])) continue;
                ?>
                <div class="section-divider"><hr><span><?php echo $label; ?></span><hr></div>
                <?php foreach ($grouped[$type] as $acct): ?>
                <div class="ledger-card">
                    <div>
                        <div class="l-code"><?php echo htmlspecialchars($acct['account_code']); ?></div>
                        <div class="l-name"><?php echo htmlspecialchars($acct['account_name']); ?></div>
                        <div style="font-size:.75rem;color:var(--c-text-soft);margin-top:2px;"><?php echo number_format($acct['entry_count']); ?> journal entries</div>
                    </div>
                    <div style="display:flex;align-items:center;gap:var(--space-4);">
                        <span class="l-type <?php echo htmlspecialchars($acct['account_type']); ?>"><?php echo ucfirst($acct['account_type']); ?></span>
                        <div class="ledger-balance" style="color:<?php echo $acct['balance'] >= 0 ? 'var(--c-primary)' : 'var(--c-danger)'; ?>">
                            <?php echo formatCurrency(abs($acct['balance'])); ?>
                            <?php if ($acct['balance'] < 0): ?><span style="font-size:.7rem;font-weight:500;"> (CR)</span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB: REFUNDS                                        -->
        <!-- ════════════════════════════════════════════════════ -->
        <div id="tab-refunds" class="tab-pane <?php echo $active_tab==='refunds'?'active':''; ?>">
            <div style="display:grid;grid-template-columns:1fr 400px;gap:var(--space-5);">

                <!-- Left: process refund -->
                <div class="card card-flat">
                    <h3 style="margin-bottom:var(--space-2);">↩️ Process Refund</h3>
                    <p class="text-muted" style="font-size:.85rem;margin-bottom:var(--space-4);">
                        Enter a receipt number to look up the sale. Select items to refund and enter a reason.
                    </p>

                    <!-- Receipt lookup -->
                    <div style="display:flex;gap:var(--space-3);margin-bottom:var(--space-4);">
                        <input type="text" id="refundReceiptInput" class="form-input" placeholder="e.g. JJ-000042" style="flex:1;font-family:monospace;">
                        <button class="btn btn-primary" onclick="lookupReceipt()">Look Up</button>
                    </div>
                    <div id="refundLookupMsg" style="display:none;"></div>

                    <!-- Sale details (shown after lookup) -->
                    <div id="refundSaleDetails" style="display:none;">
                        <div class="section-divider"><hr><span>Sale Details</span><hr></div>
                        <div id="refundSaleInfo" style="margin-bottom:var(--space-4);font-size:.88rem;"></div>

                        <div class="section-divider"><hr><span>Items</span><hr></div>
                        <div id="refundItemsList" style="margin-bottom:var(--space-4);"></div>

                        <div style="margin-bottom:var(--space-4);">
                            <label class="form-label">Refund Amount (₱)</label>
                            <input type="number" id="refundAmountInput" class="form-input" step="0.01" min="0.01" style="width:200px;">
                            <div style="font-size:.75rem;color:var(--c-text-soft);margin-top:4px;" id="refundMaxInfo"></div>
                        </div>

                        <div style="margin-bottom:var(--space-4);">
                            <label class="form-label">Reason <span style="color:var(--c-danger);">*</span></label>
                            <textarea id="refundReasonInput" class="form-input" rows="2" placeholder="Required — explain the reason for refund"></textarea>
                        </div>

                        <button class="btn btn-primary" onclick="submitRefund()" style="background:var(--c-warning);border-color:var(--c-warning);">
                            ↩️ Process Refund
                        </button>
                    </div>
                </div>

                <!-- Right: recent refunds -->
                <div class="card card-flat" style="align-self:start;">
                    <h4 style="margin-bottom:var(--space-3);">Recent Refunds</h4>
                    <?php if (empty($recent_refunds)): ?>
                        <p class="text-muted" style="font-size:.85rem;">No refunds recorded yet.</p>
                    <?php else: ?>
                    <div class="overflow-x" style="max-height:500px;overflow-y:auto;">
                        <?php foreach ($recent_refunds as $rf): ?>
                        <div style="padding:var(--space-3) 0;border-bottom:1px solid var(--c-border);">
                            <div style="display:flex;justify-content:space-between;">
                                <div>
                                    <div style="font-family:monospace;font-size:.85rem;font-weight:600;"><?php echo htmlspecialchars($rf['receipt_number'] ?? '—'); ?></div>
                                    <div style="font-size:.75rem;color:var(--c-text-soft);"><?php echo date('M j, g:i A', strtotime($rf['created_at'])); ?></div>
                                    <div style="font-size:.78rem;color:var(--c-text-soft);margin-top:2px;"><?php echo htmlspecialchars($rf['processed_by_name'] ?? '—'); ?></div>
                                </div>
                                <div style="text-align:right;">
                                    <div style="font-weight:700;color:var(--c-danger);">-<?php echo formatCurrency($rf['refund_amount']); ?></div>
                                    <div style="font-size:.75rem;color:var(--c-text-soft);" title="<?php echo htmlspecialchars($rf['reason']); ?>">
                                        <?php echo htmlspecialchars(mb_substr($rf['reason'], 0, 30) . (mb_strlen($rf['reason']) > 30 ? '…' : '')); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB: AUDIT TRAIL                                    -->
        <!-- ════════════════════════════════════════════════════ -->
        <div id="tab-audit" class="tab-pane <?php echo $active_tab==='audit'?'active':''; ?>">
            <div class="card card-flat">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--space-3);margin-bottom:var(--space-4);">
                    <h3 style="margin:0;">🔍 Audit Trail</h3>
                    <div style="display:flex;gap:var(--space-2);align-items:center;">
                        <label style="font-size:.85rem;color:var(--c-text-soft);">Severity:</label>
                        <a href="?tab=audit" class="btn btn-ghost btn-sm <?php echo !$audit_severity?'btn-primary':''; ?>" style="font-size:.8rem;">All</a>
                        <a href="?tab=audit&severity=info"     class="btn btn-ghost btn-sm" style="font-size:.8rem;">Info</a>
                        <a href="?tab=audit&severity=warning"  class="btn btn-ghost btn-sm" style="font-size:.8rem;">Warning</a>
                        <a href="?tab=audit&severity=critical" class="btn btn-ghost btn-sm" style="font-size:.8rem;">Critical</a>
                    </div>
                </div>
                <p class="text-muted" style="font-size:.82rem;margin-bottom:var(--space-4);">
                    Immutable system-wide action log. Entries cannot be edited or deleted. Showing last <?php echo $audit_limit; ?> entries.
                </p>

                <?php if (empty($audit_log)): ?>
                    <p class="text-muted" style="text-align:center;padding:var(--space-6) 0;">No audit log entries found.</p>
                <?php else: ?>
                <div id="auditSearch" style="margin-bottom:var(--space-3);">
                    <input type="text" class="form-input" placeholder="Search audit log..." oninput="filterAudit(this.value)" style="max-width:360px;">
                </div>
                <div class="overflow-x">
                    <table class="data-table" id="auditTable">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>User</th>
                                <th>Severity</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($audit_log as $al): ?>
                            <tr>
                                <td class="text-muted" style="white-space:nowrap;font-size:.8rem;"><?php echo date('M j, g:i A', strtotime($al['created_at'])); ?></td>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($al['user_name'] ?? 'System'); ?></td>
                                <td>
                                    <?php $sev = $al['severity'] ?? 'info'; ?>
                                    <span class="sev-<?php echo $sev; ?>"><?php echo strtoupper($sev); ?></span>
                                </td>
                                <td style="font-family:monospace;font-size:.82rem;"><?php echo htmlspecialchars($al['action']); ?></td>
                                <td style="font-size:.82rem;max-width:300px;"><?php echo htmlspecialchars($al['details'] ?? ''); ?></td>
                                <td class="text-muted" style="font-size:.78rem;font-family:monospace;"><?php echo htmlspecialchars($al['ip_address'] ?? '—'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB: READ HISTORY                                   -->
        <!-- ════════════════════════════════════════════════════ -->
        <div id="tab-history" class="tab-pane <?php echo $active_tab==='history'?'active':''; ?>">
            <div class="card card-flat">
                <h3 style="margin-bottom:var(--space-4);">🗂️ X-Read / Z-Read History</h3>
                <?php if (empty($past_reads)): ?>
                    <p class="text-muted" style="text-align:center;padding:var(--space-6) 0;">No register reads recorded yet.</p>
                <?php else: ?>
                <div class="overflow-x">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Date</th>
                                <th>Transactions</th>
                                <th>Gross Sales</th>
                                <th>Discounts</th>
                                <th>VAT</th>
                                <th>Cash</th>
                                <th>GCash</th>
                                <th>Card</th>
                                <th>Voids</th>
                                <th>Generated By</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($past_reads as $rr): ?>
                            <tr>
                                <td><?php echo $rr['read_type']==='z_read'?'<span class="read-badge-z">Z-Read</span>':'<span class="read-badge-x">X-Read</span>'; ?></td>
                                <td><?php echo date('M j, Y', strtotime($rr['read_date'])); ?></td>
                                <td><?php echo number_format($rr['total_transactions']); ?></td>
                                <td><strong><?php echo formatCurrency($rr['total_gross']); ?></strong></td>
                                <td><?php echo isset($rr['total_discounts']) ? formatCurrency($rr['total_discounts']) : '—'; ?></td>
                                <td><?php echo formatCurrency($rr['total_vat']); ?></td>
                                <td><?php echo formatCurrency($rr['cash_sales']); ?></td>
                                <td><?php echo formatCurrency($rr['gcash_sales']); ?></td>
                                <td><?php echo formatCurrency($rr['card_sales']); ?></td>
                                <td><?php echo ($rr['void_count']??0) > 0 ? '<span style="color:var(--c-danger);">'.$rr['void_count'].'</span>' : '0'; ?></td>
                                <td><?php echo htmlspecialchars($rr['generated_by_name'] ?? '—'); ?></td>
                                <td class="text-muted"><?php echo date('g:i A', strtotime($rr['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /dashboard-container -->

    <script src="<?php echo JS_URL; ?>/main.js"></script>
    <script>
    // ── Tab switching ─────────────────────────────────────────
    function switchTab(name, btn) {
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('tab-' + name).classList.add('active');
        btn.classList.add('active');
        history.replaceState(null, '', '?tab=' + name);
    }

    // ── Denomination calculator (Remittance) ──────────────────
    const DENOMS = [1000, 500, 200, 100, 50, 20];
    const cashierExpected = <?php echo json_encode($cashier_expected_map); ?>;
    let expectedCash = 0;

    function fmt(n) {
        return '₱' + n.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    }

    function updateExpected(cashierId) {
        const data = cashierExpected[cashierId];
        const display = document.getElementById('expectedCashDisplay');
        const input   = document.getElementById('expectedCashInput');
        if (data) {
            expectedCash = data.expected;
            display.textContent = fmt(data.expected);
            display.style.color = 'var(--c-text)';
        } else {
            expectedCash = 0;
            display.textContent = '—';
            display.style.color = 'var(--c-text-soft)';
        }
        if (input) input.value = expectedCash.toFixed(2);
        recalc();
    }

    function recalc() {
        let total = 0, parts = [];
        DENOMS.forEach(d => {
            const qty = parseInt(document.getElementById('b' + d)?.value) || 0;
            const sub = qty * d;
            total += sub;
            const el = document.getElementById('sub' + d);
            if (el) el.textContent = fmt(sub);
            if (qty > 0) parts.push(qty + ' × ₱' + d.toLocaleString());
        });
        const coins = parseFloat(document.getElementById('coins')?.value) || 0;
        total += coins;
        const subcoins = document.getElementById('subcoins');
        if (subcoins) subcoins.textContent = fmt(coins);
        if (coins > 0) parts.push('₱' + coins.toFixed(2) + ' coins');

        const d2  = document.getElementById('actualDisplay');
        const bd  = document.getElementById('denomBreakdown');
        if (d2) d2.textContent = fmt(total);
        if (bd) bd.textContent = parts.length ? parts.join(' + ') : 'No bills entered';

        // Over/Short
        const osBox   = document.getElementById('overShortBox');
        const osLabel = document.getElementById('overShortLabel');
        if (osBox && osLabel) {
            const os = total - expectedCash;
            osBox.style.display = 'block';
            osBox.className = 'over-short-box ' + (os > 0 ? 'over' : (os < 0 ? 'short' : 'exact'));
            const sign = os > 0 ? 'OVER' : (os < 0 ? 'SHORT' : 'EXACT');
            osLabel.textContent = sign + (os !== 0 ? ': ' + fmt(Math.abs(os)) : ' — Cash matches!');
        }
    }
    recalc();

    // ── Payment pie chart (X-Read) ────────────────────────────
    (function(){
        const cash  = <?php echo floatval($sales_summary['cash_sales']  ?? 0); ?>;
        const gcash = <?php echo floatval($sales_summary['gcash_sales'] ?? 0); ?>;
        const card  = <?php echo floatval($sales_summary['card_sales']  ?? 0); ?>;
        const ctx = document.getElementById('xreadChart');
        if (!ctx || (cash + gcash + card) === 0) {
            if (ctx) ctx.parentElement.innerHTML = '<p class="text-muted" style="text-align:center;padding:20px 0;">No sales today.</p>';
            return;
        }
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const textC  = isDark ? '#9CA3AF' : '#718096';
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Cash', 'GCash', 'Card'],
                datasets: [{
                    data: [cash, gcash, card],
                    backgroundColor: ['#4CAF50','#2196F3','#FF9800'],
                    borderWidth: 2,
                    borderColor: isDark ? '#1F2937' : '#fff'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position:'bottom', labels:{ color:textC, font:{size:11} } },
                    tooltip: { callbacks: { label: c => c.label + ': ₱' + c.raw.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',') } }
                }
            }
        });
    })();

    // ── Audit trail search ────────────────────────────────────
    function filterAudit(q) {
        const rows = document.querySelectorAll('#auditTable tbody tr');
        q = q.toLowerCase();
        rows.forEach(r => {
            r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    }

    // ── Refund functionality ──────────────────────────────────
    const CSRF_TOKEN = '<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES); ?>';
    let currentSale = null;

    function lookupReceipt() {
        const receipt = document.getElementById('refundReceiptInput').value.trim();
        if (!receipt) { showMsg('error', 'Enter a receipt number.'); return; }

        const btn = document.querySelector('#tab-refunds .btn-primary');
        btn.disabled = true;
        btn.textContent = 'Looking up…';

        fetch('<?php echo BASE_URL; ?>/api/refunds.php?receipt=' + encodeURIComponent(receipt))
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.textContent = 'Look Up';
                if (data.error) { showMsg('error', data.error); hideDetails(); return; }
                showSaleDetails(data.sale);
            })
            .catch(() => { btn.disabled = false; btn.textContent = 'Look Up'; showMsg('error', 'Network error. Try again.'); });
    }

    function showMsg(type, msg) {
        const el = document.getElementById('refundLookupMsg');
        el.style.display = 'block';
        el.className = 'alert alert-' + (type === 'error' ? 'danger' : 'success');
        el.textContent = msg;
    }

    function hideDetails() {
        document.getElementById('refundSaleDetails').style.display = 'none';
        currentSale = null;
    }

    function showSaleDetails(sale) {
        currentSale = sale;
        document.getElementById('refundLookupMsg').style.display = 'none';
        document.getElementById('refundSaleDetails').style.display = 'block';

        // Sale info
        document.getElementById('refundSaleInfo').innerHTML =
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">' +
            '<div><span style="color:var(--c-text-soft);">Receipt:</span> <strong style="font-family:monospace;">' + esc(sale.receipt_number || '#'+sale.id) + '</strong></div>' +
            '<div><span style="color:var(--c-text-soft);">Date:</span> ' + esc(sale.created_at) + '</div>' +
            '<div><span style="color:var(--c-text-soft);">Cashier:</span> ' + esc(sale.cashier_name || '—') + '</div>' +
            '<div><span style="color:var(--c-text-soft);">Payment:</span> ' + esc(sale.payment_method) + '</div>' +
            '<div><span style="color:var(--c-text-soft);">Total:</span> <strong>₱' + parseFloat(sale.total_amount).toFixed(2) + '</strong></div>' +
            '<div><span style="color:var(--c-text-soft);">Refundable:</span> <strong style="color:var(--c-success);">₱' + parseFloat(sale.refundable).toFixed(2) + '</strong></div>' +
            (sale.customer_name ? '<div><span style="color:var(--c-text-soft);">Customer:</span> ' + esc(sale.customer_name) + '</div>' : '') +
            '</div>';

        // Items
        let itemsHtml = '';
        (sale.items || []).forEach(item => {
            itemsHtml += '<div class="refund-item-row">' +
                '<div style="flex:1;">' +
                  '<div style="font-weight:600;">' + esc(item.product_name || item.product_id) + '</div>' +
                  '<div style="font-size:.78rem;color:var(--c-text-soft);">' + item.quantity + ' × ₱' + parseFloat(item.unit_price||item.price||0).toFixed(2) + '</div>' +
                '</div>' +
                '<strong>₱' + parseFloat(item.subtotal||item.total||0).toFixed(2) + '</strong>' +
            '</div>';
        });
        document.getElementById('refundItemsList').innerHTML = itemsHtml || '<p class="text-muted">No items.</p>';

        const maxRefund = parseFloat(sale.refundable);
        document.getElementById('refundAmountInput').value = maxRefund.toFixed(2);
        document.getElementById('refundAmountInput').max   = maxRefund;
        document.getElementById('refundMaxInfo').textContent = 'Maximum refundable: ₱' + maxRefund.toFixed(2);
    }

    function submitRefund() {
        if (!currentSale) { showMsg('error', 'No sale loaded.'); return; }
        const reason = document.getElementById('refundReasonInput').value.trim();
        const amount = parseFloat(document.getElementById('refundAmountInput').value);
        if (!reason) { showMsg('error', 'Reason is required.'); return; }
        if (isNaN(amount) || amount <= 0) { showMsg('error', 'Enter a valid refund amount.'); return; }
        if (!confirm('Process refund of ₱' + amount.toFixed(2) + ' on ' + (currentSale.receipt_number || '#'+currentSale.id) + '?\n\nReason: ' + reason + '\n\nThis cannot be undone.')) return;

        const btn = document.querySelector('#tab-refunds .btn[onclick="submitRefund()"]');
        if (btn) { btn.disabled = true; btn.textContent = 'Processing…'; }

        const fd = new FormData();
        fd.append('action', 'process_refund');
        fd.append('sale_id', currentSale.id);
        fd.append('reason', reason);
        fd.append('refund_amount', amount.toFixed(2));
        fd.append('csrf_token', CSRF_TOKEN);

        fetch('<?php echo BASE_URL; ?>/api/refunds.php', { method:'POST', body:fd })
            .then(r => r.json())
            .then(data => {
                if (btn) { btn.disabled = false; btn.textContent = '↩️ Process Refund'; }
                if (data.error) { showMsg('error', data.error); return; }
                showMsg('success', data.message);
                hideDetails();
                document.getElementById('refundReceiptInput').value = '';
                document.getElementById('refundReasonInput').value  = '';
                // Reload page after 1.5s to refresh recent refunds list
                setTimeout(() => location.href = '?tab=refunds', 1500);
            })
            .catch(() => {
                if (btn) { btn.disabled = false; btn.textContent = '↩️ Process Refund'; }
                showMsg('error', 'Network error. Try again.');
            });
    }

    function esc(str) {
        if (str == null) return '—';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // Enter key on receipt input
    document.getElementById('refundReceiptInput')?.addEventListener('keydown', e => {
        if (e.key === 'Enter') lookupReceipt();
    });
    </script>
</body>
</html>
