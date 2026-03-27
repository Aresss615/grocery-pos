<?php
/**
 * Quick Login Troubleshooter
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helpers.php';

$db = new Database();

echo "<h2>🔧 Login Troubleshooter</h2>";
echo "<hr>";

// Test 1: Check users table
echo "<h3>1. Users in Database</h3>";
$users = $db->fetchAll("SELECT id, name, username, password, active FROM users");
if (empty($users)) {
    echo "<p style='color: red;'>❌ NO USERS FOUND! Database may not be imported correctly.</p>";
} else {
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>ID</th><th>Name</th><th>Username</th><th>Password Hash</th><th>Active</th></tr>";
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td>" . $user['id'] . "</td>";
        echo "<td>" . $user['name'] . "</td>";
        echo "<td>" . $user['username'] . "</td>";
        echo "<td><code>" . substr($user['password'], 0, 20) . "...</code></td>";
        echo "<td>" . ($user['active'] ? '✅ Yes' : '❌ No') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";
echo "<h3>2. Test Admin Login</h3>";

$admin = $db->fetchOne("SELECT * FROM users WHERE username = 'admin' AND active = 1");
if (!$admin) {
    echo "<p style='color: red;'>❌ Admin user not found or inactive!</p>";
} else {
    echo "<p>✅ Admin user found: " . $admin['name'] . "</p>";
    
    echo "<h3>3. Test Password Verify</h3>";
    $test_password = 'admin123';
    $result = password_verify($test_password, $admin['password']);
    
    echo "<p>Password: <code>$test_password</code></p>";
    echo "<p>Hash in DB: <code>" . substr($admin['password'], 0, 50) . "...</code></p>";
    echo "<p>Verify result: <strong>" . ($result ? "✅ WORKS!" : "❌ FAILED") . "</strong></p>";
    
    if (!$result) {
        echo "<p style='color: orange;'><strong>⚠️ Password doesn't match!</strong></p>";
        echo "<p>The hash in the database is incorrect. Generate a new one:</p>";
        
        $new_hash = password_hash($test_password, PASSWORD_BCRYPT, ['cost' => 10]);
        echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 5px;'>" . $new_hash . "</pre>";
        
        echo "<p>Run this SQL command:</p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 5px;'>UPDATE users SET password = '$new_hash' WHERE username = 'admin';</pre>";
    }
}

echo "<hr>";
echo "<h3>4. Quick Fix</h3>";
echo "<p><a href='fix-password.php' style='padding: 10px 20px; background: #E53935; color: white; text-decoration: none; border-radius: 5px;'>Click here to AUTO-FIX admin password</a></p>";

echo "<hr>";
echo "<p><strong>After fixing, try login:</strong> <a href='index.php'>Go to Login</a></p>";
?>
