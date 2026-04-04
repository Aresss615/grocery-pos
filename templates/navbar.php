<?php
/**
 * Navigation Bar — v3
 * Includes: theme toggle, admin POS access, manager portal in main nav,
 *           removed duplicate Sales Report link.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/helpers.php';

$current_page = basename($_SERVER['PHP_SELF']);
$user = getCurrentUser();
$_biz_nav  = getBusinessSettings();
$_nav_logo = !empty($_biz_nav['business_logo'])
    ? IMG_URL . '/' . htmlspecialchars(basename($_biz_nav['business_logo']))
    : null;
$_nav_name = htmlspecialchars($_biz_nav['business_name'] ?? APP_NAME);
?>
<!-- FOUC-prevention: apply saved theme before CSS paints -->
<script>
(function(){
    var t=localStorage.getItem('pos_theme')||'light';
    document.documentElement.setAttribute('data-theme',t);
})();
</script>
<!-- Theme CSS (loaded after main.css via inline link) -->
<link rel="stylesheet" href="<?php echo CSS_URL; ?>/theme.css">

<nav class="navbar">
    <div class="navbar-container">

        <!-- Brand -->
        <div class="navbar-brand">
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo BASE_URL; ?>/pages/dashboard.php">
                <?php if ($_nav_logo): ?>
                    <img src="<?php echo $_nav_logo; ?>" alt="logo" style="height:36px;width:auto;object-fit:contain">
                <?php else: ?>
                    <span style="font-size:1.5rem">&#128722;</span>
                <?php endif; ?>
                <span class="fw-bold"><?php echo $_nav_name; ?></span>
            </a>
        </div>

        <!-- Menu -->
        <div class="navbar-menu">

            <a href="<?php echo BASE_URL; ?>/pages/dashboard.php"
               class="nav-link <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                <span class="nav-icon">📊</span> Dashboard
            </a>

            <?php if (hasAccess('pos')): ?>
            <a href="<?php echo BASE_URL; ?>/pages/pos.php"
               class="nav-link <?php echo in_array($current_page, ['pos.php','sales.php']) ? 'active' : ''; ?>">
                <span class="nav-icon">🖥️</span> POS
            </a>
            <?php endif; ?>

            <?php if (hasAccess('products')): ?>
            <a href="<?php echo BASE_URL; ?>/pages/products.php"
               class="nav-link <?php echo $current_page === 'products.php' ? 'active' : ''; ?>">
                <span class="nav-icon">📦</span> Products
            </a>
            <?php endif; ?>

            <?php if (hasAccess('inventory')): ?>
            <a href="<?php echo BASE_URL; ?>/pages/inventory.php"
               class="nav-link <?php echo $current_page === 'inventory.php' ? 'active' : ''; ?>">
                <span class="nav-icon">🗂️</span> Inventory
            </a>
            <?php endif; ?>

            <?php if (hasAccess('master-data')): ?>
            <a href="<?php echo BASE_URL; ?>/pages/master-data.php"
               class="nav-link <?php echo $current_page === 'master-data.php' ? 'active' : ''; ?>">
                <span class="nav-icon">🏷️</span> Master Data
            </a>
            <?php endif; ?>

            <?php if (hasAccess('users')): ?>
            <a href="<?php echo BASE_URL; ?>/pages/users.php"
               class="nav-link <?php echo $current_page === 'users.php' ? 'active' : ''; ?>">
                <span class="nav-icon">👥</span> Users
            </a>
            <?php endif; ?>

            <?php if (hasAccess('manager')): ?>
            <a href="<?php echo BASE_URL; ?>/pages/manager.php"
               class="nav-link <?php echo $current_page === 'manager.php' ? 'active' : ''; ?>">
                <span class="nav-icon">💼</span> Manager
            </a>
            <?php endif; ?>

            <?php if (hasAccess('reports')): ?>
            <a href="<?php echo BASE_URL; ?>/pages/reports.php"
               class="nav-link <?php echo in_array($current_page, ['reports.php','sales-report.php']) ? 'active' : ''; ?>">
                <span class="nav-icon">📈</span> Reports
            </a>
            <?php endif; ?>

        </div>

        <!-- Right side: theme toggle + user info + logout -->
        <div class="navbar-right">
            <button class="theme-toggle" onclick="toggleTheme()" title="Toggle dark / light mode">
                <span class="tt-icon" id="themeIcon">🌙</span>
            </button>
            <div class="user-info">
                <div class="user-role"><?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?></div>
                <div class="user-name"><?php echo htmlspecialchars($user['name']); ?></div>
            </div>
            <a href="<?php echo BASE_URL; ?>/auth/logout.php" class="btn btn-outline btn-sm">
                🚪 Logout
            </a>
        </div>

    </div>
</nav>

<script>
// ── Theme toggle (shared across all pages) ─────────────────
function toggleTheme() {
    var cur = document.documentElement.getAttribute('data-theme') || 'light';
    var next = cur === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('pos_theme', next);
    document.cookie = 'pos_theme=' + next + ';path=/;max-age=31536000';
    updateThemeIcon(next);
}
function updateThemeIcon(t) {
    var el = document.getElementById('themeIcon');
    if (el) el.textContent = t === 'dark' ? '☀️' : '🌙';
}
// Set correct icon on load
(function(){
    var t = document.documentElement.getAttribute('data-theme') || 'light';
    updateThemeIcon(t);
})();
</script>
