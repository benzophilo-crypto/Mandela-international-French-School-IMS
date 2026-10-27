<?php
require_once __DIR__ . '/config.php';

$password = password_hash('Admin12345!', PASSWORD_DEFAULT);

$check = db()->prepare('SELECT id FROM users WHERE email = ?');
$check->execute(['admin@mandelafrenchschool.edu']);

if ($check->fetch()) {
    exit('Administrator already exists.');
}

$roleId = db()->query("SELECT id FROM roles WHERE role_name = 'Administrator' LIMIT 1")->fetchColumn();
if (!$roleId) {
    exit('Administrator role is not configured. Run dabase.php first.');
}

$stmt = db()->prepare(
    'INSERT INTO users (full_name, email, password_hash, role_id)
     VALUES (?, ?, ?, ?)'
);

$stmt->execute([
    'System Administrator',
    'admin@mandelafrenchschool.edu',
    $password,
    (int) $roleId
]);

echo 'Administrator created. Email: admin@mandelafrenchschool.edu | Password: Admin12345!';