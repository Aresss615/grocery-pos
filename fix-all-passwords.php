<?php
require_once 'config/database.php';

$db = new Database();

// Generate correct hashes
$hashes = [
    'admin' => password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 10]),
    'cashier' => password_hash('cashier123', PASSWORD_BCRYPT, ['cost' => 10]),
    'manager' => password_hash('manager123', PASSWORD_BCRYPT, ['cost' => 10]),
    'inventory' => password_hash('inventory123', PASSWORD_BCRYPT, ['cost' => 10]),
];

echo "Updating passwords in database...\n\n";

foreach ($hashes as $username => $hash) {
    $db->execute("UPDATE users SET password = ? WHERE username = ?", [$hash, $username]);
    echo "✅ Updated $username\n";
    echo "   Hash: " . substr($hash, 0, 20) . "...\n\n";
}

echo "Testing login...\n";
$admin = $db->fetchOne("SELECT * FROM users WHERE username = ?", ['admin']);
if ($admin && password_verify('admin123', $admin['password'])) {
    echo "✅ SUCCESS: Admin password verified!\n";
} else {
    echo "❌ FAILED: Password verification failed\n";
}
?>
