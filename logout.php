<?php
require_once __DIR__ . '/config.php';

if (isset($_SESSION['user'])) {
    log_system_event('logout', 'User signed out.');
}

session_unset();
session_destroy();

header('Location: index.php');
exit;