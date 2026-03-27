<?php
require_once 'config/database.php';
$db = new Database();
$admin = $db->fetchOne('SELECT * FROM users WHERE username = ?', ['admin']);

if ($admin) {
    $verify = password_verify('admin123', $admin['password']);
    echo $verify ? "✅ SUCCESS: Password verified!" : "❌ FAILED: Password mismatch";
} else {
    echo "❌ User not found";
}
?>
