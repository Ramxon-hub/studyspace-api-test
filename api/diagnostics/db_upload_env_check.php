<?php
// api/diagnostics/db_upload_env_check.php - Safe Environment & Authorization Diagnostic

if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if (!headers_sent()) http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/env.php';

// 1. Check getenv
$getenv_val = function_exists('getenv') ? getenv('PRODUCTION_DB_UPLOAD_TOKEN') : false;
$getenv_present = ($getenv_val !== false && $getenv_val !== null && $getenv_val !== '');
$getenv_len = $getenv_present ? strlen((string)$getenv_val) : 0;

// 2. Check $_ENV
$env_val = $_ENV['PRODUCTION_DB_UPLOAD_TOKEN'] ?? null;
$env_present = ($env_val !== null && $env_val !== '');
$env_len = $env_present ? strlen((string)$env_val) : 0;

// 3. Check $_SERVER
$server_val = $_SERVER['PRODUCTION_DB_UPLOAD_TOKEN'] ?? null;
$server_present = ($server_val !== null && $server_val !== '');
$server_len = $server_present ? strlen((string)$server_val) : 0;

// 4. Check env_get() helper function from config/env.php
$env_get_val = function_exists('env_get') ? env_get('PRODUCTION_DB_UPLOAD_TOKEN') : null;
$env_get_present = ($env_get_val !== null && $env_get_val !== '');
$env_get_len = $env_get_present ? strlen((string)$env_get_val) : 0;

// 5. Check Authorization header from various sources
$auth_header = '';
$http_auth_present = !empty($_SERVER['HTTP_AUTHORIZATION']);
$redirect_http_auth_present = !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
$apache_headers_present = false;

if ($http_auth_present) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
} elseif ($redirect_http_auth_present) {
    $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} else {
    $headers = [];
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
    }
    
    if (!empty($headers['Authorization'])) {
        $auth_header = $headers['Authorization'];
        $apache_headers_present = true;
    } elseif (!empty($headers['authorization'])) {
        $auth_header = $headers['authorization'];
        $apache_headers_present = true;
    }
}

$auth_header_present = !empty(trim($auth_header));
$auth_scheme = 'missing';
if ($auth_header_present) {
    if (preg_match('/^Bearer\s+/i', trim($auth_header))) {
        $auth_scheme = 'Bearer';
    } else {
        $auth_scheme = 'other';
    }
}

// Ensure ZERO secrets or sensitive tokens are exposed in response
echo json_encode([
    'success' => true,
    'getenv_present' => $getenv_present,
    'env_present' => $env_present,
    'server_present' => $server_present,
    'env_get_present' => $env_get_present,
    'getenv_length' => $getenv_len,
    'env_length' => $env_len,
    'server_length' => $server_len,
    'env_get_length' => $env_get_len,
    'authorization_header_present' => $auth_header_present,
    'authorization_scheme' => $auth_scheme,
    'http_authorization_server_var' => $http_auth_present,
    'redirect_http_authorization_server_var' => $redirect_http_auth_present,
    'apache_headers_present' => $apache_headers_present
], JSON_PRETTY_PRINT);
