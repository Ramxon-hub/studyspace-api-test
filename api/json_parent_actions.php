<?php
// api/json_parent_actions.php - REST API for Parent Portal System

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Support raw JSON body or POST/GET form data
$raw_input = file_get_contents('php://input');
if (!empty($raw_input)) {
    $input = json_decode($raw_input, true);
    if (is_array($input)) {
        $_POST = array_merge($_POST, $input);
        $_GET = array_merge($_GET, $input);
        $_REQUEST = array_merge($_REQUEST, $input);
    }
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Strict Authentication Check
if (!is_logged_in() || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'parent') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Active parent session required.']);
    exit();
}

$parent_id = (int)$_SESSION['user_id'];

// Fetch parent user profile
$stmt_p = $pdo->prepare("SELECT id, name, email, phone, role, status, created_at FROM users WHERE id = ?");
$stmt_p->execute([$parent_id]);
$parent_user = $stmt_p->fetch(PDO::FETCH_ASSOC);

if (!$parent_user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Parent account not found.']);
    exit();
}

try {
    if ($action === 'parent_profile') {
        $students = get_parent_linked_students($pdo, $parent_id);
        echo json_encode([
            'success' => true,
            'parent' => $parent_user,
            'linked_students' => $students
        ]);
        exit();

    } elseif ($action === 'linked_students') {
        $students = get_parent_linked_students($pdo, $parent_id);
        echo json_encode([
            'success' => true,
            'linked_students' => $students
        ]);
        exit();

    } elseif ($action === 'student_summary') {
        $student_id = (int)($_GET['student_id'] ?? ($_POST['student_id'] ?? 0));
        
        // Auto-select first linked student if student_id not explicitly provided
        if ($student_id <= 0) {
            $linked = get_parent_linked_students($pdo, $parent_id);
            if (!empty($linked)) {
                $student_id = (int)$linked[0]['id'];
            }
        }

        if ($student_id <= 0 || !verify_parent_student_access($pdo, $parent_id, $student_id)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Student record does not belong to your parent account.']);
            exit();
        }

        // Fetch Student Details
        $stmt_s = $pdo->prepare("SELECT id, name, email, phone, father_name, status, preparation_for, created_at FROM users WHERE id = ?");
        $stmt_s->execute([$student_id]);
        $student = $stmt_s->fetch(PDO::FETCH_ASSOC);

        // Fetch Allocation Details
        $stmt_alloc = $pdo->prepare("
            SELECT a.*, s.seat_number, s.row_label, sh.name as shift_name, sh.start_time, sh.end_time, sh.fee_amount
            FROM allocations a
            JOIN seats s ON a.seat_id = s.id
            JOIN shifts sh ON a.shift_id = sh.id
            WHERE a.user_id = ? AND (a.status = 'active' OR a.status = 'approved')
            ORDER BY a.id DESC LIMIT 1
        ");
        $stmt_alloc->execute([$student_id]);
        $alloc = $stmt_alloc->fetch(PDO::FETCH_ASSOC);

        // Fetch Today's Attendance
        $today = date('Y-m-d');
        $stmt_att = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ? LIMIT 1");
        $stmt_att->execute([$student_id, $today]);
        $today_att = $stmt_att->fetch(PDO::FETCH_ASSOC);

        // Fee Status
        $fee_status = get_student_fee_status($pdo, $student_id);

        echo json_encode([
            'success' => true,
            'student' => $student,
            'allocation' => $alloc ?: null,
            'seat_number' => $alloc ? $alloc['seat_number'] : null,
            'shift' => $alloc ? [
                'id' => $alloc['shift_id'],
                'name' => $alloc['shift_name'],
                'timing' => $alloc['start_time'] . ' - ' . $alloc['end_time'],
                'fee' => $alloc['fee_amount']
            ] : null,
            'today_attendance' => $today_att ?: [
                'date' => $today,
                'status' => 'not_marked',
                'check_in_time' => null,
                'check_out_time' => null
            ],
            'fee_status' => $fee_status
        ]);
        exit();

    } elseif ($action === 'attendance_history') {
        $student_id = (int)($_GET['student_id'] ?? ($_POST['student_id'] ?? 0));
        $month = trim($_GET['month'] ?? ($_POST['month'] ?? date('Y-m')));

        if ($student_id <= 0 || !verify_parent_student_access($pdo, $parent_id, $student_id)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Student record does not belong to your parent account.']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT id, user_id, date, check_in_time, check_out_time, status
            FROM attendance
            WHERE user_id = ? AND date LIKE ?
            ORDER BY date DESC
        ");
        $stmt->execute([$student_id, $month . '%']);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $present_count = 0;
        $absent_count = 0;
        foreach ($records as $r) {
            if ($r['status'] === 'present') {
                $present_count++;
            } else {
                $absent_count++;
            }
        }

        echo json_encode([
            'success' => true,
            'month' => $month,
            'total_records' => count($records),
            'present_count' => $present_count,
            'absent_count' => $absent_count,
            'records' => $records
        ]);
        exit();

    } elseif ($action === 'fee_history') {
        $student_id = (int)($_GET['student_id'] ?? ($_POST['student_id'] ?? 0));

        if ($student_id <= 0 || !verify_parent_student_access($pdo, $parent_id, $student_id)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Student record does not belong to your parent account.']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT id, allocation_id, month_year, amount, due_date, paid_date, payment_status, payment_mode, receipt_no, remarks, created_at
            FROM fee_payments
            WHERE user_id = ?
            ORDER BY month_year DESC
        ");
        $stmt->execute([$student_id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $fee_status = get_student_fee_status($pdo, $student_id);

        echo json_encode([
            'success' => true,
            'fee_status' => $fee_status,
            'payments' => $payments
        ]);
        exit();

    } elseif ($action === 'fee_12_month') {
        $student_id = (int)($_GET['student_id'] ?? ($_POST['student_id'] ?? 0));

        if ($student_id <= 0 || !verify_parent_student_access($pdo, $parent_id, $student_id)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Student record does not belong to your parent account.']);
            exit();
        }

        $fee_status = get_student_fee_status($pdo, $student_id);

        // Fetch paid fee payments
        $stmt_p = $pdo->prepare("SELECT month_year, paid_date, amount, payment_mode, receipt_no FROM fee_payments WHERE user_id = ? AND payment_status = 'paid'");
        $stmt_p->execute([$student_id]);
        $paid_rows = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
        $paid_map = [];
        foreach ($paid_rows as $pr) {
            $paid_map[$pr['month_year']] = $pr;
        }

        // Build 12-Month Matrix starting from current month going back 6 months and forward 5 months
        $matrix = [];
        $current = new DateTime(date('Y-m-01'));
        $current->modify('-5 months');

        for ($i = 0; $i < 12; $i++) {
            $m_str = $current->format('Y-m');
            $m_label = $current->format('M Y');
            
            $is_paid = isset($paid_map[$m_str]);
            $pay_info = $is_paid ? $paid_map[$m_str] : null;

            $matrix[] = [
                'month_year' => $m_str,
                'month_label' => $m_label,
                'is_paid' => $is_paid,
                'paid_date' => $pay_info ? $pay_info['paid_date'] : null,
                'amount' => $pay_info ? (float)$pay_info['amount'] : null,
                'payment_mode' => $pay_info ? $pay_info['payment_mode'] : null,
                'receipt_no' => $pay_info ? $pay_info['receipt_no'] : null,
                'status' => $is_paid ? 'paid' : ($m_str < date('Y-m') ? 'unpaid' : 'upcoming')
            ];
            $current->modify('+1 month');
        }

        echo json_encode([
            'success' => true,
            'fee_status' => $fee_status,
            'matrix' => $matrix
        ]);
        exit();

    } elseif ($action === 'current_seat') {
        $student_id = (int)($_GET['student_id'] ?? ($_POST['student_id'] ?? 0));

        if ($student_id <= 0 || !verify_parent_student_access($pdo, $parent_id, $student_id)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Student record does not belong to your parent account.']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT s.id, s.seat_number, s.row_label, s.remarks, a.start_date, a.status as allocation_status
            FROM allocations a
            JOIN seats s ON a.seat_id = s.id
            WHERE a.user_id = ? AND (a.status = 'active' OR a.status = 'approved')
            ORDER BY a.id DESC LIMIT 1
        ");
        $stmt->execute([$student_id]);
        $seat = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'seat' => $seat ?: null
        ]);
        exit();

    } elseif ($action === 'current_shift') {
        $student_id = (int)($_GET['student_id'] ?? ($_POST['student_id'] ?? 0));

        if ($student_id <= 0 || !verify_parent_student_access($pdo, $parent_id, $student_id)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Student record does not belong to your parent account.']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT sh.id, sh.name, sh.start_time, sh.end_time, sh.fee_amount
            FROM allocations a
            JOIN shifts sh ON a.shift_id = sh.id
            WHERE a.user_id = ? AND (a.status = 'active' OR a.status = 'approved')
            ORDER BY a.id DESC LIMIT 1
        ");
        $stmt->execute([$student_id]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'shift' => $shift ?: null
        ]);
        exit();

    } elseif ($action === 'notifications') {
        $student_id = (int)($_GET['student_id'] ?? ($_POST['student_id'] ?? 0));

        if ($student_id <= 0 || !verify_parent_student_access($pdo, $parent_id, $student_id)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Student record does not belong to your parent account.']);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT id, user_id, title, message, is_read, created_at
            FROM notifications
            WHERE user_id = 0 OR user_id = ?
            ORDER BY id DESC LIMIT 20
        ");
        $stmt->execute([$student_id]);
        $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'notifications' => $notifs
        ]);
        exit();

    } elseif ($action === 'student_full_summary') {
        $student_id = (int)($_GET['student_id'] ?? ($_POST['student_id'] ?? 0));

        if ($student_id <= 0) {
            $linked = get_parent_linked_students($pdo, $parent_id);
            if (!empty($linked)) {
                $student_id = (int)$linked[0]['id'];
            }
        }

        if ($student_id <= 0 || !verify_parent_student_access($pdo, $parent_id, $student_id)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Student record does not belong to your parent account.']);
            exit();
        }

        // Student Info
        $stmt_s = $pdo->prepare("SELECT id, name, email, phone, father_name, status, preparation_for, created_at FROM users WHERE id = ?");
        $stmt_s->execute([$student_id]);
        $student = $stmt_s->fetch(PDO::FETCH_ASSOC);

        // Seat & Shift Allocation
        $stmt_alloc = $pdo->prepare("
            SELECT a.*, s.seat_number, s.row_label, sh.name as shift_name, sh.start_time, sh.end_time, sh.fee_amount
            FROM allocations a
            JOIN seats s ON a.seat_id = s.id
            JOIN shifts sh ON a.shift_id = sh.id
            WHERE a.user_id = ? AND (a.status = 'active' OR a.status = 'approved')
            ORDER BY a.id DESC LIMIT 1
        ");
        $stmt_alloc->execute([$student_id]);
        $alloc = $stmt_alloc->fetch(PDO::FETCH_ASSOC);

        // Today's Attendance
        $today = date('Y-m-d');
        $stmt_att = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ? LIMIT 1");
        $stmt_att->execute([$student_id, $today]);
        $today_att = $stmt_att->fetch(PDO::FETCH_ASSOC);

        // Monthly Attendance Stats
        $cur_month = date('Y-m');
        $stmt_month_att = $pdo->prepare("SELECT status FROM attendance WHERE user_id = ? AND date LIKE ?");
        $stmt_month_att->execute([$student_id, $cur_month . '%']);
        $month_atts = $stmt_month_att->fetchAll(PDO::FETCH_COLUMN);

        $present_count = 0;
        foreach ($month_atts as $st) {
            if ($st === 'present') $present_count++;
        }

        // Fee Status & 12-Month Matrix
        $fee_status = get_student_fee_status($pdo, $student_id);

        $stmt_p = $pdo->prepare("SELECT month_year, paid_date, amount, payment_mode, receipt_no FROM fee_payments WHERE user_id = ? AND payment_status = 'paid'");
        $stmt_p->execute([$student_id]);
        $paid_rows = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
        $paid_map = [];
        foreach ($paid_rows as $pr) {
            $paid_map[$pr['month_year']] = $pr;
        }

        $matrix = [];
        $current = new DateTime(date('Y-m-01'));
        $current->modify('-5 months');

        for ($i = 0; $i < 12; $i++) {
            $m_str = $current->format('Y-m');
            $m_label = $current->format('M Y');
            $is_paid = isset($paid_map[$m_str]);
            $pay_info = $is_paid ? $paid_map[$m_str] : null;

            $matrix[] = [
                'month_year' => $m_str,
                'month_label' => $m_label,
                'is_paid' => $is_paid,
                'paid_date' => $pay_info ? $pay_info['paid_date'] : null,
                'amount' => $pay_info ? (float)$pay_info['amount'] : null,
                'payment_mode' => $pay_info ? $pay_info['payment_mode'] : null,
                'receipt_no' => $pay_info ? $pay_info['receipt_no'] : null,
                'status' => $is_paid ? 'paid' : ($m_str < date('Y-m') ? 'unpaid' : 'upcoming')
            ];
            $current->modify('+1 month');
        }

        // Notifications
        $stmt_n = $pdo->prepare("SELECT id, user_id, title, message, is_read, created_at FROM notifications WHERE user_id = 0 OR user_id = ? ORDER BY id DESC LIMIT 10");
        $stmt_n->execute([$student_id]);
        $notifs = $stmt_n->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'student' => $student,
            'allocation' => $alloc ?: null,
            'seat_number' => $alloc ? $alloc['seat_number'] : null,
            'shift' => $alloc ? [
                'id' => $alloc['shift_id'],
                'name' => $alloc['shift_name'],
                'timing' => $alloc['start_time'] . ' - ' . $alloc['end_time'],
                'fee' => $alloc['fee_amount']
            ] : null,
            'today_attendance' => $today_att ?: [
                'date' => $today,
                'status' => 'not_marked',
                'check_in_time' => null,
                'check_out_time' => null
            ],
            'month_attendance_summary' => [
                'month' => $cur_month,
                'total_days_marked' => count($month_atts),
                'present_count' => $present_count
            ],
            'fee_status' => $fee_status,
            'fee_12_month_matrix' => $matrix,
            'notifications' => $notifs
        ]);
        exit();

    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
        exit();
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit();
}
?>
