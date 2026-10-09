<?php

if (!function_exists('safe_http_response_code')) {
    function safe_http_response_code($code) {
        if (!headers_sent()) {
            @http_response_code($code);
        }
    }
}
// api/json_auth.php - Mobile REST API for Login and Student Registration

if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    safe_http_response_code(200);
    if (defined('IN_TEST_SUITE')) return; else exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/sms_config.php';

// Support raw JSON body or POST form-data
$input = json_decode(file_get_contents('php://input'), true);
if (!empty($input)) {
    $_POST = array_merge($_POST, $input);
    $_GET = array_merge($_GET, $input);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

$tenant_ctx = resolve_tenant_context();
$pdo = $tenant_ctx['pdo'];
$current_tenant_code = $tenant_ctx['library_code'];

try {
    if ($action === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($email) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Please fill in both email and password.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Fetch seat assignment details if student
            $seat_info = null;
            $shift_info = null;
            if ($user['role'] === 'student') {
                $stmt_alloc = $pdo->prepare("
                    SELECT a.*, s.seat_number, s.row_label, sh.name as shift_name, sh.start_time, sh.end_time, sh.fee_amount
                    FROM allocations a
                    JOIN seats s ON a.seat_id = s.id
                    JOIN shifts sh ON a.shift_id = sh.id
                    WHERE a.user_id = ? AND (a.status = 'active' OR a.status = 'approved')
                    ORDER BY a.id DESC LIMIT 1
                ");
                $stmt_alloc->execute([$user['id']]);
                $alloc = $stmt_alloc->fetch();
                if ($alloc) {
                    $seat_info = $alloc['seat_number'];
                    $shift_info = [
                        'id' => $alloc['shift_id'],
                        'name' => $alloc['shift_name'],
                        'timing' => $alloc['start_time'] . ' - ' . $alloc['end_time'],
                        'fee' => $alloc['fee_amount']
                    ];
                    if ($user['status'] === 'pending' || $user['status'] === 'hold') {
                        $pdo->prepare("UPDATE users SET status = 'approved' WHERE id = ?")->execute([$user['id']]);
                        $user['status'] = 'approved';
                    }
                }
            }

            $linked_students = [];
            if ($user['role'] === 'parent') {
                $linked_students = get_parent_linked_students($pdo, $user['id']);
            }

            // Set session variables for authenticated API requests
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['library_code'] = $current_tenant_code;
            $_SESSION['tenant_code'] = $current_tenant_code;
            $_SESSION['auth_time'] = time();
            $_SESSION['session_id'] = session_id();
            session_write_close();

            // Clean up password hash before sending
            unset($user['password']);
            
            echo json_encode([
                'success' => true,
                'message' => 'Login successful',
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'phone' => $user['phone'],
                    'role' => $user['role'],
                    'status' => $user['status'],
                    'library_code' => $current_tenant_code,
                    'preparation_for' => $user['preparation_for'] ?? 'Not specified',
                    'seat_number' => $seat_info,
                    'shift' => $shift_info,
                    'linked_students' => $linked_students
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        }
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'register') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $shift_id = (int)($_POST['shift_id'] ?? 1);
        $emergency_contact = trim($_POST['emergency_contact'] ?? '');
        $id_proof_type = trim($_POST['id_proof_type'] ?? 'Aadhaar Card');
        $id_proof_no = trim($_POST['id_proof_no'] ?? '');
        $preparation_for = trim($_POST['preparation_for'] ?? '');

        if (empty($name) || empty($email) || empty($phone) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'All required fields must be filled.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Check if email already exists
        $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt_check->execute([$email]);
        if ($stmt_check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'This email is already registered.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt_insert = $pdo->prepare("
            INSERT INTO users (name, email, phone, password, role, emergency_contact, id_proof_type, id_proof_no, preparation_for, status)
            VALUES (?, ?, ?, ?, 'student', ?, ?, ?, ?, 'pending')
        ");
        $stmt_insert->execute([$name, $email, $phone, $hashed, $emergency_contact, $id_proof_type, $id_proof_no, $preparation_for]);
        $user_id = (int)$pdo->lastInsertId();
        $pdo->exec("UPDATE users SET id = rowid WHERE id IS NULL OR id = 0");

        // Create initial pending allocation request
        $stmt_dummy_seat = $pdo->query("SELECT id FROM seats LIMIT 1");
        $dummy_seat = $stmt_dummy_seat->fetch();
        $seat_id = $dummy_seat ? $dummy_seat['id'] : 1;

        $cur_date = db_current_date();
        $stmt_alloc = $pdo->prepare("
            INSERT INTO allocations (user_id, seat_id, shift_id, start_date, status, notes)
            VALUES (?, ?, ?, $cur_date, 'hold', 'Requested registration by student')
        ");
        $stmt_alloc->execute([$user_id, $seat_id, $shift_id]);

        echo json_encode([
            'success' => true,
            'message' => 'Registration request submitted successfully! Admin will assign your seat desk.'
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_shifts') {
        $shifts = $pdo->query("SELECT * FROM shifts WHERE is_active = 1")->fetchAll();
        echo json_encode(['success' => true, 'shifts' => $shifts]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'send_login_otp') {
        $phone_or_email = trim($_POST['phone_or_email'] ?? ($_GET['phone_or_email'] ?? ''));
        $device_id = trim($_POST['device_id'] ?? ($_GET['device_id'] ?? ''));

        if (empty($phone_or_email)) {
            echo json_encode(['success' => false, 'message' => 'Please enter registered Student Mobile Phone or Email.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ? OR email = ?");
        $stmt->execute([$phone_or_email, $phone_or_email]);
        $user = $stmt->fetch();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'No student account found with this mobile phone or email.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Security Enforcement 1: Admin accounts CANNOT use public Phone OTP login
        if ($user['role'] === 'admin') {
            echo json_encode(['success' => false, 'message' => 'Admin Accounts must log in securely using Admin Password.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Security Enforcement 2: Hardware Device ID Lockout Verification
        if (!empty($device_id)) {
            if (!empty($user['registered_device_id']) && $user['registered_device_id'] !== $device_id) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Security Lockout 🔒: This account belongs to another registered mobile phone. You cannot log in from a different device!'
                ]);
                if (defined('IN_TEST_SUITE')) return; else exit();
            }
            // Bind device ID if unassigned
            if (empty($user['registered_device_id'])) {
                $pdo->prepare("UPDATE users SET registered_device_id = ? WHERE id = ?")->execute([$device_id, $user['id']]);
            }
        }

        // Generate 6-digit OTP
        $otp = sprintf("%06d", rand(100000, 999999));
        $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        $stmt_upd = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
        $stmt_upd->execute([$otp, $expires_at, $user['id']]);

        // Insert private high-priority device notification for student's phone
        try {
            $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'Private Login OTP 🔑', ?)")
                ->execute([$user['id'], "Your Private Login OTP code is: $otp. Valid for 10 minutes. Do not share this code with anyone."]);
        } catch (Exception $e) {}

        // Mask phone number for privacy (e.g. 9812345678 -> 981XXXX678)
        $phone = $user['phone'];
        $masked_phone = (strlen($phone) >= 10) ? substr($phone, 0, 3) . 'XXXX' . substr($phone, -3) : $phone;

        // Try Real Cellular SMS Gateway (Fast2SMS / MSG91) if configured for Live Production
        $smsResult = send_real_sms_otp($user['phone'], $otp);
        if (defined('ENABLE_REAL_SMS') && ENABLE_REAL_SMS === true && $smsResult['success'] === true) {
            echo json_encode([
                'success' => true,
                'message' => 'OTP sent via SMS to registered phone number ' . $masked_phone . '!',
                'phone' => $masked_phone,
                'mode' => 'live_sms'
            ]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Local Server Demo Mode (returns OTP for local testing app notification)
        echo json_encode([
            'success' => true,
            'message' => 'OTP dispatched securely to registered phone ' . $masked_phone . '! Check notification bar 📲',
            'phone' => $masked_phone,
            'otp_code' => $otp,
            'mode' => 'local_demo'
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'verify_login_otp') {
        $phone_or_email = trim($_POST['phone_or_email'] ?? ($_GET['phone_or_email'] ?? ''));
        $otp_code = trim($_POST['otp_code'] ?? ($_GET['otp_code'] ?? ''));
        $device_id = trim($_POST['device_id'] ?? ($_GET['device_id'] ?? ''));

        if (empty($phone_or_email) || empty($otp_code)) {
            echo json_encode(['success' => false, 'message' => 'Please enter both phone/email and OTP code.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE (phone = ? OR email = ?) AND otp_code = ?");
        $stmt->execute([$phone_or_email, $phone_or_email, $otp_code]);
        $user = $stmt->fetch();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Invalid OTP code. Please check and try again.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if (!empty($user['otp_expires_at']) && strtotime($user['otp_expires_at']) < time()) {
            echo json_encode(['success' => false, 'message' => 'OTP has expired! Please request a new OTP.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Hardware Device Lockout Check on Verification
        if (!empty($device_id)) {
            if (!empty($user['registered_device_id']) && $user['registered_device_id'] !== $device_id) {
                echo json_encode(['success' => false, 'message' => 'Security Lockout 🔒: Device mismatch. Access denied!']);
                if (defined('IN_TEST_SUITE')) return; else exit();
            }
            if (empty($user['registered_device_id'])) {
                $pdo->prepare("UPDATE users SET registered_device_id = ? WHERE id = ?")->execute([$device_id, $user['id']]);
            }
        }

        // Clear OTP after verification
        $pdo->prepare("UPDATE users SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?")->execute([$user['id']]);

        // Fetch seat allocation
        $seat_info = null;
        $shift_info = null;
        if ($user['role'] === 'student') {
            $stmt_alloc = $pdo->prepare("
                SELECT a.*, s.seat_number, sh.name as shift_name, sh.start_time, sh.end_time, sh.fee_amount
                FROM allocations a
                JOIN seats s ON a.seat_id = s.id
                JOIN shifts sh ON a.shift_id = sh.id
                WHERE a.user_id = ? AND (a.status = 'active' OR a.status = 'approved')
                ORDER BY a.id DESC LIMIT 1
            ");
            $stmt_alloc->execute([$user['id']]);
            $alloc = $stmt_alloc->fetch();
            if ($alloc) {
                $seat_info = $alloc['seat_number'];
                $shift_info = [
                    'id' => $alloc['shift_id'],
                    'name' => $alloc['shift_name'],
                    'timing' => $alloc['start_time'] . ' - ' . $alloc['end_time'],
                    'fee' => $alloc['fee_amount']
                ];
                if ($user['status'] === 'pending') {
                    $pdo->prepare("UPDATE users SET status = 'approved' WHERE id = ?")->execute([$user['id']]);
                    $user['status'] = 'approved';
                }
            }
        }

        unset($user['password']);

        echo json_encode([
            'success' => true,
            'message' => 'OTP verified successfully!',
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'role' => $user['role'],
                'status' => $user['status'],
                'seat_number' => $seat_info,
                'shift' => $shift_info
            ]
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
        if (defined('IN_TEST_SUITE')) return; else exit();
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    if (defined('IN_TEST_SUITE')) return; else exit();
}
?>
