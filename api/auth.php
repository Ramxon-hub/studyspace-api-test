<?php
// api/auth.php - Authentication API handler

require_once __DIR__ . '/../config/auth.php';

$action = $_REQUEST['action'] ?? '';
$tenant_ctx = resolve_tenant_context();
$pdo = $tenant_ctx['pdo'];
$current_tenant_code = $tenant_ctx['library_code'];

if ($action === 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        header("Location: ../login.php?error=" . urlencode("Email and Password are required."));
        exit();
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['library_code'] = $current_tenant_code;
        $_SESSION['tenant_code'] = $current_tenant_code;
        $_SESSION['auth_time'] = time();
        $_SESSION['session_id'] = session_id();

        if ($user['role'] === 'admin') {
            header("Location: ../admin_dashboard.php");
        } elseif ($user['role'] === 'parent') {
            header("Location: ../parent_dashboard.php");
        } else {
            header("Location: ../student_dashboard.php");
        }
        exit();
    } else {
        $redir_code = !empty($current_tenant_code) ? ("?code=" . urlencode($current_tenant_code) . "&") : "?";
        header("Location: ../login.php{$redir_code}error=" . urlencode("Invalid email or password for tenant " . $current_tenant_code));
        exit();
    }
}

if ($action === 'register') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $emergency_contact = trim($_POST['emergency_contact'] ?? '');
    $id_proof_type = trim($_POST['id_proof_type'] ?? '');
    $id_proof_no = trim($_POST['id_proof_no'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $preferred_shift_id = (int)($_POST['preferred_shift_id'] ?? 1);
    $preferred_seat_id = (int)($_POST['preferred_seat_id'] ?? 0);

    if (empty($name) || empty($email) || empty($phone) || empty($password)) {
        header("Location: ../login.php?tab=register&error=" . urlencode("All required fields must be filled."));
        exit();
    }

    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        header("Location: ../login.php?tab=register&error=" . urlencode("An account with this email already exists."));
        exit();
    }

    // Create user
    $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, emergency_contact, id_proof_type, id_proof_no, status) VALUES (?, ?, ?, ?, 'student', ?, ?, ?, 'pending')");
    $stmt->execute([$name, $email, $phone, $hashed_pass, $emergency_contact, $id_proof_type, $id_proof_no]);
    $user_id = (int)$pdo->lastInsertId();
    $pdo->exec("UPDATE users SET id = rowid WHERE id IS NULL OR id = 0");

    // If preferred seat is selected, create a pending allocation request
    if ($preferred_seat_id > 0) {
        $stmt_alloc = $pdo->prepare("INSERT INTO allocations (user_id, seat_id, shift_id, start_date, status) VALUES (?, ?, ?, date('now'), 'waiting')");
        $stmt_alloc->execute([$user_id, $preferred_seat_id, $preferred_shift_id]);
    }

    // Auto login
    $_SESSION['user_id'] = $user_id;
    $_SESSION['user_name'] = $name;
    $_SESSION['user_role'] = 'student';

    header("Location: ../student_dashboard.php?msg=" . urlencode("Registration successful! Your seat request is sent to Admin for approval."));
    exit();
}

if ($action === 'logout') {
    session_destroy();
    header("Location: ../login.php");
    exit();
}
?>
