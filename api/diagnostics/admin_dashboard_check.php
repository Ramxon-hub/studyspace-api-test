<?php
// api/diagnostics/admin_dashboard_check.php — Read-Only Admin Dashboard Diagnostic Endpoint

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    require_once __DIR__ . '/../../config/auth.php';

    // 1. Require Authenticated ADMIN Session
    if (!is_logged_in() || !is_admin()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'UNAUTHORIZED: Authenticated Admin session required.'
        ], JSON_PRETTY_PRINT);
        exit;
    }

    $fatalErrorDetected = false;
    $diagnosticMessages = [];

    // 2. Resolve Tenant Context
    $tenantResolution = "FAILED";
    $tenantPdo = null;
    try {
        $tenantContext = resolve_tenant_context();
        $tenantPdo = $tenantContext['pdo'];
        $tenantCode = $tenantContext['library_code'];
        if ($tenantPdo !== null && !empty($tenantCode)) {
            $tenantResolution = "RESOLVED";
        }
    } catch (Exception $e) {
        $fatalErrorDetected = true;
        $diagnosticMessages[] = "Tenant resolution error: unable to establish connection handle";
    }

    // 3. Required Files Existence Check
    $requiredFilesStatus = "OK";
    $filesToCheck = [
        'includes/header.php' => __DIR__ . '/../../includes/header.php',
        'includes/footer.php' => __DIR__ . '/../../includes/footer.php',
        'config/auth.php' => __DIR__ . '/../../config/auth.php',
        'config/db.php' => __DIR__ . '/../../config/db.php',
        'config/master_db.php' => __DIR__ . '/../../config/master_db.php',
        'config/tenant_router.php' => __DIR__ . '/../../config/tenant_router.php',
        'assets/css/style.css' => __DIR__ . '/../../assets/css/style.css',
        'assets/js/main.js' => __DIR__ . '/../../assets/js/main.js'
    ];

    foreach ($filesToCheck as $label => $path) {
        if (!file_exists($path) || !is_readable($path)) {
            $requiredFilesStatus = "MISSING_DEPENDENCY";
            $fatalErrorDetected = true;
            $diagnosticMessages[] = "Missing required file dependency: " . $label;
        }
    }

    // 4. Admin Dashboard PHP File Inspection
    $dashboardPhpStatus = "OK";
    $adminDashboardPath = __DIR__ . '/../../admin_dashboard.php';
    if (!file_exists($adminDashboardPath) || !is_readable($adminDashboardPath)) {
        $dashboardPhpStatus = "UNREADABLE";
        $fatalErrorDetected = true;
        $diagnosticMessages[] = "admin_dashboard.php is unreadable or missing.";
    } else {
        $code = file_get_contents($adminDashboardPath);
        if (empty($code)) {
            $dashboardPhpStatus = "EMPTY";
            $fatalErrorDetected = true;
            $diagnosticMessages[] = "admin_dashboard.php is 0 bytes.";
        }
    }

    // 5. Data Dependencies (Read-Only Database Query Health Checks)
    $dataDependenciesStatus = "OK";
    if ($tenantResolution === "RESOLVED" && $tenantPdo !== null) {
        // Tenant Database queries executed in admin_dashboard.php
        $tenantQueries = [
            'users_student_count' => "SELECT COUNT(*) FROM users WHERE role = 'student'",
            'users_approved' => "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'approved'",
            'users_pending' => "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'pending'",
            'users_hold' => "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'hold'",
            'seats_active' => "SELECT COUNT(*) FROM seats WHERE is_active = 1",
            'allocations_active' => "SELECT COUNT(DISTINCT seat_id) FROM allocations WHERE status = 'active'",
            'attendance_today' => "SELECT COUNT(*) FROM attendance WHERE date = CURRENT_DATE",
            'shifts_list' => "SELECT * FROM shifts ORDER BY id ASC",
            'all_allocations' => "SELECT seat_id, shift_id, user_id FROM allocations WHERE status = 'active'",
            'students_list' => "SELECT u.id, u.name, u.email, u.phone, a.id as allocation_id FROM users u LEFT JOIN allocations a ON u.id = a.user_id WHERE u.role = 'student' LIMIT 5",
            'recycle_bin' => "SELECT u.id, u.name FROM users u WHERE u.role = 'student' AND u.is_deleted = 1 LIMIT 5",
            'complaints' => "SELECT c.id, c.subject FROM complaints c JOIN users u ON c.user_id = u.id LIMIT 5",
            'notifications' => "SELECT n.id, n.title FROM notifications n LEFT JOIN users u ON n.user_id = u.id LIMIT 5",
            'parents' => "SELECT id, name FROM users WHERE role = 'parent' AND (is_deleted IS NULL OR is_deleted = 0) LIMIT 5"
        ];

        foreach ($tenantQueries as $key => $sql) {
            try {
                $stmt = $tenantPdo->query($sql);
                if ($stmt === false) {
                    $dataDependenciesStatus = "QUERY_FAILED";
                    $fatalErrorDetected = true;
                    $diagnosticMessages[] = "Tenant database dataset query failed: " . $key;
                }
            } catch (Exception $e) {
                $dataDependenciesStatus = "QUERY_EXCEPTION";
                $fatalErrorDetected = true;
                $diagnosticMessages[] = "Tenant database dataset query exception: " . $key;
            }
        }

        // Master Database queries executed in admin_dashboard.php (lines 106-125)
        try {
            $masterPdo = get_master_pdo();
            $masterQueries = [
                'libraries_subscription' => "SELECT l.name, s.plan_name FROM libraries l LEFT JOIN subscriptions s ON l.library_code = s.library_code WHERE l.library_code = 'LIB001'",
                'invoices_list' => "SELECT * FROM invoices WHERE library_code = 'LIB001' LIMIT 5",
                'manual_payments_list' => "SELECT * FROM manual_payments WHERE library_code = 'LIB001' LIMIT 5",
                'renewal_requests_list' => "SELECT * FROM subscription_renewal_requests WHERE library_code = 'LIB001' LIMIT 5",
                'support_settings_list' => "SELECT setting_key, setting_value FROM support_settings"
            ];

            foreach ($masterQueries as $mKey => $mSql) {
                try {
                    $mStmt = $masterPdo->query($mSql);
                    if ($mStmt === false) {
                        $dataDependenciesStatus = "MASTER_QUERY_FAILED";
                        $fatalErrorDetected = true;
                        $diagnosticMessages[] = "Master database query failed: " . $mKey;
                    }
                } catch (Exception $e) {
                    $dataDependenciesStatus = "MASTER_QUERY_EXCEPTION";
                    $fatalErrorDetected = true;
                    $diagnosticMessages[] = "Master database dataset query exception: " . $mKey;
                }
            }
        } catch (Exception $e) {
            $dataDependenciesStatus = "MASTER_DB_UNAVAILABLE";
            $fatalErrorDetected = true;
            $diagnosticMessages[] = "Master control database connection failed during dashboard load.";
        }
    }

    // 6. API Dependencies Check
    $apiDependenciesStatus = "OK";
    $apiEndpoints = [
        'api/admin_actions.php' => __DIR__ . '/../admin_actions.php',
        'api/json_admin_actions.php' => __DIR__ . '/../json_admin_actions.php',
        'api/json_tenant_billing.php' => __DIR__ . '/../json_tenant_billing.php',
        'api/seat_matrix.php' => __DIR__ . '/../seat_matrix.php'
    ];

    foreach ($apiEndpoints as $name => $filePath) {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            $apiDependenciesStatus = "DEGRADED";
            $fatalErrorDetected = true;
            $diagnosticMessages[] = "API endpoint dependency unavailable: " . $name;
        }
    }

    // 7. Compile Diagnostic Summary
    if (!$fatalErrorDetected) {
        $diagnosis = "All dashboard PHP files, required dependencies, tenant routing, master DB queries, and API endpoints are healthy and accessible.";
    } else {
        $diagnosis = "Dashboard rendering issue detected: " . implode(" | ", $diagnosticMessages);
    }

    http_response_code($fatalErrorDetected ? 500 : 200);

    echo json_encode([
        'success' => !$fatalErrorDetected,
        'dashboard_php' => $dashboardPhpStatus,
        'required_files' => $requiredFilesStatus,
        'tenant_resolution' => $tenantResolution,
        'data_dependencies' => $dataDependenciesStatus,
        'api_dependencies' => $apiDependenciesStatus,
        'fatal_error_detected' => $fatalErrorDetected,
        'diagnosis' => $diagnosis
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'dashboard_php' => 'FAILED',
        'required_files' => 'UNKNOWN',
        'tenant_resolution' => 'FAILED',
        'data_dependencies' => 'FAILED',
        'api_dependencies' => 'UNKNOWN',
        'fatal_error_detected' => true,
        'diagnosis' => 'Fatal exception during diagnostic execution.'
    ], JSON_PRETTY_PRINT);
}
