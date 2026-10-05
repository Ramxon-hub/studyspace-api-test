<?php
// api/json_super_admin.php - Comprehensive Super Admin Control Panel API (Phase 5 SaaS Operations)

if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Super-Admin-Key');
    header('Content-Type: application/json');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if (!headers_sent()) http_response_code(200);
    if (defined('IN_TEST_SUITE')) return; else exit();
}

require_once __DIR__ . '/../config/master_db.php';
require_once __DIR__ . '/../config/tenant_router.php';

// Support raw JSON body or GET/POST parameters
$raw_input = file_get_contents('php://input');
$input = !empty($raw_input) ? (json_decode($raw_input, true) ?: []) : [];
$_POST = array_merge($_POST, $input);
$_GET = array_merge($_GET, $input);

$action = $_GET['action'] ?? ($_POST['action'] ?? 'dashboard_stats');
$master_pdo = get_master_pdo();

if ($action === 'login') {
    $username = trim($_POST['username'] ?? ($_POST['email'] ?? ''));
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Username and password are required.']);
        if (defined('IN_TEST_SUITE')) return; else exit();
    }

    $stmt = $master_pdo->prepare("SELECT * FROM super_admins WHERE username = ? OR email = ?");
    $stmt->execute([$username, $username]);
    $sa = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($sa && password_verify($password, $sa['password_hash'])) {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_SESSION['is_super_admin'] = true;
        $_SESSION['role'] = 'super_admin';
        $_SESSION['super_admin_user'] = $sa['username'];
        $token = 'SA_TOKEN_' . bin2hex(random_bytes(16));
        $_SESSION['super_admin_token'] = $token;

        log_super_admin_action($master_pdo, 'super_admin_login', $sa['username'], null, 'SUCCESS');

        echo json_encode([
            'success' => true,
            'message' => 'Super Admin authenticated successfully.',
            'token' => $token,
            'role' => 'super_admin',
            'username' => $sa['username'],
            'email' => $sa['email']
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();
    } else {
        log_super_admin_action($master_pdo, 'super_admin_login_failed', $username, null, 'FAILED');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid Super Admin username or password.']);
        if (defined('IN_TEST_SUITE')) return; else exit();
    }
}

$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['HTTP_X_SUPER_ADMIN_TOKEN'] ?? '');
if (strpos($auth_header, 'Bearer ') === 0) {
    $bearer_token = trim(substr($auth_header, 7));
} else {
    $bearer_token = trim($auth_header);
}

$admin_key = $_SERVER['HTTP_X_SUPER_ADMIN_KEY'] ?? ($_GET['super_key'] ?? ($_POST['super_key'] ?? ''));
$expected_key = env_get('SUPER_ADMIN_KEY', 'superadmin_secret_key_2026');

$is_super_admin_session = !empty($_SESSION['is_super_admin']) || (!empty($_SESSION['role']) && $_SESSION['role'] === 'super_admin') || (!empty($bearer_token) && !empty($_SESSION['super_admin_token']) && $bearer_token === $_SESSION['super_admin_token']);
$has_valid_key = (!empty($admin_key) && $admin_key === $expected_key);

// Strict Security Enforcement: Reject non-Super Admin users (Normal Admins, Students, Parents)
if (!$is_super_admin_session && !$has_valid_key) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized Super Admin access. Super Admin credentials required.']);
    if (defined('IN_TEST_SUITE')) return; else exit();
}

if ($action === 'logout') {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    log_super_admin_action($master_pdo, 'super_admin_logout', $_SESSION['super_admin_user'] ?? 'superadmin', null, 'SUCCESS');
    unset($_SESSION['is_super_admin'], $_SESSION['role'], $_SESSION['super_admin_user'], $_SESSION['super_admin_token']);
    @session_destroy();
    echo json_encode(['success' => true, 'message' => 'Super Admin logged out successfully.']);
    if (defined('IN_TEST_SUITE')) return; else exit();
}

$super_admin_user = $_SESSION['super_admin_user'] ?? 'superadmin';

try {
    if ($action === 'dashboard_stats') {
        $today = date('Y-m-d');
        $thirty_days_later = date('Y-m-d', strtotime('+30 days'));

        $total_libs = (int)$master_pdo->query("SELECT COUNT(*) FROM libraries")->fetchColumn();
        $active_libs = (int)$master_pdo->query("SELECT COUNT(*) FROM libraries WHERE status = 'active'")->fetchColumn();
        $suspended_libs = (int)$master_pdo->query("SELECT COUNT(*) FROM libraries WHERE status = 'suspended'")->fetchColumn();
        
        $trial_libs = (int)$master_pdo->query("
            SELECT COUNT(DISTINCT l.library_code) FROM libraries l
            LEFT JOIN subscriptions s ON l.library_code = s.library_code
            WHERE LOWER(s.plan_name) LIKE '%trial%' OR s.status = 'trial'
        ")->fetchColumn();

        $expired_libs = (int)$master_pdo->query("
            SELECT COUNT(DISTINCT l.library_code) FROM libraries l
            LEFT JOIN subscriptions s ON l.library_code = s.library_code
            WHERE s.valid_until < '$today' OR s.status = 'expired'
        ")->fetchColumn();

        $active_subs = (int)$master_pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'active' AND valid_until >= '$today'")->fetchColumn();
        $expiring_soon = (int)$master_pdo->query("SELECT COUNT(*) FROM subscriptions WHERE valid_until >= '$today' AND valid_until <= '$thirty_days_later'")->fetchColumn();
        $total_tenant_dbs = (int)$master_pdo->query("SELECT COUNT(*) FROM tenant_db_configs")->fetchColumn();
        $provisioning_failures = (int)$master_pdo->query("SELECT COUNT(*) FROM libraries WHERE provisioning_status = 'FAILED'")->fetchColumn();
        $pending_payments = (int)$master_pdo->query("SELECT COUNT(*) FROM manual_payments WHERE status = 'PENDING'")->fetchColumn();
        $verified_payments = (int)$master_pdo->query("SELECT COUNT(*) FROM manual_payments WHERE status = 'VERIFIED'")->fetchColumn();
        $total_collections = (float)$master_pdo->query("SELECT COALESCE(SUM(amount), 0.00) FROM manual_payments WHERE status = 'VERIFIED'")->fetchColumn();

        $recent_libs = $master_pdo->query("SELECT library_code, name, status, created_at FROM libraries ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $recent_audit = $master_pdo->query("SELECT action, super_admin, library_code, result_status, created_at FROM super_admin_audit_logs ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'stats' => [
                'total_libraries' => $total_libs,
                'active_libraries' => $active_libs,
                'suspended_libraries' => $suspended_libs,
                'trial_libraries' => $trial_libs,
                'expired_libraries' => $expired_libs,
                'active_subscriptions' => $active_subs,
                'expiring_soon' => $expiring_soon,
                'total_tenant_databases' => $total_tenant_dbs,
                'provisioning_failures' => $provisioning_failures,
                'pending_manual_payments' => $pending_payments,
                'verified_manual_payments' => $verified_payments,
                'total_manual_collections' => $total_collections,
                'recent_registrations' => $recent_libs,
                'recent_audit_logs' => $recent_audit
            ]
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'list_libraries') {
        $filter_status = strtolower(trim($_GET['status'] ?? ($_POST['status'] ?? 'all')));
        $search = trim($_GET['search'] ?? ($_POST['search'] ?? ''));

        $query = "
            SELECT l.id, l.library_code, l.name, l.contact_person, l.phone, l.email, l.address,
                   l.status, l.provisioning_status, l.provisioning_error, l.db_connection_status,
                   l.schema_version, l.last_connection_check, l.last_provisioning_attempt, l.created_at,
                   s.plan_name, s.max_students, s.valid_until, s.status as sub_status,
                   b.logo_url, b.primary_color, b.contact_phone, b.address as branding_address, b.tagline,
                   c.db_driver, c.db_file, c.db_host, c.db_name
            FROM libraries l
            LEFT JOIN subscriptions s ON l.library_code = s.library_code
            LEFT JOIN library_branding b ON l.library_code = b.library_code
            LEFT JOIN tenant_db_configs c ON l.library_code = c.library_code
            WHERE 1=1
        ";
        $params = [];

        if ($filter_status === 'active') {
            $query .= " AND l.status = 'active'";
        } elseif ($filter_status === 'suspended') {
            $query .= " AND l.status = 'suspended'";
        } elseif ($filter_status === 'trial') {
            $query .= " AND (LOWER(s.plan_name) LIKE '%trial%' OR s.status = 'trial')";
        } elseif ($filter_status === 'expired') {
            $today = date('Y-m-d');
            $query .= " AND (s.valid_until < '$today' OR s.status = 'expired')";
        }

        if (!empty($search)) {
            $query .= " AND (l.library_code LIKE ? OR l.name LIKE ? OR l.contact_person LIKE ?)";
            $term = "%$search%";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $query .= " ORDER BY l.id ASC";
        $stmt = $master_pdo->prepare($query);
        $stmt->execute($params);
        $libraries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'libraries' => $libraries]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_library') {
        $code = strtoupper(trim($_GET['library_code'] ?? ($_POST['library_code'] ?? '')));
        if (empty($code)) {
            echo json_encode(['success' => false, 'error' => 'Library code is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt = $master_pdo->prepare("
            SELECT l.id, l.library_code, l.name, l.contact_person, l.phone, l.email, l.address,
                   l.status, l.provisioning_status, l.provisioning_error, l.db_connection_status,
                   l.schema_version, l.last_connection_check, l.last_provisioning_attempt, l.created_at,
                   s.plan_name, s.max_students, s.valid_until, s.status as sub_status,
                   b.logo_url, b.primary_color, b.contact_phone, b.address as branding_address, b.tagline,
                   c.db_driver, c.db_file, c.db_host, c.db_name
            FROM libraries l
            LEFT JOIN subscriptions s ON l.library_code = s.library_code
            LEFT JOIN library_branding b ON l.library_code = b.library_code
            LEFT JOIN tenant_db_configs c ON l.library_code = c.library_code
            WHERE l.library_code = ?
        ");
        $stmt->execute([$code]);
        $lib = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lib) {
            echo json_encode(['success' => false, 'error' => 'Library not found: ' . $code]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        try {
            $tenant_pdo = TenantDatabaseFactory::getTenantConnection($code);
            $lib['metrics'] = [
                'total_students' => (int)$tenant_pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND (is_deleted IS NULL OR is_deleted = 0)")->fetchColumn(),
                'total_parents' => (int)$tenant_pdo->query("SELECT COUNT(*) FROM users WHERE role = 'parent' AND (is_deleted IS NULL OR is_deleted = 0)")->fetchColumn(),
                'linked_parents' => (int)$tenant_pdo->query("SELECT COUNT(DISTINCT parent_user_id) FROM parent_student_links WHERE status = 'active'")->fetchColumn(),
                'active_allocations' => (int)$tenant_pdo->query("SELECT COUNT(*) FROM allocations WHERE status = 'active'")->fetchColumn(),
                'total_seats' => (int)$tenant_pdo->query("SELECT COUNT(*) FROM seats WHERE is_active = 1")->fetchColumn(),
            ];
        } catch (Exception $e) {
            $lib['metrics'] = null;
        }

        echo json_encode(['success' => true, 'library' => $lib]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'create_library') {
        $code = strtoupper(trim($_POST['library_code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? ($_POST['contact_phone'] ?? ''));
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $plan_name = trim($_POST['plan_name'] ?? ($_POST['plan_id'] ?? 'MONTHLY'));
        $max_students = (int)($_POST['max_students'] ?? 100);
        $valid_until = trim($_POST['valid_until'] ?? date('Y-m-d', strtotime('+1 year')));
        $tagline = trim($_POST['tagline'] ?? 'Self Study Hall');
        $primary_color = trim($_POST['primary_color'] ?? '#1D4ED8');
        $logo_url = trim($_POST['logo_url'] ?? '');
        $initial_admin_user = trim($_POST['admin_username'] ?? '');
        $initial_admin_pass = trim($_POST['admin_password'] ?? '');
        $initial_admin_email = trim($_POST['admin_email'] ?? $email);

        if (empty($code) || empty($name)) {
            echo json_encode(['success' => false, 'error' => 'Library code and name are required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if (!preg_match('/^[A-Z0-9_-]{3,50}$/', $code)) {
            echo json_encode(['success' => false, 'error' => 'Invalid library code format. Must be 3-50 alphanumeric characters.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Duplicate Check
        $chk = $master_pdo->prepare("SELECT COUNT(*) FROM libraries WHERE library_code = ?");
        $chk->execute([$code]);
        if ((int)$chk->fetchColumn() > 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Library code already registered: ' . $code]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // 1. Master DB Registration
        $stmt_lib = $master_pdo->prepare("
            INSERT INTO libraries (library_code, name, contact_person, phone, email, address, status, provisioning_status, db_connection_status, last_provisioning_attempt)
            VALUES (?, ?, ?, ?, ?, ?, 'active', 'PROVISIONING', 'PROVISIONING', CURRENT_TIMESTAMP)
        ");
        $stmt_lib->execute([$code, $name, $contact_person, $phone, $email, $address]);

        $data_dir = __DIR__ . '/../data';
        if (!file_exists($data_dir)) @mkdir($data_dir, 0777, true);
        $db_file = 'data/tenant_' . strtolower($code) . '.sqlite';

        $master_pdo->prepare("INSERT INTO tenant_db_configs (library_code, db_driver, db_file) VALUES (?, 'sqlite', ?)")
                   ->execute([$code, $db_file]);

        $master_pdo->prepare("INSERT INTO subscriptions (library_code, plan_name, max_students, valid_until, status) VALUES (?, ?, ?, ?, 'active')")
                   ->execute([$code, $plan_name, $max_students, $valid_until]);

        $master_pdo->prepare("INSERT INTO library_branding (library_code, logo_url, primary_color, contact_phone, address, tagline) VALUES (?, ?, ?, ?, ?, ?)")
                   ->execute([$code, $logo_url, $primary_color, $phone, $address, $tagline]);

        log_super_admin_action($master_pdo, 'library_created', $super_admin_user, $code, 'SUCCESS', ['name' => $name, 'plan' => $plan_name]);

        // 2. Provision Tenant DB Schema
        $prov_success = false;
        $prov_error = null;
        try {
            TenantDatabaseFactory::clearCache();
            $tenant_pdo = TenantDatabaseFactory::getTenantConnection($code);

            // Optional Initial Admin Creation
            if (!empty($initial_admin_user) && !empty($initial_admin_pass)) {
                $hash = password_hash($initial_admin_pass, PASSWORD_DEFAULT);
                $admin_email = filter_var($initial_admin_email, FILTER_VALIDATE_EMAIL) ? $initial_admin_email : "$initial_admin_user@$code.com";
                $chk_adm = $tenant_pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                $chk_adm->execute([$admin_email]);
                if ((int)$chk_adm->fetchColumn() === 0) {
                    $stmt_adm = $tenant_pdo->prepare("INSERT INTO users (name, email, phone, password, role, status) VALUES (?, ?, ?, ?, 'admin', 'approved')");
                    $stmt_adm->execute(["Admin - $name", $admin_email, $phone ?: '+91 98765 00000', $hash]);
                    log_super_admin_action($master_pdo, 'admin_created', $super_admin_user, $code, 'SUCCESS', ['email' => $admin_email]);
                }
            }

            $prov_success = true;
            $master_pdo->prepare("
                UPDATE libraries
                SET provisioning_status = 'READY', db_connection_status = 'CONNECTED', schema_version = '1.0.0', last_connection_check = CURRENT_TIMESTAMP, provisioning_error = NULL
                WHERE library_code = ?
            ")->execute([$code]);

            log_super_admin_action($master_pdo, 'provision_succeeded', $super_admin_user, $code, 'SUCCESS', ['db_file' => $db_file]);

        } catch (Exception $e) {
            $prov_error = $e->getMessage();
            $master_pdo->prepare("
                UPDATE libraries
                SET provisioning_status = 'FAILED', db_connection_status = 'FAILED', provisioning_error = ?
                WHERE library_code = ?
            ")->execute([$prov_error, $code]);

            log_super_admin_action($master_pdo, 'provision_failed', $super_admin_user, $code, 'FAILED', ['error' => $prov_error]);
        }

        echo json_encode([
            'success' => $prov_success,
            'message' => $prov_success ? "Library $code created and database provisioned successfully." : "Library created but provisioning encountered an issue: $prov_error",
            'library_code' => $code,
            'provisioning_status' => $prov_success ? 'READY' : 'FAILED',
            'error' => $prov_error
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'update_library') {
        $code = strtoupper(trim($_POST['library_code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? ($_POST['contact_phone'] ?? ''));
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = trim($_POST['status'] ?? '');

        if (empty($code)) {
            echo json_encode(['success' => false, 'error' => 'Library code is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $updates = [];
        $params = [];
        if (!empty($name)) { $updates[] = "name = ?"; $params[] = $name; }
        if (isset($_POST['contact_person'])) { $updates[] = "contact_person = ?"; $params[] = $contact_person; }
        if (!empty($phone)) { $updates[] = "phone = ?"; $params[] = $phone; }
        if (isset($_POST['email'])) { $updates[] = "email = ?"; $params[] = $email; }
        if (isset($_POST['address'])) { $updates[] = "address = ?"; $params[] = $address; }
        if (!empty($status)) { $updates[] = "status = ?"; $params[] = $status; }

        if (!empty($updates)) {
            $params[] = $code;
            $master_pdo->prepare("UPDATE libraries SET " . implode(', ', $updates) . " WHERE library_code = ?")->execute($params);
        }

        // Update Branding if provided
        if (isset($_POST['tagline']) || isset($_POST['primary_color']) || isset($_POST['logo_url'])) {
            $stmt_b = $master_pdo->prepare("SELECT logo_url, primary_color, tagline FROM library_branding WHERE library_code = ?");
            $stmt_b->execute([$code]);
            $existing_b = $stmt_b->fetch(PDO::FETCH_ASSOC) ?: [];

            $tagline = isset($_POST['tagline']) && trim($_POST['tagline']) !== '' ? trim($_POST['tagline']) : ($existing_b['tagline'] ?? 'Self Study Hall');
            $primary_color = isset($_POST['primary_color']) && trim($_POST['primary_color']) !== '' ? trim($_POST['primary_color']) : ($existing_b['primary_color'] ?? '#1D4ED8');
            $logo_url = isset($_POST['logo_url']) && trim($_POST['logo_url']) !== '' ? trim($_POST['logo_url']) : ($existing_b['logo_url'] ?? '');

            $master_pdo->prepare("
                INSERT INTO library_branding (library_code, logo_url, primary_color, contact_phone, address, tagline)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT (library_code) DO UPDATE SET
                    logo_url = EXCLUDED.logo_url,
                    primary_color = EXCLUDED.primary_color,
                    contact_phone = EXCLUDED.contact_phone,
                    address = EXCLUDED.address,
                    tagline = EXCLUDED.tagline
            ")->execute([$code, $logo_url, $primary_color, $phone, $address, $tagline]);
        }

        log_super_admin_action($master_pdo, 'library_updated', $super_admin_user, $code, 'SUCCESS', ['updated_fields' => array_keys($_POST)]);

        echo json_encode(['success' => true, 'message' => "Library $code updated successfully."]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'activate_library') {
        $code = strtoupper(trim($_POST['library_code'] ?? ''));
        if (empty($code)) {
            echo json_encode(['success' => false, 'error' => 'Library code is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $master_pdo->prepare("UPDATE libraries SET status = 'active', provisioning_status = 'READY', db_connection_status = 'CONNECTED' WHERE library_code = ?")->execute([$code]);
        TenantDatabaseFactory::clearCache();

        log_super_admin_action($master_pdo, 'library_activated', $super_admin_user, $code, 'SUCCESS');

        echo json_encode(['success' => true, 'message' => "Library $code activated successfully."]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'suspend_library') {
        $code = strtoupper(trim($_POST['library_code'] ?? ''));
        if (empty($code)) {
            echo json_encode(['success' => false, 'error' => 'Library code is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $master_pdo->prepare("UPDATE libraries SET status = 'suspended', db_connection_status = 'SUSPENDED' WHERE library_code = ?")->execute([$code]);
        TenantDatabaseFactory::clearCache();

        log_super_admin_action($master_pdo, 'library_suspended', $super_admin_user, $code, 'SUCCESS');

        echo json_encode(['success' => true, 'message' => "Library $code suspended successfully."]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'provision_database') {
        $code = strtoupper(trim($_POST['library_code'] ?? ''));
        if (empty($code)) {
            echo json_encode(['success' => false, 'error' => 'Library code is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $master_pdo->prepare("UPDATE libraries SET provisioning_status = 'PROVISIONING', last_provisioning_attempt = CURRENT_TIMESTAMP WHERE library_code = ?")->execute([$code]);
        log_super_admin_action($master_pdo, 'provision_started', $super_admin_user, $code, 'IN_PROGRESS');

        try {
            TenantDatabaseFactory::clearCache();
            $tenant_pdo = TenantDatabaseFactory::getTenantConnection($code);

            // Compute Status Metrics
            $users_cnt = (int)$tenant_pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $students_cnt = (int)$tenant_pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
            $alloc_cnt = (int)$tenant_pdo->query("SELECT COUNT(*) FROM allocations WHERE status = 'active'")->fetchColumn();
            $schema_ver = (int)$tenant_pdo->query("SELECT MAX(version) FROM schema_migrations")->fetchColumn();
            $driver = $tenant_pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            $table_count = 12;
            if ($driver === 'sqlite') {
                $table_count = (int)$tenant_pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
            }

            $master_pdo->prepare("
                UPDATE libraries
                SET provisioning_status = 'READY', db_connection_status = 'CONNECTED', schema_version = '1.0.0', last_connection_check = CURRENT_TIMESTAMP, provisioning_error = NULL
                WHERE library_code = ?
            ")->execute([$code]);

            log_super_admin_action($master_pdo, 'provision_succeeded', $super_admin_user, $code, 'SUCCESS', ['table_count' => $table_count]);

            echo json_encode([
                'success' => true,
                'message' => "Database for $code provisioned and verified.",
                'db_status' => [
                    'library_code' => $code,
                    'driver' => strtoupper($driver),
                    'provisioned' => true,
                    'table_count' => $table_count,
                    'schema_version' => $schema_ver,
                    'total_users' => $users_cnt,
                    'total_students' => $students_cnt,
                    'active_allocations' => $alloc_cnt
                ]
            ]);
            if (defined('IN_TEST_SUITE')) return; else exit();

        } catch (Exception $e) {
            $err = $e->getMessage();
            $master_pdo->prepare("
                UPDATE libraries
                SET provisioning_status = 'FAILED', db_connection_status = 'FAILED', provisioning_error = ?
                WHERE library_code = ?
            ")->execute([$err, $code]);

            log_super_admin_action($master_pdo, 'provision_failed', $super_admin_user, $code, 'FAILED', ['error' => $err]);

            http_response_code(500);
            echo json_encode(['success' => false, 'error' => "Provisioning failed for $code: $err"]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

    } elseif ($action === 'check_db_status') {
        $code = strtoupper(trim($_GET['library_code'] ?? ($_POST['library_code'] ?? '')));
        if (empty($code)) {
            echo json_encode(['success' => false, 'error' => 'Library code is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        try {
            TenantDatabaseFactory::clearCache();
            $tenant_pdo = TenantDatabaseFactory::getTenantConnection($code);

            $driver = $tenant_pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $users_cnt = (int)$tenant_pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $schema_ver = (int)$tenant_pdo->query("SELECT MAX(version) FROM schema_migrations")->fetchColumn();

            $table_count = 12;
            if ($driver === 'sqlite') {
                $table_count = (int)$tenant_pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
            }

            $master_pdo->prepare("
                UPDATE libraries
                SET db_connection_status = 'CONNECTED', last_connection_check = CURRENT_TIMESTAMP
                WHERE library_code = ?
            ")->execute([$code]);

            log_super_admin_action($master_pdo, 'connection_check', $super_admin_user, $code, 'CONNECTED');

            echo json_encode([
                'success' => true,
                'connection_status' => 'CONNECTED',
                'driver' => strtoupper($driver),
                'table_count' => $table_count,
                'schema_version' => $schema_ver,
                'total_users' => $users_cnt,
                'last_check' => date('Y-m-d H:i:s')
            ]);
            if (defined('IN_TEST_SUITE')) return; else exit();

        } catch (Exception $e) {
            $master_pdo->prepare("UPDATE libraries SET db_connection_status = 'DISCONNECTED' WHERE library_code = ?")->execute([$code]);
            log_super_admin_action($master_pdo, 'connection_check', $super_admin_user, $code, 'DISCONNECTED', ['error' => $e->getMessage()]);

            echo json_encode([
                'success' => false,
                'connection_status' => 'DISCONNECTED',
                'error' => $e->getMessage()
            ]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

    } elseif ($action === 'create_library_admin') {
        $code = strtoupper(trim($_POST['library_code'] ?? ''));
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $name = trim($_POST['name'] ?? "Admin - $code");
        $email = trim($_POST['email'] ?? ($username ? (strpos($username, '@') !== false ? $username : "$username@$code.com") : "admin@$code.com"));

        if (empty($code) || (empty($username) && empty($email)) || empty($password)) {
            echo json_encode(['success' => false, 'error' => 'Library code, email/username, and password are required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $tenant_pdo = TenantDatabaseFactory::getTenantConnection($code);

        // Check if admin/user already exists by email
        $chk = $tenant_pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ((int)$chk->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'Admin email already exists in library ' . $code]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $tenant_pdo->prepare("INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, 'admin')");
        $stmt->execute([$name, $email, '+91 98765 00000', $hash]);

        log_super_admin_action($master_pdo, 'admin_created', $super_admin_user, $code, 'SUCCESS', ['email' => $email]);

        echo json_encode(['success' => true, 'message' => "Library admin '$email' created successfully for library $code."]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'reset_admin_password') {
        $code = strtoupper(trim($_POST['library_code'] ?? ''));
        $email = trim($_POST['email'] ?? ($_POST['username'] ?? ''));
        $new_password = trim($_POST['new_password'] ?? '');

        if (empty($code) || empty($email) || empty($new_password)) {
            echo json_encode(['success' => false, 'error' => 'Library code, admin email, and new_password are required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $tenant_pdo = TenantDatabaseFactory::getTenantConnection($code);

        $chk = $tenant_pdo->prepare("SELECT id FROM users WHERE (email = ? OR email LIKE ?) AND role = 'admin'");
        $chk->execute([$email, "$email%"]);
        $user_id = $chk->fetchColumn();

        if (!$user_id) {
            echo json_encode(['success' => false, 'error' => "Tenant admin '$email' not found in library $code."]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $tenant_pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $user_id]);

        log_super_admin_action($master_pdo, 'password_reset', $super_admin_user, $code, 'SUCCESS', ['email' => $email]);

        echo json_encode(['success' => true, 'message' => "Password for tenant admin '$email' reset successfully."]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'list_plans') {
        $stmt = $master_pdo->query("SELECT id, plan_id, name, duration_days, price, max_students, description, status FROM plans ORDER BY price ASC");
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'plans' => $plans]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'update_subscription') {
        $code = strtoupper(trim($_POST['library_code'] ?? ''));
        $plan_name = trim($_POST['plan_name'] ?? 'MONTHLY');
        $max_students = (int)($_POST['max_students'] ?? 100);
        $valid_until = trim($_POST['valid_until'] ?? '');
        $sub_status = trim($_POST['status'] ?? 'active');

        if (empty($code) || empty($valid_until)) {
            echo json_encode(['success' => false, 'error' => 'Library code and valid_until date are required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $master_pdo->prepare("
            UPDATE subscriptions SET plan_name = ?, max_students = ?, valid_until = ?, status = ? WHERE library_code = ?
        ")->execute([$plan_name, $max_students, $valid_until, $sub_status, $code]);

        log_super_admin_action($master_pdo, 'subscription_updated', $super_admin_user, $code, 'SUCCESS', ['plan' => $plan_name, 'valid_until' => $valid_until, 'status' => $sub_status]);

        echo json_encode(['success' => true, 'message' => "Subscription for $code updated successfully."]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_qr') {
        $code = strtoupper(trim($_GET['library_code'] ?? ($_POST['library_code'] ?? '')));
        if (empty($code)) {
            echo json_encode(['success' => false, 'error' => 'Library code is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt = $master_pdo->prepare("SELECT name FROM libraries WHERE library_code = ?");
        $stmt->execute([$code]);
        $name = $stmt->fetchColumn();

        if (!$name) {
            echo json_encode(['success' => false, 'error' => "Library not found: $code"]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $qr_payload = "STUDYSPACE:" . $code;

        echo json_encode([
            'success' => true,
            'library_code' => $code,
            'name' => $name,
            'qr_payload' => $qr_payload,
            'qr_display_text' => "Scan in StudySpace App to connect to $name ($code)"
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'list_audit_logs') {
        $code = strtoupper(trim($_GET['library_code'] ?? ($_POST['library_code'] ?? '')));
        $limit = (int)($_GET['limit'] ?? 50);

        if (!empty($code)) {
            $stmt = $master_pdo->prepare("SELECT id, action, super_admin, library_code, result_status, metadata, created_at FROM super_admin_audit_logs WHERE library_code = ? ORDER BY id DESC LIMIT ?");
            $stmt->execute([$code, $limit]);
        } else {
            $stmt = $master_pdo->prepare("SELECT id, action, super_admin, library_code, result_status, metadata, created_at FROM super_admin_audit_logs ORDER BY id DESC LIMIT ?");
            $stmt->execute([$limit]);
        }
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'logs' => $logs]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'renew_subscription') {
        $code = strtoupper(trim($_POST['library_code'] ?? ''));
        $months = (int)($_POST['months'] ?? 12);
        $plan_name = trim($_POST['plan_name'] ?? 'YEARLY');
        $payment_method = trim($_POST['payment_method'] ?? 'Bank Transfer / Manual');
        $payment_ref = trim($_POST['payment_reference'] ?? ('REF-' . time()));

        if (empty($code)) {
            echo json_encode(['success' => false, 'error' => 'Library code is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmtSub = $master_pdo->prepare("SELECT valid_until, max_students FROM subscriptions WHERE library_code = ?");
        $stmtSub->execute([$code]);
        $sub = $stmtSub->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            echo json_encode(['success' => false, 'error' => "Subscription not found for library $code"]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $current_expiry = ($sub['valid_until'] && $sub['valid_until'] > date('Y-m-d')) ? $sub['valid_until'] : date('Y-m-d');
        $new_expiry = date('Y-m-d', strtotime("+$months months", strtotime($current_expiry)));

        $master_pdo->prepare("
            UPDATE subscriptions SET valid_until = ?, status = 'active', plan_name = ? WHERE library_code = ?
        ")->execute([$new_expiry, $plan_name, $code]);

        $master_pdo->prepare("UPDATE libraries SET status = 'active' WHERE library_code = ?")->execute([$code]);

        // Generate Commercial Invoice Record
        $invoice_no = 'INV-' . strtoupper($code) . '-' . date('Ymd-His');
        $amount = ($plan_name === 'YEARLY' || $months >= 12) ? 299.99 : 29.99;
        
        $master_pdo->prepare("
            INSERT INTO invoices (invoice_number, library_code, plan_id, amount, currency, invoice_date, due_date, status, payment_reference, payment_method, paid_at)
            VALUES (?, ?, ?, ?, 'INR', DATE('now'), DATE('now'), 'PAID', ?, ?, CURRENT_TIMESTAMP)
        ")->execute([$invoice_no, $code, $plan_name, $amount, $payment_ref, $payment_method]);

        log_super_admin_action($master_pdo, 'subscription_renewed', $super_admin_user, $code, 'SUCCESS', [
            'previous_expiry' => $current_expiry,
            'new_expiry' => $new_expiry,
            'invoice_number' => $invoice_no,
            'amount' => $amount
        ]);

        echo json_encode([
            'success' => true,
            'message' => "Subscription for $code renewed by $months months until $new_expiry.",
            'previous_expiry' => $current_expiry,
            'new_expiry' => $new_expiry,
            'invoice_number' => $invoice_no
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'list_invoices') {
        $code = strtoupper(trim($_GET['library_code'] ?? ($_POST['library_code'] ?? '')));
        if (!empty($code)) {
            $stmt = $master_pdo->prepare("SELECT * FROM invoices WHERE library_code = ? ORDER BY id DESC");
            $stmt->execute([$code]);
        } else {
            $stmt = $master_pdo->query("SELECT * FROM invoices ORDER BY id DESC LIMIT 100");
        }
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'invoices' => $invoices]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'create_invoice') {
        $code = strtoupper(trim($_POST['library_code'] ?? ''));
        $plan_id = trim($_POST['plan_id'] ?? 'MONTHLY');
        $amount = (float)($_POST['amount'] ?? 29.99);
        $due_date = trim($_POST['due_date'] ?? date('Y-m-d', strtotime('+14 days')));
        $status = strtoupper(trim($_POST['status'] ?? 'ISSUED'));
        $payment_ref = trim($_POST['payment_reference'] ?? '');
        $payment_method = trim($_POST['payment_method'] ?? 'Manual');

        if (empty($code)) {
            echo json_encode(['success' => false, 'error' => 'Library code is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $invoice_no = 'INV-' . strtoupper($code) . '-' . date('Ymd-His') . '-' . rand(100, 999);
        $paid_at = ($status === 'PAID') ? date('Y-m-d H:i:s') : null;

        $stmt = $master_pdo->prepare("
            INSERT INTO invoices (invoice_number, library_code, plan_id, amount, currency, invoice_date, due_date, status, payment_reference, payment_method, paid_at)
            VALUES (?, ?, ?, ?, 'INR', DATE('now'), ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$invoice_no, $code, $plan_id, $amount, $due_date, $status, $payment_ref, $payment_method, $paid_at]);

        log_super_admin_action($master_pdo, 'invoice_created', $super_admin_user, $code, 'SUCCESS', ['invoice_number' => $invoice_no, 'amount' => $amount, 'status' => $status]);

        echo json_encode(['success' => true, 'message' => "Invoice $invoice_no created successfully.", 'invoice_number' => $invoice_no]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'create_manual_payment') {
        $invoice_ref = trim($_POST['invoice_id'] ?? ($_POST['invoice_number'] ?? ''));
        $code = strtoupper(trim($_POST['library_code'] ?? ''));
        $amount = (float)($_POST['amount'] ?? 0);
        $payment_method = trim($_POST['payment_method'] ?? 'manual_cash');
        $payment_reference = trim($_POST['payment_reference'] ?? '');
        $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');
        $proof_reference = trim($_POST['proof_reference'] ?? '');

        if (empty($invoice_ref) || empty($code)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invoice ID and library code are required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Fetch invoice
        if (is_numeric($invoice_ref)) {
            $stmtInv = $master_pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmtInv->execute([(int)$invoice_ref]);
        } else {
            $stmtInv = $master_pdo->prepare("SELECT * FROM invoices WHERE invoice_number = ?");
            $stmtInv->execute([$invoice_ref]);
        }
        $invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Invoice '$invoice_ref' not found."]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if ($invoice['library_code'] !== $code) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Library code mismatch for specified invoice.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if ($invoice['status'] === 'PAID') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invoice is already paid.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if ($invoice['status'] === 'CANCELLED') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Cannot pay a cancelled invoice.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $valid_methods = ['manual_cash', 'manual_upi', 'manual_bank_transfer', 'manual_other'];
        if (!in_array($payment_method, $valid_methods)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Invalid payment method. Must be one of: ' . implode(', ', $valid_methods)]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if (abs($amount - (float)$invoice['amount']) > 0.01) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => "Payment amount (" . number_format($amount, 2) . ") does not match invoice amount (" . number_format($invoice['amount'], 2) . ")."]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if (($payment_method === 'manual_upi' || $payment_method === 'manual_bank_transfer') && empty($payment_reference)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Payment reference (UTR/Transaction ID) is required for UPI and Bank Transfer payments.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Duplicate reference check for non-cash or non-empty references
        if (!empty($payment_reference)) {
            $stmtChkRef = $master_pdo->prepare("SELECT COUNT(*) FROM manual_payments WHERE payment_reference = ? AND status != 'REJECTED' AND status != 'VOID'");
            $stmtChkRef->execute([$payment_reference]);
            if ((int)$stmtChkRef->fetchColumn() > 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => "Duplicate payment reference: UTR/Ref '$payment_reference' has already been recorded."]);
                if (defined('IN_TEST_SUITE')) return; else exit();
            }
        }

        // Check if a PENDING payment already exists for this invoice
        $stmtChkPending = $master_pdo->prepare("SELECT COUNT(*) FROM manual_payments WHERE invoice_id = ? AND status = 'PENDING'");
        $stmtChkPending->execute([$invoice['id']]);
        if ((int)$stmtChkPending->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'A pending payment record already exists for this invoice.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $payment_id = 'PAY-MAN-' . $code . '-' . date('YmdHis') . '-' . rand(100, 999);

        $stmtIns = $master_pdo->prepare("
            INSERT INTO manual_payments (payment_id, invoice_id, library_code, amount, currency, payment_method, payment_reference, payment_date, notes, proof_reference, received_by_super_admin, status)
            VALUES (?, ?, ?, ?, 'INR', ?, ?, ?, ?, ?, ?, 'PENDING')
        ");
        $stmtIns->execute([$payment_id, $invoice['id'], $code, $amount, $payment_method, $payment_reference, $payment_date, $notes, $proof_reference, $super_admin_user]);

        $master_pdo->prepare("UPDATE invoices SET status = 'PAYMENT PENDING' WHERE id = ? AND status IN ('DRAFT', 'ISSUED')")->execute([$invoice['id']]);

        log_super_admin_action($master_pdo, 'MANUAL_PAYMENT_CREATED', $super_admin_user, $code, 'SUCCESS', [
            'payment_id' => $payment_id,
            'invoice_id' => $invoice['id'],
            'amount' => $amount,
            'payment_method' => $payment_method,
            'payment_reference' => $payment_reference
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Manual payment created successfully and is pending verification.',
            'payment_id' => $payment_id,
            'status' => 'PENDING',
            'invoice_status' => 'PAYMENT PENDING'
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'verify_manual_payment') {
        $payment_ref = trim($_POST['payment_id'] ?? ($_GET['payment_id'] ?? ''));

        if (empty($payment_ref)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Payment ID is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if (is_numeric($payment_ref)) {
            $stmtP = $master_pdo->prepare("SELECT * FROM manual_payments WHERE id = ?");
            $stmtP->execute([(int)$payment_ref]);
        } else {
            $stmtP = $master_pdo->prepare("SELECT * FROM manual_payments WHERE payment_id = ?");
            $stmtP->execute([$payment_ref]);
        }
        $payment = $stmtP->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Payment record '$payment_ref' not found."]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if ($payment['status'] === 'VERIFIED') {
            $stmtSub = $master_pdo->prepare("SELECT valid_until, status FROM subscriptions WHERE library_code = ?");
            $stmtSub->execute([$payment['library_code']]);
            $sub = $stmtSub->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Payment has already been processed and verified.',
                'already_processed' => true,
                'payment_status' => 'VERIFIED',
                'invoice_status' => 'PAID',
                'subscription_status' => $sub['status'] ?? 'ACTIVE',
                'subscription_expiry' => $sub['valid_until'] ?? null
            ]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if ($payment['status'] === 'REJECTED' || $payment['status'] === 'VOID') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Cannot verify a payment with status '{$payment['status']}'."]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $code = $payment['library_code'];
        $stmtInv = $master_pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmtInv->execute([$payment['invoice_id']]);
        $invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Associated invoice not found for payment."]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Transactional execution of Payment Verification + Invoice Paid + Subscription Renewal
        $master_pdo->beginTransaction();
        try {
            // 1. Update Payment Record
            $master_pdo->prepare("
                UPDATE manual_payments
                SET status = 'VERIFIED', verified_at = CURRENT_TIMESTAMP, received_by_super_admin = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$super_admin_user, $payment['id']]);

            // 2. Update Invoice Status
            $master_pdo->prepare("
                UPDATE invoices
                SET status = 'PAID', paid_at = CURRENT_TIMESTAMP, payment_reference = ?, payment_method = ?
                WHERE id = ?
            ")->execute([$payment['payment_reference'], $payment['payment_method'], $invoice['id']]);

            // 3. Subscription Expiry Calculation
            $stmtSub = $master_pdo->prepare("SELECT valid_until, plan_name, max_students FROM subscriptions WHERE library_code = ?");
            $stmtSub->execute([$code]);
            $sub = $stmtSub->fetch(PDO::FETCH_ASSOC);

            // Determine plan duration in days
            $plan_id = strtoupper($invoice['plan_id'] ?? 'MONTHLY');
            $duration_days = 30;
            $max_students = $sub['max_students'] ?? 100;

            $stmtPlan = $master_pdo->prepare("SELECT duration_days, max_students FROM plans WHERE plan_id = ?");
            $stmtPlan->execute([$plan_id]);
            $plan_info = $stmtPlan->fetch(PDO::FETCH_ASSOC);
            if ($plan_info) {
                $duration_days = (int)$plan_info['duration_days'];
                $max_students = (int)$plan_info['max_students'];
            } else {
                if (strpos($plan_id, 'YEAR') !== false || $plan_id === 'YEARLY') {
                    $duration_days = 365;
                } elseif (strpos($plan_id, 'TRIAL') !== false) {
                    $duration_days = 14;
                }
            }

            $today = date('Y-m-d');
            $current_expiry = $sub['valid_until'] ?? null;

            if ($current_expiry && $current_expiry >= $today) {
                // Subscription is active: extend from current expiry date
                $new_expiry = date('Y-m-d', strtotime("+$duration_days days", strtotime($current_expiry)));
            } else {
                // Subscription is expired or new: start from payment/activation date
                $start_base = ($payment['payment_date'] && $payment['payment_date'] <= $today) ? $payment['payment_date'] : $today;
                $new_expiry = date('Y-m-d', strtotime("+$duration_days days", strtotime($start_base)));
            }

            // Update Subscription & Library Status
            $master_pdo->prepare("
                UPDATE subscriptions SET valid_until = ?, status = 'active', plan_name = ?, max_students = ? WHERE library_code = ?
            ")->execute([$new_expiry, $plan_id, $max_students, $code]);

            $master_pdo->prepare("UPDATE libraries SET status = 'active' WHERE library_code = ?")->execute([$code]);

            // Create Audit Logs
            log_super_admin_action($master_pdo, 'MANUAL_PAYMENT_VERIFIED', $super_admin_user, $code, 'SUCCESS', [
                'payment_id' => $payment['payment_id'],
                'amount' => $payment['amount'],
                'invoice_number' => $invoice['invoice_number']
            ]);

            log_super_admin_action($master_pdo, 'INVOICE_MARKED_PAID', $super_admin_user, $code, 'SUCCESS', [
                'invoice_number' => $invoice['invoice_number'],
                'amount' => $invoice['amount']
            ]);

            log_super_admin_action($master_pdo, 'SUBSCRIPTION_ACTIVATED', $super_admin_user, $code, 'SUCCESS', [
                'previous_expiry' => $current_expiry,
                'new_expiry' => $new_expiry,
                'plan_name' => $plan_id
            ]);

            $master_pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Payment verified successfully and subscription activated.',
                'payment_status' => 'VERIFIED',
                'invoice_status' => 'PAID',
                'subscription_status' => 'ACTIVE',
                'subscription_expiry' => $new_expiry
            ]);
            if (defined('IN_TEST_SUITE')) return; else exit();

        } catch (Exception $ex) {
            $master_pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to verify payment: ' . $ex->getMessage()]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

    } elseif ($action === 'reject_manual_payment') {
        $payment_ref = trim($_POST['payment_id'] ?? ($_GET['payment_id'] ?? ''));
        $rejection_reason = trim($_POST['rejection_reason'] ?? ($_POST['reason'] ?? ''));

        if (empty($payment_ref)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Payment ID is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if (empty($rejection_reason)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Rejection reason is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if (is_numeric($payment_ref)) {
            $stmtP = $master_pdo->prepare("SELECT * FROM manual_payments WHERE id = ?");
            $stmtP->execute([(int)$payment_ref]);
        } else {
            $stmtP = $master_pdo->prepare("SELECT * FROM manual_payments WHERE payment_id = ?");
            $stmtP->execute([$payment_ref]);
        }
        $payment = $stmtP->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Payment record '$payment_ref' not found."]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if ($payment['status'] === 'VERIFIED') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Cannot reject an already verified payment.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $code = $payment['library_code'];

        $master_pdo->prepare("
            UPDATE manual_payments SET status = 'REJECTED', rejection_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?
        ")->execute([$rejection_reason, $payment['id']]);

        // Revert invoice status to ISSUED if it was PAYMENT PENDING
        $master_pdo->prepare("UPDATE invoices SET status = 'ISSUED' WHERE id = ? AND status = 'PAYMENT PENDING'")->execute([$payment['invoice_id']]);

        log_super_admin_action($master_pdo, 'MANUAL_PAYMENT_REJECTED', $super_admin_user, $code, 'SUCCESS', [
            'payment_id' => $payment['payment_id'],
            'reason' => $rejection_reason
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Payment rejected successfully.',
            'payment_status' => 'REJECTED',
            'invoice_status' => 'ISSUED'
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'list_manual_payments') {
        $code = strtoupper(trim($_GET['library_code'] ?? ($_POST['library_code'] ?? '')));
        $status_filter = trim($_GET['status'] ?? ($_POST['status'] ?? ''));

        $query = "
            SELECT p.*, i.invoice_number, i.plan_id, i.due_date as invoice_due_date, l.name as library_name
            FROM manual_payments p
            LEFT JOIN invoices i ON p.invoice_id = i.id
            LEFT JOIN libraries l ON p.library_code = l.library_code
            WHERE 1=1
        ";
        $params = [];

        if (!empty($code)) {
            $query .= " AND p.library_code = ?";
            $params[] = $code;
        }

        if (!empty($status_filter) && $status_filter !== 'all') {
            $query .= " AND p.status = ?";
            $params[] = strtoupper($status_filter);
        }

        $query .= " ORDER BY p.id DESC LIMIT 100";

        $stmt = $master_pdo->prepare($query);
        $stmt->execute($params);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'payments' => $payments]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'cancel_invoice') {
        $invoice_ref = trim($_POST['invoice_id'] ?? ($_POST['invoice_number'] ?? ''));
        $reason = trim($_POST['reason'] ?? 'Cancelled by Super Admin');

        if (empty($invoice_ref)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invoice ID is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if (is_numeric($invoice_ref)) {
            $stmtInv = $master_pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmtInv->execute([(int)$invoice_ref]);
        } else {
            $stmtInv = $master_pdo->prepare("SELECT * FROM invoices WHERE invoice_number = ?");
            $stmtInv->execute([$invoice_ref]);
        }
        $invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Invoice '$invoice_ref' not found."]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if ($invoice['status'] === 'PAID') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Cannot cancel an already paid invoice.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $master_pdo->prepare("UPDATE invoices SET status = 'CANCELLED' WHERE id = ?")->execute([$invoice['id']]);

        log_super_admin_action($master_pdo, 'INVOICE_CANCELLED', $super_admin_user, $invoice['library_code'], 'SUCCESS', [
            'invoice_number' => $invoice['invoice_number'],
            'reason' => $reason
        ]);

        echo json_encode(['success' => true, 'message' => "Invoice {$invoice['invoice_number']} cancelled successfully.", 'invoice_status' => 'CANCELLED']);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'list_renewal_requests') {
        $code = strtoupper(trim($_GET['library_code'] ?? ($_POST['library_code'] ?? '')));
        $status = trim($_GET['status'] ?? ($_POST['status'] ?? ''));

        $query = "
            SELECT r.*, l.name as library_name, s.valid_until as current_expiry, s.plan_name as current_plan
            FROM subscription_renewal_requests r
            LEFT JOIN libraries l ON r.library_code = l.library_code
            LEFT JOIN subscriptions s ON r.library_code = s.library_code
            WHERE 1=1
        ";
        $params = [];
        if (!empty($code)) {
            $query .= " AND r.library_code = ?";
            $params[] = $code;
        }
        if (!empty($status) && $status !== 'all') {
            $query .= " AND r.status = ?";
            $params[] = strtoupper($status);
        }
        $query .= " ORDER BY r.id DESC LIMIT 100";

        $stmt = $master_pdo->prepare($query);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'requests' => $requests]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'update_renewal_request') {
        $req_id = trim($_POST['request_id'] ?? '');
        $new_status = strtoupper(trim($_POST['status'] ?? 'CONTACTED'));
        $admin_note = trim($_POST['admin_note'] ?? '');

        if (empty($req_id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Request ID is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt = $master_pdo->prepare("SELECT * FROM subscription_renewal_requests WHERE request_id = ? OR id = ?");
        $stmt->execute([$req_id, is_numeric($req_id) ? (int)$req_id : 0]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Renewal request '$req_id' not found."]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $master_pdo->prepare("
            UPDATE subscription_renewal_requests
            SET status = ?, admin_note = ?, processed_at = CURRENT_TIMESTAMP, processed_by = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$new_status, $admin_note, $super_admin_user, $req['id']]);

        log_super_admin_action($master_pdo, 'RENEWAL_REQUEST_' . $new_status, $super_admin_user, $req['library_code'], 'SUCCESS', [
            'request_id' => $req['request_id'],
            'status' => $new_status,
            'admin_note' => $admin_note
        ]);

        echo json_encode(['success' => true, 'message' => "Renewal request {$req['request_id']} updated to $new_status.", 'status' => $new_status]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_customer_profile') {
        $code = strtoupper(trim($_GET['library_code'] ?? ($_POST['library_code'] ?? '')));
        if (empty($code)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Library code is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmtLib = $master_pdo->prepare("
            SELECT l.*, s.plan_name, s.max_students, s.valid_until, s.status as sub_status,
                   b.logo_url, b.primary_color, b.contact_phone, b.tagline,
                   c.db_driver, c.db_file
            FROM libraries l
            LEFT JOIN subscriptions s ON l.library_code = s.library_code
            LEFT JOIN library_branding b ON l.library_code = b.library_code
            LEFT JOIN tenant_db_configs c ON l.library_code = c.library_code
            WHERE l.library_code = ?
        ");
        $stmtLib->execute([$code]);
        $lib = $stmtLib->fetch(PDO::FETCH_ASSOC);

        if (!$lib) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Library not found: $code"]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $total_inv_count = (int)$master_pdo->query("SELECT COUNT(*) FROM invoices WHERE library_code = '$code'")->fetchColumn();
        $paid_inv_count = (int)$master_pdo->query("SELECT COUNT(*) FROM invoices WHERE library_code = '$code' AND status = 'PAID'")->fetchColumn();
        $pending_inv_count = (int)$master_pdo->query("SELECT COUNT(*) FROM invoices WHERE library_code = '$code' AND status IN ('ISSUED', 'PAYMENT PENDING')")->fetchColumn();
        $total_paid_amount = (float)$master_pdo->query("SELECT COALESCE(SUM(amount), 0.00) FROM invoices WHERE library_code = '$code' AND status = 'PAID'")->fetchColumn();
        $last_payment_date = $master_pdo->query("SELECT MAX(paid_at) FROM invoices WHERE library_code = '$code' AND status = 'PAID'")->fetchColumn();

        $invoices = $master_pdo->query("SELECT * FROM invoices WHERE library_code = '$code' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        $payments = $master_pdo->query("SELECT * FROM manual_payments WHERE library_code = '$code' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        $renewal_requests = $master_pdo->query("SELECT * FROM subscription_renewal_requests WHERE library_code = '$code' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        $audit_logs = $master_pdo->query("SELECT * FROM super_admin_audit_logs WHERE library_code = '$code' ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);

        $today = date('Y-m-d');
        $diff_days = (int)ceil((strtotime($lib['valid_until'] ?? $today) - strtotime($today)) / 86400);

        echo json_encode([
            'success' => true,
            'customer_profile' => [
                'library' => $lib,
                'days_remaining' => max(0, $diff_days),
                'commercial_summary' => [
                    'total_invoices' => $total_inv_count,
                    'paid_invoices' => $paid_inv_count,
                    'pending_invoices' => $pending_inv_count,
                    'total_paid_amount' => $total_paid_amount,
                    'last_payment_date' => $last_payment_date
                ],
                'recent_invoices' => $invoices,
                'recent_payments' => $payments,
                'renewal_requests' => $renewal_requests,
                'audit_logs' => $audit_logs
            ]
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_support_settings') {
        $rows = $master_pdo->query("SELECT setting_key, setting_value FROM support_settings")->fetchAll(PDO::FETCH_ASSOC);
        $settings = [];
        foreach ($rows as $r) { $settings[$r['setting_key']] = $r['setting_value']; }
        echo json_encode(['success' => true, 'settings' => $settings]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'update_support_settings') {
        $settings = [
            'support_name' => trim($_POST['support_name'] ?? 'StudySpace SaaS Billing & Support'),
            'support_email' => trim($_POST['support_email'] ?? 'support@studyspace.com'),
            'support_phone' => trim($_POST['support_phone'] ?? '+91 98765 43210'),
            'support_whatsapp' => trim($_POST['support_whatsapp'] ?? '+91 98765 43210'),
            'support_message' => trim($_POST['support_message'] ?? 'Please contact StudySpace billing administrator for support.')
        ];

        $stmt = $master_pdo->prepare("
            INSERT INTO support_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON CONFLICT(setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP
        ");
        foreach ($settings as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        log_super_admin_action($master_pdo, 'SUPPORT_SETTINGS_UPDATED', $super_admin_user, null, 'SUCCESS', $settings);

        echo json_encode(['success' => true, 'message' => 'Support contact settings updated successfully.', 'settings' => $settings]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'list_backups') {
        $code = strtoupper(trim($_GET['library_code'] ?? ($_POST['library_code'] ?? '')));
        $query = "SELECT * FROM backup_metadata WHERE 1=1";
        $params = [];
        if (!empty($code)) {
            $query .= " AND (library_code = ? OR scope = 'MASTER')";
            $params[] = $code;
        }
        $query .= " ORDER BY id DESC LIMIT 100";
        $stmt = $master_pdo->prepare($query);
        $stmt->execute($params);
        $backups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'backups' => $backups]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'create_backup') {
        $code = strtoupper(trim($_POST['library_code'] ?? ($_GET['library_code'] ?? 'MASTER')));
        $scope = ($code === 'MASTER') ? 'MASTER' : 'TENANT';

        $backups_root = __DIR__ . '/../data/backups';
        if (!file_exists($backups_root)) @mkdir($backups_root, 0777, true);

        $target_dir = $backups_root . '/' . ($scope === 'MASTER' ? 'master' : $code);
        if (!file_exists($target_dir)) @mkdir($target_dir, 0777, true);

        $timestamp = date('Ymd_His');
        if ($scope === 'MASTER') {
            $src_file = get_master_db_path();
            $filename = "studyspace_master_{$timestamp}.sqlite";
        } else {
            $stmt_chk = $master_pdo->prepare("SELECT id FROM libraries WHERE library_code = ?");
            $stmt_chk->execute([$code]);
            if (!$stmt_chk->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => "Library code not registered: $code"]);
                if (defined('IN_TEST_SUITE')) return; else exit();
            }
            $src_file = TenantDatabaseFactory::resolveTenantDbPath($code);
            $filename = "tenant_{$code}_{$timestamp}.sqlite";
        }

        if (!file_exists($src_file)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Source database file does not exist: $src_file"]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $dest_file = $target_dir . '/' . $filename;
        if (!copy($src_file, $dest_file)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => "Failed to copy database file for backup."]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        @chmod($dest_file, 0777);
        $file_size = filesize($dest_file);
        $sha256 = hash_file('sha256', $dest_file);

        // Verify SQLite Integrity of created backup
        try {
            $b_pdo = new PDO("sqlite:" . $dest_file);
            $b_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $chk_res = $b_pdo->query("PRAGMA integrity_check")->fetchColumn();
            if ($chk_res !== 'ok') {
                @unlink($dest_file);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => "Backup failed integrity check: $chk_res"]);
                if (defined('IN_TEST_SUITE')) return; else exit();
            }
            $b_pdo = null;
        } catch (Exception $e) {
            @unlink($dest_file);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => "Backup integrity check failed: " . $e->getMessage()]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $backup_id = 'BK-' . ($scope === 'MASTER' ? 'MASTER' : $code) . '-' . $timestamp . '-' . rand(100, 999);
        $stmt_ins = $master_pdo->prepare("
            INSERT INTO backup_metadata (backup_id, scope, library_code, file_name, file_path, file_size, checksum, status, verified_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'VERIFIED', CURRENT_TIMESTAMP)
        ");
        $stmt_ins->execute([$backup_id, $scope, ($scope === 'MASTER' ? null : $code), $filename, $dest_file, $file_size, $sha256]);

        log_super_admin_action($master_pdo, 'BACKUP_CREATED', $super_admin_user, ($scope === 'MASTER' ? null : $code), 'SUCCESS', [
            'backup_id' => $backup_id,
            'file_name' => $filename,
            'sha256' => $sha256,
            'file_size' => $file_size
        ]);

        echo json_encode([
            'success' => true,
            'message' => "Backup created successfully.",
            'backup' => [
                'backup_id' => $backup_id,
                'scope' => $scope,
                'library_code' => $code,
                'file_name' => $filename,
                'file_path' => $dest_file,
                'file_size' => $file_size,
                'checksum' => $sha256,
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'download_backup') {
        $backup_id = trim($_GET['backup_id'] ?? ($_POST['backup_id'] ?? ''));
        if (empty($backup_id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Backup ID is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt = $master_pdo->prepare("SELECT * FROM backup_metadata WHERE backup_id = ? OR id = ? OR file_name = ?");
        $stmt->execute([$backup_id, is_numeric($backup_id) ? (int)$backup_id : 0, $backup_id]);
        $bk = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bk || !file_exists($bk['file_path'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Backup file not found on disk.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if (defined('IN_TEST_SUITE')) {
            echo json_encode(['success' => true, 'backup' => $bk]);
            return;
        }

        header('Content-Type: application/x-sqlite3');
        header('Content-Disposition: attachment; filename="' . basename($bk['file_name']) . '"');
        header('Content-Length: ' . filesize($bk['file_path']));
        readfile($bk['file_path']);
        exit();

    } elseif ($action === 'restore_backup') {
        $code = strtoupper(trim($_POST['library_code'] ?? ($_GET['library_code'] ?? '')));
        $backup_id = trim($_POST['backup_id'] ?? ($_GET['backup_id'] ?? ''));

        if (empty($code) || empty($backup_id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Library code and backup ID are required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt = $master_pdo->prepare("SELECT * FROM backup_metadata WHERE backup_id = ? OR id = ? OR file_name = ?");
        $stmt->execute([$backup_id, is_numeric($backup_id) ? (int)$backup_id : 0, $backup_id]);
        $bk = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bk) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Backup '$backup_id' not found in backup registry."]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // STRICT TENANT MATCH SECURITY CHECK
        if ($bk['scope'] === 'TENANT' && strtoupper(trim($bk['library_code'])) !== $code) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => "CROSS-TENANT RESTORE BLOCKED: Backup {$bk['backup_id']} belongs to tenant '{$bk['library_code']}', cannot restore into target tenant '$code'."
            ]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if (!file_exists($bk['file_path'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Backup file does not exist on disk: {$bk['file_path']}"]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Verify SHA256 Checksum on Backup File
        $actual_sha256 = hash_file('sha256', $bk['file_path']);
        if (!empty($bk['checksum']) && $actual_sha256 !== $bk['checksum']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => "BACKUP CHECKSUM MISMATCH: Backup file hash ($actual_sha256) does not match recorded checksum ({$bk['checksum']}). Restoration aborted."
            ]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Verify SQLite Integrity of Backup File
        try {
            $b_pdo = new PDO("sqlite:" . $bk['file_path']);
            $b_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $chk_res = $b_pdo->query("PRAGMA integrity_check")->fetchColumn();
            if ($chk_res !== 'ok') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Backup file failed SQLite integrity check: $chk_res. Restoration aborted."]);
                if (defined('IN_TEST_SUITE')) return; else exit();
            }
            $b_pdo = null;
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Backup file failed SQLite integrity verification: " . $e->getMessage()]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Target File Resolution
        if ($code === 'MASTER') {
            $target_file = get_master_db_path();
        } else {
            $target_file = TenantDatabaseFactory::resolveTenantDbPath($code);
        }

        // CREATE PRE-RESTORE SAFETY SNAPSHOT
        $backups_root = __DIR__ . '/../data/backups/' . strtolower($code);
        if (!file_exists($backups_root)) @mkdir($backups_root, 0777, true);
        $safety_file = $backups_root . '/pre_restore_safety_' . date('Ymd_His') . '.sqlite';

        if (file_exists($target_file)) {
            copy($target_file, $safety_file);
        }

        TenantDatabaseFactory::clearCache();

        // Perform Restore Copy
        if (!copy($bk['file_path'], $target_file)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => "Failed to copy backup file over target database."]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }
        @chmod($target_file, 0777);

        // Post-Restore Integrity & Foreign Key Check
        try {
            $t_pdo = new PDO("sqlite:" . $target_file);
            $t_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $t_chk = $t_pdo->query("PRAGMA integrity_check")->fetchColumn();
            $fk_errs = $t_pdo->query("PRAGMA foreign_key_check")->fetchAll();
            if ($t_chk !== 'ok' || count($fk_errs) > 0) {
                // Revert from safety snapshot
                if (file_exists($safety_file)) {
                    copy($safety_file, $target_file);
                }
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => "Restored database failed post-restore verification. Reverted to safety snapshot."]);
                if (defined('IN_TEST_SUITE')) return; else exit();
            }
            $t_pdo = null;
        } catch (Exception $e) {
            if (file_exists($safety_file)) copy($safety_file, $target_file);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => "Post-restore database verification exception: " . $e->getMessage()]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        log_super_admin_action($master_pdo, 'RESTORE_EXECUTED', $super_admin_user, $code, 'SUCCESS', [
            'backup_id' => $bk['backup_id'],
            'safety_snapshot' => $safety_file,
            'sha256' => $actual_sha256
        ]);

        echo json_encode([
            'success' => true,
            'message' => "Database for $code successfully restored from backup {$bk['backup_id']}.",
            'library_code' => $code,
            'backup_id' => $bk['backup_id'],
            'safety_snapshot' => $safety_file
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'generate_apk_config') {
        $code = strtoupper(trim($_POST['library_code'] ?? ($_GET['library_code'] ?? '')));
        if (empty($code)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Library code is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt = $master_pdo->prepare("
            SELECT l.library_code, l.name, l.status,
                   b.logo_url, b.primary_color, b.contact_phone, b.address, b.tagline,
                   s.plan_name, s.valid_until
            FROM libraries l
            LEFT JOIN library_branding b ON l.library_code = b.library_code
            LEFT JOIN subscriptions s ON l.library_code = s.library_code
            WHERE l.library_code = ?
        ");
        $stmt->execute([$code]);
        $lib = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lib) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Library code not found: $code"]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $flavor_name = strtolower($code);
        $package_id = "com.studyspace." . $flavor_name;
        $build_id = "APK-BUILD-" . $code . "-" . date('YmdHis');
        $api_base_url = env_get('API_BASE_URL', 'https://studyspace-api-test.de.deplexo.com');

        $build_config = [
            'build_id' => $build_id,
            'tenant_code' => $code,
            'flavor_name' => $flavor_name,
            'package_id' => $package_id,
            'app_name' => $lib['name'],
            'tagline' => $lib['tagline'] ?? 'Self Study Hall',
            'primary_color' => $lib['primary_color'] ?? '#1D4ED8',
            'logo_url' => $lib['logo_url'] ?? '',
            'api_base_url' => $api_base_url,
            'json_auth_endpoint' => $api_base_url . '/api/json_auth.php',
            'json_tenant_endpoint' => $api_base_url . '/api/json_tenant.php?code=' . $code,
            'json_admin_endpoint' => $api_base_url . '/api/json_admin_actions.php',
            'json_parent_endpoint' => $api_base_url . '/api/json_parent_actions.php',
            'created_at' => date('Y-m-d H:i:s')
        ];

        $stmt_ins = $master_pdo->prepare("
            INSERT INTO apk_build_metadata (build_id, library_code, flavor_name, package_id, version_name, build_number, status)
            VALUES (?, ?, ?, ?, '1.0.0', 1, 'CONFIGURED')
        ");
        $stmt_ins->execute([$build_id, $code, $flavor_name, $package_id]);

        log_super_admin_action($master_pdo, 'APK_CONFIG_GENERATED', $super_admin_user, $code, 'SUCCESS', $build_config);

        echo json_encode([
            'success' => true,
            'message' => "APK build configuration for library $code generated successfully.",
            'build_config' => $build_config
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action specified.']);
        if (defined('IN_TEST_SUITE')) return; else exit();
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    if (defined('IN_TEST_SUITE')) return; else exit();
}
