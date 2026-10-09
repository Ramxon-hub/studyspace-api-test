<?php
/**
 * Phase 46 — Comprehensive Super Admin APK, Admin Management & UI Redesign Acceptance Test Suite
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

function call_super_admin_api($post = [], $get = [], $session = null, $headers = []) {
    $_GET = $get;
    $_POST = $post;
    
    if ($session !== null) {
        $_SESSION = $session;
    }

    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_SUPER_ADMIN_TOKEN'], $_SERVER['HTTP_X_SUPER_ADMIN_KEY']);
    foreach ($headers as $k => $v) {
        $_SERVER[$k] = $v;
    }

    TenantDatabaseFactory::clearCache();

    ob_start();
    include __DIR__ . '/../api/json_super_admin.php';
    $out = ob_get_clean();

    return json_decode($out, true) ?: ['_raw' => $out];
}

$saSession = [
    'is_super_admin' => true,
    'role' => 'super_admin',
    'super_admin_user' => 'superadmin'
];
$saHeaders = ['HTTP_X_SUPER_ADMIN_KEY' => $superAdminKey];

echo "=========================================================\n";
echo "PHASE 46 COMPREHENSIVE AUTOMATED ACCEPTANCE TEST SUITE\n";
echo "=========================================================\n\n";

// ---------------------------------------------------------
// TEST 1: SUPER ADMIN LOGIN
// ---------------------------------------------------------
echo "--- TEST 1: Super Admin Login ---\n";
try {
    $res = call_super_admin_api(
        ['action' => 'login', 'username' => 'superadmin', 'password' => 'superadmin123'],
        [],
        []
    );
    testAssert(!empty($res) && ($res['success'] ?? false) === true && !empty($res['token']), "Super Admin authentication succeeds against Master DB");
    $saToken = $res['token'] ?? '';
    $saSession['super_admin_token'] = $saToken;
} catch (Exception $e) {
    testAssert(false, "Test 1 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 2: UNAUTHORIZED REQUEST REJECTED
// ---------------------------------------------------------
echo "\n--- TEST 2: Unauthorized Request Protection ---\n";
try {
    $res = call_super_admin_api(
        ['action' => 'dashboard_stats'],
        [],
        [],
        [] // No headers, no session
    );
    testAssert(!empty($res) && ($res['success'] ?? true) === false && str_contains($res['error'] ?? '', 'Unauthorized'), "Unauthorized request without Super Admin key/session is rejected");
} catch (Exception $e) {
    testAssert(false, "Test 2 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 3: TENANT ADMIN DENIED SUPER ADMIN ENDPOINTS
// ---------------------------------------------------------
echo "\n--- TEST 3: Tenant Admin Access Denied to Super Admin Endpoints ---\n";
try {
    $res = call_super_admin_api(
        ['action' => 'dashboard_stats'],
        [],
        ['user_id' => 1, 'user_role' => 'admin', 'library_code' => 'LIB001'],
        [] // No super admin key
    );
    testAssert(!empty($res) && ($res['success'] ?? true) === false && (str_contains($res['error'] ?? '', '403') || str_contains($res['error'] ?? '', 'Forbidden')), "Tenant admin session attempting to call Super Admin API is BLOCKED -> 403 Forbidden");
} catch (Exception $e) {
    testAssert(false, "Test 3 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 4 & 5: CREATE LIB001 ADMIN & VALIDATE LOGIN
// ---------------------------------------------------------
echo "\n--- TEST 4 & 5: Create LIB001 Admin & Validate Login ---\n";
$testEmail1 = "phase46_admin1@lib001.com";
$testPass1 = "Phase46Pass123!";
try {
    $pdo1 = TenantDatabaseFactory::getTenantConnection('LIB001');
    $pdo1->prepare("DELETE FROM users WHERE email = ?")->execute([$testEmail1]);
    TenantDatabaseFactory::clearCache();

    $res = call_super_admin_api([
        'action' => 'create_library_admin',
        'library_code' => 'LIB001',
        'name' => 'Phase 46 Admin LIB001',
        'email' => $testEmail1,
        'phone' => '+91 99988 77711',
        'password' => $testPass1
    ], [], $saSession, $saHeaders);

    testAssert(($res['success'] ?? false) === true, "Create LIB001 admin via Super Admin API succeeds");

    $pdo1Fresh = TenantDatabaseFactory::getTenantConnection('LIB001');
    $adm1 = $pdo1Fresh->prepare("SELECT id, password FROM users WHERE email = ? AND role = 'admin'");
    $adm1->execute([$testEmail1]);
    $user1 = $adm1->fetch(PDO::FETCH_ASSOC);

    testAssert(!empty($user1) && password_verify($testPass1, $user1['password']), "LIB001 admin login succeeds against LIB001 tenant DB");
    TenantDatabaseFactory::clearCache();
} catch (Exception $e) {
    testAssert(false, "Test 4 & 5 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 6: LIB001 ADMIN LOGIN AGAINST LIB002 FAILS
// ---------------------------------------------------------
echo "\n--- TEST 6: LIB001 Admin Login Against LIB002 Fails ---\n";
try {
    $pdo2 = TenantDatabaseFactory::getTenantConnection('LIB002');
    $chk2 = $pdo2->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $chk2->execute([$testEmail1]);
    $existsInLib2 = (int)$chk2->fetchColumn() > 0;

    testAssert($existsInLib2 === false, "LIB001 admin account does NOT exist in LIB002 tenant DB (Strict Auth Isolation)");
    TenantDatabaseFactory::clearCache();
} catch (Exception $e) {
    testAssert(false, "Test 6 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 7 & 8: CREATE LIB002 ADMIN & VALIDATE LOGIN
// ---------------------------------------------------------
echo "\n--- TEST 7 & 8: Create LIB002 Admin & Validate Login ---\n";
$testEmail2 = "phase46_admin2@lib002.com";
$testPass2 = "Phase46Pass456!";
try {
    $pdo2 = TenantDatabaseFactory::getTenantConnection('LIB002');
    $pdo2->prepare("DELETE FROM users WHERE email = ?")->execute([$testEmail2]);
    TenantDatabaseFactory::clearCache();

    $res = call_super_admin_api([
        'action' => 'create_library_admin',
        'library_code' => 'LIB002',
        'name' => 'Phase 46 Admin LIB002',
        'email' => $testEmail2,
        'phone' => '+91 99988 77722',
        'password' => $testPass2
    ], [], $saSession, $saHeaders);

    testAssert(($res['success'] ?? false) === true, "Create LIB002 admin via Super Admin API succeeds");

    $pdo2Fresh = TenantDatabaseFactory::getTenantConnection('LIB002');
    $adm2 = $pdo2Fresh->prepare("SELECT id, password FROM users WHERE email = ? AND role = 'admin'");
    $adm2->execute([$testEmail2]);
    $user2 = $adm2->fetch(PDO::FETCH_ASSOC);

    testAssert(!empty($user2) && password_verify($testPass2, $user2['password']), "LIB002 admin login succeeds against LIB002 tenant DB");
    TenantDatabaseFactory::clearCache();
} catch (Exception $e) {
    testAssert(false, "Test 7 & 8 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 9, 10, 11, 12: RESET LIB001 ADMIN PASSWORD & CREDENTIAL ISOLATION
// ---------------------------------------------------------
echo "\n--- TEST 9, 10, 11, 12: Reset LIB001 Admin Password & Credential Isolation ---\n";
$newPass1 = "NewPhase46Pass789!";
try {
    $res = call_super_admin_api([
        'action' => 'reset_admin_password',
        'library_code' => 'LIB001',
        'email' => $testEmail1,
        'new_password' => $newPass1
    ], [], $saSession, $saHeaders);

    testAssert(($res['success'] ?? false) === true, "Super Admin resets LIB001 admin password successfully");

    $pdo1 = TenantDatabaseFactory::getTenantConnection('LIB001');
    $adm1Updated = $pdo1->prepare("SELECT password FROM users WHERE email = ? AND role = 'admin'");
    $adm1Updated->execute([$testEmail1]);
    $updatedPassHash1 = $adm1Updated->fetchColumn();

    $oldPassFails = !password_verify($testPass1, $updatedPassHash1);
    $newPassSucceeds = password_verify($newPass1, $updatedPassHash1);

    testAssert($oldPassFails === true, "Old password FAILS authentication after password reset");
    testAssert($newPassSucceeds === true, "New password SUCCEEDS authentication after password reset");

    $pdo2 = TenantDatabaseFactory::getTenantConnection('LIB002');
    $adm2Check = $pdo2->prepare("SELECT password FROM users WHERE email = ? AND role = 'admin'");
    $adm2Check->execute([$testEmail2]);
    $passHash2 = $adm2Check->fetchColumn();
    $lib2Unchanged = password_verify($testPass2, $passHash2);

    testAssert($lib2Unchanged === true, "LIB002 admin password remains 100% UNCHANGED during LIB001 password reset");
    TenantDatabaseFactory::clearCache();
} catch (Exception $e) {
    testAssert(false, "Test 9-12 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 13 & 14: VALIDATION CHECKS (DUPLICATE ADMIN & INVALID LIB)
// ---------------------------------------------------------
echo "\n--- TEST 13 & 14: Duplicate Admin & Invalid Library Validations ---\n";
try {
    $resDup = call_super_admin_api([
        'action' => 'create_library_admin',
        'library_code' => 'LIB001',
        'name' => 'Duplicate Admin',
        'email' => $testEmail1,
        'password' => 'SomePass123!'
    ], [], $saSession, $saHeaders);

    testAssert(($resDup['success'] ?? true) === false && str_contains($resDup['error'] ?? '', 'already exists'), "Duplicate admin email validation blocks creation");

    $resInv = call_super_admin_api([
        'action' => 'create_library_admin',
        'library_code' => 'NON_EXISTENT_LIB_9999',
        'name' => 'Invalid Lib Admin',
        'email' => 'invalid@nonexistent.com',
        'password' => 'SomePass123!'
    ], [], $saSession, $saHeaders);

    testAssert(($resInv['success'] ?? true) === false && str_contains($resInv['error'] ?? '', 'Invalid library code'), "Invalid library code validation blocks admin creation");
} catch (Exception $e) {
    testAssert(false, "Test 13 & 14 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 15 & 16: PROVISIONING & TENANT DB PATH ISOLATION
// ---------------------------------------------------------
echo "\n--- TEST 15 & 16: Database Provisioning & Path Isolation ---\n";
try {
    $path1 = TenantDatabaseFactory::resolveTenantDbPath('LIB001');
    $path2 = TenantDatabaseFactory::resolveTenantDbPath('LIB002');
    $path3 = TenantDatabaseFactory::resolveTenantDbPath('LIB003');

    testAssert($path1 !== $path2 && $path2 !== $path3, "Tenant database file paths are strictly SEPARATE");
    testAssert(file_exists($path1) && file_exists($path2) && file_exists($path3), "Tenant database files exist on disk");

    $resProv = call_super_admin_api(['action' => 'provision_database', 'library_code' => 'LIB001'], [], $saSession, $saHeaders);

    testAssert(($resProv['success'] ?? false) === true && ($resProv['db_status']['provisioned'] ?? false) === true, "Database provisioning & schema verification succeeds for LIB001");
} catch (Exception $e) {
    testAssert(false, "Test 15 & 16 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 17, 18, 19: TENANT-ISOLATED BACKUPS & RESTORE SAFETY
// ---------------------------------------------------------
echo "\n--- TEST 17, 18, 19: Backup Creation & Cross-Tenant Restore Blocking ---\n";
try {
    $resBk2 = call_super_admin_api(['action' => 'create_backup', 'library_code' => 'LIB002'], [], $saSession, $saHeaders);
    testAssert(($resBk2['success'] ?? false) === true && !empty($resBk2['backup']['backup_id']), "Tenant-isolated backup created for LIB002");
    $backupId2 = $resBk2['backup']['backup_id'] ?? '';

    $resBkM = call_super_admin_api(['action' => 'create_backup', 'library_code' => 'MASTER'], [], $saSession, $saHeaders);
    testAssert(($resBkM['success'] ?? false) === true && ($resBkM['backup']['scope'] ?? '') === 'MASTER', "Master DB backup created successfully");

    $resCross = call_super_admin_api([
        'action' => 'restore_backup',
        'library_code' => 'LIB001',
        'backup_id' => $backupId2
    ], [], $saSession, $saHeaders);

    testAssert(($resCross['success'] ?? true) === false && str_contains($resCross['error'] ?? '', 'CROSS-TENANT RESTORE BLOCKED'), "Cross-Tenant restore (LIB002 backup -> LIB001 DB) is strictly BLOCKED");
} catch (Exception $e) {
    testAssert(false, "Test 17-19 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 20: DYNAMIC BRANDING ISOLATION
// ---------------------------------------------------------
echo "\n--- TEST 20: Dynamic Branding Isolation ---\n";
try {
    $origName3 = $masterPdo->query("SELECT name FROM libraries WHERE library_code = 'LIB003'")->fetchColumn();

    $resBrand = call_super_admin_api([
        'action' => 'update_library',
        'library_code' => 'LIB003',
        'name' => 'LIB003 Phase 46 Test Name',
        'tagline' => 'Isolated Phase 46 Tagline',
        'primary_color' => '#8B5CF6'
    ], [], $saSession, $saHeaders);

    testAssert(($resBrand['success'] ?? false) === true, "LIB003 branding updated successfully");

    $brand1 = $masterPdo->query("SELECT tagline FROM library_branding WHERE library_code = 'LIB001'")->fetchColumn();
    $brand2 = $masterPdo->query("SELECT tagline FROM library_branding WHERE library_code = 'LIB002'")->fetchColumn();

    testAssert($brand1 !== 'Isolated Phase 46 Tagline' && $brand2 !== 'Isolated Phase 46 Tagline', "LIB001 and LIB002 branding remains isolated and unchanged");

    $masterPdo->prepare("UPDATE libraries SET name = ? WHERE library_code = 'LIB003'")->execute([$origName3]);
} catch (Exception $e) {
    testAssert(false, "Test 20 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 21: SUSPENSION & REACTIVATION
// ---------------------------------------------------------
echo "\n--- TEST 21: Tenant Suspension & Reactivation ---\n";
try {
    call_super_admin_api(['action' => 'suspend_library', 'library_code' => 'LIB003'], [], $saSession, $saHeaders);
    get_master_pdo(true);
    TenantDatabaseFactory::clearCache('LIB003');

    $suspendBlocked = false;
    try {
        TenantDatabaseFactory::getTenantConnection('LIB003');
    } catch (Exception $ex) {
        if (str_contains($ex->getMessage(), 'suspended')) {
            $suspendBlocked = true;
        }
    }
    testAssert($suspendBlocked === true, "Suspended tenant LIB003 connection is BLOCKED");

    call_super_admin_api(['action' => 'activate_library', 'library_code' => 'LIB003'], [], $saSession, $saHeaders);
    get_master_pdo(true);
    TenantDatabaseFactory::clearCache('LIB003');

    $pdo3Reactive = TenantDatabaseFactory::getTenantConnection('LIB003');
    testAssert($pdo3Reactive instanceof PDO, "Reactivated tenant LIB003 connection restored successfully");
} catch (Exception $e) {
    $masterPdo->exec("UPDATE libraries SET status = 'active' WHERE library_code = 'LIB003'");
    testAssert(false, "Test 21 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 22: AUDIT LOGS CONTAIN OPERATIONS BUT NO SECRETS
// ---------------------------------------------------------
echo "\n--- TEST 22: Audit Logs Integrity (No Plaintext Passwords) ---\n";
try {
    $logs = $masterPdo->query("SELECT action, super_admin, library_code, metadata FROM super_admin_audit_logs ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

    $noPlaintextSecrets = true;
    foreach ($logs as $l) {
        $meta = (string)($l['metadata'] ?? '');
        if (str_contains($meta, $testPass1) || str_contains($meta, $testPass2) || str_contains($meta, $newPass1)) {
            $noPlaintextSecrets = false;
            break;
        }
    }

    testAssert(count($logs) > 0, "Audit logs recorded Super Admin operations");
    testAssert($noPlaintextSecrets === true, "Audit logs contain ZERO plaintext passwords or secrets");
} catch (Exception $e) {
    testAssert(false, "Test 22 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 23: SUPER ADMIN APK NAVIGATION ENDPOINTS
// ---------------------------------------------------------
echo "\n--- TEST 23: Super Admin APK Navigation Endpoints ---\n";
try {
    $actionsToTest = [
        'dashboard_stats', 'list_libraries', 'list_tenant_admins', 'list_plans',
        'list_invoices', 'list_manual_payments', 'list_backups', 'list_audit_logs', 'get_support_settings'
    ];

    $allSuccess = true;
    foreach ($actionsToTest as $act) {
        $r = call_super_admin_api(['action' => $act], [], $saSession, $saHeaders);
        if (($r['success'] ?? false) !== true) {
            $allSuccess = false;
            echo "  [DEBUG] Action $act failed: " . ($r['error'] ?? 'Unknown error') . "\n";
        }
    }

    testAssert($allSuccess === true, "All 9 core Super Admin navigation API endpoints return success: true");
} catch (Exception $e) {
    testAssert(false, "Test 23 failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// TEST 24: CLEANUP TEST RECORDS & DATABASE INTEGRITY
// ---------------------------------------------------------
echo "\n--- TEST 24: Cleanup Test Records & Final Integrity Audit ---\n";
try {
    $pdo1 = TenantDatabaseFactory::getTenantConnection('LIB001');
    $pdo2 = TenantDatabaseFactory::getTenantConnection('LIB002');
    $pdo1->prepare("DELETE FROM users WHERE email = ?")->execute([$testEmail1]);
    $pdo2->prepare("DELETE FROM users WHERE email = ?")->execute([$testEmail2]);
    TenantDatabaseFactory::clearCache();

    $dbs = [
        'MASTER' => get_master_db_path(),
        'LIB001' => TenantDatabaseFactory::resolveTenantDbPath('LIB001'),
        'LIB002' => TenantDatabaseFactory::resolveTenantDbPath('LIB002'),
        'LIB003' => TenantDatabaseFactory::resolveTenantDbPath('LIB003'),
    ];

    $allDbClean = true;
    foreach ($dbs as $lbl => $fpath) {
        $p = new PDO("sqlite:$fpath");
        $integ = $p->query("PRAGMA integrity_check")->fetchColumn();
        $fkErrs = $p->query("PRAGMA foreign_key_check")->fetchAll();
        if ($integ !== 'ok' || count($fkErrs) > 0) {
            $allDbClean = false;
            echo "  [DEBUG] DB $lbl failed check! Integrity: $integ, FK Errors: " . count($fkErrs) . "\n";
        }
    }

    testAssert($allDbClean === true, "Final DB integrity & FK check passed (0 errors) across MASTER, LIB001, LIB002, LIB003");
} catch (Exception $e) {
    testAssert(false, "Test 24 failed: " . $e->getMessage());
}

echo "\n=========================================================\n";
echo "FINAL ACCEPTANCE TEST RESULTS: $pass PASSED, $fail FAILED\n";
echo "=========================================================\n";

if ($fail > 0) {
    exit(1);
}
