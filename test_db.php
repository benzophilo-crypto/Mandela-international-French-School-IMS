<?php
require_once __DIR__ . '/config.php';

try {
    $connection = db();
    $database = $connection->query('SELECT DATABASE()')->fetchColumn();
    $tables = $connection->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $tableNames = array_map('strtolower', $tables);
    $requiredTables = ['users', 'roles'];

    echo '<h1>MIFS IMS Database Test</h1>';
    echo '<p>Database connection: <strong>Successful</strong></p>';
    echo '<p>Connected database: <strong>' . e((string) $database) . '</strong></p>';

    echo '<h2>Tables</h2>';
    if (!$tables) {
        echo '<p>No tables found.</p>';
    } else {
        echo '<ul>';
        foreach ($tables as $table) {
            echo '<li>' . e((string) $table) . ' - <strong>Exists</strong></li>';
        }
        echo '</ul>';
    }

    echo '<h2>Required Tables</h2><ul>';
    foreach ($requiredTables as $requiredTable) {
        $status = in_array($requiredTable, $tableNames, true) ? 'Exists' : 'Missing';
        echo '<li>' . e($requiredTable) . ' - <strong>' . e($status) . '</strong></li>';
    }
    echo '</ul>';

    echo '<h2>Administrator</h2>';
    if (in_array('users', $tableNames, true)) {
        $admin = $connection->prepare('SELECT id, full_name, email FROM users WHERE email = ? LIMIT 1');
        $admin->execute(['admin@mandelafrenchschool.edu']);
        $adminUser = $admin->fetch();

        if ($adminUser) {
            echo '<p>Admin user: <strong>Exists</strong></p>';
            echo '<p>Name: ' . e($adminUser['full_name']) . '<br>Email: ' . e($adminUser['email']) . '</p>';
        } else {
            echo '<p>Admin user: <strong>Missing</strong></p>';
        }
    } else {
        echo '<p>Admin user: <strong>Cannot check</strong> because the users table is missing.</p>';
    }
} catch (Throwable $exception) {
    http_response_code(500);
    echo '<h1>MIFS IMS Database Test</h1>';
    echo '<p>Database connection: <strong>Failed</strong></p>';
    echo '<p>Error: ' . e($exception->getMessage()) . '</p>';
}