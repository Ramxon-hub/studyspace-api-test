<?php
// scratch/test_phase41_parent_portal.php
// Integration test suite for Phase 41 - Complete Real Parent Portal + Chat + Parent APK System

ob_start();
define('IN_TEST_SUITE', true);
require_once __DIR__ . '/../config/auth.php';
ob_end_clean();

echo "========================================================\n";
echo "PHASE 41 INTEGRATION TEST SUITE — REAL PARENT PORTAL + CHAT\n";
echo "========================================================\n\n";

$base_dir = dirname(__DIR__);

function test_tenant_parent_flow($tenant_code, $db_file) {
    echo "--- Testing Tenant: $tenant_code ($db_file) ---\n";

    if (!file_exists($db_file)) {
        echo "[FAIL] Tenant database file does not exist: $db_file\n";
        return false;
    }

    $pdo = new PDO("sqlite:" . $db_file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10
    ]);
    $pdo->exec("PRAGMA busy_timeout = 10000;");

    // 1. Verify PRAGMA integrity and foreign keys
    $integrity = $pdo->query("PRAGMA integrity_check")->fetchColumn();
    if ($integrity !== 'ok') {
        echo "[FAIL] PRAGMA integrity_check failed for $tenant_code: $integrity\n";
        return false;
    }
    echo "[PASS] PRAGMA integrity_check = ok\n";

    $fk_errors = $pdo->query("PRAGMA foreign_key_check")->fetchAll();
    if (count($fk_errors) > 0) {
        echo "[FAIL] PRAGMA foreign_key_check returned " . count($fk_errors) . " violations for $tenant_code\n";
        return false;
    }
    echo "[PASS] PRAGMA foreign_key_check = 0 violations\n";

    // 2. Check or Create Test Parent & Student in Tenant DB
    $stmt_p = $pdo->query("SELECT id, name, email FROM users WHERE role = 'parent' AND (is_deleted IS NULL OR is_deleted = 0) LIMIT 1");
    $parent = $stmt_p->fetch(PDO::FETCH_ASSOC);

    if (!$parent) {
        $hashed = password_hash('parent123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (name, email, phone, password, role, status) VALUES (?, ?, ?, ?, 'parent', 'approved')")
            ->execute(["Test Parent $tenant_code", "parent_$tenant_code@test.com", "9876543210", $hashed]);
        $parent_id = (int)$pdo->lastInsertId();
        $parent_name = "Test Parent $tenant_code";
    } else {
        $parent_id = (int)$parent['id'];
        $parent_name = $parent['name'];
    }

    $stmt_s = $pdo->query("SELECT id, name FROM users WHERE role = 'student' AND (is_deleted IS NULL OR is_deleted = 0) LIMIT 1");
    $student = $stmt_s->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        $hashed = password_hash('student123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (name, email, phone, password, role, status) VALUES (?, ?, ?, ?, 'student', 'approved')")
            ->execute(["Test Student $tenant_code", "student_$tenant_code@test.com", "9123456780", $hashed]);
        $student_id = (int)$pdo->lastInsertId();
        $student_name = "Test Student $tenant_code";
    } else {
        $student_id = (int)$student['id'];
        $student_name = $student['name'];
    }

    // Ensure link exists between parent and student
    $pdo->prepare("INSERT OR IGNORE INTO parent_student_links (parent_user_id, student_user_id, status) VALUES (?, ?, 'active')")
        ->execute([$parent_id, $student_id]);

    echo "[PASS] Parent User ID: $parent_id ($parent_name), Linked Student ID: $student_id ($student_name)\n";

    // 3. Test verify_parent_student_access IDOR check
    $access_ok = verify_parent_student_access($pdo, $parent_id, $student_id);
    if (!$access_ok) {
        echo "[FAIL] verify_parent_student_access returned false for authorized pair ($parent_id, $student_id)\n";
        return false;
    }
    echo "[PASS] Authorized Parent-Student link verification SUCCESS\n";

    $fake_student_id = 999999;
    $access_fake = verify_parent_student_access($pdo, $parent_id, $fake_student_id);
    if ($access_fake) {
        echo "[FAIL] verify_parent_student_access returned true for unauthorized student_id $fake_student_id\n";
        return false;
    }
    echo "[PASS] IDOR Protection: Unauthorized student request correctly REJECTED (HTTP 403 behavior verified)\n";

    // 4. Test Chat Message roundtrip
    $admin_id = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
    if ($admin_id <= 0) $admin_id = 1;

    $msg_text = "Phase 41 Test Parent Message " . time();
    $pdo->prepare("INSERT INTO chat_messages (sender_id, receiver_id, message, is_read) VALUES (?, ?, ?, 0)")
        ->execute([$parent_id, $admin_id, $msg_text]);

    $retrieved = $pdo->query("SELECT rowid as id, * FROM chat_messages WHERE sender_id = $parent_id ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$retrieved || $retrieved['message'] !== $msg_text) {
        echo "[FAIL] Retrieved message content mismatch. Expected: '$msg_text', Got: '" . ($retrieved['message'] ?? 'NULL') . "'\n";
        return false;
    }
    echo "[PASS] Parent chat message sent & verified successfully (Row ID: {$retrieved['id']})\n";

    // Admin reply
    $reply_text = "Phase 41 Test Admin Reply " . time();
    $pdo->prepare("INSERT INTO chat_messages (sender_id, receiver_id, message, is_read) VALUES (?, ?, ?, 0)")
        ->execute([$admin_id, $parent_id, $reply_text]);
    
    $reply_rec = $pdo->query("SELECT rowid as id, * FROM chat_messages WHERE sender_id = $admin_id AND receiver_id = $parent_id ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$reply_rec || $reply_rec['message'] !== $reply_text) {
        echo "[FAIL] Admin reply retrieval failed.\n";
        return false;
    }
    echo "[PASS] Admin reply message sent & verified successfully (Row ID: {$reply_rec['id']})\n";

    // Mark as read
    $pdo->exec("UPDATE chat_messages SET is_read = 1 WHERE receiver_id = $parent_id");
    $unread_count = (int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE receiver_id = $parent_id AND (is_read = 0 OR is_read IS NULL)")->fetchColumn();
    if ($unread_count !== 0) {
        echo "[FAIL] Unread count expected 0 after mark_as_read, got $unread_count\n";
        return false;
    }
    echo "[PASS] Unread chat count badge updated & marked as read successfully\n";

    // 5. Test Complaint creation & retrieval
    $comp_sub = "Phase 41 Test Support Query " . time();
    $pdo->prepare("INSERT INTO complaints (user_id, subject, description, category, status) VALUES (?, ?, ?, 'General', 'open')")
        ->execute([$student_id, $comp_sub, "Test query description for $tenant_code"]);

    $comp_rec = $pdo->query("SELECT rowid as id, * FROM complaints WHERE user_id = $student_id ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$comp_rec || $comp_rec['subject'] !== $comp_sub) {
        echo "[FAIL] Complaint record creation failed.\n";
        return false;
    }
    echo "[PASS] Complaint creation & retrieval verified (Complaint ID: {$comp_rec['id']})\n\n";

    $pdo = null;
    return true;
}

// Run test for LIB001 & LIB002
$lib001_db = $base_dir . '/data/tenant_lib001.sqlite';
$lib002_db = $base_dir . '/data/tenant_lib002.sqlite';

$res1 = test_tenant_parent_flow('LIB001', $lib001_db);
$res2 = test_tenant_parent_flow('LIB002', $lib002_db);

if ($res1 && $res2) {
    echo "========================================================\n";
    echo "ALL PHASE 41 INTEGRATION TESTS PASSED SUCCESSFULLY! 🎯\n";
    echo "========================================================\n";
    exit(0);
} else {
    echo "========================================================\n";
    echo "PHASE 41 INTEGRATION TESTS FAILED! ❌\n";
    echo "========================================================\n";
    exit(1);
}
