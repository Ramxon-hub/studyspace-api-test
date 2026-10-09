<?php
// scratch/run_phase48_acceptance.php - Phase 48 Automated Acceptance Test Suite

define('IN_TEST_SUITE', true);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/api/json_parent_actions.php';

require_once __DIR__ . '/../config/tenant_router.php';
require_once __DIR__ . '/../config/auth.php';

$pass_count = 0;
$fail_count = 0;

function assert_test($condition, $description) {
    global $pass_count, $fail_count;
    if ($condition) {
        echo "  [PASS] $description\n";
        $pass_count++;
    } else {
        echo "  [FAIL] $description\n";
        $fail_count++;
    }
}

function call_parent_api($action, $params = [], $parent_id = 0, $library_code = 'LIB001') {
    $_GET = array_merge(['action' => $action, 'library_code' => $library_code], $params);
    $_POST = $_GET;
    $_REQUEST = $_GET;
    $_SERVER['HTTP_X_LIBRARY_CODE'] = $library_code;
    $_SERVER['HTTP_X_PARENT_ID'] = (string)$parent_id;
    $_SESSION['user_id'] = $parent_id;
    $_SESSION['user_role'] = 'parent';
    $_SESSION['library_code'] = $library_code;

    ob_start();
    try {
        include __DIR__ . '/../api/json_parent_actions.php';
    } catch (Exception $e) {
        ob_end_clean();
        return ['success' => false, 'error' => $e->getMessage()];
    }
    $out = ob_get_clean();
    $first_brace = strpos($out, '{');
    $last_brace = strrpos($out, '}');
    if ($first_brace !== false && $last_brace !== false) {
        $json_str = substr($out, $first_brace, $last_brace - $first_brace + 1);
        return json_decode($json_str, true) ?? ['raw' => $out];
    }
    return ['raw' => $out];
}

function call_admin_api($action, $params = [], $library_code = 'LIB001') {
    $pdo = TenantDatabaseFactory::getTenantConnection($library_code);
    $admin_id = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
    if ($admin_id <= 0) $admin_id = 1;

    $_GET = array_merge(['action' => $action, 'library_code' => $library_code], $params);
    $_POST = $_GET;
    $_REQUEST = $_GET;
    $_SERVER['HTTP_X_LIBRARY_CODE'] = $library_code;
    $_SERVER['HTTP_X_ADMIN_ID'] = (string)$admin_id;
    $_SESSION['user_id'] = $admin_id;
    $_SESSION['user_role'] = 'admin';
    $_SESSION['library_code'] = $library_code;

    ob_start();
    try {
        include __DIR__ . '/../api/json_admin_actions.php';
    } catch (Exception $e) {
        ob_end_clean();
        return ['success' => false, 'error' => $e->getMessage()];
    }
    $out = ob_get_clean();
    $first_brace = strpos($out, '{');
    $last_brace = strrpos($out, '}');
    if ($first_brace !== false && $last_brace !== false) {
        $json_str = substr($out, $first_brace, $last_brace - $first_brace + 1);
        return json_decode($json_str, true) ?? ['raw' => $out];
    }
    return ['raw' => $out];
}

echo "=========================================================\n";
echo "PHASE 48 COMPREHENSIVE AUTOMATED ACCEPTANCE TEST SUITE\n";
echo "=========================================================\n\n";

$pdo_lib1 = TenantDatabaseFactory::getTenantConnection('LIB001');
$pdo_lib2 = TenantDatabaseFactory::getTenantConnection('LIB002');

// Setup test parents and students in LIB001
$admin_id_1 = (int)$pdo_lib1->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn() ?: 1;

// Fetch or create Parent A and Student A
$stmt = $pdo_lib1->prepare("SELECT id FROM users WHERE role = 'parent' AND email = 'p48_parent_a@test.com'");
$stmt->execute();
$parent_a_id = (int)$stmt->fetchColumn();
if ($parent_a_id <= 0) {
    $stmt = $pdo_lib1->prepare("INSERT INTO users (name, email, phone, password, role, status) VALUES ('Phase48 Parent A', 'p48_parent_a@test.com', '9998880001', 'pass123', 'parent', 'approved')");
    $stmt->execute();
    $parent_a_id = (int)$pdo_lib1->lastInsertId();
}

$stmt = $pdo_lib1->prepare("SELECT id FROM users WHERE role = 'student' LIMIT 1");
$stmt->execute();
$student_a_id = (int)$stmt->fetchColumn();

// Ensure link exists for Parent A -> Student A
$stmt = $pdo_lib1->prepare("SELECT COUNT(*) FROM parent_student_links WHERE parent_user_id = ? AND student_user_id = ?");
$stmt->execute([$parent_a_id, $student_a_id]);
if ($stmt->fetchColumn() == 0) {
    $stmt = $pdo_lib1->prepare("INSERT INTO parent_student_links (parent_user_id, student_user_id, relationship, status) VALUES (?, ?, 'Father', 'active')");
    $stmt->execute([$parent_a_id, $student_a_id]);
}

// Fetch or create Parent B and Student B
$stmt = $pdo_lib1->prepare("SELECT id FROM users WHERE role = 'parent' AND email = 'p48_parent_b@test.com'");
$stmt->execute();
$parent_b_id = (int)$stmt->fetchColumn();
if ($parent_b_id <= 0) {
    $stmt = $pdo_lib1->prepare("INSERT INTO users (name, email, phone, password, role, status) VALUES ('Phase48 Parent B', 'p48_parent_b@test.com', '9998880002', 'pass123', 'parent', 'approved')");
    $stmt->execute();
    $parent_b_id = (int)$pdo_lib1->lastInsertId();
}

$stmt = $pdo_lib1->prepare("SELECT id FROM users WHERE role = 'student' AND id != ? LIMIT 1");
$stmt->execute([$student_a_id]);
$student_b_id = (int)$stmt->fetchColumn();
if ($student_b_id <= 0) $student_b_id = $student_a_id; // Fallback if single student

// Ensure link exists for Parent B -> Student B
if ($student_b_id != $student_a_id) {
    $stmt = $pdo_lib1->prepare("SELECT COUNT(*) FROM parent_student_links WHERE parent_user_id = ? AND student_user_id = ?");
    $stmt->execute([$parent_b_id, $student_b_id]);
    if ($stmt->fetchColumn() == 0) {
        $stmt = $pdo_lib1->prepare("INSERT INTO parent_student_links (parent_user_id, student_user_id, relationship, status) VALUES (?, ?, 'Mother', 'active')");
        $stmt->execute([$parent_b_id, $student_b_id]);
    }
}

echo "--- TEST 1: Message Ordering (Oldest to Newest: created_at ASC, id ASC) ---\n";
// Insert message 1: Admin says "Hi" at 08:21 AM
$time1 = '2026-10-09 08:21:00';
$stmt = $pdo_lib1->prepare("INSERT INTO chat_messages (sender_id, receiver_id, message, is_read, created_at) VALUES (?, ?, 'Hi', 0, ?)");
$stmt->execute([$admin_id_1, $parent_a_id, $time1]);
$msg_admin_id = $pdo_lib1->lastInsertId();

// Insert message 2: Parent A says "Hello" at 08:33 AM
$time2 = '2026-10-09 08:33:00';
$stmt = $pdo_lib1->prepare("INSERT INTO chat_messages (sender_id, receiver_id, message, is_read, created_at) VALUES (?, ?, 'Hello', 0, ?)");
$stmt->execute([$parent_a_id, $admin_id_1, $time2]);
$msg_parent_id = $pdo_lib1->lastInsertId();

$res = call_parent_api('get_chat_messages', ['student_id' => $student_a_id], $parent_a_id, 'LIB001');
assert_test($res['success'] === true, "Fetch chat messages API returned success");
$messages = $res['messages'] ?? [];
assert_test(count($messages) >= 2, "Chat messages array contains test messages");

// Verify Admin's "Hi" (08:21 AM) appears BEFORE Parent's "Hello" (08:33 AM)
$pos_admin = -1;
$pos_parent = -1;
foreach ($messages as $idx => $m) {
    if ($m['id'] == $msg_admin_id) $pos_admin = $idx;
    if ($m['id'] == $msg_parent_id) $pos_parent = $idx;
}
assert_test($pos_admin !== -1 && $pos_parent !== -1 && $pos_admin < $pos_parent, "Admin's 08:21 AM 'Hi' appears BEFORE Parent's 08:33 AM 'Hello' (oldest first)");

echo "\n--- TEST 2: Sender Identity & Roles ---\n";
$admin_msg = null;
$parent_msg = null;
foreach ($messages as $m) {
    if ($m['id'] == $msg_admin_id) $admin_msg = $m;
    if ($m['id'] == $msg_parent_id) $parent_msg = $m;
}
assert_test($admin_msg !== null && ($admin_msg['sender_role'] ?? '') === 'admin', "Admin message has sender_role = 'admin'");
assert_test($parent_msg !== null && ($parent_msg['sender_role'] ?? '') === 'parent', "Parent message has sender_role = 'parent'");

echo "\n--- TEST 3: Strict Privacy & Parent Isolation ---\n";
// Parent B attempts to access Parent A's student chat (Student A)
$res_unauth = call_parent_api('get_chat_messages', ['student_id' => $student_a_id], $parent_b_id, 'LIB001');
assert_test($res_unauth['success'] === false, "Parent B blocked from viewing Parent A's student chat (403 Forbidden)");

echo "\n--- TEST 4: Read Status Isolation ---\n";
// Insert unread message for Parent B
$stmt = $pdo_lib1->prepare("INSERT INTO chat_messages (sender_id, receiver_id, message, is_read, created_at) VALUES (?, ?, 'Message for Parent B', 0, ?)");
$stmt->execute([$admin_id_1, $parent_b_id, '2026-10-09 09:00:00']);
$msg_parent_b_id = $pdo_lib1->lastInsertId();

// Parent A opens Parent A's chat (marks Parent A's messages read)
call_parent_api('get_chat_messages', ['student_id' => $student_a_id], $parent_a_id, 'LIB001');

// Verify Parent A's admin message is read
$stmt = $pdo_lib1->prepare("SELECT is_read FROM chat_messages WHERE id = ?");
$stmt->execute([$msg_admin_id]);
$is_read_a = (int)$stmt->fetchColumn();
assert_test($is_read_a === 1, "Parent A opening chat marked Parent A's message as read (is_read = 1)");

// Verify Parent B's message is STILL unread
$stmt->execute([$msg_parent_b_id]);
$is_read_b = (int)$stmt->fetchColumn();
assert_test($is_read_b === 0, "Parent B's message remains UNREAD (is_read = 0) after Parent A opened chat");

echo "\n--- TEST 5: Unread Counts ---\n";
$res_uc_b = call_parent_api('get_unread_counts', ['student_id' => $student_b_id], $parent_b_id, 'LIB001');
assert_test($res_uc_b['success'] === true && ($res_uc_b['unread_chat_count'] ?? 0) >= 1, "Parent B unread count correctly reflects unread message");

echo "\n--- TEST 6: Clean Up Test Messages ---\n";
$pdo_lib1->prepare("DELETE FROM chat_messages WHERE id IN (?, ?, ?)")->execute([$msg_admin_id, $msg_parent_id, $msg_parent_b_id]);
assert_test(true, "Cleaned up Phase 48 temporary test chat messages");

echo "\n--- TEST 7: Database Integrity & Foreign Key Audit ---\n";
$dbs = [
    'MASTER' => get_master_pdo(),
    'LIB001' => TenantDatabaseFactory::getTenantConnection('LIB001'),
    'LIB002' => TenantDatabaseFactory::getTenantConnection('LIB002'),
    'LIB003' => TenantDatabaseFactory::getTenantConnection('LIB003'),
];

foreach ($dbs as $name => $db_pdo) {
    $integrity = $db_pdo->query("PRAGMA integrity_check")->fetchColumn();
    $fk = $db_pdo->query("PRAGMA foreign_key_check")->fetchAll();
    $errors = count($fk);
    assert_test($integrity === 'ok' && $errors === 0, "$name DB integrity: '$integrity', FK errors: $errors");
}

echo "\n=========================================================\n";
echo "FINAL ACCEPTANCE TEST RESULTS: $pass_count PASSED, $fail_count FAILED\n";
echo "=========================================================\n";
