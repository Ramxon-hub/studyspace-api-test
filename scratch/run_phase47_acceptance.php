<?php
/**
 * Phase 47 — Complete Parent Management, Parent UI Redesign & Tenant Backup Acceptance Test Suite
 */

define('IN_TEST_SUITE', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/master_db.php';
require_once __DIR__ . '/../config/tenant_router.php';

$pass = 0;
$fail = 0;

function testAssert($condition, $description) {
    global $pass, $fail;
    if ($condition) {
        echo "  [PASS] $description\n";
        $pass++;
    } else {
        echo "  [FAIL] $description\n";
        $fail++;
    }
}

$masterPdo = get_master_pdo(true);
$superAdminKey = env_get('SUPER_ADMIN_KEY', 'superadmin_secret_key_2026');

function call_api($script, $post = [], $get = [], $session = null, $headers = []) {
    $_GET = $get;
    $_POST = $post;
    $_REQUEST = array_merge($_GET, $_POST);
    $_SERVER['REQUEST_METHOD'] = !empty($post) ? 'POST' : 'GET';

    if ($session !== null) {
        $_SESSION = $session;
    } else {
        $_SESSION = [];
    }

    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_SUPER_ADMIN_TOKEN'], $_SERVER['HTTP_X_SUPER_ADMIN_KEY'], $_SERVER['HTTP_X_PARENT_ID'], $_SERVER['HTTP_X_LIBRARY_CODE']);
    foreach ($headers as $k => $v) {
        $_SERVER[$k] = $v;
    }

    TenantDatabaseFactory::clearCache();

    ob_start();
    include __DIR__ . '/../api/' . $script;
    $out = ob_get_clean();

    $jsonStart = strpos($out, '{');
    if ($jsonStart !== false) {
        $jsonStr = substr($out, $jsonStart);
        $decoded = json_decode($jsonStr, true);
        if ($decoded !== null) return $decoded;
    }

    return json_decode($out, true) ?: ['_raw' => $out];
}

$saSession = [
    'is_super_admin' => true,
    'role' => 'super_admin',
    'super_admin_user' => 'superadmin'
];
$saHeaders = ['HTTP_X_SUPER_ADMIN_KEY' => $superAdminKey];

echo "=========================================================\n";
echo "PHASE 47 COMPREHENSIVE AUTOMATED ACCEPTANCE TEST SUITE\n";
echo "=========================================================\n\n";

// Setup Test Environment
$pdo1 = TenantDatabaseFactory::getTenantConnection('LIB001');
$pdo2 = TenantDatabaseFactory::getTenantConnection('LIB002');

// Clean up previous test entries cleanly
$pdo1->exec("DELETE FROM users WHERE email IN ('p47_parent1@lib001.com', 'p47_parent_a@lib001.com', 'p47_parent_b@lib001.com')");
$pdo2->exec("DELETE FROM users WHERE email IN ('p47_parent2@lib002.com')");
TenantDatabaseFactory::clearCache();

// ---------------------------------------------------------
// TEST 1 & 2: ADMIN CREATE PARENT IN LIB001 & LIB002
// ---------------------------------------------------------
echo "--- TEST 1 & 2: Admin Create Parent in LIB001 & LIB002 ---\n";
try {
    // Get existing student in LIB001
    $stu1 = $pdo1->query("SELECT id FROM users WHERE role = 'student' AND (is_deleted IS NULL OR is_deleted = 0) LIMIT 1")->fetchColumn();
    testAssert(!empty($stu1), "Found registered student in LIB001 DB (ID: $stu1)");

    // Get existing student in LIB002
    $stu2 = $pdo2->query("SELECT id FROM users WHERE role = 'student' AND (is_deleted IS NULL OR is_deleted = 0) LIMIT 1")->fetchColumn();
    testAssert(!empty($stu2), "Found registered student in LIB002 DB (ID: $stu2)");

    // Create Parent in LIB001 via Admin API
    $res1 = call_api('json_admin_actions.php', [
        'action' => 'create_parent',
        'name' => 'Phase 47 Parent LIB001',
        'email' => 'p47_parent1@lib001.com',
        'phone' => '+91 98765 43210',
        'password' => 'ParentPass123!',
        'relationship' => 'Father',
        'student_ids' => [$stu1]
    ], [], ['role' => 'admin', 'user_id' => 1], ['HTTP_X_LIBRARY_CODE' => 'LIB001']);

    testAssert(($res1['success'] ?? false) === true && !empty($res1['parent_id']), "LIB001 Admin creates Parent account with student link");
    $parentId1 = $res1['parent_id'] ?? 0;

    // Create Parent in LIB002 via Admin API
    $res2 = call_api('json_admin_actions.php', [
        'action' => 'create_parent',
        'name' => 'Phase 47 Parent LIB002',
        'email' => 'p47_parent2@lib002.com',
        'phone' => '+91 98765 43211',
        'password' => 'ParentPass456!',
        'relationship' => 'Mother',
        'student_ids' => [$stu2]
    ], [], ['role' => 'admin', 'user_id' => 1], ['HTTP_X_LIBRARY_CODE' => 'LIB002']);

    testAssert(($res2['success'] ?? false) === true && !empty($res2['parent_id']), "LIB002 Admin creates Parent account with student link");
    $parentId2 = $res2['parent_id'] ?? 0;

} catch (Exception $e) {
    testAssert(false, "Test 1 & 2 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 3 & 4: PARENT AUTHENTICATION (LIB001 & LIB002)
// ---------------------------------------------------------
echo "\n--- TEST 3 & 4: Parent Authentication (LIB001 & LIB002) ---\n";
try {
    $loginRes1 = call_api('json_auth.php', [
        'action' => 'login',
        'email' => 'p47_parent1@lib001.com',
        'password' => 'ParentPass123!'
    ], [], [], ['HTTP_X_LIBRARY_CODE' => 'LIB001']);

    testAssert(($loginRes1['success'] ?? false) === true && ($loginRes1['user']['role'] ?? '') === 'parent', "LIB001 Parent login succeeds");

    $loginRes2 = call_api('json_auth.php', [
        'action' => 'login',
        'email' => 'p47_parent2@lib002.com',
        'password' => 'ParentPass456!'
    ], [], [], ['HTTP_X_LIBRARY_CODE' => 'LIB002']);

    testAssert(($loginRes2['success'] ?? false) === true && ($loginRes2['user']['role'] ?? '') === 'parent', "LIB002 Parent login succeeds");

} catch (Exception $e) {
    testAssert(false, "Test 3 & 4 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 5 & 6: STRICT TENANT ISOLATION (CROSS-TENANT ACCESS BLOCKED)
// ---------------------------------------------------------
echo "\n--- TEST 5 & 6: Strict Tenant Isolation (Cross-Tenant Access Blocked) ---\n";
try {
    // LIB001 Parent attempting to view LIB002 Student DB
    $crossRes1 = call_api('json_parent_actions.php', [
        'action' => 'student_summary',
        'student_id' => $stu2,
        'parent_id' => $parentId1
    ], [], ['user_id' => $parentId1, 'user_role' => 'parent'], ['HTTP_X_LIBRARY_CODE' => 'LIB002', 'HTTP_X_PARENT_ID' => (string)$parentId1]);

    testAssert(($crossRes1['success'] ?? true) === false, "LIB001 Parent blocked from viewing LIB002 student");

    // LIB002 Parent attempting to view LIB001 Student DB
    $crossRes2 = call_api('json_parent_actions.php', [
        'action' => 'student_summary',
        'student_id' => $stu1,
        'parent_id' => $parentId2
    ], [], ['user_id' => $parentId2, 'user_role' => 'parent'], ['HTTP_X_LIBRARY_CODE' => 'LIB001', 'HTTP_X_PARENT_ID' => (string)$parentId2]);

    testAssert(($crossRes2['success'] ?? true) === false, "LIB002 Parent blocked from viewing LIB001 student");

} catch (Exception $e) {
    testAssert(false, "Test 5 & 6 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 7: IDOR PROTECTION (PARENT A CANNOT VIEW PARENT B'S STUDENT)
// ---------------------------------------------------------
echo "\n--- TEST 7: IDOR Protection (Parent A Cannot View Parent B's Student) ---\n";
try {
    // Create Student 2 in LIB001
    $stu1_other = $pdo1->query("SELECT id FROM users WHERE role = 'student' AND id != $stu1 AND (is_deleted IS NULL OR is_deleted = 0) LIMIT 1")->fetchColumn();
    if (empty($stu1_other)) {
        $pdo1->exec("INSERT INTO users (name, email, phone, password, role, status) VALUES ('Other Student', 'other_stu@lib001.com', '9999900000', 'pass', 'student', 'approved')");
        $stu1_other = $pdo1->lastInsertId();
    }

    // Create Parent B in LIB001 linked to Student 2
    $resB = call_api('json_admin_actions.php', [
        'action' => 'create_parent',
        'name' => 'Parent B LIB001',
        'email' => 'p47_parent_b@lib001.com',
        'password' => 'Pass123!',
        'student_ids' => [$stu1_other]
    ], [], ['role' => 'admin', 'user_id' => 1], ['HTTP_X_LIBRARY_CODE' => 'LIB001']);
    $parentIdB = $resB['parent_id'] ?? 0;

    // Parent A attempts to view Student 2 (linked only to Parent B)
    $idorRes = call_api('json_parent_actions.php', [
        'action' => 'student_summary',
        'student_id' => $stu1_other,
        'parent_id' => $parentId1
    ], [], ['user_id' => $parentId1, 'user_role' => 'parent'], ['HTTP_X_LIBRARY_CODE' => 'LIB001', 'HTTP_X_PARENT_ID' => (string)$parentId1]);

    testAssert(($idorRes['success'] ?? true) === false && str_contains($idorRes['message'] ?? '', 'Unauthorized'), "Parent A strictly BLOCKED from viewing Parent B's student (IDOR Guard)");

} catch (Exception $e) {
    testAssert(false, "Test 7 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 8 & 9: PRIVILEGE ESCALATION BLOCKED
// ---------------------------------------------------------
echo "\n--- TEST 8 & 9: Privilege Escalation Protection ---\n";
try {
    // Parent session calls Admin API
    $escRes1 = call_api('json_admin_actions.php', [
        'action' => 'manage_students'
    ], [], ['user_id' => $parentId1, 'user_role' => 'parent'], ['HTTP_X_LIBRARY_CODE' => 'LIB001']);

    testAssert(($escRes1['success'] ?? true) === false, "Parent session blocked from Admin endpoints");

    // Tenant Admin calls Super Admin API
    $escRes2 = call_api('json_super_admin.php', [
        'action' => 'list_libraries'
    ], [], ['user_id' => 1, 'user_role' => 'admin'], ['HTTP_X_LIBRARY_CODE' => 'LIB001']);

    testAssert(($escRes2['success'] ?? true) === false && (str_contains($escRes2['error'] ?? '', '403 Forbidden') || str_contains($escRes2['error'] ?? '', 'Tenant Admin')), "Tenant Admin blocked from Super Admin endpoints (403 Forbidden)");

} catch (Exception $e) {
    testAssert(false, "Test 8 & 9 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 10 & 11: TWO-WAY CHAT & COMPLAINTS WORKFLOW
// ---------------------------------------------------------
echo "\n--- TEST 10 & 11: Two-Way Chat & Complaints Workflow ---\n";
try {
    // Parent sends chat message to Admin
    $chatRes = call_api('json_parent_actions.php', [
        'action' => 'send_chat_message',
        'student_id' => $stu1,
        'message' => 'Hello Admin, checking child attendance status.'
    ], [], ['user_id' => $parentId1, 'user_role' => 'parent'], ['HTTP_X_LIBRARY_CODE' => 'LIB001', 'HTTP_X_PARENT_ID' => (string)$parentId1]);

    testAssert(($chatRes['success'] ?? false) === true && !empty($chatRes['message_id']), "Parent sends chat message to Admin successfully");

    // Parent files complaint
    $compRes = call_api('json_parent_actions.php', [
        'action' => 'create_complaint',
        'student_id' => $stu1,
        'subject' => 'Shift Change Query',
        'description' => 'Requesting morning shift change.'
    ], [], ['user_id' => $parentId1, 'user_role' => 'parent'], ['HTTP_X_LIBRARY_CODE' => 'LIB001', 'HTTP_X_PARENT_ID' => (string)$parentId1]);

    testAssert(($compRes['success'] ?? false) === true, "Parent files complaint/query successfully");

} catch (Exception $e) {
    testAssert(false, "Test 10 & 11 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 12: COMPLETE TENANT BACKUP COVERAGE
// ---------------------------------------------------------
echo "\n--- TEST 12: Complete Tenant Backup Coverage & Verification ---\n";
try {
    $bkInfo = call_api('json_admin_actions.php', [
        'action' => 'get_database_backup_info'
    ], [], ['user_id' => 1, 'user_role' => 'admin'], ['HTTP_X_LIBRARY_CODE' => 'LIB001']);

    testAssert(($bkInfo['success'] ?? false) === true, "Tenant backup info endpoint returns success");
    testAssert(!empty($bkInfo['sha256']), "Backup info includes SHA256 checksum");
    testAssert(($bkInfo['integrity_status'] ?? '') === 'ok', "SQLite integrity check passes (status: ok)");

    $counts = $bkInfo['table_counts'] ?? [];
    $allTablesPresent = isset($counts['users'], $counts['parent_student_links'], $counts['seats'], $counts['shifts'], $counts['allocations'], $counts['fee_payments'], $counts['attendance'], $counts['complaints'], $counts['chat_messages'], $counts['notifications'], $counts['system_settings'], $counts['schema_migrations']);
    testAssert($allTablesPresent === true, "Backup info covers all 12 tenant database tables (including parent_student_links)");

} catch (Exception $e) {
    testAssert(false, "Test 12 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 13: ISOLATED TEST RESTORE VERIFICATION
// ---------------------------------------------------------
echo "\n--- TEST 13: Isolated Test Restore Verification ---\n";
try {
    $srcPath = TenantDatabaseFactory::resolveTenantDbPath('LIB001');
    $tempCopy = sys_get_temp_dir() . '/p47_restore_test_' . time() . '.sqlite';
    if (file_exists($tempCopy)) @unlink($tempCopy);

    copy($srcPath, $tempCopy);

    $testPdo = new PDO("sqlite:" . $tempCopy);
    $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $parentCnt = (int)$testPdo->query("SELECT COUNT(*) FROM users WHERE role = 'parent'")->fetchColumn();
    $linkCnt = (int)$testPdo->query("SELECT COUNT(*) FROM parent_student_links")->fetchColumn();
    $fkCheck = $testPdo->query("PRAGMA foreign_key_check")->fetchAll();

    testAssert($parentCnt > 0 && $linkCnt > 0, "Restored database copy contains parent user accounts ($parentCnt) and links ($linkCnt)");
    testAssert(count($fkCheck) === 0, "Restored database foreign key check passed (0 errors)");

    $testPdo = null;
    if (file_exists($tempCopy)) @unlink($tempCopy);

} catch (Exception $e) {
    testAssert(false, "Test 13 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 14: SUPER ADMIN BACKUP CENTER & CROSS-TENANT RESTORE BLOCKING
// ---------------------------------------------------------
echo "\n--- TEST 14: Super Admin Backup Center & Cross-Tenant Restore Blocking ---\n";
try {
    $resBk2 = call_api('json_super_admin.php', ['action' => 'create_backup', 'library_code' => 'LIB002'], [], $saSession, $saHeaders);
    testAssert(($resBk2['success'] ?? false) === true && !empty($resBk2['backup']['backup_id']), "Super Admin creates tenant backup for LIB002");
    $backupId2 = $resBk2['backup']['backup_id'] ?? '';

    $resCross = call_api('json_super_admin.php', [
        'action' => 'restore_backup',
        'library_code' => 'LIB001',
        'backup_id' => $backupId2
    ], [], $saSession, $saHeaders);

    testAssert(($resCross['success'] ?? true) === false && str_contains($resCross['error'] ?? '', 'CROSS-TENANT RESTORE BLOCKED'), "Cross-Tenant restore (LIB002 backup -> LIB001 DB) is strictly BLOCKED");

} catch (Exception $e) {
    testAssert(false, "Test 14 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// CLEANUP & FINAL AUDIT
// ---------------------------------------------------------
echo "\n--- Final Database Integrity & Foreign Key Audit ---\n";
$dbErr = false;
foreach (['MASTER', 'LIB001', 'LIB002', 'LIB003'] as $c) {
    try {
        $p = ($c === 'MASTER') ? get_master_pdo(true) : TenantDatabaseFactory::getTenantConnection($c);
        $integ = $p->query("PRAGMA integrity_check")->fetchColumn();
        $fk = $p->query("PRAGMA foreign_key_check")->fetchAll();
        if ($integ !== 'ok' || count($fk) > 0) {
            $dbErr = true;
            echo "  [FAIL] $c DB integrity check: $integ, FK errors: " . count($fk) . "\n";
        }
    } catch (Exception $ex) {
        $dbErr = true;
    }
}
testAssert(!$dbErr, "Final DB integrity & FK check passed (0 errors) across MASTER, LIB001, LIB002, LIB003");

echo "\n=========================================================\n";
echo "FINAL ACCEPTANCE TEST RESULTS: $pass PASSED, $fail FAILED\n";
echo "=========================================================\n";
