<?php
/**
 * J&J Grocery POS — Manager Portal v3
 * X-Read, Z-Read, Cash Remittal, Cashier Summary, Void Log
 * Requires manager or admin role.
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn()) redirect(BASE_URL . '/index.php');
checkSessionTimeout();
if (!hasRole('manager') && !hasRole('admin')) redirect(BASE_URL . '/pages/dashboard.php');

$user  = getCurrentUser();
$today = date('Y-m-d');
$now   = date('Y-m-d H:i:s');

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // ── Record Cash Remittal ──────────────────────────────────
    if ($action === 'process_remittal') {
        $cashier_id = intval($_POST['cashier_id'] ?? 0);
        $amount     = floatval($_POST['amount'] ?? 0);
        $notes      = trim($_POST['notes'] ?? '');
        $b1000 = intval($_POST['b1000'] ?? 0);
        $b500  = intval($_POST['b500']  ?? 0);
        $b200  = intval($_POST['b200']  ?? 0);
        $b100  = intval($_POST['b100']  ?? 0);
        $b50   = intval($_POST['b50']   ?? 0);
        $b20   = intval($_POST['b20']   ?? 0);
        $coins = floatval($_POST['coins'] ?? 0);

        if ($cashier_id && $amount > 0) {
            $ok = $db->execute(
                "INSERT INTO cash_remittals
                    (cashier_id, manager_id, amount, notes, status, created_at)
                 VALUES (?, ?, ?, ?, 'completed', ?)",
                [$cashier_id, $user['id'], $amount, $notes, $now]
            );
            if ($ok) {
                logActivity($db, 'cash_remittal',
                    "Recorded ₱" . number_format($amount, 2) . " remittal from cashier #$cashier_id",
                    $cashier_id);
                redirectWithMessage(BASE_URL . '/pages/manager.php?tab=remittal',
                    "Cash remittal of ₱" . number_format($amount, 2) . " recorded successfully.", 'success');
            } else {
                redirectWithMessage(BASE_URL . '/pages/manager.php?tab=remittal',
                    "Failed to record remittal.", 'error');
            }
        } else {
            redirectWithMessage(BASE_URL . '/pages/manager.php?tab=remittal',
                "Please select a cashier and enter a valid amount.", 'error');
        }
    }

    // ── Generate X-Read (snapshot, no reset) ─────────────────
    if ($action === 'xread' || $action === 'zread') {
        $read_type = $action === 'zread' ? 'z_read' : 'x_read';

        // Check if z-read already done today
        if ($read_type === 'z_read') {
            try {
                $existing = $db->fetchOne(
                    "SELECT id FROM register_reads WHERE read_type = 'z_read' AND read_date = ?",
                    [$today]
                );
            } catch (Exception $e) {
                $existing = null;
            }
            if ($existing) {
                redirectWithMessage(BASE_URL . '/pages/manager.php?tab=zread',
                    "A Z-Read has already been generated for today ($today). Only one Z-Read is allowed per day.", 'warning');
            }
        }

        // Aggregate today's sales
        $totals = $db->fetchOne(
            "SELECT
                COUNT(*)                                 AS total_transactions,
                COALESCE(SUM(total_amount),  0)          AS total_gross,
                COALESCE(SUM(tax_amount),    0)          AS total_vat,
                COALESCE(SUM(discount_amount),0)         AS total_discounts,
                COALESCE(SUM(subtotal),      0)          AS total_net,
                COALESCE(SUM(CASE WHEN payment_method='cash'  THEN total_amount ELSE 0 END),0) AS cash_sales,
                COALESCE(SUM(CASE WHEN payment_method='gcash' THEN total_amount ELSE 0 END),0) AS gcash_sales,
                COALESCE(SUM(CASE WHEN payment_method='card'  THEN total_amount ELSE 0 END),0) AS card_sales,
                MIN(created_at) AS period_start,
                MAX(created_at) AS period_end
             FROM sales WHERE DATE(created_at) = ? AND voided = 0",
            [$today]
        );

        // Void summary
        $voids = $db->fetchOne(
            "SELECT COUNT(*) AS void_count, COALESCE(SUM(total_amount),0) AS void_amount
             FROM sales WHERE DATE(created_at) = ? AND voided = 1",
            [$today]
        );

        $ok = $db->execute(
            "INSERT INTO register_reads
                (read_type, register_no, generated_by, read_date, period_start, period_end,
                 total_transactions, total_gross, total_vat, total_discounts, total_net,
                 cash_sales, gcash_sales, card_sales, void_count, void_amount, created_at)
             VALUES (?, 'REG-01', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $read_type,
                $user['id'],
                $today,
                $totals['period_start'] ?? $now,
                $totals['period_end']   ?? $now,
                $totals['total_transactions'] ?? 0,
                $totals['total_gross']        ?? 0,
                $totals['total_vat']          ?? 0,
                $totals['total_discounts']    ?? 0,
                $totals['total_net']          ?? 0,
                $totals['cash_sales']         ?? 0,
                $totals['gcash_sales']        ?? 0,
                $totals['card_sales']         ?? 0,
                $voids['void_count']   ?? 0,
                $voids['void_amount']  ?? 0,
                $now
            ]
        );

        $label = $read_type === 'z_read' ? 'Z-Read' : 'X-Read';
        $tab   = $read_type === 'z_read' ? 'zread'  : 'xread';
        if ($ok) {
            logActivity($db, strtolower($label), "$label generated for $today", null);
            redirectWithMessage(BASE_URL . "/pages/manager.php?tab=$tab",
                "$label generated successfully for $today.", 'success');
        } else {
            redirectWithMessage(BASE_URL . "/pages/manager.php?tab=$tab",
                "Failed to save $label record.", 'error');
        }
    }
}

// ── Active tab ────────────────────────────────────────────────
$active_tab = $_GET['tab'] ?? 'xread';

// ── Stats ─────────────────────────────────────────────────────
// Graceful: voided column may not exist (pre-migration)
try {
    $sales_summary = $db->fetchOne(
        "SELECT COUNT(*) AS cnt,
                COALESCE(SUM(total_amount), 0) AS gross,
                COALESCE(SUM(tax_amount),   0) AS vat,
                COALESCE(SUM(CASE WHEN payment_method='cash'  THEN total_amount ELSE 0 END),0) AS cash_sales,
                COALESCE(SUM(CASE WHEN payment_method='gcash' THEN total_amount ELSE 0 END),0) AS gcash_sales,
                COALESCE(SUM(CASE WHEN payment_method='card'  THEN total_amount ELSE 0 END),0) AS card_sales
         FROM sales WHERE DATE(created_at) = ? AND voided = 0",
        [$today]
    );
    $void_summary = $db->fetchOne(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS amount
         FROM sales WHERE DATE(created_at) = ? AND voided = 1",
        [$today]
    );
} catch (Exception $e) {
    // voided column missing
    $sales_summary = $db->fetchOne(
        "SELECT COUNT(*) AS cnt,
                COALESCE(SUM(total_amount), 0) AS gross,
                COALESCE(SUM(tax_amount),   0) AS vat,
                COALESCE(SUM(CASE WHEN payment_method='cash'  THEN total_amount ELSE 0 END),0) AS cash_sales,
                COALESCE(SUM(CASE WHEN payment_method='gcash' THEN total_amount ELSE 0 END),0) AS gcash_sales,
                COALESCE(SUM(CASE WHEN payment_method='card'  THEN total_amount ELSE 0 END),0) AS card_sales
         FROM sales WHERE DATE(created_at) = ?",
        [$today]
    );
    $void_summary = ['cnt' => 0, 'amount' => 0];
}

// ── Cashier summary ───────────────────────────────────────────
try {
    $cashier_summary = $db->fetchAll(
        "SELECT u.name AS cashier_name,
                COUNT(s.id)               AS txn_count,
                COALESCE(SUM(s.total_amount), 0) AS total_sales,
                COALESCE(SUM(s.tax_amount),   0) AS total_vat,
                COALESCE(SUM(CASE WHEN s.payment_method='cash'  THEN s.total_amount ELSE 0 END),0) AS cash,
                COALESCE(SUM(CASE WHEN s.payment_method='gcash' THEN s.total_amount ELSE 0 END),0) AS gcash,
                COALESCE(SUM(CASE WHEN s.payment_method='card'  THEN s.total_amount ELSE 0 END),0) AS card
         FROM sales s
         JOIN users u ON s.cashier_id = u.id
         WHERE DATE(s.created_at) = ? AND s.voided = 0
         GROUP BY s.cashier_id, u.name
         ORDER BY total_sales DESC",
        [$today]
    );
} catch (Exception $e) {
    $cashier_summary = $db->fetchAll(
        "SELECT u.name AS cashier_name,
                COUNT(s.id) AS txn_count,
                COALESCE(SUM(s.total_amount), 0) AS total_sales,
                COALESCE(SUM(s.tax_amount),   0) AS total_vat,
                0 AS cash, 0 AS gcash, 0 AS card
         FROM sales s JOIN users u ON s.cashier_id = u.id
         WHERE DATE(s.created_at) = ?
         GROUP BY s.cashier_id, u.name ORDER BY total_sales DESC",
        [$today]
    );
}

// ── Void log ──────────────────────────────────────────────────
try {
    $void_log = $db->fetchAll(
        "SELECT s.id, s.total_amount, s.payment_method, s.created_at, s.voided_at,
                s.void_reason,
                uc.name AS cashier_name,
                uv.name AS voided_by_name
         FROM sales s
         JOIN users uc ON s.cashier_id = uc.id
         LEFT JOIN users uv ON s.voided_by = uv.id
         WHERE DATE(s.created_at) = ? AND s.voided = 1
         ORDER BY s.voided_at DESC",
        [$today]
    );
} catch (Exception $e) {
    $void_log = [];
}

// ── Past register reads ───────────────────────────────────────
try {
    $past_reads = $db->fetchAll(
        "SELECT rr.*, u.name AS generated_by_name
         FROM register_reads rr
         LEFT JOIN users u ON rr.generated_by = u.id
         ORDER BY rr.created_at DESC LIMIT 30"
    );
} catch (Exception $e) {
    $past_reads = [];
}

// ── Cashiers list (for remittal form) ─────────────────────────
$cashiers = $db->fetchAll(
    "SELECT id, name, username FROM users WHERE (role='cashier' OR role='admin') AND active=1 ORDER BY name"
);

// ── Remittal history ──────────────────────────────────────────
try {
    $remittals = $db->fetchAll(
        "SELECT cr.*, u.name AS cashier_name, m.name AS manager_name
         FROM cash_remittals cr
         LEFT JOIN users u ON cr.cashier_id = u.id
         LEFT JOIN users m ON cr.manager_id = m.id
         WHERE DATE(cr.created_at) = ?
         ORDER BY cr.created_at DESC",
        [$today]
    );
} catch (Exception $e) {
    $remittals = [];
}
$remittal_total = array_sum(array_column($remittals, 'amount'));

// ── Z-Read check for today ────────────────────────────────────
try {
    $zread_done_today = $db->fetchOne(
        "SELECT id, created_at FROM register_reads WHERE read_type='z_read' AND read_date=?",
        [$today]
    );
} catch (Exception $e) {
    $zread_done_today = null;
}
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
    /* ── Manager-specific styles ────────────────────────── */
    .read-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: var(--space-4);
        margin-bottom: var(--space-5);
    }
    .read-row { display: flex; justify-content: space-between; align-items: center; padding: var(--space-2) 0; border-bottom: 1px solid var(--c-border); font-size: .9rem; }
    .read-row:last-child { border-bottom: none; font-weight: 700; font-size: 1rem; }
    .read-row .label { color: var(--c-text-soft); }
    .read-row .value { font-weight: 600; color: var(--c-text); }
    .read-row.total .value { color: var(--c-primary); font-size: 1.1rem; }
    .denomination-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: var(--space-3); }
    .denom-item { display: flex; flex-direction: column; gap: 4px; }
    .denom-item label { font-size: .78rem; font-weight: 600; color: var(--c-text-soft); }
    .denom-item input { text-align: center; font-size: 1rem; font-weight: 700; padding: var(--space-2) var(--space-3); background: var(--c-bg); border: 1.5px solid var(--c-border); border-radius: var(--radius-md); color: var(--c-text); width: 100%; }
    .denom-item input:focus { border-color: var(--c-primary); outline: none; }
    .denom-subtotal { font-size: .75rem; color: var(--c-text-soft); text-align: center; }
    .cash-total-box { background: var(--c-primary-fade); border: 1.5px solid rgba(211,47,47,.3); border-radius: var(--radius-lg); padding: var(--space-4) var(--space-5); margin-top: var(--space-4); }
    .cash-total-box .amount { font-size: 2rem; font-weight: 800; color: var(--c-primary); }
    .zread-done-banner { background: var(--c-success-light); border: 1px solid var(--c-success); border-radius: var(--radius-lg); padding: var(--space-4) var(--space-5); margin-bottom: var(--space-5); display: flex; align-items: center; gap: var(--space-3); }
    .variance-positive { color: var(--c-success) !important; }
    .variance-negative { color: var(--c-danger) !important; }
    .tab-nav { display: flex; gap: 4px; border-bottom: 2px solid var(--c-border); margin-bottom: var(--space-5); flex-wrap: wrap; }
    .tab-nav .tab-btn { padding: var(--space-3) var(--space-4); background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-size: .88rem; font-weight: 600; color: var(--c-text-soft); font-family: var(--font-sans); transition: var(--transition); margin-bottom: -2px; }
    .tab-nav .tab-btn:hover { color: var(--c-text); background: rgba(211,47,47,.06); border-radius: var(--radius-md) var(--radius-md) 0 0; }
    .tab-nav .tab-btn.active { color: var(--c-primary); border-bottom-color: var(--c-primary); }
    .tab-pane { display: none; }
    .tab-pane.active { display: block; }
    .read-history-card { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3) var(--space-4); border: 1px solid var(--c-border); border-radius: var(--radius-md); margin-bottom: var(--space-3); background: var(--c-surface); cursor: pointer; transition: var(--transition); }
    .read-history-card:hover { border-color: var(--c-primary); }
    .read-badge-x { background: #e3f2fd; color: #1565c0; padding: 3px 10px; border-radius: 20px; font-size: .75rem; font-weight: 700; }
    .read-badge-z { background: #fce4ec; color: #c62828; padding: 3px 10px; border-radius: 20px; font-size: .75rem; font-weight: 700; }
    [data-theme="dark"] .read-badge-x { background: #0c1a3a; color: #93c5fd; }
    [data-theme="dark"] .read-badge-z { background: #450a0a; color: #fca5a5; }
    [data-theme="dark"] .denom-item input { background: #111827; }
    .void-row td { color: var(--c-danger) !important; }
    .section-divider { display: flex; align-items: center; gap: var(--space-3); margin: var(--space-5) 0 var(--space-4); }
    .section-divider hr { flex: 1; border: none; border-top: 1px solid var(--c-border); }
    .section-divider span { font-size: .8rem; font-weight: 700; color: var(--c-text-soft); text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; }
    @media(max-width:768px){
        .read-summary { grid-template-columns: 1fr 1fr; }
        .denomination-grid { grid-template-columns: repeat(3, 1fr); }
    }
    </style>
</head>
<body class="dashboard-page">
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard-container">

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>💼 Manager Portal</h1>
                <p class="text-muted"><?php echo date('l, F j, Y'); ?> · Register REG-01</p>
            </div>
            <div style="display:flex;gap:var(--space-3);align-items:center;">
                <?php if ($zread_done_today): ?>
                <span class="badge" style="background:#fce4ec;color:#c62828;font-size:.8rem;padding:6px 14px;">
                    ✓ Z-Read done <?php echo date('g:i A', strtotime($zread_done_today['created_at'])); ?>
                </span>
                <?php endif; ?>
                <span style="font-size:.85rem;color:var(--c-text-soft);"><?php echo htmlspecialchars($user['name']); ?></span>
            </div>
        </div>

        <?php displayMessage(); ?>

        <!-- Quick stats row -->
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
                    <div class="stat-label">Transactions Today</div>
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
                    <div class="stat-value"><?php echo formatCurrency($sales_summary['cash_sales'] ?? 0); ?></div>
                    <div class="stat-label">Cash Sales</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📱</div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo formatCurrency($sales_summary['gcash_sales'] ?? 0); ?></div>
                    <div class="stat-label">GCash Sales</div>
                </div>
            </div>
            <?php if ($void_summary['cnt'] > 0): ?>
            <div class="stat-card" style="border-left-color:var(--c-danger);">
                <div class="stat-icon">🚫</div>
                <div class="stat-info">
                    <div class="stat-value" style="color:var(--c-danger);"><?php echo (int)$void_summary['cnt']; ?></div>
                    <div class="stat-label">Voided Transactions</div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tabs -->
        <div class="tab-nav">
            <button class="tab-btn <?php echo $active_tab==='xread'?'active':''; ?>" onclick="switchTab('xread',this)">📊 X-Read</button>
            <button class="tab-btn <?php echo $active_tab==='zread'?'active':''; ?>" onclick="switchTab('zread',this)">📋 Z-Read</button>
            <button class="tab-btn <?php echo $active_tab==='remittal'?'active':''; ?>" onclick="switchTab('remittal',this)">💰 Cash Remittal</button>
            <button class="tab-btn <?php echo $active_tab==='cashiers'?'active':''; ?>" onclick="switchTab('cashiers',this)">👥 Cashier Summary</button>
            <button class="tab-btn <?php echo $active_tab==='voids'?'active':''; ?>" onclick="switchTab('voids',this)">
                🚫 Void Log <?php if ($void_summary['cnt'] > 0): ?><span style="background:var(--c-danger);color:#fff;border-radius:99px;font-size:.7rem;padding:1px 7px;margin-left:4px;"><?php echo (int)$void_summary['cnt']; ?></span><?php endif; ?>
            </button>
            <button class="tab-btn <?php echo $active_tab==='history'?'active':''; ?>" onclick="switchTab('history',this)">🗂️ Read History</button>
        </div>

        <!-- ══════════════════════════════════════════════════════ -->
        <!-- TAB: X-READ                                           -->
        <!-- ══════════════════════════════════════════════════════ -->
        <div id="tab-xread" class="tab-pane <?php echo $active_tab==='xread'?'active':''; ?>">
            <div style="display:grid;grid-template-columns:1fr 340px;gap:var(--space-5);">

                <!-- Left: summary -->
                <div class="card card-flat">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4);">
                        <h3 style="margin:0;">📊 X-Read — Today's Snapshot</h3>
                        <span style="font-size:.8rem;color:var(--c-text-soft);">Generated: <?php echo date('g:i A'); ?></span>
                    </div>
                    <p class="text-muted" style="margin-bottom:var(--space-4);font-size:.85rem;">
                        X-Read shows current sales totals without resetting any counters. Run this anytime during the shift.
                    </p>

                    <div class="section-divider"><hr><span>Sales Totals</span><hr></div>
                    <div style="margin-bottom:var(--space-4);">
                        <div class="read-row"><span class="label">Total Transactions</span><span class="value"><?php echo number_format($sales_summary['cnt'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">Gross Sales</span><span class="value"><?php echo formatCurrency($sales_summary['gross'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">VAT (12%)</span><span class="value"><?php echo formatCurrency($sales_summary['vat'] ?? 0); ?></span></div>
                        <div class="read-row total"><span class="label">Net Sales (excl. VAT)</span><span class="value"><?php echo formatCurrency(($sales_summary['gross'] ?? 0) - ($sales_summary['vat'] ?? 0)); ?></span></div>
                    </div>

                    <div class="section-divider"><hr><span>By Payment Method</span><hr></div>
                    <div style="margin-bottom:var(--space-4);">
                        <div class="read-row"><span class="label">💵 Cash</span><span class="value"><?php echo formatCurrency($sales_summary['cash_sales'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">📱 GCash</span><span class="value"><?php echo formatCurrency($sales_summary['gcash_sales'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">💳 Card</span><span class="value"><?php echo formatCurrency($sales_summary['card_sales'] ?? 0); ?></span></div>
                    </div>

                    <?php if ($void_summary['cnt'] > 0): ?>
                    <div class="section-divider"><hr><span>Voids</span><hr></div>
                    <div>
                        <div class="read-row"><span class="label" style="color:var(--c-danger);">Voided Transactions</span><span class="value" style="color:var(--c-danger);"><?php echo (int)$void_summary['cnt']; ?></span></div>
                        <div class="read-row"><span class="label" style="color:var(--c-danger);">Total Void Amount</span><span class="value" style="color:var(--c-danger);"><?php echo formatCurrency($void_summary['amount'] ?? 0); ?></span></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right: generate + payment pie -->
                <div style="display:flex;flex-direction:column;gap:var(--space-4);">
                    <div class="card card-flat">
                        <h4 style="margin-bottom:var(--space-3);">Generate X-Read</h4>
                        <p style="font-size:.82rem;color:var(--c-text-soft);margin-bottom:var(--space-4);">
                            Saves a snapshot to the register log. Can be run multiple times.
                        </p>
                        <form method="POST">
                            <?php csrfInput(); ?>
                            <input type="hidden" name="action" value="xread">
                            <button type="submit" class="btn btn-primary btn-block">
                                📊 Generate X-Read
                            </button>
                        </form>
                    </div>
                    <div class="card card-flat">
                        <h4 style="margin-bottom:var(--space-3);">Payment Breakdown</h4>
                        <canvas id="xreadChart" height="180"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════ -->
        <!-- TAB: Z-READ                                           -->
        <!-- ══════════════════════════════════════════════════════ -->
        <div id="tab-zread" class="tab-pane <?php echo $active_tab==='zread'?'active':''; ?>">
            <?php if ($zread_done_today): ?>
            <div class="zread-done-banner">
                <span style="font-size:1.5rem;">✅</span>
                <div>
                    <strong>Z-Read already completed for today.</strong><br>
                    <span style="font-size:.85rem;color:var(--c-text-soft);">Generated at <?php echo date('g:i A', strtotime($zread_done_today['created_at'])); ?> — only one Z-Read per business day is allowed.</span>
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
                        Z-Read is the official end-of-day closing report. It is saved to the register log and <strong>cannot be repeated</strong> for the same day.
                    </p>

                    <div class="section-divider"><hr><span>Daily Totals</span><hr></div>
                    <div style="margin-bottom:var(--space-4);">
                        <div class="read-row"><span class="label">Total Transactions</span><span class="value"><?php echo number_format($sales_summary['cnt'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">Gross Sales</span><span class="value"><?php echo formatCurrency($sales_summary['gross'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">VAT (12%)</span><span class="value"><?php echo formatCurrency($sales_summary['vat'] ?? 0); ?></span></div>
                        <div class="read-row total"><span class="label">Net Sales</span><span class="value"><?php echo formatCurrency(($sales_summary['gross'] ?? 0) - ($sales_summary['vat'] ?? 0)); ?></span></div>
                    </div>

                    <div class="section-divider"><hr><span>By Payment</span><hr></div>
                    <div style="margin-bottom:var(--space-4);">
                        <div class="read-row"><span class="label">💵 Cash</span><span class="value"><?php echo formatCurrency($sales_summary['cash_sales'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">📱 GCash</span><span class="value"><?php echo formatCurrency($sales_summary['gcash_sales'] ?? 0); ?></span></div>
                        <div class="read-row"><span class="label">💳 Card</span><span class="value"><?php echo formatCurrency($sales_summary['card_sales'] ?? 0); ?></span></div>
                    </div>

                    <div class="section-divider"><hr><span>Cash Remittals Today</span><hr></div>
                    <div style="margin-bottom:var(--space-4);">
                        <div class="read-row"><span class="label">Remittals Recorded</span><span class="value"><?php echo count($remittals); ?></span></div>
                        <div class="read-row total"><span class="label">Total Remitted</span><span class="value"><?php echo formatCurrency($remittal_total); ?></span></div>
                    </div>

                    <?php if ($void_summary['cnt'] > 0): ?>
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
                            This will save the official end-of-day totals. Only one Z-Read per day is allowed.
                        </p>
                        <form method="POST" onsubmit="return confirm('Generate Z-Read for <?php echo $today; ?>? This cannot be undone for today.');">
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

        <!-- ══════════════════════════════════════════════════════ -->
        <!-- TAB: CASH REMITTAL                                    -->
        <!-- ══════════════════════════════════════════════════════ -->
        <div id="tab-remittal" class="tab-pane <?php echo $active_tab==='remittal'?'active':''; ?>">
            <div style="display:grid;grid-template-columns:1fr 320px;gap:var(--space-5);">

                <!-- Left: form -->
                <div class="card card-flat">
                    <h3 style="margin-bottom:var(--space-4);">💰 Record Cash Remittal</h3>
                    <form method="POST" id="remittalForm">
                        <?php csrfInput(); ?>
                        <input type="hidden" name="action" value="process_remittal">
                        <input type="hidden" name="amount" id="remittalAmount">

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);margin-bottom:var(--space-4);">
                            <div>
                                <label class="form-label">Cashier</label>
                                <select name="cashier_id" class="form-select" required>
                                    <option value="">— Select cashier —</option>
                                    <?php foreach ($cashiers as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Total Amount</label>
                                <div style="font-size:1.6rem;font-weight:800;color:var(--c-primary);padding:var(--space-2) 0;" id="remittalDisplay">₱0.00</div>
                            </div>
                        </div>

                        <div class="section-divider"><hr><span>Bill Denomination Breakdown</span><hr></div>
                        <div class="denomination-grid" style="margin-bottom:var(--space-4);">
                            <?php
                            $denoms = [1000, 500, 200, 100, 50, 20];
                            foreach ($denoms as $d):
                            ?>
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

                        <div class="cash-total-box" style="margin-bottom:var(--space-4);">
                            <div style="font-size:.8rem;color:var(--c-text-soft);margin-bottom:4px;">Total Cash to Remit</div>
                            <div class="amount" id="remittalDisplay2">₱0.00</div>
                            <div style="font-size:.75rem;color:var(--c-text-soft);margin-top:4px;" id="denomBreakdown">No bills entered</div>
                        </div>

                        <div style="margin-bottom:var(--space-4);">
                            <label class="form-label">Notes (optional)</label>
                            <textarea name="notes" class="form-input" rows="2" placeholder="e.g., Morning shift remittal, Drawer #1"></textarea>
                        </div>

                        <div style="display:flex;gap:var(--space-3);">
                            <button type="submit" class="btn btn-primary">✅ Record Remittal</button>
                            <button type="reset" class="btn btn-ghost" onclick="setTimeout(recalc,0)">Clear</button>
                        </div>
                    </form>
                </div>

                <!-- Right: today's remittals -->
                <div class="card card-flat" style="align-self:start;">
                    <h4 style="margin-bottom:var(--space-3);">Today's Remittals</h4>
                    <?php if (empty($remittals)): ?>
                        <p class="text-muted" style="font-size:.85rem;">No remittals recorded today.</p>
                    <?php else: ?>
                        <?php foreach ($remittals as $r): ?>
                        <div style="display:flex;justify-content:space-between;padding:var(--space-2) 0;border-bottom:1px solid var(--c-border);font-size:.85rem;">
                            <div>
                                <div style="font-weight:600;"><?php echo htmlspecialchars($r['cashier_name'] ?? '—'); ?></div>
                                <div style="color:var(--c-text-soft);font-size:.75rem;"><?php echo date('g:i A', strtotime($r['created_at'])); ?></div>
                            </div>
                            <strong><?php echo formatCurrency($r['amount']); ?></strong>
                        </div>
                        <?php endforeach; ?>
                        <div style="display:flex;justify-content:space-between;padding:var(--space-3) 0;font-weight:700;">
                            <span>Total</span>
                            <span style="color:var(--c-primary);"><?php echo formatCurrency($remittal_total); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════ -->
        <!-- TAB: CASHIER SUMMARY                                  -->
        <!-- ══════════════════════════════════════════════════════ -->
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
                                <th>VAT</th>
                                <th>Cash</th>
                                <th>GCash</th>
                                <th>Card</th>
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
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($cs['cashier_name']); ?></strong></td>
                                <td><?php echo number_format($cs['txn_count']); ?></td>
                                <td><strong><?php echo formatCurrency($cs['total_sales']); ?></strong></td>
                                <td><?php echo formatCurrency($cs['total_vat']); ?></td>
                                <td><?php echo formatCurrency($cs['cash']); ?></td>
                                <td><?php echo formatCurrency($cs['gcash']); ?></td>
                                <td><?php echo formatCurrency($cs['card']); ?></td>
                                <td class="text-muted"><?php echo formatCurrency($avg); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:var(--c-primary-fade);font-weight:700;">
                                <td>Total</td>
                                <td><?php echo number_format($grand_txn); ?></td>
                                <td><?php echo formatCurrency($grand_sales); ?></td>
                                <td colspan="5"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════ -->
        <!-- TAB: VOID LOG                                         -->
        <!-- ══════════════════════════════════════════════════════ -->
        <div id="tab-voids" class="tab-pane <?php echo $active_tab==='voids'?'active':''; ?>">
            <div class="card card-flat">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4);">
                    <h3 style="margin:0;">🚫 Void / Cancelled Transactions — <?php echo date('F j, Y'); ?></h3>
                    <?php if (!empty($void_log)): ?>
                    <span class="badge" style="background:var(--c-danger-light);color:var(--c-danger);">
                        <?php echo count($void_log); ?> voided · <?php echo formatCurrency($void_summary['amount'] ?? 0); ?>
                    </span>
                    <?php endif; ?>
                </div>

                <?php if (empty($void_log)): ?>
                    <div style="text-align:center;padding:var(--space-8) 0;">
                        <div style="font-size:2.5rem;margin-bottom:var(--space-3);">✅</div>
                        <p class="text-muted">No voided transactions today. All clear.</p>
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
                                <td><code><?php echo str_pad($v['id'], 6, '0', STR_PAD_LEFT); ?></code></td>
                                <td><?php echo htmlspecialchars($v['cashier_name']); ?></td>
                                <td><strong><?php echo formatCurrency($v['total_amount']); ?></strong></td>
                                <td>
                                    <?php $mc=['cash'=>'badge-success','gcash'=>'badge-info','card'=>'badge-warning']; ?>
                                    <span class="badge <?php echo $mc[$v['payment_method']]??'badge-neutral'; ?>"><?php echo ucfirst($v['payment_method']); ?></span>
                                </td>
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

        <!-- ══════════════════════════════════════════════════════ -->
        <!-- TAB: READ HISTORY                                     -->
        <!-- ══════════════════════════════════════════════════════ -->
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
                                <td>
                                    <?php if ($rr['read_type'] === 'z_read'): ?>
                                        <span class="read-badge-z">Z-Read</span>
                                    <?php else: ?>
                                        <span class="read-badge-x">X-Read</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($rr['read_date'])); ?></td>
                                <td><?php echo number_format($rr['total_transactions']); ?></td>
                                <td><strong><?php echo formatCurrency($rr['total_gross']); ?></strong></td>
                                <td><?php echo formatCurrency($rr['total_vat']); ?></td>
                                <td><?php echo formatCurrency($rr['cash_sales']); ?></td>
                                <td><?php echo formatCurrency($rr['gcash_sales']); ?></td>
                                <td><?php echo formatCurrency($rr['card_sales']); ?></td>
                                <td><?php echo $rr['void_count'] > 0 ? '<span style="color:var(--c-danger);">'.$rr['void_count'].'</span>' : '0'; ?></td>
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

    // ── Bill denomination recalculator ────────────────────────
    const DENOMS = [1000, 500, 200, 100, 50, 20];
    function fmt(n) { return '₱' + n.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}); }

    function recalc() {
        let total = 0;
        let parts = [];
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

        const display = fmt(total);
        const d1 = document.getElementById('remittalDisplay');
        const d2 = document.getElementById('remittalDisplay2');
        const ra = document.getElementById('remittalAmount');
        const bd = document.getElementById('denomBreakdown');
        if (d1) d1.textContent = display;
        if (d2) d2.textContent = display;
        if (ra) ra.value = total.toFixed(2);
        if (bd) bd.textContent = parts.length ? parts.join(' + ') : 'No bills entered';
    }
    recalc();

    // ── X-Read payment pie chart ──────────────────────────────
    (function(){
        const cash  = <?php echo floatval($sales_summary['cash_sales']  ?? 0); ?>;
        const gcash = <?php echo floatval($sales_summary['gcash_sales'] ?? 0); ?>;
        const card  = <?php echo floatval($sales_summary['card_sales']  ?? 0); ?>;
        const ctx = document.getElementById('xreadChart');
        if (!ctx) return;
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const textC = isDark ? '#9CA3AF' : '#718096';
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
                    legend: { position: 'bottom', labels: { color: textC, font: { size: 11 } } },
                    tooltip: { callbacks: { label: ctx => ctx.label + ': ₱' + ctx.raw.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') } }
                }
            }
        });
    })();
    </script>
</body>
</html>
