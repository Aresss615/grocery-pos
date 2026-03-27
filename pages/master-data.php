<?php
/**
 * Master Data Management - Categories & Suppliers
 * Admin-only page for managing product categories and suppliers
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn() || !hasRole('admin')) {
    redirect(BASE_URL . '/index.php');
}

checkSessionTimeout();

$page_title = 'Master Data';

$categories = $db->fetchAll("SELECT id, name FROM categories ORDER BY name");
$suppliers   = $db->fetchAll("SELECT id, name, contact, email FROM suppliers ORDER BY name");
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> — <?php echo $page_title; ?></title>
    <script>(function(){var t=localStorage.getItem('pos_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>/main.css">
    <style>
        .tabs-container {
            display: flex;
            gap: 4px;
            margin-bottom: var(--space-6);
            border-bottom: 2px solid var(--c-border);
            padding-bottom: 0;
        }
        .tab-btn {
            padding: 10px 20px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            cursor: pointer;
            font-weight: 600;
            font-size: .88rem;
            color: var(--c-text-soft);
            font-family: var(--font-sans);
            transition: var(--transition);
        }
        .tab-btn:hover { color: var(--c-text); }
        .tab-btn.active { color: var(--c-primary); border-bottom-color: var(--c-primary); }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }
    </style>
</head>
<body class="dashboard-page">
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <div>
                <h1><?php echo $page_title; ?></h1>
                <p class="text-muted">Manage product categories and suppliers</p>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs-container">
            <button class="tab-btn active" onclick="switchTab(event,'categories')">Categories
                <span class="badge badge-primary" style="margin-left:6px"><?php echo count($categories); ?></span>
            </button>
            <button class="tab-btn" onclick="switchTab(event,'suppliers')">Suppliers
                <span class="badge badge-primary" style="margin-left:6px"><?php echo count($suppliers); ?></span>
            </button>
        </div>

        <!-- Categories Tab -->
        <div id="categories" class="tab-pane active">
            <div class="card card-flat" style="margin-bottom:0">
                <div style="display:flex;justify-content:flex-end;margin-bottom:var(--space-4)">
                    <button class="btn btn-primary" onclick="openCategoryModal('new')">+ Add Category</button>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Category Name</th>
                            <th style="width:140px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                            <tr><td colspan="2" class="text-center text-muted" style="padding:40px">No categories yet</td></tr>
                        <?php else: ?>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($cat['name']); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-secondary" onclick="openCategoryModal('edit',<?php echo $cat['id']; ?>,'<?php echo htmlspecialchars(addslashes($cat['name'])); ?>')">Edit</button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteCategory(<?php echo $cat['id']; ?>)">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Suppliers Tab -->
        <div id="suppliers" class="tab-pane">
            <div class="card card-flat" style="margin-bottom:0">
                <div style="display:flex;justify-content:flex-end;margin-bottom:var(--space-4)">
                    <button class="btn btn-primary" onclick="openSupplierModal('new')">+ Add Supplier</button>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Supplier Name</th>
                            <th>Contact</th>
                            <th>Email</th>
                            <th style="width:140px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($suppliers)): ?>
                            <tr><td colspan="4" class="text-center text-muted" style="padding:40px">No suppliers yet</td></tr>
                        <?php else: ?>
                        <?php foreach ($suppliers as $supp): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($supp['name']); ?></td>
                                <td><?php echo htmlspecialchars($supp['contact'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($supp['email'] ?? '—'); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-secondary" onclick="openSupplierModal('edit',<?php echo $supp['id']; ?>,'<?php echo htmlspecialchars(addslashes($supp['name'])); ?>','<?php echo htmlspecialchars(addslashes($supp['contact'] ?? '')); ?>','<?php echo htmlspecialchars(addslashes($supp['email'] ?? '')); ?>')">Edit</button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteSupplier(<?php echo $supp['id']; ?>)">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Category Modal -->
    <div class="modal" id="categoryModal">
        <div class="modal-content" style="max-width:420px">
            <div class="modal-header">
                <h2 id="categoryModalTitle">Add Category</h2>
                <button class="modal-close" onclick="closeCategoryModal()">×</button>
            </div>
            <form id="categoryForm" onsubmit="saveCategory(event)" class="modal-form" style="padding:var(--space-5) var(--space-6)">
                <input type="hidden" id="categoryId">
                <div class="form-group">
                    <label for="categoryName">Category Name</label>
                    <input type="text" id="categoryName" class="form-input" required autofocus>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-ghost" onclick="closeCategoryModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Supplier Modal -->
    <div class="modal" id="supplierModal">
        <div class="modal-content" style="max-width:460px">
            <div class="modal-header">
                <h2 id="supplierModalTitle">Add Supplier</h2>
                <button class="modal-close" onclick="closeSupplierModal()">×</button>
            </div>
            <form id="supplierForm" onsubmit="saveSupplier(event)" class="modal-form" style="padding:var(--space-5) var(--space-6)">
                <input type="hidden" id="supplierId">
                <div class="form-group">
                    <label for="supplierName">Supplier Name</label>
                    <input type="text" id="supplierName" class="form-input" required>
                </div>
                <div class="form-group">
                    <label for="supplierContact">Contact Number</label>
                    <input type="text" id="supplierContact" class="form-input" placeholder="+63-9XX-XXX-XXXX">
                </div>
                <div class="form-group">
                    <label for="supplierEmail">Email</label>
                    <input type="email" id="supplierEmail" class="form-input" placeholder="supplier@example.com">
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-ghost" onclick="closeSupplierModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function switchTab(e, tabName) {
            e.preventDefault();
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(tabName).classList.add('active');
            e.currentTarget.classList.add('active');
        }

        function openCategoryModal(mode, id = null, name = '') {
            document.getElementById('categoryId').value = id || '';
            document.getElementById('categoryName').value = name;
            document.getElementById('categoryModalTitle').textContent = mode === 'new' ? 'Add Category' : 'Edit Category';
            document.getElementById('categoryModal').classList.add('active');
            setTimeout(() => document.getElementById('categoryName').focus(), 50);
        }
        function closeCategoryModal() { document.getElementById('categoryModal').classList.remove('active'); }

        function saveCategory(event) {
            event.preventDefault();
            const id = document.getElementById('categoryId').value;
            const name = document.getElementById('categoryName').value;
            const fd = new FormData();
            fd.append('type', 'category');
            fd.append('action', id ? 'update' : 'create');
            fd.append('name', name);
            if (id) fd.append('id', id);
            fetch('<?php echo API_URL; ?>/master-data.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => { if (d.success) { closeCategoryModal(); location.reload(); } else alert('Error: ' + (d.error || 'Failed')); });
        }

        function deleteCategory(id) {
            if (!confirm('Delete this category? Products in this category will be uncategorized.')) return;
            const fd = new FormData();
            fd.append('type', 'category'); fd.append('action', 'delete'); fd.append('id', id);
            fetch('<?php echo API_URL; ?>/master-data.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => { if (d.success) location.reload(); else alert('Error: ' + (d.error || 'Failed')); });
        }

        function openSupplierModal(mode, id = null, name = '', contact = '', email = '') {
            document.getElementById('supplierId').value = id || '';
            document.getElementById('supplierName').value = name;
            document.getElementById('supplierContact').value = contact;
            document.getElementById('supplierEmail').value = email;
            document.getElementById('supplierModalTitle').textContent = mode === 'new' ? 'Add Supplier' : 'Edit Supplier';
            document.getElementById('supplierModal').classList.add('active');
            setTimeout(() => document.getElementById('supplierName').focus(), 50);
        }
        function closeSupplierModal() { document.getElementById('supplierModal').classList.remove('active'); }

        function saveSupplier(event) {
            event.preventDefault();
            const id = document.getElementById('supplierId').value;
            const fd = new FormData();
            fd.append('type', 'supplier');
            fd.append('action', id ? 'update' : 'create');
            fd.append('name', document.getElementById('supplierName').value);
            fd.append('contact', document.getElementById('supplierContact').value);
            fd.append('email', document.getElementById('supplierEmail').value);
            if (id) fd.append('id', id);
            fetch('<?php echo API_URL; ?>/master-data.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => { if (d.success) { closeSupplierModal(); location.reload(); } else alert('Error: ' + (d.error || 'Failed')); });
        }

        function deleteSupplier(id) {
            if (!confirm('Delete this supplier?')) return;
            const fd = new FormData();
            fd.append('type', 'supplier'); fd.append('action', 'delete'); fd.append('id', id);
            fetch('<?php echo API_URL; ?>/master-data.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => { if (d.success) location.reload(); else alert('Error: ' + (d.error || 'Failed')); });
        }

        document.querySelectorAll('.modal').forEach(m => {
            m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); });
        });
    </script>
</body>
</html>
