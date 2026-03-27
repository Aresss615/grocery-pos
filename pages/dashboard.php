<?php
/**
 * J&J Grocery POS — Dashboard v3
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn()) redirect(BASE_URL . '/index.php');
checkSessionTimeout();

$user  = getCurrentUser();
$today = date(DATE_FORMAT);

// ── Stats ───────────────────────────────────────────────────
$total_products = $db->fetchOne("SELECT COUNT(*) AS c FROM products WHERE active = 1");

$sales_today = $db->fetchOne(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS rev,
            COALESCE(SUM(tax_amount),0) AS vat
     FROM sales WHERE DATE(created_at) = ? AND voided = 0",
    [$today]
);

// Handle missing voided column gracefully
if (!$sales_today) {
    $sales_today = $db->fetchOne(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS rev, COALESCE(SUM(tax_amount),0) AS vat
         FROM sales WHERE DATE(created_at) = ?", [$today]
    );
}

$month_start = date('Y-m-01');
$sales_month = $db->fetchOne(
    "SELECT COALESCE(SUM(total_amount),0) AS rev FROM sales WHERE created_at >= ?",
    [$month_start]
);

// Yesterday comparison
$yesterday = date('Y-m-d', strtotime('-1 day'));
$sales_yday = $db->fetchOne(
    "SELECT COALESCE(SUM(total_amount),0) AS rev FROM sales WHERE DATE(created_at) = ?", [$yesterday]
);
$rev_today  = floatval($sales_today['rev'] ?? 0);
$rev_yday   = floatval($sales_yday['rev'] ?? 0);
$rev_change = $rev_yday > 0 ? (($rev_today - $rev_yday) / $rev_yday * 100) : null;

$stats = [
    'products'      => $total_products['c'] ?? 0,
    'sales_today'   => $sales_today['cnt'] ?? 0,
    'revenue_today' => $rev_today,
    'vat_today'     => $sales_today['vat'] ?? 0,
    'revenue_month' => $sales_month['rev'] ?? 0,
];

if (hasRole('admin')) {
    $total_users       = $db->fetchOne("SELECT COUNT(*) AS c FROM users WHERE active = 1");
    $stats['users']    = $total_users['c'] ?? 0;
}

// ── Low stock ────────────────────────────────────────────────
$low_stock = $db->fetchAll(
    "SELECT name, quantity, min_quantity FROM products
     WHERE active = 1 AND quantity IS NOT NULL AND quantity <= min_quantity
     ORDER BY quantity ASC LIMIT 12"
);

// ── Recent sales ─────────────────────────────────────────────
$recent_sales = $db->fetchAll(
    "SELECT s.id, s.total_amount, s.payment_method, s.created_at, u.name AS cashier_name
     FROM sales s JOIN users u ON s.cashier_id = u.id
     ORDER BY s.created_at DESC LIMIT 8"
);

// ── Hourly sales chart data (today) ──────────────────────────
$hourly = $db->fetchAll(
    "SELECT HOUR(created_at) AS hr, COUNT(*) AS cnt, SUM(total_amount) AS rev
     FROM sales WHERE DATE(created_at) = ?
     GROUP BY HOUR(created_at) ORDER BY hr",
    [$today]
);
$hourly_map = [];
foreach ($hourly as $h) $hourly_map[$h['hr']] = $h;
$hourly_labels = $hourly_rev = [];
for ($i = 6; $i <= 22; $i++) {
    $label = date('g A', mktime($i, 0, 0));
    $hourly_labels[] = $label;
    $hourly_rev[]    = floatval($hourly_map[$i]['rev'] ?? 0);
}
$hourly_labels_json = json_encode($hourly_labels);
$hourly_rev_json    = json_encode($hourly_rev);
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> — Dashboard</title>
    <script>(function(){var t=localStorage.getItem('pos_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>/main.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
</head>
<body class="dashboard-page">
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard-container">

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $user['name'])[0]); ?>!</h1>
                <p class="text-muted"><?php echo date('l, F j, Y'); ?></p>
            </div>
            <?php if (hasRole('cashier') || hasRole('admin')): ?>
            <a href="<?php echo BASE_URL; ?>/pages/pos.php" class="btn btn-primary btn-lg">
                🖥️ Open POS Terminal
            </a>
            <?php endif; ?>
        </div>

        <?php displayMessage(); ?>

        <!-- Low stock alert -->
        <?php if (!empty($low_stock)): ?>
        <div class="low-stock-alert" role="alert" style="display:flex;align-items:flex-start;gap:12px;background:var(--c-warning-light);border:1px solid rgba(230,81,0,.25);border-radius:var(--radius-lg);padding:var(--space-4) var(--space-5);margin-bottom:var(--space-5);">
            <span style="font-size:1.4rem;flex-shrink:0;">⚠️</span>
            <div style="flex:1;">
                <h4 style="margin-bottom:6px;color:var(--c-warning);">Low Stock — <?php echo count($low_stock); ?> product<?php echo count($low_stock)>1?'s':''; ?> need restocking</h4>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                    <?php foreach ($low_stock as $ls): ?>
                    <span style="background:rgba(230,81,0,.12);border:1px solid rgba(230,81,0,.2);border-radius:20px;padding:2px 10px;font-size:.78rem;font-weight:600;color:var(--c-warning);">
                        <?php echo htmlspecialchars($ls['name']); ?>
                        <span style="opacity:.7;">(<?php echo (int)$ls['quantity']; ?>/<?php echo (int)$ls['min_quantity']; ?>)</span>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (hasRole('admin') || hasRole('inventory_checker')): ?>
            <a href="<?php echo BASE_URL; ?>/pages/inventory.php" class="btn btn-sm" style="flex-shrink:0;background:var(--c-warning);color:#fff;border:none;">View All</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Stat cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">📦</div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($stats['products']); ?></div>
                    <div class="stat-label">Active Products</div>
                </div>
            </div>

            <div class="stat-card stat-success">
                <div class="stat-icon">🛒</div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($stats['sales_today']); ?></div>
                    <div class="stat-label">Sales Today</div>
                </div>
            </div>

            <div class="stat-card stat-info">
                <div class="stat-icon">💰</div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo formatCurrency($stats['revenue_today']); ?></div>
                    <div class="stat-label">Revenue Today
                        <?php if ($rev_change !== null): ?>
                        <span style="font-size:.7rem;font-weight:600;color:<?php echo $rev_change>=0?'var(--c-success)':'var(--c-danger)'; ?>;">
                            <?php echo $rev_change>=0?'▲':'▼'; ?><?php echo abs(round($rev_change,1)); ?>% vs yesterday
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="stat-card stat-warning">
                <div class="stat-icon">📈</div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo formatCurrency($stats['revenue_month']); ?></div>
                    <div class="stat-label">This Month</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🧾</div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo formatCurrency($stats['vat_today']); ?></div>
                    <div class="stat-label">VAT Collected Today</div>
                </div>
            </div>

            <?php if (hasRole('admin')): ?>
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($stats['users']); ?></div>
                    <div class="stat-label">Team Members</div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Content row -->
        <div style="display:grid;grid-template-columns:1fr 320px;gap:var(--space-6);">

            <!-- Left: chart + recent sales -->
            <div style="display:flex;flex-direction:column;gap:var(--space-5);">

                <!-- Hourly sales chart -->
                <div class="card card-flat">
                    <h3 style="margin-bottom:var(--space-4);">Today's Hourly Sales</h3>
                    <canvas id="hourlyChart" height="80"></canvas>
                </div>

                <!-- Recent transactions -->
                <div class="card card-flat">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4);">
                        <h3 style="margin:0;">Recent Transactions</h3>
                        <?php if (hasRole('admin') || hasRole('manager')): ?>
                        <a href="<?php echo BASE_URL; ?>/pages/reports.php" class="btn btn-sm btn-ghost">View All →</a>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($recent_sales)): ?>
                        <p class="text-muted" style="text-align:center;padding:var(--space-6) 0;">No transactions today yet.</p>
                    <?php else: ?>
                    <div class="overflow-x">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Receipt #</th>
                                    <th>Cashier</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_sales as $sale): ?>
                                <tr>
                                    <td><code><?php echo str_pad($sale['id'], 6, '0', STR_PAD_LEFT); ?></code></td>
                                    <td><?php echo htmlspecialchars($sale['cashier_name']); ?></td>
                                    <td><strong><?php echo formatCurrency($sale['total_amount']); ?></strong></td>
                                    <td>
                                        <?php
                                        $mc = ['cash'=>'badge-success','gcash'=>'badge-info','card'=>'badge-warning'];
                                        $cls = $mc[$sale['payment_method']] ?? 'badge-neutral';
                                        ?>
                                        <span class="badge <?php echo $cls; ?>"><?php echo ucfirst($sale['payment_method']); ?></span>
                                    </td>
                                    <td class="text-muted"><?php echo date('g:i A', strtotime($sale['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right: quick actions + user info -->
            <div style="display:flex;flex-direction:column;gap:var(--space-4);">
                <div class="card card-flat">
                    <h3 style="margin-bottom:var(--space-4);">Quick Actions</h3>
                    <div style="display:flex;flex-direction:column;gap:var(--space-3);">
                        <?php if (hasRole('cashier') || hasRole('admin')): ?>
                        <a href="<?php echo BASE_URL; ?>/pages/pos.php" class="btn btn-primary btn-block">
                            🖥️ POS Terminal
                        </a>
                        <?php endif; ?>
                        <?php if (hasRole('admin') || hasRole('manager')): ?>
                        <a href="<?php echo BASE_URL; ?>/pages/products.php" class="btn btn-ghost btn-block">
                            📦 Manage Products
                        </a>
                        <?php endif; ?>
                        <?php if (hasRole('admin')): ?>
                        <a href="<?php echo BASE_URL; ?>/pages/users.php" class="btn btn-ghost btn-block">
                            👥 Manage Users
                        </a>
                        <?php endif; ?>
                        <?php if (hasRole('inventory_checker') || hasRole('admin') || hasRole('manager')): ?>
                        <a href="<?php echo BASE_URL; ?>/pages/inventory.php" class="btn btn-ghost btn-block">
                            🗂️ Inventory
                        </a>
                        <?php endif; ?>
                        <?php if (hasRole('manager') || hasRole('admin')): ?>
                        <a href="<?php echo BASE_URL; ?>/pages/manager.php" class="btn btn-ghost btn-block">
                            💼 Manager Portal
                        </a>
                        <a href="<?php echo BASE_URL; ?>/pages/reports.php" class="btn btn-ghost btn-block">
                            📈 Reports
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- User card -->
                <div class="card card-flat" style="background:var(--c-primary-fade);border-color:rgba(211,47,47,.18);">
                    <p style="font-size:.75rem;color:var(--c-text-soft);margin-bottom:var(--space-2);">Logged in as</p>
                    <p style="font-weight:700;color:var(--c-text);margin-bottom:var(--space-2);"><?php echo htmlspecialchars($user['name']); ?></p>
                    <span class="badge badge-primary"><?php echo ucfirst(str_replace('_',' ',$user['role'])); ?></span>
                    <p style="font-size:.72rem;color:var(--c-text-soft);margin-top:var(--space-3);">Session active</p>
                </div>
            </div>

        </div><!-- /content row -->
    </div><!-- /dashboard-container -->

    <style>
    @media (max-width:960px){
        .dashboard-container>div:last-child{grid-template-columns:1fr!important;}
    }
    </style>

    <script src="<?php echo JS_URL; ?>/main.js"></script>
    <script>
    // Hourly chart
    (function(){
        const labels = <?php echo $hourly_labels_json; ?>;
        const data   = <?php echo $hourly_rev_json; ?>;
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const gridC  = isDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';
        const textC  = isDark ? '#94A3B8' : '#718096';

        const ctx = document.getElementById('hourlyChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Revenue (₱)',
                    data,
                    backgroundColor: 'rgba(211,47,47,.75)',
                    borderColor: '#D32F2F',
                    borderWidth: 1,
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => '₱' + ctx.raw.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',') } } },
                scales: {
                    x: { ticks: { color: textC, font: { size: 10 } }, grid: { color: gridC } },
                    y: { ticks: { color: textC, font: { size: 10 }, callback: v => '₱' + (v/1000).toFixed(0) + 'k' }, grid: { color: gridC } }
                }
            }
        });
    })();
    </script>
</body>
</html>
