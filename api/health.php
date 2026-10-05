<?php
// api/health.php - Safe Health Check Endpoint for Deplexo Container

if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if (!headers_sent()) http_response_code(200);
    exit();
}

http_response_code(200);

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/master_db.php';

$app_env = defined('APP_ENV') ? APP_ENV : (getenv('APP_ENV') ?: 'production');
$db_connected = false;
$db_path_label = function_exists('get_master_db_path') ? get_master_db_path() : 'unknown';
$db_error = null;

try {
    if (function_exists('get_master_pdo')) {
        $master_pdo = get_master_pdo();
        $chk = $master_pdo->query("SELECT COUNT(*) FROM libraries");
        if ($chk !== false) {
            $db_connected = true;
        }
    }
} catch (Throwable $e) {
    $db_connected = false;
    $db_error = $e->getMessage();
}

$response = [
    'success' => true,
    'environment' => $app_env,
    'database' => $db_connected ? 'connected' : 'disconnected',
    'database_path' => $db_path_label,
    'php_version' => PHP_VERSION,
    'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Apache'
];

if (!$db_connected && $db_error) {
    $response['database_error'] = $db_error;
}

echo json_encode($response, JSON_PRETTY_PRINT);
