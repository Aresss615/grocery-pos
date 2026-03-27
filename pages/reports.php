<?php
/**
 * J&J Grocery POS - Reports & Analytics
 * Tabbed: Overview | By Cashier | By Product | VAT Report | Void Report
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn() || (!hasRole('manager') && !hasRole('admin'))) {
    redirect(BASE_URL . '/index.php');
}

checkSessionTimeout();

$page_title = 'Reports & Analytics';
$days = intval($_GET['days'] ?? 30);
$active_tab = $_GET['tab'] ?? 'overview';
if (!in_array($days, [7, 30, 90, 365])) $days = 30;
$valid_tabs = ['overview', 'cashier', 'product', 'vat', 'voids'];
if (!in_array($active_tab, $valid_tabs)) $active_tab = 'overview';

// ── Server-rendered: VAT Report ──────────────────────────────────────────────
$vat_rows = $db->fetchAll("
    SELECT
        DATE(created_at)          AS sale_date,
        COUNT(*)                  AS transactions,
        SUM(total_amount)         AS gross_sales,
        SUM(subtotal)             AS vat_excl,
        SUM(tax_amount)           AS vat_amount
    FROM sales
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND (voided = 0 OR voided IS NULL)
    GROUP BY DATE(created_at)
    ORDER BY sale_date DESC
", [$days]);

$vat_totals = $db->fetchOne("
    SELECT
        COUNT(*)          AS transactions,
        SUM(total_amount) AS gross_sales,
        SUM(subtotal)     AS vat_excl,
        SUM(tax_amount)   AS vat_amount
    FROM sales
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND (voided = 0 OR voided IS NULL)
", [$days]);

// ── Server-rendered: Void Report ─────────────────────────────────────────────
$void_rows = $db->fetchAll("
    SELECT
        s.id,
        u_cashier.name  AS cashier,
        s.total_amount,
        s.void_reason,
        u_void.name     AS voided_by,
        s.voided_at
    FROM sales s
    JOIN users u_cashier ON s.cashier_id  = u_cashier.id
    LEFT JOIN users u_void ON s.voided_by = u_void.id
    WHERE s.voided = 1
      AND s.voided_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ORDER BY s.voided_at DESC
", [$days]);

// ── CSV export ────────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $type = $_GET['export'];
    if ($type === 'vat') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="vat-report-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Transactions', 'Gross Sales', 'VAT-Excl Amount', 'VAT Amount']);
        foreach ($vat_rows as $r) {
            fputcsv($out, [
                $r['sale_date'],
                $r['transactions'],
                number_format($r['gross_sales'], 2, '.', ''),
                number_format($r['vat_excl'], 2, '.', ''),
                number_format($r['vat_amount'], 2, '.', ''),
            ]);
        }
        fputcsv($out, ['TOTAL', $vat_totals['transactions'],
            number_format($vat_totals['gross_sales'], 2, '.', ''),
            number_format($vat_totals['vat_excl'], 2, '.', ''),
            number_format($vat_totals['vat_amount'], 2, '.', ''),
        ]);
        fclose($out);
        exit();
    }
    if ($type === 'voids') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="void-report-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Sale ID', 'Cashier', 'Total Amount', 'Void Reason', 'Voided By', 'Voided At']);
        foreach ($void_rows as $r) {
            fputcsv($out, [
                $r['id'],
                $r['cashier'],
                number_format($r['total_amount'], 2, '.', ''),
                $r['void_reason'] ?? '',
                $r['voided_by'] ?? '',
                $r['voided_at'],
            ]);
        }
        fclose($out);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> — <?php echo $page_title; ?></title>
    <script>(function(){var t=localStorage.getItem('pos_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>/main.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <style>
        /* ── Tab nav ─────────────────────────────── */
        .tab-nav {
            display: flex;
            gap: 4px;
            border-bottom: 2px solid var(--c-border);
            margin-bottom: var(--space-5);
            flex-wrap: wrap;
        }
        .tab-btn {
            padding: 10px 20px;
            border: none;
            background: transparent;
            color: var(--c-text-soft);
            font-family: var(--font-sans);
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: color .15s, border-color .15s;
        }
        .tab-btn:hover { color: var(--c-text); }
        .tab-btn.active { color: var(--c-primary); border-bottom-color: var(--c-primary); }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }

        /* ── Period selector ─────────────────────── */
        .report-toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: var(--space-5);
            flex-wrap: wrap;
        }
        .period-label { font-size: .85rem; font-weight: 600; color: var(--c-text-soft); }
        .period-btn {
            padding: 6px 14px;
            border: 1.5px solid var(--c-border);
            border-radius: var(--radius-md);
            background: var(--c-surface);
            color: var(--c-text-soft);
            font-family: var(--font-sans);
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s, color .15s, border-color .15s;
        }
        .period-btn:hover { border-color: var(--c-primary); color: var(--c-primary); }
        .period-btn.active { background: var(--c-primary); border-color: var(--c-primary); color: #fff; }

        /* ── Stat cards ──────────────────────────── */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: var(--space-4); margin-bottom: var(--space-5); }
        .stat-card { padding: var(--space-4); border-radius: var(--radius-lg); background: var(--c-surface); border: 1px solid var(--c-border); }
        .stat-card .stat-label { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--c-text-soft); margin-bottom: 6px; }
        .stat-card .stat-value { font-size: 1.6rem; font-weight: 700; color: var(--c-text); }
        .stat-card .stat-sub   { font-size: .78rem; color: var(--c-text-soft); margin-top: 3px; }

        /* ── Chart containers ────────────────────── */
        .charts-row { display: grid; grid-template-columns: 2fr 1fr; gap: var(--space-4); margin-bottom: var(--space-5); }
        @media (max-width: 800px) { .charts-row { grid-template-columns: 1fr; } }
        .chart-card { background: var(--c-surface); border: 1px solid var(--c-border); border-radius: var(--radius-lg); padding: var(--space-4); }
        .chart-card h3 { font-size: .85rem; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: var(--c-text-soft); margin-bottom: var(--space-3); }
        .chart-wrap { position: relative; height: 260px; }

        /* ── Tables ──────────────────────────────── */
        .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-3); flex-wrap: wrap; gap: 8px; }
        .section-header h3 { font-size: 1rem; font-weight: 700; color: var(--c-text); margin: 0; }
        .tfoot-total td { font-weight: 700; background: var(--c-surface); }
    </style>
</head>
<body class="dashboard-page">
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <div>
                <h1><?php echo $page_title; ?></h1>
                <p class="text-muted">Sales analysis and reporting</p>
            </div>
        </div>

        <!-- Period selector -->
        <div class="report-toolbar">
            <span class="period-label">Period:</span>
            <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year'] as $d => $label): ?>
            <button class="period-btn <?php echo $days == $d ? 'active' : ''; ?>"
                    onclick="setPeriod(<?php echo $d; ?>)">
                <?php echo $label; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Tab nav -->
        <div class="tab-nav" role="tablist">
            <button class="tab-btn <?php echo $active_tab === 'overview' ? 'active' : ''; ?>" onclick="switchTab('overview')">Overview</button>
            <button class="tab-btn <?php echo $active_tab === 'cashier'  ? 'active' : ''; ?>" onclick="switchTab('cashier')">By Cashier</button>
            <button class="tab-btn <?php echo $active_tab === 'product'  ? 'active' : ''; ?>" onclick="switchTab('product')">By Product</button>
            <button class="tab-btn <?php echo $active_tab === 'vat'      ? 'active' : ''; ?>" onclick="switchTab('vat')">VAT Report</button>
            <button class="tab-btn <?php echo $active_tab === 'voids'    ? 'active' : ''; ?>" onclick="switchTab('voids')">Void Report</button>
        </div>

        <!-- ═══════════════ OVERVIEW TAB ═══════════════ -->
        <div id="tab-overview" class="tab-pane <?php echo $active_tab === 'overview' ? 'active' : ''; ?>">
            <div class="stats-grid" id="overview-stats">
                <div class="stat-card"><div class="stat-label">Total Sales</div><div class="stat-value" id="stat-total">—</div></div>
                <div class="stat-card"><div class="stat-label">Transactions</div><div class="stat-value" id="stat-txns">—</div></div>
                <div class="stat-card"><div class="stat-label">Avg Transaction</div><div class="stat-value" id="stat-avg">—</div></div>
                <div class="stat-card"><div class="stat-label">Total VAT Collected</div><div class="stat-value" id="stat-vat">—</div></div>
            </div>
            <div class="charts-row">
                <div class="chart-card">
                    <h3>Daily Revenue Trend</h3>
                    <div class="chart-wrap"><canvas id="chart-daily"></canvas></div>
                </div>
                <div class="chart-card">
                    <h3>Payment Methods</h3>
                    <div class="chart-wrap"><canvas id="chart-payment"></canvas></div>
                </div>
            </div>
        </div>

        <!-- ═══════════════ BY CASHIER TAB ═══════════════ -->
        <div id="tab-cashier" class="tab-pane <?php echo $active_tab === 'cashier' ? 'active' : ''; ?>">
            <div class="chart-card" style="margin-bottom:var(--space-4)">
                <h3>Revenue by Cashier</h3>
                <div class="chart-wrap"><canvas id="chart-cashier"></canvas></div>
            </div>
            <div class="section-header">
                <h3>Cashier Breakdown</h3>
            </div>
            <div class="card card-flat">
                <table class="data-table" id="tbl-cashier">
                    <thead>
                        <tr>
                            <th>Cashier</th>
                            <th class="text-right">Transactions</th>
                            <th class="text-right">Total Revenue</th>
                            <th class="text-right">Avg Transaction</th>
                            <th class="text-right">Total Tax</th>
                        </tr>
                    </thead>
                    <tbody><tr><td colspan="5" class="text-center text-muted">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>

        <!-- ═══════════════ BY PRODUCT TAB ═══════════════ -->
        <div id="tab-product" class="tab-pane <?php echo $active_tab === 'product' ? 'active' : ''; ?>">
            <div class="chart-card" style="margin-bottom:var(--space-4)">
                <h3>Top 15 Products by Quantity Sold</h3>
                <div class="chart-wrap" style="height:320px"><canvas id="chart-product"></canvas></div>
            </div>
            <div class="section-header">
                <h3>Product Performance</h3>
            </div>
            <div class="card card-flat">
                <table class="data-table" id="tbl-product">
                    <thead>
                        <tr>
                            <th class="text-center">#</th>
                            <th>Product</th>
                            <th>Barcode</th>
                            <th class="text-right">Qty Sold</th>
                            <th class="text-right">Avg Price</th>
                            <th class="text-right">Revenue</th>
                        </tr>
                    </thead>
                    <tbody><tr><td colspan="6" class="text-center text-muted">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>

        <!-- ═══════════════ VAT REPORT TAB ═══════════════ -->
        <div id="tab-vat" class="tab-pane <?php echo $active_tab === 'vat' ? 'active' : ''; ?>">
            <div class="section-header">
                <h3>VAT Report — Last <?php echo $days; ?> days</h3>
                <a href="?days=<?php echo $days; ?>&tab=vat&export=vat" class="btn btn-secondary">Export CSV</a>
            </div>
            <div class="card card-flat">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="text-right">Transactions</th>
                            <th class="text-right">Gross Sales</th>
                            <th class="text-right">VAT-Excl Amount</th>
                            <th class="text-right">VAT Amount (12%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vat_rows)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No sales data for this period.</td></tr>
                        <?php else: ?>
                        <?php foreach ($vat_rows as $r): ?>
                        <tr>
                            <td><?php echo sanitize($r['sale_date']); ?></td>
                            <td class="text-right"><?php echo number_format($r['transactions']); ?></td>
                            <td class="text-right"><?php echo formatCurrency($r['gross_sales']); ?></td>
                            <td class="text-right"><?php echo formatCurrency($r['vat_excl']); ?></td>
                            <td class="text-right"><?php echo formatCurrency($r['vat_amount']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($vat_rows)): ?>
                    <tfoot>
                        <tr class="tfoot-total">
                            <td><strong>Total</strong></td>
                            <td class="text-right"><?php echo number_format($vat_totals['transactions']); ?></td>
                            <td class="text-right"><?php echo formatCurrency($vat_totals['gross_sales']); ?></td>
                            <td class="text-right"><?php echo formatCurrency($vat_totals['vat_excl']); ?></td>
                            <td class="text-right"><?php echo formatCurrency($vat_totals['vat_amount']); ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- ═══════════════ VOID REPORT TAB ═══════════════ -->
        <div id="tab-voids" class="tab-pane <?php echo $active_tab === 'voids' ? 'active' : ''; ?>">
            <div class="section-header">
                <h3>Void Report — Last <?php echo $days; ?> days
                    <span class="badge badge-danger" style="margin-left:8px"><?php echo count($void_rows); ?> voided</span>
                </h3>
                <a href="?days=<?php echo $days; ?>&tab=voids&export=voids" class="btn btn-secondary">Export CSV</a>
            </div>
            <div class="card card-flat">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Sale ID</th>
                            <th>Cashier</th>
                            <th class="text-right">Total</th>
                            <th>Void Reason</th>
                            <th>Voided By</th>
                            <th>Voided At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($void_rows)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No voided sales for this period.</td></tr>
                        <?php else: ?>
                        <?php foreach ($void_rows as $r): ?>
                        <tr>
                            <td>#<?php echo $r['id']; ?></td>
                            <td><?php echo sanitize($r['cashier']); ?></td>
                            <td class="text-right"><?php echo formatCurrency($r['total_amount']); ?></td>
                            <td><?php echo sanitize($r['void_reason'] ?? '—'); ?></td>
                            <td><?php echo sanitize($r['voided_by'] ?? '—'); ?></td>
                            <td><?php echo sanitize($r['voided_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /.dashboard-container -->

<script>
(function () {
    'use strict';

    const DAYS = <?php echo $days; ?>;
    const API  = '<?php echo API_URL; ?>/sales-analytics.php';

    // ── Chart instances ──────────────────────────────────────────────────────
    const charts = {};
    function destroyChart(key) {
        if (charts[key]) { charts[key].destroy(); delete charts[key]; }
    }

    // ── Palette ──────────────────────────────────────────────────────────────
    const PALETTE = ['#6366f1','#22d3ee','#f59e0b','#10b981','#f43f5e','#a78bfa',
                     '#fb923c','#34d399','#60a5fa','#e879f9','#4ade80','#fbbf24',
                     '#38bdf8','#f87171','#c084fc'];

    function fmt(n)  { return '₱' + Number(n).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}); }
    function fmtNum(n){ return Number(n).toLocaleString('en-PH'); }

    // ── Tab switching ────────────────────────────────────────────────────────
    const loadedTabs = new Set();

    window.switchTab = function(tab) {
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('tab-' + tab).classList.add('active');
        document.querySelectorAll('.tab-btn').forEach(b => {
            if (b.getAttribute('onclick') === "switchTab('" + tab + "')") b.classList.add('active');
        });
        history.replaceState(null, '', '?days=' + DAYS + '&tab=' + tab);
        if (!loadedTabs.has(tab)) { loadTab(tab); }
    };

    function loadTab(tab) {
        loadedTabs.add(tab);
        if (tab === 'overview')  { loadOverview(); }
        if (tab === 'cashier')   { loadCashier(); }
        if (tab === 'product')   { loadProduct(); }
        // vat + voids are server-rendered — nothing to load
    }

    // ── Period change ────────────────────────────────────────────────────────
    window.setPeriod = function(d) {
        const tab = new URLSearchParams(location.search).get('tab') || 'overview';
        location.href = '?days=' + d + '&tab=' + tab;
    };

    // ── Overview ─────────────────────────────────────────────────────────────
    async function loadOverview() {
        const [sumRes, dailyRes, payRes] = await Promise.all([
            fetch(API + '?action=summary&days=' + DAYS).then(r => r.json()),
            fetch(API + '?action=daily_sales&days=' + DAYS).then(r => r.json()),
            fetch(API + '?action=payment_breakdown&days=' + DAYS).then(r => r.json()),
        ]);

        // Stat cards
        if (sumRes.success) {
            const s = sumRes.data;
            document.getElementById('stat-total').textContent = fmt(s.total_sales || 0);
            document.getElementById('stat-txns').textContent  = fmtNum(s.total_transactions || 0);
            document.getElementById('stat-avg').textContent   = fmt(s.avg_transaction || 0);
            document.getElementById('stat-vat').textContent   = fmt(s.total_tax || 0);
        }

        // Daily trend line chart
        if (dailyRes.success && dailyRes.data.length) {
            destroyChart('daily');
            const labels = dailyRes.data.map(r => r.sale_date);
            const values = dailyRes.data.map(r => parseFloat(r.daily_total) || 0);
            charts['daily'] = new Chart(document.getElementById('chart-daily'), {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: 'Daily Revenue',
                        data: values,
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99,102,241,.12)',
                        borderWidth: 2,
                        pointRadius: labels.length <= 14 ? 4 : 2,
                        fill: true,
                        tension: .35,
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { maxTicksLimit: 10 } },
                        y: { ticks: { callback: v => '₱' + v.toLocaleString('en-PH') } }
                    }
                }
            });
        }

        // Payment pie chart — cash/gcash/card only
        if (payRes.success && payRes.data.length) {
            destroyChart('payment');
            const ALLOWED = ['cash', 'gcash', 'card'];
            const filtered = payRes.data.filter(r => ALLOWED.includes((r.payment_method || '').toLowerCase()));
            const labels  = filtered.map(r => r.payment_method.charAt(0).toUpperCase() + r.payment_method.slice(1));
            const values  = filtered.map(r => parseFloat(r.total_amount) || 0);
            const colors  = ['#10b981', '#6366f1', '#f59e0b'];
            charts['payment'] = new Chart(document.getElementById('chart-payment'), {
                type: 'pie',
                data: { labels, datasets: [{ data: values, backgroundColor: colors.slice(0, labels.length), borderWidth: 0 }] },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 14, boxWidth: 12 } },
                        tooltip: { callbacks: { label: ctx => ' ' + fmt(ctx.raw) } }
                    }
                }
            });
        }
    }

    // ── By Cashier ───────────────────────────────────────────────────────────
    async function loadCashier() {
        const res = await fetch(API + '?action=cashier_breakdown&days=' + DAYS).then(r => r.json());
        if (!res.success || !res.data.length) {
            document.querySelector('#tbl-cashier tbody').innerHTML =
                '<tr><td colspan="5" class="text-center text-muted">No data for this period.</td></tr>';
            return;
        }
        const data = res.data;

        // Bar chart
        destroyChart('cashier');
        charts['cashier'] = new Chart(document.getElementById('chart-cashier'), {
            type: 'bar',
            data: {
                labels: data.map(r => r.cashier),
                datasets: [{
                    label: 'Revenue',
                    data: data.map(r => parseFloat(r.total_revenue) || 0),
                    backgroundColor: PALETTE.slice(0, data.length),
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { ticks: { callback: v => '₱' + v.toLocaleString('en-PH') } } }
            }
        });

        // Table
        const tbody = document.querySelector('#tbl-cashier tbody');
        tbody.innerHTML = data.map(r => `
            <tr>
                <td>${r.cashier}</td>
                <td class="text-right">${fmtNum(r.transactions)}</td>
                <td class="text-right">${fmt(r.total_revenue)}</td>
                <td class="text-right">${fmt(r.avg_transaction)}</td>
                <td class="text-right">${fmt(r.total_tax)}</td>
            </tr>`).join('');
    }

    // ── By Product ───────────────────────────────────────────────────────────
    async function loadProduct() {
        const res = await fetch(API + '?action=top_products&days=' + DAYS).then(r => r.json());
        if (!res.success || !res.data.length) {
            document.querySelector('#tbl-product tbody').innerHTML =
                '<tr><td colspan="6" class="text-center text-muted">No data for this period.</td></tr>';
            return;
        }
        const data = res.data;

        // Bar chart
        destroyChart('product');
        charts['product'] = new Chart(document.getElementById('chart-product'), {
            type: 'bar',
            data: {
                labels: data.map(r => r.name),
                datasets: [{
                    label: 'Qty Sold',
                    data: data.map(r => parseFloat(r.total_qty) || 0),
                    backgroundColor: PALETTE.slice(0, data.length),
                    borderRadius: 4,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { ticks: { stepSize: 1 } } }
            }
        });

        // Table
        const tbody = document.querySelector('#tbl-product tbody');
        tbody.innerHTML = data.map((r, i) => `
            <tr>
                <td class="text-center">${i + 1}</td>
                <td>${r.name}</td>
                <td><small class="text-muted">${r.barcode || '—'}</small></td>
                <td class="text-right">${fmtNum(r.total_qty)}</td>
                <td class="text-right">${fmt(r.avg_price)}</td>
                <td class="text-right">${fmt(r.total_amount)}</td>
            </tr>`).join('');
    }

    // ── Init ─────────────────────────────────────────────────────────────────
    const initTab = '<?php echo $active_tab; ?>';
    loadedTabs.add(initTab); // mark as loaded so switchTab won't double-load
    // Actually load data for the initial tab
    if (initTab === 'overview') loadOverview();
    else if (initTab === 'cashier') loadCashier();
    else if (initTab === 'product') loadProduct();
    // vat/voids need no JS loading

})();
</script>
</body>
</html>
