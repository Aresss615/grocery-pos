<?php
/**
 * J&J Grocery POS — User Management v4
 * Tabs: Users | Roles
 * - Users tab: CRUD with role_id from roles table
 * - Roles tab: create/edit/delete custom roles with permission checkboxes
 * Requires users permission (v4) or admin role (legacy fallback).
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn()) redirect(BASE_URL . '/index.php');
checkSessionTimeout();
if (!hasAccess('users')) redirect(BASE_URL . '/pages/dashboard.php');

$db   = new Database();
$user = getCurrentUser();

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
        $role_id     = intval($_POST['role_id'] ?? 0);
        $email       = sanitize($_POST['email'] ?? '');
        $phone       = sanitize($_POST['phone'] ?? '');
        $employee_id = sanitize($_POST['employee_id'] ?? '');

        if (empty($name) || empty($username) || empty($password)) {
            $response['error'] = 'Name, username and password are required';
        } elseif (strlen($password) < 6) {
            $response['error'] = 'Password must be at least 6 characters';
        } elseif (!$role_id) {
            $response['error'] = 'Please select a role';
        } else {
            $existing = $db->fetchOne("SELECT id FROM users WHERE username = ? LIMIT 1", [$username]);
            if ($existing) {
                $response['error'] = 'Username already exists';
            } else {
                // Get role slug for legacy `role` column
                $role_row = $db->fetchOne("SELECT slug FROM roles WHERE id = ?", [$role_id]);
                $role_slug = $role_row['slug'] ?? 'cashier';

                $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
                $ok = $db->execute(
                    "INSERT INTO users (name, email, phone, employee_id, username, password, role, role_id, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)",
                    [$name, $email ?: null, $phone ?: null, $employee_id ?: null, $username, $hashed, $role_slug, $role_id]
                );
                if ($ok) {
                    logActivity($db, 'user_created', "User '$name' ($username) created with role '$role_slug'", null, 'warning');
                    $response['success'] = true;
                    $response['message'] = 'User added';
                } else {
                    $response['error'] = 'Error adding user';
                }
            }
        }

    } elseif ($action === 'edit') {
        $user_id     = intval($_POST['user_id'] ?? 0);
        $name        = sanitize($_POST['name'] ?? '');
        $role_id     = intval($_POST['role_id'] ?? 0);
        $email       = sanitize($_POST['email'] ?? '');
        $phone       = sanitize($_POST['phone'] ?? '');
        $employee_id = sanitize($_POST['employee_id'] ?? '');

        if (!$user_id || empty($name) || !$role_id) {
            $response['error'] = 'Invalid input';
        } else {
            $role_row = $db->fetchOne("SELECT slug FROM roles WHERE id = ?", [$role_id]);
            $role_slug = $role_row['slug'] ?? 'cashier';

            $ok = $db->execute(
                "UPDATE users SET name=?, email=?, phone=?, employee_id=?, role=?, role_id=? WHERE id=? AND id != ?",
                [$name, $email ?: null, $phone ?: null, $employee_id ?: null, $role_slug, $role_id, $user_id, $_SESSION['user_id']]
            );
            if ($ok) {
                logActivity($db, 'user_updated', "User '$name' (ID:$user_id) updated, role: $role_slug", $user_id, 'warning');
                $response['success'] = true;
                $response['message'] = 'User updated';
            } else {
                $response['error'] = 'Error updating user';
            }
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
            $target = $db->fetchOne("SELECT name FROM users WHERE id = ?", [$user_id]);
            $ok = $db->execute("UPDATE users SET active=0 WHERE id=?", [$user_id]);
            if ($ok) {
                logActivity($db, 'user_deleted', "User '{$target['name']}' (ID:$user_id) deactivated", $user_id, 'critical');
                $response['success'] = true;
                $response['message'] = 'User deactivated';
            } else {
                $response['error'] = 'Error deactivating user';
            }
        }
    }

    echo json_encode($response);
    exit;
}

// ── Page data ────────────────────────────────────────────────
$users = $db->fetchAll(
    "SELECT u.id, u.name, u.email, u.phone, u.employee_id, u.username, u.role, u.role_id, u.created_at,
            r.name AS role_name
     FROM users u
     LEFT JOIN roles r ON u.role_id = r.id
     WHERE u.active = 1
     ORDER BY u.name ASC"
);

// Load roles from DB
try {
    $roles = $db->fetchAll("SELECT * FROM roles ORDER BY is_system DESC, name ASC");
    foreach ($roles as &$r) {
        $perms = $db->fetchAll("SELECT permission FROM role_permissions WHERE role_id = ?", [$r['id']]);
        $r['permissions'] = array_column($perms, 'permission');
        $r['user_count'] = (int)($db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM users WHERE role_id = ? AND active = 1", [$r['id']]
        )['cnt'] ?? 0);
    }
    unset($r);
} catch (Exception $e) {
    $roles = [];
}

$all_permissions = [
    'dashboard'      => 'Dashboard',
    'pos'            => 'POS Terminal',
    'products'       => 'Products',
    'inventory'      => 'Inventory',
    'master_data'    => 'Master Data',
    'users'          => 'User Management',
    'manager_portal' => 'Manager Portal',
    'reports'        => 'Reports & Analytics',
    'settings'       => 'System Settings',
    'audit_trail'    => 'Audit Trail',
];

$active_tab = $_GET['tab'] ?? 'users';
$csrf = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> — User Management</title>
    <script>(function(){var t=localStorage.getItem('pos_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>/main.css">
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>/theme.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    .tab-nav { display:flex;gap:4px;border-bottom:2px solid var(--c-border);margin-bottom:var(--space-5);flex-wrap:wrap; }
    .tab-nav .tab-btn { padding:var(--space-3) var(--space-4);background:none;border:none;border-bottom:3px solid transparent;cursor:pointer;font-size:.85rem;font-weight:600;color:var(--c-text-soft);font-family:var(--font-sans);transition:var(--transition);margin-bottom:-2px; }
    .tab-nav .tab-btn:hover { color:var(--c-text);background:rgba(211,47,47,.06);border-radius:var(--radius-md) var(--radius-md) 0 0; }
    .tab-nav .tab-btn.active { color:var(--c-primary);border-bottom-color:var(--c-primary); }
    .tab-pane { display:none; }
    .tab-pane.active { display:block; }

    .role-badge { display:inline-flex;align-items:center;padding:3px 10px;border-radius:var(--radius-pill);font-size:.72rem;font-weight:700;letter-spacing:.3px;text-transform:uppercase;white-space:nowrap; }
    .role-badge.role-admin    { background:var(--c-danger-light);color:var(--c-danger); }
    .role-badge.role-manager  { background:var(--c-warning-light);color:var(--c-warning); }
    .role-badge.role-cashier  { background:var(--c-success-light);color:var(--c-success); }
    .role-badge.role-inventory_checker { background:var(--c-info-light);color:var(--c-info); }
    .role-badge.role-custom   { background:#e0e7ff;color:#3730a3; }
    [data-theme="dark"] .role-badge.role-custom { background:#1e1b4b;color:#a5b4fc; }

    /* Role cards */
    .role-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:var(--space-4); }
    .role-card { background:var(--c-surface);border:1.5px solid var(--c-border);border-radius:var(--radius-lg);padding:var(--space-4) var(--space-5);transition:var(--transition); }
    .role-card:hover { border-color:var(--c-primary);box-shadow:0 2px 8px rgba(0,0,0,.06); }
    .role-card-header { display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-3); }
    .role-card-name { font-weight:700;font-size:1rem; }
    .role-card-meta { font-size:.82rem;color:var(--c-text-soft);margin-bottom:var(--space-3); }
    .perm-pills { display:flex;flex-wrap:wrap;gap:4px;margin-bottom:var(--space-3); }
    .perm-pill { font-size:.7rem;font-weight:600;padding:2px 8px;border-radius:var(--radius-pill);background:var(--c-primary-fade);color:var(--c-primary); }
    .role-card-actions { display:flex;gap:var(--space-2);padding-top:var(--space-3);border-top:1px solid var(--c-border); }
    .system-badge { font-size:.68rem;font-weight:700;padding:2px 6px;border-radius:4px;background:var(--c-info-light);color:var(--c-info);text-transform:uppercase; }

    /* Permission checkbox grid */
    .perm-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:var(--space-2); }
    .perm-check { display:flex;align-items:center;gap:8px;padding:var(--space-2) var(--space-3);border-radius:var(--radius-md);border:1px solid var(--c-border);cursor:pointer;transition:var(--transition);font-size:.85rem; }
    .perm-check:hover { border-color:var(--c-primary);background:var(--c-primary-fade); }
    .perm-check input { margin:0; }
    .perm-check label { cursor:pointer;font-weight:500; }

    @media(max-width:768px) { .role-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body class="dashboard-page">
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <div>
                <h1>User Management</h1>
                <p class="text-muted"><?php echo count($users); ?> active users · <?php echo count($roles); ?> roles</p>
            </div>
            <button class="btn btn-primary" onclick="openAddModal()">+ Add User</button>
        </div>

        <?php displayMessage(); ?>

        <!-- Tab Navigation -->
        <div class="tab-nav">
            <button class="tab-btn <?php echo $active_tab==='users'?'active':''; ?>" onclick="switchTab('users',this)">
                Users <span class="badge badge-primary" style="margin-left:4px;"><?php echo count($users); ?></span>
            </button>
            <button class="tab-btn <?php echo $active_tab==='roles'?'active':''; ?>" onclick="switchTab('roles',this)">
                Roles <span class="badge badge-primary" style="margin-left:4px;"><?php echo count($roles); ?></span>
            </button>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB: USERS                                          -->
        <!-- ════════════════════════════════════════════════════ -->
        <div id="tab-users" class="tab-pane <?php echo $active_tab==='users'?'active':''; ?>">
            <div class="card card-flat">
                <div class="overflow-x">
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
                            <?php foreach ($users as $u):
                                $role_slug = $u['role'] ?? 'cashier';
                                $role_display = $u['role_name'] ?? ucfirst(str_replace('_', ' ', $role_slug));
                                $is_system_role = in_array($role_slug, ['admin','manager','cashier','inventory_checker']);
                                $badge_class = $is_system_role ? 'role-' . $role_slug : 'role-custom';
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($u['name']); ?></strong></td>
                                    <td><code><?php echo htmlspecialchars($u['username']); ?></code></td>
                                    <td><?php echo $u['email'] ? htmlspecialchars($u['email']) : '<span class="text-muted">—</span>'; ?></td>
                                    <td><?php echo $u['phone'] ? htmlspecialchars($u['phone']) : '<span class="text-muted">—</span>'; ?></td>
                                    <td><?php echo $u['employee_id'] ? htmlspecialchars($u['employee_id']) : '<span class="text-muted">—</span>'; ?></td>
                                    <td><span class="role-badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($role_display); ?></span></td>
                                    <td class="text-muted text-sm"><?php echo formatDate($u['created_at']); ?></td>
                                    <td>
                                        <button class="btn btn-xs btn-secondary" onclick="openEditModal(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['name']), ENT_QUOTES); ?>',<?php echo (int)($u['role_id'] ?? 0); ?>,'<?php echo htmlspecialchars(addslashes($u['email'] ?? ''), ENT_QUOTES); ?>','<?php echo htmlspecialchars(addslashes($u['phone'] ?? ''), ENT_QUOTES); ?>','<?php echo htmlspecialchars(addslashes($u['employee_id'] ?? ''), ENT_QUOTES); ?>')">Edit</button>
                                        <button class="btn btn-xs btn-ghost" onclick="openPasswordModal(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['name']), ENT_QUOTES); ?>')">Password</button>
                                        <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                                        <button class="btn btn-xs btn-danger" onclick="openDeleteModal(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['name']), ENT_QUOTES); ?>')">Remove</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB: ROLES                                          -->
        <!-- ════════════════════════════════════════════════════ -->
        <div id="tab-roles" class="tab-pane <?php echo $active_tab==='roles'?'active':''; ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4);">
                <p class="text-muted" style="font-size:.85rem;">System roles cannot be deleted. Custom roles can be freely created and managed.</p>
                <button class="btn btn-primary" onclick="openRoleModal('new')">+ Create Role</button>
            </div>

            <?php if (empty($roles)): ?>
                <div style="text-align:center;padding:var(--space-8) 0;color:var(--c-text-soft);">
                    <p>No roles found. Run migration_v4.sql to seed system roles.</p>
                </div>
            <?php else: ?>
            <div class="role-grid">
                <?php foreach ($roles as $r): ?>
                <div class="role-card">
                    <div class="role-card-header">
                        <div>
                            <span class="role-card-name"><?php echo htmlspecialchars($r['name']); ?></span>
                            <?php if ($r['is_system']): ?>
                                <span class="system-badge" style="margin-left:6px;">System</span>
                            <?php endif; ?>
                        </div>
                        <span style="font-size:.82rem;color:var(--c-text-soft);"><?php echo (int)$r['user_count']; ?> user<?php echo $r['user_count'] != 1 ? 's' : ''; ?></span>
                    </div>
                    <div class="perm-pills">
                        <?php foreach ($r['permissions'] as $p): ?>
                            <span class="perm-pill"><?php echo htmlspecialchars($all_permissions[$p] ?? $p); ?></span>
                        <?php endforeach; ?>
                        <?php if (empty($r['permissions'])): ?>
                            <span style="font-size:.82rem;color:var(--c-text-soft);">No permissions assigned</span>
                        <?php endif; ?>
                    </div>
                    <div class="role-card-actions">
                        <button class="btn btn-xs btn-secondary" onclick='openRoleModal("edit",<?php echo json_encode($r); ?>)'>Edit Permissions</button>
                        <?php if (!$r['is_system']): ?>
                            <button class="btn btn-xs btn-danger" onclick="deleteRole(<?php echo $r['id']; ?>,'<?php echo htmlspecialchars(addslashes($r['name']), ENT_QUOTES); ?>',<?php echo (int)$r['user_count']; ?>)">Delete</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /dashboard-container -->

    <!-- Add User Modal -->
    <div class="modal" id="addModal">
        <div class="modal-content" style="max-width:480px">
            <div class="modal-header">
                <h2>Add New User</h2>
                <button class="modal-close" onclick="closeModal('addModal')">×</button>
            </div>
            <form class="modal-form" style="padding:var(--space-5) var(--space-6)" onsubmit="submitUserForm(event,'addModal')">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group">
                        <label class="form-label">Full Name *</label>
                        <input type="text" id="addName" name="name" class="form-input" required autofocus>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Employee ID</label>
                        <input type="text" id="addEmpId" name="employee_id" class="form-input" placeholder="EMP-001">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Username *</label>
                    <input type="text" id="addUsername" name="username" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Password * (min 6 chars)</label>
                    <input type="password" id="addPassword" name="password" class="form-input" required minlength="6">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" id="addEmail" name="email" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" id="addPhone" name="phone" class="form-input" placeholder="+63-9XX-XXX-XXXX">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Role *</label>
                    <select id="addRole" name="role_id" class="form-input" required>
                        <option value="">— Select Role —</option>
                        <?php foreach ($roles as $r): ?>
                        <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?><?php echo $r['is_system'] ? '' : ' (custom)'; ?></option>
                        <?php endforeach; ?>
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
            <form class="modal-form" style="padding:var(--space-5) var(--space-6)" onsubmit="submitUserForm(event,'editModal')">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" id="editUserId" name="user_id">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group">
                        <label class="form-label">Full Name *</label>
                        <input type="text" id="editName" name="name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Employee ID</label>
                        <input type="text" id="editEmpId" name="employee_id" class="form-input">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" id="editEmail" name="email" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" id="editPhone" name="phone" class="form-input">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Role *</label>
                    <select id="editRole" name="role_id" class="form-input" required>
                        <option value="">— Select Role —</option>
                        <?php foreach ($roles as $r): ?>
                        <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?><?php echo $r['is_system'] ? '' : ' (custom)'; ?></option>
                        <?php endforeach; ?>
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
            <form class="modal-form" style="padding:var(--space-5) var(--space-6)" onsubmit="submitUserForm(event,'passwordModal')">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" id="pwdUserId" name="user_id">
                <div class="form-group">
                    <label class="form-label">New Password (min 6 chars)</label>
                    <input type="password" id="newPassword" name="new_password" class="form-input" required minlength="6">
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" id="confirmPassword" class="form-input" required minlength="6">
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
            <div style="padding:var(--space-4) var(--space-6);">
                <p>Remove <strong id="deleteUserName"></strong>? They will be deactivated and cannot log in.</p>
            </div>
            <form class="modal-form" style="padding:0 var(--space-6) var(--space-5)" onsubmit="submitUserForm(event,'deleteModal')">
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

    <!-- Role Create/Edit Modal -->
    <div class="modal" id="roleModal">
        <div class="modal-content" style="max-width:560px">
            <div class="modal-header">
                <h2 id="roleModalTitle">Create Role</h2>
                <button class="modal-close" onclick="closeModal('roleModal')">×</button>
            </div>
            <form id="roleForm" class="modal-form" style="padding:var(--space-5) var(--space-6)" onsubmit="saveRole(event)">
                <input type="hidden" id="roleId" value="">
                <div class="form-group">
                    <label class="form-label">Role Name *</label>
                    <input type="text" id="roleName" class="form-input" required placeholder="e.g. Shift Supervisor">
                </div>
                <div class="form-group">
                    <label class="form-label" style="margin-bottom:var(--space-3);">Permissions</label>
                    <div class="perm-grid" id="permGrid">
                        <?php foreach ($all_permissions as $key => $label): ?>
                        <div class="perm-check">
                            <input type="checkbox" id="perm_<?php echo $key; ?>" value="<?php echo $key; ?>">
                            <label for="perm_<?php echo $key; ?>"><?php echo $label; ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div id="roleMsg" style="display:none;margin-bottom:var(--space-3);"></div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" id="roleSaveBtn">Save Role</button>
                    <button type="button" class="btn btn-ghost" onclick="closeModal('roleModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="<?php echo JS_URL; ?>/main.js"></script>
    <script>
    const CSRF    = '<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>';
    const UAPI    = '<?php echo BASE_URL; ?>/pages/users.php';
    const RAPI    = '<?php echo BASE_URL; ?>/api/roles.php';

    // ── Tab switching ─────────────────────────────────────────
    function switchTab(name, btn) {
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('tab-' + name).classList.add('active');
        btn.classList.add('active');
        history.replaceState(null, '', '?tab=' + name);
    }

    // ── Modal helpers ─────────────────────────────────────────
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    document.querySelectorAll('.modal').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
    });

    // ── User CRUD ─────────────────────────────────────────────
    function openAddModal() {
        ['addName','addUsername','addPassword','addEmail','addPhone','addEmpId'].forEach(id => document.getElementById(id).value = '');
        document.getElementById('addRole').value = '';
        document.getElementById('addModal').classList.add('active');
        setTimeout(() => document.getElementById('addName').focus(), 50);
    }

    function openEditModal(id, name, roleId, email, phone, empId) {
        document.getElementById('editUserId').value = id;
        document.getElementById('editName').value = name;
        document.getElementById('editRole').value = roleId;
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

    function submitUserForm(event, modalId) {
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

        fetch(UAPI, { method:'POST', body: new FormData(form) })
            .then(r => r.json())
            .then(d => {
                btn.disabled = false;
                if (d.success) { closeModal(modalId); location.reload(); }
                else alert(d.error || 'Operation failed');
            })
            .catch(() => { btn.disabled = false; alert('Network error'); });
    }

    // ── Role CRUD ─────────────────────────────────────────────
    function openRoleModal(mode, role = null) {
        document.getElementById('roleId').value = '';
        document.getElementById('roleName').value = '';
        document.getElementById('roleModalTitle').textContent = 'Create Role';
        document.getElementById('roleMsg').style.display = 'none';

        // Uncheck all permissions
        document.querySelectorAll('#permGrid input[type="checkbox"]').forEach(cb => cb.checked = false);

        if (mode === 'edit' && role) {
            document.getElementById('roleId').value = role.id;
            document.getElementById('roleName').value = role.name;
            document.getElementById('roleModalTitle').textContent = 'Edit Role — ' + role.name;
            // Check existing permissions
            (role.permissions || []).forEach(p => {
                const cb = document.getElementById('perm_' + p);
                if (cb) cb.checked = true;
            });
        }

        document.getElementById('roleModal').classList.add('active');
        setTimeout(() => document.getElementById('roleName').focus(), 50);
    }

    function saveRole(e) {
        e.preventDefault();
        const id   = document.getElementById('roleId').value;
        const name = document.getElementById('roleName').value.trim();
        const perms = [];
        document.querySelectorAll('#permGrid input:checked').forEach(cb => perms.push(cb.value));

        if (!name) { alert('Role name is required'); return; }

        const btn = document.getElementById('roleSaveBtn');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        const fd = new FormData();
        fd.append('action', id ? 'update' : 'create');
        fd.append('csrf_token', CSRF);
        fd.append('name', name);
        fd.append('permissions', JSON.stringify(perms));
        if (id) fd.append('role_id', id);

        fetch(RAPI, { method:'POST', body:fd })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.textContent = 'Save Role';
                if (data.success) {
                    closeModal('roleModal');
                    location.href = '?tab=roles';
                } else {
                    const msgEl = document.getElementById('roleMsg');
                    msgEl.style.display = 'block';
                    msgEl.className = 'alert alert-danger';
                    msgEl.textContent = data.error || 'Failed to save role.';
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.textContent = 'Save Role';
                alert('Network error');
            });
    }

    function deleteRole(id, name, userCount) {
        if (userCount > 0) {
            alert('Cannot delete "' + name + '" — ' + userCount + ' user(s) are still assigned to this role. Reassign them first.');
            return;
        }
        if (!confirm('Delete role "' + name + '"? This cannot be undone.')) return;

        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('csrf_token', CSRF);
        fd.append('role_id', id);

        fetch(RAPI, { method:'POST', body:fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) location.href = '?tab=roles';
                else alert(data.error || 'Failed to delete role.');
            })
            .catch(() => alert('Network error'));
    }
    </script>
</body>
</html>
