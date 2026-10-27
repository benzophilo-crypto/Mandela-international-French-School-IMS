<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$language = $_POST['language'] ?? '';
if (!is_string($language) || !in_array($language, ['en', 'fr'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Choose English or French.']);
    exit;
}

set_app_language($language);
echo json_encode(['language' => $language]);
