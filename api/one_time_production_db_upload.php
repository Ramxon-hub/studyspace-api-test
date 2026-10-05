<?php
// api/one_time_production_db_upload.php - One-Time Secure Production Database Upload Endpoint

if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if (!headers_sent()) http_response_code(200);
    exit();
}

function respond_json($status_code, $success, $message, $extra = []) {
    if (!headers_sent()) {
        http_response_code($status_code);
    }
    $response = array_merge([
        'success' => $success,
        'message' => $message
    ], $extra);
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond_json(405, false, 'Method Not Allowed. Use POST.');
}

require_once __DIR__ . '/../config/env.php';

// 1. One-Time Completion Marker Check
$completion_marker = '/data/.production_db_upload_completed';
if (file_exists($completion_marker)) {
    respond_json(409, false, 'One-Time Upload Endpoint Disabled: Production database placement has already been completed.');
}

// 2. Existing File Protection Check
$target_master = '/data/studyspace_master.sqlite';
$target_tenant = '/data/tenant_lib001.sqlite';

if (file_exists($target_master) || file_exists($target_tenant)) {
    respond_json(409, false, 'Target database files already exist in /data/. Upload aborted to prevent accidental overwrite.');
}

// 3. Authorization Bearer Token Verification
$expected_token = function_exists('env_get') ? env_get('PRODUCTION_DB_UPLOAD_TOKEN') : (getenv('PRODUCTION_DB_UPLOAD_TOKEN') ?: ($_ENV['PRODUCTION_DB_UPLOAD_TOKEN'] ?? ($_SERVER['PRODUCTION_DB_UPLOAD_TOKEN'] ?? null)));

if (empty($expected_token) && defined('PRODUCTION_DB_UPLOAD_TOKEN')) {
    $expected_token = PRODUCTION_DB_UPLOAD_TOKEN;
}

if (empty($expected_token)) {
    respond_json(500, false, 'PRODUCTION_DB_UPLOAD_TOKEN environment variable is not configured on server.');
}

$auth_header = '';
if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
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
    } elseif (!empty($headers['authorization'])) {
        $auth_header = $headers['authorization'];
    }
}

$provided_token = null;

if (preg_match('/Bearer\s+(.*)$/i', trim($auth_header), $matches)) {
    $provided_token = trim($matches[1]);
} elseif (!empty($_POST['token'])) {
    $provided_token = trim($_POST['token']);
}

if (empty($provided_token) || !hash_equals($expected_token, $provided_token)) {
    respond_json(401, false, 'Unauthorized: Invalid or missing PRODUCTION_DB_UPLOAD_TOKEN.');
}

// 4. Multipart File Upload Check
if (empty($_FILES['master_db']) || empty($_FILES['tenant_db'])) {
    respond_json(400, false, 'Bad Request: Both master_db and tenant_db multipart files must be provided.');
}

$master_file = $_FILES['master_db'];
$tenant_file = $_FILES['tenant_db'];

if ($master_file['error'] !== UPLOAD_ERR_OK || $tenant_file['error'] !== UPLOAD_ERR_OK) {
    respond_json(400, false, 'File Upload Error: One or both uploaded files encountered a transmission error.');
}

// 5. Expected SHA256 Checksums
$EXPECTED_MASTER_SHA256 = 'baa5c2549211e9209741b2801409924440c1234fc8525a0d78cf714e482b3fc3';
$EXPECTED_TENANT_SHA256 = '5df05d12b2d8c673a2fa4498d8e94b3971101a0120a1d0d4bcb79dbacc6543e6';

// 6. Temporary Pre-Verification of Uploaded Files
$uploaded_master_sha = hash_file('sha256', $master_file['tmp_name']);
$uploaded_tenant_sha = hash_file('sha256', $tenant_file['tmp_name']);

if (strtolower($uploaded_master_sha) !== strtolower($EXPECTED_MASTER_SHA256)) {
    respond_json(422, false, 'SHA256 Mismatch on master_db: Uploaded file checksum does not match expected master SHA256.', [
        'expected_sha256' => $EXPECTED_MASTER_SHA256,
        'received_sha256' => $uploaded_master_sha
    ]);
}

if (strtolower($uploaded_tenant_sha) !== strtolower($EXPECTED_TENANT_SHA256)) {
    respond_json(422, false, 'SHA256 Mismatch on tenant_db: Uploaded file checksum does not match expected tenant SHA256.', [
        'expected_sha256' => $EXPECTED_TENANT_SHA256,
        'received_sha256' => $uploaded_tenant_sha
    ]);
}

// 7. Atomic Write to Persistent /data Volume
$data_dir = '/data';
if (!file_exists($data_dir)) {
    @mkdir($data_dir, 0777, true);
}

$tmp_master_dest = $data_dir . '/master_temp_' . uniqid() . '.tmp';
$tmp_tenant_dest = $data_dir . '/tenant_temp_' . uniqid() . '.tmp';

if (!move_uploaded_file($master_file['tmp_name'], $tmp_master_dest)) {
    respond_json(500, false, 'Failed to write master_db to persistent volume directory /data.');
}

if (!move_uploaded_file($tenant_file['tmp_name'], $tmp_tenant_dest)) {
    @unlink($tmp_master_dest);
    respond_json(500, false, 'Failed to write tenant_db to persistent volume directory /data.');
}

// Verify post-write SHA256
if (strtolower(hash_file('sha256', $tmp_master_dest)) !== strtolower($EXPECTED_MASTER_SHA256) ||
    strtolower(hash_file('sha256', $tmp_tenant_dest)) !== strtolower($EXPECTED_TENANT_SHA256)) {
    @unlink($tmp_master_dest);
    @unlink($tmp_tenant_dest);
    respond_json(500, false, 'Post-write integrity verification failed: SHA256 mismatch after copy.');
}

// 8. PRAGMA Integrity & Baseline Checks
try {
    $pdo_m = new PDO("sqlite:" . $tmp_master_dest);
    $pdo_m->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $m_integrity = $pdo_m->query("PRAGMA integrity_check")->fetchColumn();
    $m_fk = count($pdo_m->query("PRAGMA foreign_key_check")->fetchAll());

    $pdo_t = new PDO("sqlite:" . $tmp_tenant_dest);
    $pdo_t->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $t_integrity = $pdo_t->query("PRAGMA integrity_check")->fetchColumn();
    $t_fk = count($pdo_t->query("PRAGMA foreign_key_check")->fetchAll());

    if ($m_integrity !== 'ok' || $m_fk !== 0 || $t_integrity !== 'ok' || $t_fk !== 0) {
        @unlink($tmp_master_dest);
        @unlink($tmp_tenant_dest);
        respond_json(422, false, 'Database Integrity Failure: SQLite PRAGMA check failed.');
    }

    // Baseline count verification
    $u_count = (int)$pdo_t->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $s_count = (int)$pdo_t->query("SELECT COUNT(*) FROM seats")->fetchColumn();
    $sh_count = (int)$pdo_t->query("SELECT COUNT(*) FROM shifts")->fetchColumn();

    if ($u_count !== 4 || $s_count !== 40 || $sh_count !== 4) {
        @unlink($tmp_master_dest);
        @unlink($tmp_tenant_dest);
        respond_json(422, false, 'Baseline Row Count Mismatch on tenant_db.', [
            'users' => $u_count,
            'seats' => $s_count,
            'shifts' => $sh_count
        ]);
    }
} catch (Throwable $e) {
    @unlink($tmp_master_dest);
    @unlink($tmp_tenant_dest);
    respond_json(500, false, 'SQLite Verification Error: ' . $e->getMessage());
}

// 9. Final Atomic Rename & Permissions
rename($tmp_master_dest, $target_master);
rename($tmp_tenant_dest, $target_tenant);

@chmod($target_master, 0777);
@chmod($target_tenant, 0777);
@chmod($data_dir, 0777);

// 10. Write One-Time Completion Marker
file_put_contents($completion_marker, json_encode([
    'timestamp' => date('c'),
    'master_sha256' => $EXPECTED_MASTER_SHA256,
    'tenant_sha256' => $EXPECTED_TENANT_SHA256,
    'status' => 'COMPLETED'
]));

respond_json(200, true, 'PHASE 37 — Production databases successfully verified and placed in persistent /data volume.', [
    'master_db_path' => $target_master,
    'master_sha256' => hash_file('sha256', $target_master),
    'tenant_db_path' => $target_tenant,
    'tenant_sha256' => hash_file('sha256', $target_tenant),
    'integrity_check' => 'ok',
    'foreign_key_check' => 0,
    'completion_marker' => $completion_marker
]);
