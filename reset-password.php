<?php
/**
 * Password Reset Tool - Emergency Access
 * Use only when locked out, then delete this file
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';

$db = new Database();
$message = '';
$message_type = '';

// Process password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'reset_password') {
        $username = $_POST['username'] ?? '';
        $new_password = $_POST['new_password'] ?? '';

        // Validate input
        if (empty($username) || empty($new_password)) {
            $message = 'Username and password are required';
            $message_type = 'error';
        } elseif (strlen($new_password) < 6) {
            $message = 'Password must be at least 6 characters';
            $message_type = 'error';
        } else {
            // Hash the password
            $hashed = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 10]);

            // Update the password
            $result = $db->execute(
                "UPDATE users SET password = ? WHERE username = ?",
                [$hashed, $username]
            );

            if ($result) {
                $message = "✅ Password reset successfully for user: <strong>$username</strong>";
                $message_type = 'success';
            } else {
                $message = "❌ Failed to reset password. User may not exist.";
                $message_type = 'error';
            }
        }
    } elseif ($action === 'create_admin') {
        $name = $_POST['admin_name'] ?? 'Administrator';
        $username = $_POST['admin_username'] ?? 'admin';
        $password = $_POST['admin_password'] ?? 'admin123';

        // Check if admin already exists
        $existing = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$username]);
        if ($existing) {
            $message = "❌ User '$username' already exists";
            $message_type = 'error';
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
            $result = $db->execute(
                "INSERT INTO users (name, username, password, role, active) VALUES (?, ?, ?, ?, 1)",
                [$name, $username, $hashed, 'admin']
            );

            if ($result) {
                $message = "✅ Admin user created: <strong>$username</strong> | Password: <strong>$password</strong>";
                $message_type = 'success';
            } else {
                $message = "❌ Failed to create admin user";
                $message_type = 'error';
            }
        }
    }
}

// Get all users for display
$users = $db->fetchAll("SELECT id, name, username, role, active FROM users ORDER BY role, username");
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Tool</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 800px;
            width: 100%;
            padding: 40px;
        }

        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .form-section {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .form-section h2 {
            color: #333;
            font-size: 18px;
            margin-bottom: 15px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        button {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            text-decoration: none;
            text-align: center;
            display: inline-block;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        table th {
            background: #667eea;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }

        table td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
        }

        table tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            background: #e7f3ff;
            color: #0056b3;
        }

        .footer-info {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 6px;
            padding: 15px;
            margin-top: 30px;
            font-size: 13px;
            color: #856404;
        }

        .footer-info strong {
            display: block;
            margin-bottom: 5px;
        }

        hr {
            border: none;
            border-top: 1px solid #e9ecef;
            margin: 30px 0;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            padding: 8px 12px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
        }

        .back-link:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="troubleshoot.php" class="back-link">← Back to Troubleshooter</a>

        <h1>🔐 Password Reset Tool</h1>
        <p class="subtitle">Emergency access to reset user passwords</p>

        <?php if (!empty($message)): ?>
            <div class="alert <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Reset Existing User Password -->
        <div class="form-section">
            <h2>🔑 Reset User Password</h2>
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Enter username" required>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password (min 6 characters)</label>
                    <input type="password" id="new_password" name="new_password" placeholder="Enter new password" required minlength="6">
                </div>

                <div class="button-group">
                    <button type="submit" class="btn-primary">🔄 Reset Password</button>
                    <button type="reset" class="btn-secondary">Clear</button>
                </div>
            </form>
        </div>

        <!-- Create New Admin User -->
        <div class="form-section">
            <h2>➕ Create New Admin User</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_admin">

                <div class="form-group">
                    <label for="admin_name">Full Name</label>
                    <input type="text" id="admin_name" name="admin_name" value="Administrator" required>
                </div>

                <div class="form-group">
                    <label for="admin_username">Username</label>
                    <input type="text" id="admin_username" name="admin_username" value="admin" required>
                </div>

                <div class="form-group">
                    <label for="admin_password">Password</label>
                    <input type="password" id="admin_password" name="admin_password" value="admin123" required minlength="6">
                </div>

                <div class="button-group">
                    <button type="submit" class="btn-primary">➕ Create Admin</button>
                    <button type="reset" class="btn-secondary">Clear</button>
                </div>
            </form>
        </div>

        <!-- User List -->
        <div class="form-section">
            <h2>👥 Current Users</h2>
            <?php if (empty($users)): ?>
                <p style="color: #dc3545;">❌ No users found in database</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($user['username']); ?></code></td>
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><span class="role-badge"><?php echo ucfirst($user['role']); ?></span></td>
                                <td>
                                    <span class="status-badge <?php echo $user['active'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $user['active'] ? '✅ Active' : '❌ Inactive'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <hr>

        <div class="footer-info">
            <strong>⚠️ IMPORTANT:</strong>
            <div>This is an emergency access tool. Delete this file (reset-password.php) after you've regained access to your account.</div>
            <div style="margin-top: 8px;">Default credentials: admin / admin123</div>
        </div>
    </div>
</body>
</html>
