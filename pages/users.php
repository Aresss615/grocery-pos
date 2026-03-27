<?php
/**
 * J&J Grocery POS - User Management
 * Admin panel for managing staff users
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn()) redirect(BASE_URL . '/index.php');
if (!hasRole('admin')) redirect(BASE_URL . '/pages/dashboard.php');

checkSessionTimeout();

$page_title = 'User Management';

// ── AJAX POST handlers (return JSON) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    requireCsrf(true);

    $action   = $_POST['action'] ?? '';
    $response = ['success' => false, 'error' => ''];

    if ($action === 'add') {
        $name        = sanitize($_POST['name'] ?? '');
        $username    = sanitize($_POST['username'] ?? '');
        $password    = $_POST['password'] ?? '';
        $role        = sanitize($_POST['role'] ?? 'cashier');
        $email       = sanitize($_POST['email'] ?? '');
        $phone       = sanitize($_POST['phone'] ?? '');
        $employee_id = sanitize($_POST['employee_id'] ?? '');

        if (empty($name) || empty($username) || empty($password)) {
            $response['error'] = 'Name, username and password are required';
        } elseif (strlen($password) < 6) {
            $response['error'] = 'Password must be at least 6 characters';
        } else {
            $existing = $db->fetchOne("SELECT id FROM users WHERE username = ? LIMIT 1", [$username]);
            if ($existing) {
                $response['error'] = 'Username already exists';
            } else {
                $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
                $ok = $db->execute(
                    "INSERT INTO users (name, email, phone, employee_id, username, password, role, active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
                    [$name, $email ?: null, $phone ?: null, $employee_id ?: null, $username, $hashed, $role]
                );
                if ($ok) { $response['success'] = true; $response['message'] = 'User added'; }
                else      { $response['error'] = 'Error adding user'; }
            }
        }

    } elseif ($action === 'edit') {
        $user_id     = intval($_POST['user_id'] ?? 0);
        $name        = sanitize($_POST['name'] ?? '');
        $role        = sanitize($_POST['role'] ?? 'cashier');
        $email       = sanitize($_POST['email'] ?? '');
        $phone       = sanitize($_POST['phone'] ?? '');
        $employee_id = sanitize($_POST['employee_id'] ?? '');

        if (!$user_id || empty($name)) {
            $response['error'] = 'Invalid input';
        } else {
            $ok = $db->execute(
                "UPDATE users SET name=?, email=?, phone=?, employee_id=?, role=? WHERE id=? AND id != ?",
                [$name, $email ?: null, $phone ?: null, $employee_id ?: null, $role, $user_id, $_SESSION['user_id']]
            );
            if ($ok) { $response['success'] = true; $response['message'] = 'User updated'; }
            else      { $response['error'] = 'Error updating user'; }
        }

    } elseif ($action === 'change_password') {
        $user_id      = intval($_POST['user_id'] ?? 0);
        $new_password = $_POST['new_password'] ?? '';

        if (strlen($new_password) < 6) {
            $response['error'] = 'Password must be at least 6 characters';
        } elseif ($user_id === (int)$_SESSION['user_id']) {
            $response['error'] = 'Cannot change your own password here';
        } else {
            $hashed = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 10]);
            $ok = $db->execute("UPDATE users SET password=? WHERE id=?", [$hashed, $user_id]);
            if ($ok) { $response['success'] = true; $response['message'] = 'Password changed'; }
            else      { $response['error'] = 'Error changing password'; }
        }

    } elseif ($action === 'delete') {
        $user_id = intval($_POST['user_id'] ?? 0);
        if (!$user_id || $user_id === (int)$_SESSION['user_id']) {
            $response['error'] = 'Cannot delete this user';
        } else {
            $ok = $db->execute("UPDATE users SET active=0 WHERE id=?", [$user_id]);
            if ($ok) { $response['success'] = true; $response['message'] = 'User deactivated'; }
            else      { $response['error'] = 'Error deactivating user'; }
        }
    }

    echo json_encode($response);
    exit;
}

// ── Page data ────────────────────────────────────────────────
$users = $db->fetchAll(
    "SELECT id, name, email, phone, employee_id, username, role, created_at
     FROM users WHERE active = 1 ORDER BY name ASC"
);

$role_labels = [
    'admin'             => 'Admin',
    'manager'           => 'Manager',
    'cashier'           => 'Cashier',
    'inventory_checker' => 'Inv. Checker',
];

$csrf = getCsrfToken();
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
        .role-badge {
            display: inline-flex; align-items: center;
            padding: 3px 10px; border-radius: var(--radius-pill);
            font-size: .72rem; font-weight: 700; letter-spacing: .3px;
            text-transform: uppercase; white-space: nowrap;
            background: var(--c-info-light); color: var(--c-info);
        }
        .role-badge.role-admin    { background: var(--c-danger-light); color: var(--c-danger); }
        .role-badge.role-manager  { background: var(--c-warning-light); color: var(--c-warning); }
        .role-badge.role-cashier  { background: var(--c-success-light); color: var(--c-success); }
    </style>
</head>
<body class="dashboard-page">
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <div>
                <h1><?php echo $page_title; ?></h1>
                <p class="text-muted"><?php echo count($users); ?> active users</p>
            </div>
            <button class="btn btn-primary" onclick="openAddModal()">+ Add User</button>
        </div>

        <?php displayMessage(); ?>

        <div class="card card-flat">
            <table class="data-table" id="usersTable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Emp. ID</th>
                        <th>Role</th>
                        <th>Joined</th>
                        <th style="width:180px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="8" class="text-center text-muted" style="padding:40px">No users found</td></tr>
                    <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr id="user-row-<?php echo $u['id']; ?>">
                            <td><strong><?php echo htmlspecialchars($u['name']); ?></strong></td>
                            <td><code><?php echo htmlspecialchars($u['username']); ?></code></td>
                            <td><?php echo $u['email'] ? htmlspecialchars($u['email']) : '<span class="text-muted">—</span>'; ?></td>
                            <td><?php echo $u['phone'] ? htmlspecialchars($u['phone']) : '<span class="text-muted">—</span>'; ?></td>
                            <td><?php echo $u['employee_id'] ? htmlspecialchars($u['employee_id']) : '<span class="text-muted">—</span>'; ?></td>
                            <td>
                                <span class="role-badge role-<?php echo $u['role']; ?>">
                                    <?php echo $role_labels[$u['role']] ?? ucfirst(str_replace('_',' ',$u['role'])); ?>
                                </span>
                            </td>
                            <td class="text-muted text-sm"><?php echo formatDate($u['created_at']); ?></td>
                            <td>
                                <button class="btn btn-xs btn-secondary" onclick="openEditModal(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['name'])); ?>','<?php echo $u['role']; ?>','<?php echo htmlspecialchars(addslashes($u['email'] ?? '')); ?>','<?php echo htmlspecialchars(addslashes($u['phone'] ?? '')); ?>','<?php echo htmlspecialchars(addslashes($u['employee_id'] ?? '')); ?>')">Edit</button>
                                <button class="btn btn-xs btn-ghost" onclick="openPasswordModal(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['name'])); ?>')">Password</button>
                                <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                                <button class="btn btn-xs btn-danger" onclick="openDeleteModal(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['name'])); ?>')">Remove</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal" id="addModal">
        <div class="modal-content" style="max-width:480px">
            <div class="modal-header">
                <h2>Add New User</h2>
                <button class="modal-close" onclick="closeModal('addModal')">×</button>
            </div>
            <form class="modal-form" style="padding:var(--space-5) var(--space-6)" onsubmit="submitForm(event,'addModal')">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" id="addName" name="name" class="form-input" required autofocus>
                    </div>
                    <div class="form-group">
                        <label>Employee ID</label>
                        <input type="text" id="addEmpId" name="employee_id" class="form-input" placeholder="EMP-001">
                    </div>
                </div>
                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" id="addUsername" name="username" class="form-input" required>
                </div>
                <div class="form-group">
                    <label>Password * (min 6 chars)</label>
                    <input type="password" id="addPassword" name="password" class="form-input" required minlength="6">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="addEmail" name="email" class="form-input">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" id="addPhone" name="phone" class="form-input" placeholder="+63-9XX-XXX-XXXX">
                    </div>
                </div>
                <div class="form-group">
                    <label>Role *</label>
                    <select id="addRole" name="role" class="form-input" required>
                        <option value="cashier">Cashier</option>
                        <option value="inventory_checker">Inventory Checker</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Add User</button>
                    <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content" style="max-width:480px">
            <div class="modal-header">
                <h2>Edit User</h2>
                <button class="modal-close" onclick="closeModal('editModal')">×</button>
            </div>
            <form class="modal-form" style="padding:var(--space-5) var(--space-6)" onsubmit="submitForm(event,'editModal')">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" id="editUserId" name="user_id">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" id="editName" name="name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label>Employee ID</label>
                        <input type="text" id="editEmpId" name="employee_id" class="form-input">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="editEmail" name="email" class="form-input">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" id="editPhone" name="phone" class="form-input">
                    </div>
                </div>
                <div class="form-group">
                    <label>Role *</label>
                    <select id="editRole" name="role" class="form-input" required>
                        <option value="cashier">Cashier</option>
                        <option value="inventory_checker">Inventory Checker</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Update</button>
                    <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal" id="passwordModal">
        <div class="modal-content" style="max-width:400px">
            <div class="modal-header">
                <h2>Change Password — <span id="pwdUserName"></span></h2>
                <button class="modal-close" onclick="closeModal('passwordModal')">×</button>
            </div>
            <form class="modal-form" style="padding:var(--space-5) var(--space-6)" onsubmit="submitForm(event,'passwordModal')">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" id="pwdUserId" name="user_id">
                <div class="form-group">
                    <label>New Password (min 6 chars)</label>
                    <input type="password" id="newPassword" name="new_password" class="form-input" required minlength="6">
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" id="confirmPassword" name="confirm_password" class="form-input" required minlength="6">
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Change Password</button>
                    <button type="button" class="btn btn-ghost" onclick="closeModal('passwordModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content" style="max-width:380px">
            <div class="modal-header">
                <h2>Remove User</h2>
                <button class="modal-close" onclick="closeModal('deleteModal')">×</button>
            </div>
            <div class="modal-body">
                <p>Remove <strong id="deleteUserName"></strong>? They will be deactivated and cannot log in.</p>
            </div>
            <form class="modal-form" style="padding:0 var(--space-6) var(--space-5)" onsubmit="submitForm(event,'deleteModal')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" id="deleteUserId" name="user_id">
                <div class="modal-actions">
                    <button type="submit" class="btn btn-danger">Remove</button>
                    <button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddModal() {
            ['addName','addUsername','addPassword','addEmail','addPhone','addEmpId'].forEach(id => document.getElementById(id).value = '');
            document.getElementById('addRole').value = 'cashier';
            document.getElementById('addModal').classList.add('active');
            setTimeout(() => document.getElementById('addName').focus(), 50);
        }

        function openEditModal(id, name, role, email, phone, empId) {
            document.getElementById('editUserId').value = id;
            document.getElementById('editName').value = name;
            document.getElementById('editRole').value = role;
            document.getElementById('editEmail').value = email;
            document.getElementById('editPhone').value = phone;
            document.getElementById('editEmpId').value = empId;
            document.getElementById('editModal').classList.add('active');
            setTimeout(() => document.getElementById('editName').focus(), 50);
        }

        function openPasswordModal(id, name) {
            document.getElementById('pwdUserId').value = id;
            document.getElementById('pwdUserName').textContent = name;
            document.getElementById('newPassword').value = '';
            document.getElementById('confirmPassword').value = '';
            document.getElementById('passwordModal').classList.add('active');
            setTimeout(() => document.getElementById('newPassword').focus(), 50);
        }

        function openDeleteModal(id, name) {
            document.getElementById('deleteUserId').value = id;
            document.getElementById('deleteUserName').textContent = name;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function submitForm(event, modalId) {
            event.preventDefault();
            const form = event.target;
            const action = form.querySelector('[name="action"]').value;

            if (action === 'change_password') {
                const p1 = document.getElementById('newPassword').value;
                const p2 = document.getElementById('confirmPassword').value;
                if (p1 !== p2) { alert('Passwords do not match'); return; }
            }

            const btn = form.querySelector('[type="submit"]');
            btn.disabled = true;

            fetch('<?php echo BASE_URL; ?>/pages/users.php', { method: 'POST', body: new FormData(form) })
                .then(r => r.json())
                .then(d => {
                    btn.disabled = false;
                    if (d.success) { closeModal(modalId); location.reload(); }
                    else alert(d.error || 'Operation failed');
                })
                .catch(() => { btn.disabled = false; alert('Network error'); });
        }

        document.querySelectorAll('.modal').forEach(m => {
            m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); });
        });
    </script>
</body>
</html>
