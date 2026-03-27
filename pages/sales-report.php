<?php
/**
 * Sales Report & Analytics Dashboard
 * For managers to view sales trends, product performance, and analytics
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

// Check auth - manager or admin only
if (!isLoggedIn() || !hasRole('manager') && !hasRole('admin')) {
    redirect(BASE_URL . '/index.php');
}

checkSessionTimeout();

$db = new Database();
$page_title = 'Sales Report & Analytics';

// Get all products for the product selector
$products = $db->fetchAll("SELECT id, name, barcode FROM products WHERE active = 1 ORDER BY name");

// Get all suppliers for the supplier filter
$suppliers = $db->fetchAll("SELECT id, name FROM suppliers ORDER BY name");
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - <?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>/main.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .analytics-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .filters {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: var(--shadow-sm);
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-group label {
            font-size: 12px;
            font-weight: bold;
            color: var(--color-gray);
            text-transform: uppercase;
        }

        .filter-group select,
        .filter-group input {
            padding: 10px;
            border: 1px solid var(--color-border);
            border-radius: 4px;
            font-size: 14px;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
        }

        .chart-card h3 {
            margin: 0 0 20px 0;
            color: #333;
            border-bottom: 2px solid var(--color-primary);
            padding-bottom: 10px;
        }

        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--color-primary);
        }

        .stat-label {
            font-size: 12px;
            color: var(--color-gray);
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: var(--color-primary);
            margin-bottom: 5px;
        }

        .stat-subtext {
            font-size: 12px;
            color: var(--color-gray);
        }

        .table-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
        }

        .table-card h3 {
            margin: 0 0 20px 0;
            color: #333;
            border-bottom: 2px solid var(--color-primary);
            padding-bottom: 10px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead {
            background: var(--color-bg);
        }

        .data-table th {
            padding: 12px;
            text-align: left;
            font-weight: bold;
            color: var(--color-gray);
            border-bottom: 2px solid var(--color-border);
        }

        .data-table td {
            padding: 12px;
            border-bottom: 1px solid var(--color-border);
        }

        .data-table tbody tr:hover {
            background: var(--color-accent);
        }

        .rank-badge {
            display: inline-block;
            background: var(--color-primary);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            min-width: 30px;
            text-align: center;
        }

        .product-row {
            cursor: pointer;
            transition: background 0.2s;
        }

        .product-row:hover {
            background: #f0f0f0;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: var(--color-gray);
            font-size: 18px;
        }

        .page-header {
            background: linear-gradient(135deg, #D32F2F 0%, #A71E1E 100%);
            color: white;
            padding: 30px 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .page-header h1 {
            margin: 0;
            font-size: 32px;
        }

        .page-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
        }

        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }

            .filters {
                flex-direction: column;
            }

            .chart-container {
                height: 250px;
            }
        }
    </style>
</head>
<body class="dashboard-page">
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="analytics-container">
        <!-- PAGE HEADER -->
        <div class="page-header">
            <h1>📊 Sales Report & Analytics</h1>
            <p>Monitor sales performance, trends, and product insights</p>
        </div>

        <!-- FILTERS -->
        <div class="filters">
            <div class="filter-group">
                <label>Time Period</label>
                <select id="timePeriod" onchange="loadAnalytics()">
                    <option value="7">Last 7 Days</option>
                    <option value="30" selected>Last 30 Days</option>
                    <option value="90">Last 90 Days</option>
                    <option value="365">Last Year</option>
                </select>
            </div>

            <div class="filter-group" style="min-width: 200px;">
                <label>📦 Supplier (Primary Filter)</label>
                <select id="supplierFilter" onchange="loadAnalytics()">
                    <option value="">-- All Suppliers --</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo $supplier['id']; ?>">
                            <?php echo htmlspecialchars($supplier['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>🏷️ Product (Optional)</label>
                <select id="productSelect" onchange="loadAnalytics()">
                    <option value="">-- All Products --</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?php echo $product['id']; ?>">
                            <?php echo htmlspecialchars($product['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex: 1;"></div>

            <div class="filter-group">
                <label>&nbsp;</label>
                <button class="btn btn-secondary" onclick="exportReport()">📥 Export Report</button>
            </div>
        </div>

        <!-- SUMMARY STATS -->
        <div class="stats-grid" id="statsGrid">
            <div class="loading">Loading statistics...</div>
        </div>

        <!-- CHARTS -->
        <div class="charts-grid">
            <!-- Daily Sales Trend -->
            <div class="chart-card">
                <h3>📈 Daily Sales Trend</h3>
                <div class="chart-container">
                    <canvas id="dailySalesChart"></canvas>
                </div>
            </div>

            <!-- Top Selling Products -->
            <div class="chart-card">
                <h3>🏆 Top Selling Products (by Quantity)</h3>
                <div class="chart-container">
                    <canvas id="topProductsChart"></canvas>
                </div>
            </div>

            <!-- Category Breakdown -->
            <div class="chart-card">
                <h3>📦 Sales by Category</h3>
                <div class="chart-container">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>

            <!-- Pricing Tier Breakdown -->
            <div class="chart-card">
                <h3>💰 Sales by Pricing Tier (Retail/Pack/Wholesale)</h3>
                <div class="chart-container">
                    <canvas id="tierChart"></canvas>
                </div>
            </div>

            <!-- Supplier Breakdown -->
            <div class="chart-card">
                <h3>🏢 Sales by Supplier</h3>
                <div class="chart-container">
                    <canvas id="supplierChart"></canvas>
                </div>
            </div>

            <!-- Payment Methods -->
            <div class="chart-card">
                <h3>💳 Payment Methods</h3>
                <div class="chart-container">
                    <canvas id="paymentChart"></canvas>
                </div>
            </div>

            <!-- Monthly Comparison -->
            <div class="chart-card">
                <h3>📊 Monthly Sales Comparison</h3>
                <div class="chart-container">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>

            <!-- Product Timeline -->
            <div class="chart-card">
                <h3>⏰ Product Sales Timeline</h3>
                <div class="chart-container">
                    <canvas id="productTimelineChart"></canvas>
                </div>
            </div>
        </div>

        <!-- TOP PRODUCTS TABLE -->
        <div class="table-card">
            <h3>🏆 Top 15 Products by Revenue</h3>
            <table class="data-table" id="topProductsTable">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Product Name</th>
                        <th>Barcode</th>
                        <th>Qty Sold</th>
                        <th>Avg Price</th>
                        <th>Total Revenue</th>
                        <th># Sales</th>
                    </tr>
                </thead>
                <tbody id="topProductsBody">
                    <tr>
                        <td colspan="7" class="loading">Loading...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        const charts = {};

        async function loadAnalytics() {
            const days = document.getElementById('timePeriod').value;
            const supplierId = document.getElementById('supplierFilter').value;
            const productId = document.getElementById('productSelect').value;
            
            // Load summary stats
            let summaryUrl = `<?php echo API_URL; ?>/sales-analytics.php?action=summary&days=${days}`;
            if (supplierId) summaryUrl += `&supplier_id=${supplierId}`;
            if (productId) summaryUrl += `&product_id=${productId}`;
            
            fetch(summaryUrl)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderStats(data.data);
                    }
                });

            // Load daily sales
            let dailyUrl = `<?php echo API_URL; ?>/sales-analytics.php?action=daily_sales&days=${days}`;
            if (supplierId) dailyUrl += `&supplier_id=${supplierId}`;
            if (productId) dailyUrl += `&product_id=${productId}`;
            
            fetch(dailyUrl)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderDailySalesChart(data.data);
                    }
                });

            // Load top products
            let topUrl = `<?php echo API_URL; ?>/sales-analytics.php?action=top_products&days=${days}`;
            if (supplierId) topUrl += `&supplier_id=${supplierId}`;
            if (productId) topUrl += `&product_id=${productId}`;
            
            fetch(topUrl)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderTopProductsChart(data.data);
                        renderTopProductsTable(data.data);
                    }
                });

            // Load categories
            let catUrl = `<?php echo API_URL; ?>/sales-analytics.php?action=category_breakdown&days=${days}`;
            if (supplierId) catUrl += `&supplier_id=${supplierId}`;
            if (productId) catUrl += `&product_id=${productId}`;
            
            fetch(catUrl)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderCategoryChart(data.data);
                    }
                });

            // Load pricing tiers
            let tierUrl = `<?php echo API_URL; ?>/sales-analytics.php?action=pricing_tier_breakdown&days=${days}`;
            if (supplierId) tierUrl += `&supplier_id=${supplierId}`;
            if (productId) tierUrl += `&product_id=${productId}`;
            
            fetch(tierUrl)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderTierChart(data.data);
                    }
                });

            // Load suppliers (with optional filter)
            let supplierUrl = `<?php echo API_URL; ?>/sales-analytics.php?action=supplier_breakdown&days=${days}`;
            if (supplierId) {
                supplierUrl += `&supplier_id=${supplierId}`;
            }
            if (productId) {
                supplierUrl += `&product_id=${productId}`;
            }
            fetch(supplierUrl)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderSupplierChart(data.data);
                    }
                });

            // Load payment methods
            fetch(`<?php echo API_URL; ?>/sales-analytics.php?action=payment_breakdown&days=${days}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderPaymentChart(data.data);
                    }
                });

            // Load monthly sales
            fetch(`<?php echo API_URL; ?>/sales-analytics.php?action=monthly_sales&months=12`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderMonthlyChart(data.data);
                    }
                });
        }

        function renderStats(data) {
            const html = `
                <div class="stat-card">
                    <div class="stat-label">Total Sales</div>
                    <div class="stat-value">₱${parseFloat(data.total_sales || 0).toFixed(2)}</div>
                    <div class="stat-subtext">${data.total_transactions || 0} transactions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Tax</div>
                    <div class="stat-value">₱${parseFloat(data.total_tax || 0).toFixed(2)}</div>
                    <div class="stat-subtext">VAT @ 12%</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Average Transaction</div>
                    <div class="stat-value">₱${parseFloat(data.avg_transaction || 0).toFixed(2)}</div>
                    <div class="stat-subtext">Avg per sale</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Highest Transaction</div>
                    <div class="stat-value">₱${parseFloat(data.highest_transaction || 0).toFixed(2)}</div>
                    <div class="stat-subtext">Peak sale amount</div>
                </div>
            `;
            document.getElementById('statsGrid').innerHTML = html;
        }

        function renderDailySalesChart(data) {
            const ctx = document.getElementById('dailySalesChart').getContext('2d');
            if (charts.daily) charts.daily.destroy();
            
            charts.daily = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.map(d => d.sale_date),
                    datasets: [{
                        label: 'Daily Sales',
                        data: data.map(d => parseFloat(d.daily_total)),
                        borderColor: '#E53935',
                        backgroundColor: 'rgba(229, 57, 53, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true, position: 'top' }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toFixed(0);
                                }
                            }
                        }
                    }
                }
            });
        }

        function renderTopProductsChart(data) {
            const ctx = document.getElementById('topProductsChart').getContext('2d');
            if (charts.topProducts) charts.topProducts.destroy();
            
            charts.topProducts = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.map(p => p.name.substring(0, 15)),
                    datasets: [{
                        label: 'Quantity Sold',
                        data: data.map(p => p.total_qty),
                        backgroundColor: '#E53935',
                        borderColor: '#D32F2F',
                        borderWidth: 1
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        }

        function renderCategoryChart(data) {
            const ctx = document.getElementById('categoryChart').getContext('2d');
            if (charts.category) charts.category.destroy();
            
            const colors = ['#E53935', '#1E3A8A', '#388E3C', '#FBC02D', '#2196F3'];
            
            charts.category = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: data.map(c => c.category || 'Uncategorized'),
                    datasets: [{
                        data: data.map(c => parseFloat(c.total_revenue)),
                        backgroundColor: colors.slice(0, data.length)
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }

        function renderPaymentChart(data) {
            const ctx = document.getElementById('paymentChart').getContext('2d');
            if (charts.payment) charts.payment.destroy();
            
            const colors = { 'cash': '#388E3C', 'gcash': '#2196F3', 'card': '#FBC02D' };
            
            charts.payment = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: data.map(p => p.payment_method.toUpperCase()),
                    datasets: [{
                        data: data.map(p => p.num_transactions),
                        backgroundColor: data.map(p => colors[p.payment_method] || '#999')
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }

        function renderMonthlyChart(data) {
            const ctx = document.getElementById('monthlyChart').getContext('2d');
            if (charts.monthly) charts.monthly.destroy();
            
            charts.monthly = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.map(m => m.month),
                    datasets: [{
                        label: 'Total Revenue',
                        data: data.map(m => parseFloat(m.monthly_total)),
                        backgroundColor: '#1E3A8A',
                        borderColor: '#1565C0',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toFixed(0);
                                }
                            }
                        }
                    }
                }
            });
        }

        function renderSupplierChart(data) {
            const ctx = document.getElementById('supplierChart').getContext('2d');
            if (charts.supplier) charts.supplier.destroy();
            
            const colors = ['#E53935', '#1E3A8A', '#388E3C', '#FBC02D', '#2196F3', '#FF9800'];
            
            charts.supplier = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.map(s => s.supplier || 'No Supplier'),
                    datasets: [{
                        label: 'Revenue',
                        data: data.map(s => parseFloat(s.total_revenue)),
                        backgroundColor: colors.slice(0, data.length)
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: {
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toFixed(0);
                                }
                            }
                        }
                    }
                }
            });
        }

        function renderTierChart(data) {
            const ctx = document.getElementById('tierChart').getContext('2d');
            if (charts.tier) charts.tier.destroy();
            
            const colors = ['#E53935', '#1E3A8A', '#388E3C'];
            const tierLabels = data.map(t => t.tier);
            const tierRevenue = data.map(t => parseFloat(t.tier_revenue || 0));
            
            charts.tier = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: tierLabels,
                    datasets: [{
                        label: 'Revenue by Tier',
                        data: tierRevenue,
                        backgroundColor: colors.slice(0, data.length),
                        borderColor: '#fff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                font: { size: 12 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.parsed || 0;
                                    return '₱' + value.toFixed(2);
                                }
                            }
                        }
                    }
                }
            });
        }

        function renderTopProductsTable(data) {
            let html = '';
            data.forEach((p, idx) => {
                html += `
                    <tr class="product-row">
                        <td><span class="rank-badge">#${idx + 1}</span></td>
                        <td>${p.name}</td>
                        <td>${p.barcode}</td>
                        <td>${p.total_qty}</td>
                        <td>₱${parseFloat(p.avg_price).toFixed(2)}</td>
                        <td><strong>₱${parseFloat(p.total_amount).toFixed(2)}</strong></td>
                        <td>${p.num_sales}</td>
                    </tr>
                `;
            });
            document.getElementById('topProductsBody').innerHTML = html;
        }

        function loadProductTimeline() {
            const productId = document.getElementById('productSelect').value;
            if (!productId) {
                alert('Please select a product');
                return;
            }

            fetch(`<?php echo API_URL; ?>/sales-analytics.php?action=product_timeline&product_id=${productId}&months=12`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderProductTimelineChart(data.data);
                    }
                });
        }

        function renderProductTimelineChart(data) {
            const ctx = document.getElementById('productTimelineChart').getContext('2d');
            if (charts.productTimeline) charts.productTimeline.destroy();
            
            charts.productTimeline = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.map(d => d.month),
                    datasets: [{
                        label: 'Quantity Sold',
                        data: data.map(d => d.qty_sold),
                        borderColor: '#388E3C',
                        backgroundColor: 'rgba(56, 142, 60, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Revenue',
                        data: data.map(d => parseFloat(d.revenue)),
                        borderColor: '#E53935',
                        backgroundColor: 'rgba(229, 57, 53, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        yAxisID: 'y1'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: true }
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: { display: true, text: 'Quantity' }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: { display: true, text: 'Revenue (₱)' },
                            grid: { drawOnChartArea: false }
                        }
                    }
                }
            });
        }

        function exportReport() {
            const days = document.getElementById('timePeriod').value;
            window.location.href = '<?php echo BASE_URL; ?>/pages/sales-export.php?days=' + days;
        }

        // Load analytics on page load
        window.addEventListener('load', () => {
            loadAnalytics();
        });
    </script>
</body>
</html>
?>
