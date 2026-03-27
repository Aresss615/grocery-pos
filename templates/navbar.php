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
            <img src="<?php echo IMG_URL; ?>/logo-nobg.png" alt="J&J" style="height:33px;margin-right:9px;">
            <a href="<?php echo BASE_URL; ?>/pages/dashboard.php" class="brand-name">J&amp;J Grocery</a>
        </div>

        <!-- Menu -->
        <div class="navbar-menu">

            <a href="<?php echo BASE_URL; ?>/pages/dashboard.php"
               class="nav-link <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                <span class="nav-icon">📊</span> Dashboard
            </a>

            <?php if (hasRole('cashier') || hasRole('admin')): ?>
            <a href="<?php echo BASE_URL; ?>/pages/pos.php"
               class="nav-link <?php echo in_array($current_page, ['pos.php','sales.php']) ? 'active' : ''; ?>">
                <span class="nav-icon">🖥️</span> POS
            </a>
            <?php endif; ?>

            <?php if (hasRole('admin') || hasRole('manager')): ?>
            <a href="<?php echo BASE_URL; ?>/pages/products.php"
               class="nav-link <?php echo $current_page === 'products.php' ? 'active' : ''; ?>">
                <span class="nav-icon">📦</span> Products
            </a>
            <?php endif; ?>

            <?php if (hasRole('admin') || hasRole('inventory_checker') || hasRole('manager')): ?>
            <a href="<?php echo BASE_URL; ?>/pages/inventory.php"
               class="nav-link <?php echo $current_page === 'inventory.php' ? 'active' : ''; ?>">
                <span class="nav-icon">🗂️</span> Inventory
            </a>
            <?php endif; ?>

            <?php if (hasRole('admin')): ?>
            <a href="<?php echo BASE_URL; ?>/pages/master-data.php"
               class="nav-link <?php echo $current_page === 'master-data.php' ? 'active' : ''; ?>">
                <span class="nav-icon">🏷️</span> Master Data
            </a>
            <a href="<?php echo BASE_URL; ?>/pages/users.php"
               class="nav-link <?php echo $current_page === 'users.php' ? 'active' : ''; ?>">
                <span class="nav-icon">👥</span> Users
            </a>
            <?php endif; ?>

            <?php if (hasRole('manager') || hasRole('admin')): ?>
            <a href="<?php echo BASE_URL; ?>/pages/manager.php"
               class="nav-link <?php echo $current_page === 'manager.php' ? 'active' : ''; ?>">
                <span class="nav-icon">💼</span> Manager
            </a>
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
