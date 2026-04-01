<?php
/**
 * J&J Grocery POS — Roles API v4
 * GET                    → list all roles with permissions
 * GET  ?id=N             → single role with permissions
 * POST action=create     → create custom role with permissions
 * POST action=update     → update role name + permissions
 * POST action=delete     → delete custom role (only if no users assigned)
 * Requires users permission.
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']); exit;
}
if (!hasAccess('users')) {
    echo json_encode(['error' => 'Access denied']); exit;
}

$db   = new Database();
$user = getCurrentUser();

// All available permissions
$ALL_PERMISSIONS = [
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

// ── GET: List roles ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $role_id = intval($_GET['id'] ?? 0);

    if ($role_id) {
        $role = $db->fetchOne("SELECT * FROM roles WHERE id = ?", [$role_id]);
        if (!$role) {
            echo json_encode(['error' => 'Role not found']); exit;
        }
        $perms = $db->fetchAll(
            "SELECT permission FROM role_permissions WHERE role_id = ?", [$role_id]
        );
        $role['permissions'] = array_column($perms, 'permission');
        $role['user_count'] = (int)($db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM users WHERE role_id = ? AND active = 1", [$role_id]
        )['cnt'] ?? 0);
        echo json_encode(['success' => true, 'role' => $role, 'all_permissions' => $ALL_PERMISSIONS]);
    } else {
        $roles = $db->fetchAll("SELECT * FROM roles ORDER BY is_system DESC, name ASC");
        foreach ($roles as &$r) {
            $perms = $db->fetchAll(
                "SELECT permission FROM role_permissions WHERE role_id = ?", [$r['id']]
            );
            $r['permissions'] = array_column($perms, 'permission');
            $r['user_count'] = (int)($db->fetchOne(
                "SELECT COUNT(*) AS cnt FROM users WHERE role_id = ? AND active = 1", [$r['id']]
            )['cnt'] ?? 0);
        }
        unset($r);
        echo json_encode(['success' => true, 'roles' => $roles, 'all_permissions' => $ALL_PERMISSIONS]);
    }
    exit;
}

// ── POST: Create / Update / Delete ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf(true);

    $action = $_POST['action'] ?? '';

    // ── CREATE ───────────────────────────────────────────────
    if ($action === 'create') {
        $name  = trim($_POST['name'] ?? '');
        $perms = $_POST['permissions'] ?? [];
        if (is_string($perms)) $perms = json_decode($perms, true) ?: [];

        if (empty($name)) {
            echo json_encode(['error' => 'Role name is required']); exit;
        }

        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
        $slug = trim($slug, '_');

        // Check duplicate
        $existing = $db->fetchOne("SELECT id FROM roles WHERE slug = ? OR name = ?", [$slug, $name]);
        if ($existing) {
            echo json_encode(['error' => 'A role with this name already exists']); exit;
        }

        $db->beginTransaction();
        try {
            $db->execute(
                "INSERT INTO roles (name, slug, is_system) VALUES (?, ?, 0)",
                [$name, $slug]
            );
            $new_id = $db->lastInsertId();

            foreach ($perms as $perm) {
                if (isset($ALL_PERMISSIONS[$perm])) {
                    $db->execute(
                        "INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)",
                        [$new_id, $perm]
                    );
                }
            }

            logActivity($db, 'role_created',
                "Role '$name' created with permissions: " . implode(', ', $perms),
                $new_id, 'warning'
            );

            $db->commit();
            echo json_encode(['success' => true, 'message' => "Role '$name' created.", 'id' => $new_id]);
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['error' => 'Failed to create role: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── UPDATE ───────────────────────────────────────────────
    if ($action === 'update') {
        $role_id = intval($_POST['role_id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $perms   = $_POST['permissions'] ?? [];
        if (is_string($perms)) $perms = json_decode($perms, true) ?: [];

        if (!$role_id || empty($name)) {
            echo json_encode(['error' => 'Role ID and name are required']); exit;
        }

        $role = $db->fetchOne("SELECT * FROM roles WHERE id = ?", [$role_id]);
        if (!$role) {
            echo json_encode(['error' => 'Role not found']); exit;
        }

        // Check for duplicate name (exclude current role)
        $dup = $db->fetchOne("SELECT id FROM roles WHERE name = ? AND id != ?", [$name, $role_id]);
        if ($dup) {
            echo json_encode(['error' => 'A role with that name already exists']); exit;
        }

        $db->beginTransaction();
        try {
            // Update name (slug stays the same for system roles)
            if (!$role['is_system']) {
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
                $slug = trim($slug, '_');
                $db->execute("UPDATE roles SET name = ?, slug = ? WHERE id = ?", [$name, $slug, $role_id]);
            } else {
                $db->execute("UPDATE roles SET name = ? WHERE id = ?", [$name, $role_id]);
            }

            // Replace permissions
            $db->execute("DELETE FROM role_permissions WHERE role_id = ?", [$role_id]);
            foreach ($perms as $perm) {
                if (isset($ALL_PERMISSIONS[$perm])) {
                    $db->execute(
                        "INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)",
                        [$role_id, $perm]
                    );
                }
            }

            logActivity($db, 'role_updated',
                "Role '$name' (ID: $role_id) updated. Permissions: " . implode(', ', $perms),
                $role_id, 'warning'
            );

            // Refresh session permissions if current user's role was updated
            if (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === $role_id) {
                $_SESSION['permissions'] = $perms;
            }

            $db->commit();
            echo json_encode(['success' => true, 'message' => "Role '$name' updated."]);
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['error' => 'Failed to update role: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── DELETE ───────────────────────────────────────────────
    if ($action === 'delete') {
        $role_id = intval($_POST['role_id'] ?? 0);
        if (!$role_id) {
            echo json_encode(['error' => 'Role ID required']); exit;
        }

        $role = $db->fetchOne("SELECT * FROM roles WHERE id = ?", [$role_id]);
        if (!$role) {
            echo json_encode(['error' => 'Role not found']); exit;
        }
        if ($role['is_system']) {
            echo json_encode(['error' => 'System roles cannot be deleted']); exit;
        }

        $user_count = (int)($db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM users WHERE role_id = ? AND active = 1", [$role_id]
        )['cnt'] ?? 0);
        if ($user_count > 0) {
            echo json_encode(['error' => "Cannot delete — $user_count user(s) still assigned to this role"]); exit;
        }

        $db->beginTransaction();
        try {
            $db->execute("DELETE FROM role_permissions WHERE role_id = ?", [$role_id]);
            $db->execute("DELETE FROM roles WHERE id = ?", [$role_id]);

            logActivity($db, 'role_deleted',
                "Role '{$role['name']}' (ID: $role_id) deleted",
                $role_id, 'critical'
            );

            $db->commit();
            echo json_encode(['success' => true, 'message' => "Role '{$role['name']}' deleted."]);
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['error' => 'Failed to delete role: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

echo json_encode(['error' => 'Method not allowed']);
