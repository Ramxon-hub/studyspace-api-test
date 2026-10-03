<?php
// api/diagnostics/admin_dashboard_render_check.php — Execution & Render Path Diagnostic Endpoint

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$fatalErrorDetected = false;
$fatalErrorMessage = '';

// Register shutdown handler to catch unhandled fatal PHP errors during render simulation
register_shutdown_function(function() use (&$fatalErrorDetected, &$fatalErrorMessage) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'header_rendered' => true,
            'dashboard_execution_started' => true,
            'first_dashboard_html_reached' => false,
            'execution_completed' => false,
            'pdo_queries' => 'FAILED',
            'master_queries' => 'FAILED',
            'includes' => 'OK',
            'output_buffering' => 'OK',
            'fatal_error_detected' => true,
            'javascript_dependency_status' => 'UNKNOWN',
            'diagnosis' => 'Fatal PHP execution error interrupted dashboard rendering: ' . $error['message']
        ], JSON_PRETTY_PRINT);
    }
});

try {
    require_once __DIR__ . '/../../config/auth.php';

    // 1. Require Authenticated Admin Session
    if (!is_logged_in() || !is_admin()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'UNAUTHORIZED: Authenticated Admin session required.'
        ], JSON_PRETTY_PRINT);
        exit;
    }

    $headerRendered = false;
    $dashboardExecutionStarted = true;
    $firstDashboardHtmlReached = false;
    $executionCompleted = false;
    $pdoQueriesStatus = "OK";
    $masterQueriesStatus = "OK";
    $includesStatus = "OK";
    $outputBufferingStatus = "OK";
    $jsDepStatus = "OK";
    $diagnosticNotes = [];

    // 2. Test Output Buffering & Header Render
    ob_start();
    try {
        require_once __DIR__ . '/../../includes/header.php';
        $headerOutput = ob_get_contents();
        if (!empty($headerOutput) && strpos($headerOutput, '<nav') !== false) {
            $headerRendered = true;
        }
    } catch (Throwable $t) {
        $includesStatus = "FAILED";
        $diagnosticNotes[] = "Failed during includes/header.php render.";
    }
    ob_end_clean();

    // 3. Resolve Tenant Context
    $tenantPdo = null;
    $tenantCode = 'LIB001';
    try {
        $tenantContext = resolve_tenant_context();
        $tenantPdo = $tenantContext['pdo'];
        $tenantCode = $tenantContext['library_code'];
    } catch (Throwable $t) {
        $pdoQueriesStatus = "FAILED";
        $diagnosticNotes[] = "Tenant resolution or connection failed.";
    }

    if ($tenantPdo !== null) {
        $today = date('Y-m-d');
        
        $tQueries = [
            'total_students' => "SELECT COUNT(*) FROM users WHERE role = 'student'",
            'approved_students' => "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'approved'",
            'pending_students' => "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'pending'",
            'hold_students' => "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'hold'",
            'total_seats' => "SELECT COUNT(*) FROM seats WHERE is_active = 1",
            'occupied_seats' => "SELECT COUNT(DISTINCT seat_id) FROM allocations WHERE status = 'active'",
            'today_present' => "SELECT COUNT(*) FROM attendance WHERE date = '$today' AND check_out_time IS NULL",
            'today_checkins' => "SELECT COUNT(*) FROM attendance WHERE date = '$today'",
            'shifts' => "SELECT * FROM shifts ORDER BY id ASC",
            'seats' => "SELECT * FROM seats WHERE is_active = 1 ORDER BY row_label ASC, seat_number ASC",
            'all_allocations' => "SELECT seat_id, shift_id, user_id FROM allocations WHERE status = 'active'",
            'students' => "SELECT u.*, a.id as allocation_id, a.seat_id, a.shift_id, a.start_date, s.seat_number, s.row_label, sh.name as shift_name, sh.fee_amount FROM users u LEFT JOIN allocations a ON u.id = a.user_id AND a.status IN ('active', 'hold', 'waiting') LEFT JOIN seats s ON a.seat_id = s.id LEFT JOIN shifts sh ON a.shift_id = sh.id WHERE u.role = 'student' AND (u.is_deleted IS NULL OR u.is_deleted = 0) ORDER BY u.id DESC",
            'recycle_bin' => "SELECT u.*, " . db_days_left_expression('u.deleted_at', $tenantPdo) . " as days_left FROM users u WHERE u.role = 'student' AND u.is_deleted = 1 ORDER BY u.deleted_at DESC",
            'attendance_roster' => "SELECT u.id as student_id, u.name as student_name, u.phone, s.seat_number, sh.name as shift_name, att.id as attendance_id, att.check_in_time, att.check_out_time FROM users u JOIN allocations a ON u.id = a.user_id AND a.status = 'active' JOIN seats s ON a.seat_id = s.id JOIN shifts sh ON a.shift_id = sh.id LEFT JOIN attendance att ON u.id = att.user_id AND att.date = '$today' WHERE u.role = 'student' AND (u.status = 'approved' OR u.status = 'active') AND (u.is_deleted IS NULL OR u.is_deleted = 0) ORDER BY att.check_in_time DESC, u.name ASC",
            'complaints' => "SELECT c.*, u.name as student_name, u.phone FROM complaints c JOIN users u ON c.user_id = u.id ORDER BY c.id DESC",
            'notifications' => "SELECT n.*, u.name as student_name FROM notifications n LEFT JOIN users u ON n.user_id = u.id ORDER BY n.id DESC"
        ];

        $studentsListForLedger = [];
        foreach ($tQueries as $qTag => $qSql) {
            try {
                $qStmt = $tenantPdo->query($qSql);
                if ($qTag === 'students' && $qStmt) {
                    $studentsListForLedger = $qStmt->fetchAll();
                }
            } catch (Throwable $t) {
                $pdoQueriesStatus = "FAILED";
                $fatalErrorDetected = true;
                $diagnosticNotes[] = "Tenant query failed: " . $qTag;
            }
        }

        // Test fee ledger function calls
        try {
            foreach ($studentsListForLedger as $stu) {
                if (!empty($stu['status']) && $stu['status'] === 'approved' && !empty($stu['allocation_id'])) {
                    get_student_fee_status($tenantPdo, $stu['allocation_id'], $stu['start_date']);
                }
            }
        } catch (Throwable $t) {
            $pdoQueriesStatus = "FAILED";
            $fatalErrorDetected = true;
            $diagnosticNotes[] = "Fee status ledger calculation failed: get_student_fee_status";
        }
    }

    // 5. Test Master Data Queries (Read-Only Simulation of admin_dashboard.php lines 106-127)
    try {
        $masterPdo = get_master_pdo();
        
        $stmt_sub_admin = $masterPdo->prepare("
            SELECT l.name as library_name, s.plan_name, s.max_students, s.valid_until, s.status as sub_status,
                   b.logo_url, b.primary_color, b.contact_phone, b.tagline
            FROM libraries l
            LEFT JOIN subscriptions s ON l.library_code = s.library_code
            LEFT JOIN library_branding b ON l.library_code = b.library_code
            WHERE l.library_code = ?
        ");
        $stmt_sub_admin->execute([$tenantCode]);
        $tenant_sub = $stmt_sub_admin->fetch(PDO::FETCH_ASSOC) ?: [];

        $tenant_invoices = $masterPdo->query("SELECT * FROM invoices WHERE library_code = '$tenantCode' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $tenant_payments = $masterPdo->query("SELECT * FROM manual_payments WHERE library_code = '$tenantCode' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $tenant_renewal_reqs = $masterPdo->query("SELECT * FROM subscription_renewal_requests WHERE library_code = '$tenantCode' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rows_supp = $masterPdo->query("SELECT setting_key, setting_value FROM support_settings")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    } catch (Throwable $t) {
        $masterQueriesStatus = "FAILED";
        $fatalErrorDetected = true;
        $diagnosticNotes[] = "Master Control PDO query execution failed in admin_dashboard subscription section.";
    }

    // 6. First Dashboard HTML Reached Check
    // In admin_dashboard.php, line 143 (<script>const isAdmin = true;</script>) and line 170 (Banner Card)
    // are reached if all pre-rendering data queries complete without throwing an exception.
    if (!$fatalErrorDetected && $pdoQueriesStatus === "OK" && $masterQueriesStatus === "OK") {
        $firstDashboardHtmlReached = true;
        $executionCompleted = true;
    }

    // 7. JavaScript & Asset Check
    $mainJsPath = __DIR__ . '/../../assets/js/main.js';
    if (!file_exists($mainJsPath) || filesize($mainJsPath) === 0) {
        $jsDepStatus = "FAILED";
        $diagnosticNotes[] = "assets/js/main.js is missing or empty.";
    }

    // 8. Compile Diagnosis Summary
    if (!$fatalErrorDetected && $firstDashboardHtmlReached) {
        $diagnosis = "Execution flow reaches first dashboard HTML (Line 143/170). All tenant and master queries complete cleanly. If the screen appears blank in browser, check if client-side CSS/JS or hosting anti-bot script hides DOM elements.";
    } else {
        $diagnosis = "Dashboard execution halted before rendering main content: " . implode(" | ", $diagnosticNotes);
    }

    http_response_code($fatalErrorDetected ? 500 : 200);

    echo json_encode([
        'success' => !$fatalErrorDetected,
        'header_rendered' => $headerRendered,
        'dashboard_execution_started' => $dashboardExecutionStarted,
        'first_dashboard_html_reached' => $firstDashboardHtmlReached,
        'execution_completed' => $executionCompleted,
        'pdo_queries' => $pdoQueriesStatus,
        'master_queries' => $masterQueriesStatus,
        'includes' => $includesStatus,
        'output_buffering' => $outputBufferingStatus,
        'fatal_error_detected' => $fatalErrorDetected,
        'javascript_dependency_status' => $jsDepStatus,
        'diagnosis' => $diagnosis
    ], JSON_PRETTY_PRINT);

} catch (Throwable $t) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'header_rendered' => true,
        'dashboard_execution_started' => true,
        'first_dashboard_html_reached' => false,
        'execution_completed' => false,
        'pdo_queries' => 'FAILED',
        'master_queries' => 'FAILED',
        'includes' => 'FAILED',
        'output_buffering' => 'FAILED',
        'fatal_error_detected' => true,
        'javascript_dependency_status' => 'UNKNOWN',
        'diagnosis' => 'Throwable caught during dashboard render test execution.'
    ], JSON_PRETTY_PRINT);
}
