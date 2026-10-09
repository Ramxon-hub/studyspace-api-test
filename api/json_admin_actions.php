<?php

if (!function_exists('safe_http_response_code')) {
    function safe_http_response_code($code) {
        if (!headers_sent()) {
            @http_response_code($code);
        }
    }
}
// api/json_admin_actions.php - Mobile REST API for Admin Dashboard & Management

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

// Support raw JSON body or POST form-data
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

$tenant_ctx = resolve_tenant_context();
$pdo = $tenant_ctx['pdo'];
$current_tenant_code = $tenant_ctx['library_code'];

try {
    $pdo->exec("UPDATE users SET status = 'approved' WHERE (status = 'pending' OR status = 'active') AND id IN (SELECT user_id FROM allocations WHERE status = 'active')");

    if ($action === 'debug_seed') {
        echo json_encode([
            'success' => true,
            'message' => 'Production database is active and authoritative. No hardcoded seed injection.',
            'user_count' => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'students' => $pdo->query("SELECT id, name, email, status, is_deleted FROM users")->fetchAll()
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();
    }

    if ($action === 'get_dashboard_stats') {
        $total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND (is_deleted IS NULL OR is_deleted = 0) AND (status = 'approved' OR status = 'active')")->fetchColumn();
        $pending_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND (is_deleted IS NULL OR is_deleted = 0) AND status = 'pending'")->fetchColumn();
        $total_seats = $pdo->query("SELECT COUNT(*) FROM seats WHERE is_active = 1")->fetchColumn();
        
        $today = date('Y-m-d');
        $present_today = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM attendance WHERE date = '$today' AND status = 'present'")->fetchColumn();
        $currently_inside = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM attendance WHERE date = '$today' AND check_out_time IS NULL")->fetchColumn();

        $admin_id = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
        if ($admin_id <= 0) $admin_id = 1;
        $unread_chats = (int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE sender_id != $admin_id AND (receiver_id = $admin_id OR receiver_id = 1 OR receiver_id = 0) AND (is_read = 0 OR is_read IS NULL)")->fetchColumn();

        echo json_encode([
            'success' => true,
            'stats' => [
                'total_students' => (int)$total_students,
                'pending_students' => (int)$pending_students,
                'total_seats' => (int)$total_seats,
                'present_today' => (int)$present_today,
                'currently_inside' => (int)$currently_inside,
                'unread_chats_count' => (int)$unread_chats
            ]
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_pending_students') {
        $pending = $pdo->query("
            SELECT u.id, u.name, u.email, u.phone, u.created_at, a.shift_id, sh.name as shift_name
            FROM users u
            LEFT JOIN allocations a ON u.id = a.user_id
            LEFT JOIN shifts sh ON a.shift_id = sh.id
            WHERE u.role = 'student' AND u.status = 'pending'
            ORDER BY u.id DESC
        ")->fetchAll();

        $available_seats = $pdo->query("SELECT id, seat_number, row_label FROM seats WHERE is_active = 1 ORDER BY row_label ASC, seat_number ASC")->fetchAll();
        $shifts = $pdo->query("SELECT * FROM shifts WHERE is_active = 1")->fetchAll();
        $active_allocations = $pdo->query("SELECT seat_id, shift_id, user_id FROM allocations WHERE status = 'active'")->fetchAll();

        echo json_encode([
            'success' => true,
            'pending_students' => $pending,
            'available_seats' => $available_seats,
            'shifts' => $shifts,
            'active_allocations' => $active_allocations
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'allot_seat') {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $seat_id = (int)($_POST['seat_id'] ?? 0);
        $shift_id = (int)($_POST['shift_id'] ?? 1);

        if ($student_id <= 0 || $seat_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Please select a valid student and seat desk.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        try {
            $pdo->beginTransaction();

            // Verify student exists
            $stmt_u_chk = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $stmt_u_chk->execute([$student_id]);
            if (!$stmt_u_chk->fetch()) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => "Student (ID: $student_id) does not exist in the database."]);
                if (defined('IN_TEST_SUITE')) return; else exit();
            }

            // Verify seat desk exists
            $stmt_s_chk = $pdo->prepare("SELECT id FROM seats WHERE id = ?");
            $stmt_s_chk->execute([$seat_id]);
            if (!$stmt_s_chk->fetch()) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => "Seat Desk (ID: $seat_id) does not exist in the database."]);
                if (defined('IN_TEST_SUITE')) return; else exit();
            }

            // Verify shift exists
            $stmt_sh_chk = $pdo->prepare("SELECT id FROM shifts WHERE id = ?");
            $stmt_sh_chk->execute([$shift_id]);
            if (!$stmt_sh_chk->fetch()) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => "Shift (ID: $shift_id) does not exist in the database."]);
                if (defined('IN_TEST_SUITE')) return; else exit();
            }

            // Check if seat desk is already occupied in this shift by another student
            $stmt_check = $pdo->prepare("
                SELECT a.id, u.name as student_name, s.seat_number, sh.name as shift_name
                FROM allocations a
                JOIN users u ON a.user_id = u.id
                JOIN seats s ON a.seat_id = s.id
                JOIN shifts sh ON a.shift_id = sh.id
                WHERE a.seat_id = ? AND a.shift_id = ? AND a.status = 'active' AND a.user_id != ?
            ");
            $stmt_check->execute([$seat_id, $shift_id, $student_id]);
            $occupied = $stmt_check->fetch();
            if ($occupied) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => "Seat Desk {$occupied['seat_number']} is ALREADY OCCUPIED by {$occupied['student_name']} in {$occupied['shift_name']}! Please select an available desk."
                ]);
                if (defined('IN_TEST_SUITE')) return; else exit();
            }

            // Fetch original start_date for student if re-allotting seat
            $stmt_orig_start = $pdo->prepare("SELECT start_date FROM allocations WHERE user_id = ? AND start_date IS NOT NULL ORDER BY id ASC LIMIT 1");
            $stmt_orig_start->execute([$student_id]);
            $orig_start_date = $stmt_orig_start->fetchColumn();
            $alloc_start_date = !empty($orig_start_date) ? $orig_start_date : date('Y-m-d');

            // Approve user status
            $pdo->prepare("UPDATE users SET status = 'approved' WHERE id = ?")->execute([$student_id]);

            // Cancel/release all previous active/pending allocations for this student to ensure strictly 1 active allocation
            $pdo->prepare("UPDATE allocations SET status = 'cancelled' WHERE user_id = ? AND status = 'active'")->execute([$student_id]);
            $pdo->prepare("DELETE FROM allocations WHERE user_id = ? AND status = 'pending'")->execute([$student_id]);

            // Insert new active allocation
            $stmt_alloc = $pdo->prepare("
                INSERT INTO allocations (user_id, seat_id, shift_id, start_date, status, notes)
                VALUES (?, ?, ?, ?, 'active', 'Seat desk allotted by Admin')
            ");
            $stmt_alloc->execute([$student_id, $seat_id, $shift_id, $alloc_start_date]);

            // Fetch seat number for response notification
            $stmt_s = $pdo->prepare("SELECT seat_number FROM seats WHERE id = ?");
            $stmt_s->execute([$seat_id]);
            $seat_no = $stmt_s->fetchColumn();

            // Create notification for student
            $pdo->prepare("
                INSERT INTO notifications (title, message, user_id)
                VALUES ('Seat Allotted!', 'Congratulations! Admin has allotted Seat Desk: " . $seat_no . " to you.', ?)
            ")->execute([$student_id]);

            $pdo->commit();

            echo json_encode(['success' => true, 'message' => "Seat Desk $seat_no allotted successfully!"]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Failed to allot seat desk: ' . $e->getMessage()]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

    } elseif ($action === 'get_live_attendance') {
        $target_date = trim($_GET['date'] ?? ($_POST['date'] ?? date('Y-m-d')));
        
        $stmt_att = $pdo->prepare("
            SELECT u.id as user_id, u.name as student_name, u.phone, s.seat_number, sh.name as shift_name,
                   att.id as attendance_id, att.check_in_time, att.check_out_time
            FROM users u
            JOIN allocations a ON u.id = a.user_id AND a.status = 'active'
            JOIN seats s ON a.seat_id = s.id
            JOIN shifts sh ON a.shift_id = sh.id
            LEFT JOIN attendance att ON u.id = att.user_id AND att.date = ?
            WHERE u.role = 'student' AND (u.status = 'approved' OR u.status = 'active') AND (u.is_deleted IS NULL OR u.is_deleted = 0)
            ORDER BY s.seat_number ASC
        ");
        $stmt_att->execute([$target_date]);
        $students_att = $stmt_att->fetchAll(PDO::FETCH_ASSOC);

        $total_count = count($students_att);
        $inside_count = 0;
        
        foreach ($students_att as &$sa) {
            $check_in_str = 'Not Arrived';
            $check_out_str = 'N/A';
            $duration_str = '--';
            
            if (!empty($sa['check_in_time'])) {
                $check_in_ts = strtotime($sa['check_in_time']);
                $check_in_str = date('g:i A', $check_in_ts);
                
                if (!empty($sa['check_out_time'])) {
                    $check_out_ts = strtotime($sa['check_out_time']);
                    $check_out_str = date('g:i A', $check_out_ts);
                    $diff = max(0, $check_out_ts - $check_in_ts);
                    $hrs = floor($diff / 3600);
                    $mins = floor(($diff % 3600) / 60);
                    $duration_str = "{$hrs}h {$mins}m";
                } else {
                    $inside_count++;
                    $check_out_str = 'In Hall 🟢';
                    $diff = max(0, time() - $check_in_ts);
                    $hrs = floor($diff / 3600);
                    $mins = floor(($diff % 3600) / 60);
                    $duration_str = "{$hrs}h {$mins}m (In)";
                }
            }
            
            $sa['check_in_formatted'] = $check_in_str;
            $sa['check_out_formatted'] = $check_out_str;
            $sa['duration_today'] = $duration_str;
        }
        unset($sa);
        
        $absent_count = $total_count - $inside_count;

        echo json_encode([
            'success' => true,
            'date' => $target_date,
            'total_students' => $total_count,
            'currently_inside' => $inside_count,
            'absent_outside' => $absent_count,
            'attendance_list' => $students_att
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_12month_master_fee_report') {
        $stmt_students = $pdo->query("
            SELECT u.id as user_id, u.name, u.email, u.phone, u.status as user_status, u.is_deleted, u.deleted_at, u.created_at,
                   a.start_date, s.seat_number, sh.name as shift_name, sh.fee_amount
            FROM users u
            LEFT JOIN allocations a ON u.id = a.user_id AND a.status = 'active'
            LEFT JOIN seats s ON a.seat_id = s.id
            LEFT JOIN shifts sh ON a.shift_id = sh.id
            WHERE u.role = 'student'
            ORDER BY u.name ASC
        ");
        $students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);

        $report_data = [];
        $total_grand_collected = 0.0;
        $total_grand_overdue = 0.0;

        foreach ($students as $stu) {
            $uid = $stu['user_id'];
            $stmt_pay = $pdo->prepare("
                SELECT month_year, amount, payment_status, paid_date, payment_mode, receipt_no, due_date
                FROM fee_payments fp
                WHERE fp.user_id = ?
                  AND NOT (fp.payment_status != 'paid' AND EXISTS (
                      SELECT 1 FROM fee_payments fp2 
                      WHERE fp2.user_id = fp.user_id 
                        AND fp2.month_year = fp.month_year 
                        AND fp2.payment_status = 'paid'
                  ))
                ORDER BY fp.due_date DESC
            ");
            $stmt_pay->execute([$uid]);
            $payments = $stmt_pay->fetchAll(PDO::FETCH_ASSOC);
            
            $paid_amount = 0.0;
            $overdue_amount = 0.0;
            $paid_months = [];
            
            foreach ($payments as $p) {
                if ($p['payment_status'] === 'paid') {
                    $paid_amount += (float)$p['amount'];
                    $paid_months[] = $p['month_year'];
                } elseif ($p['payment_status'] === 'overdue') {
                    $overdue_amount += (float)$p['amount'];
                }
            }
            
            $total_grand_collected += $paid_amount;
            $total_grand_overdue += $overdue_amount;
            
            $status_label = 'Active';
            if ($stu['is_deleted'] == 1) {
                $status_label = 'Left / Deleted';
            } elseif ($stu['user_status'] === 'pending') {
                $status_label = 'Pending Approval';
            }
            
            $report_data[] = [
                'user_id' => $uid,
                'name' => $stu['name'],
                'phone' => $stu['phone'],
                'email' => $stu['email'],
                'status' => $status_label,
                'seat_number' => $stu['seat_number'] ?? 'N/A',
                'shift_name' => $stu['shift_name'] ?? 'N/A',
                'monthly_fee' => (float)($stu['fee_amount'] ?? 600.0),
                'start_date' => $stu['start_date'] ?? $stu['created_at'],
                'total_paid' => $paid_amount,
                'total_overdue' => $overdue_amount,
                'paid_cycles_count' => count($paid_months),
                'payments' => $payments
            ];
        }

        echo json_encode([
            'success' => true,
            'summary' => [
                'total_students' => count($report_data),
                'total_collected' => $total_grand_collected,
                'total_overdue' => $total_grand_overdue,
            ],
            'csv_url' => 'https://library-management-hmwx.onrender.com/master_12month_fee_report.php?format=csv',
            'web_report_url' => 'https://library-management-hmwx.onrender.com/master_12month_fee_report.php',
            'students' => $report_data
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_shifts') {
        $stmt = $pdo->query("
            SELECT sh.*, COUNT(a.id) as active_students_count
            FROM shifts sh
            LEFT JOIN allocations a ON sh.id = a.shift_id AND a.status = 'active'
            GROUP BY sh.id
            ORDER BY sh.id ASC
        ");
        $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'shifts' => $shifts]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'add_shift') {
        $name = trim($_POST['name'] ?? '');
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');
        $fee_amount = (float)($_POST['fee_amount'] ?? 0);

        if (empty($name) || empty($start_time) || empty($end_time) || $fee_amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Please provide valid shift name, start time, end time, and fee amount.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt = $pdo->prepare("INSERT INTO shifts (name, start_time, end_time, fee_amount, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([$name, $start_time, $end_time, $fee_amount]);
        save_db_snapshot($pdo);

        echo json_encode(['success' => true, 'message' => "New Shift '$name' added successfully!"]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'edit_shift') {
        $shift_id = (int)($_POST['shift_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');
        $fee_amount = (float)($_POST['fee_amount'] ?? 0);

        if (!$shift_id || empty($name) || empty($start_time) || empty($end_time) || $fee_amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Please fill in all required shift fields correctly.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt = $pdo->prepare("UPDATE shifts SET name = ?, start_time = ?, end_time = ?, fee_amount = ? WHERE id = ?");
        $stmt->execute([$name, $start_time, $end_time, $fee_amount, $shift_id]);
        save_db_snapshot($pdo);

        echo json_encode(['success' => true, 'message' => "Shift '$name' details updated successfully!"]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'toggle_shift') {
        $shift_id = (int)($_POST['shift_id'] ?? 0);
        $is_active = (int)($_POST['is_active'] ?? 1);

        if (!$shift_id) {
            echo json_encode(['success' => false, 'message' => 'Shift ID is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt = $pdo->prepare("UPDATE shifts SET is_active = ? WHERE id = ?");
        $stmt->execute([$is_active, $shift_id]);
        save_db_snapshot($pdo);

        $status_text = ($is_active == 1) ? 'activated' : 'deactivated';
        echo json_encode(['success' => true, 'message' => "Shift has been $status_text."]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'admin_attendance_toggle') {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $toggle_type = trim($_POST['toggle_type'] ?? 'checkin'); // checkin or checkout
        $today = date('Y-m-d');
        $current_time = date('H:i:s');

        $stmt_att = $pdo->prepare("SELECT id, check_in_time, check_out_time FROM attendance WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
        $stmt_att->execute([$student_id, $today]);
        $att = $stmt_att->fetch();

        if ($toggle_type === 'checkin') {
            if (!$att) {
                $pdo->prepare("INSERT INTO attendance (user_id, date, check_in_time, status) VALUES (?, ?, ?, 'present')")->execute([$student_id, $today, $current_time]);
            } else {
                $pdo->prepare("UPDATE attendance SET check_in_time = ?, check_out_time = NULL WHERE id = ?")->execute([$current_time, $att['id']]);
            }
            echo json_encode(['success' => true, 'message' => 'Admin checked in student at ' . $current_time]);
        } else { // checkout
            if ($att && empty($att['check_out_time'])) {
                $pdo->prepare("UPDATE attendance SET check_out_time = ? WHERE id = ?")->execute([$current_time, $att['id']]);
            }
            echo json_encode(['success' => true, 'message' => 'Admin checked out student at ' . $current_time]);
        }
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'send_notification') {
        $title = trim($_POST['title'] ?? 'Notice');
        $message = trim($_POST['message'] ?? ($_POST['content'] ?? ''));
        $target_user_id = !empty($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;

        if (empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Notification content cannot be empty.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Auto-delete notifications older than 2 days (48 hours)
        try {
            $sub_2_days = db_now_sub_days(2);
            $pdo->exec("DELETE FROM notifications WHERE created_at < $sub_2_days");
        } catch (Exception $e) {}

        $stmt_ins = $pdo->prepare("INSERT INTO notifications (title, message, user_id) VALUES (?, ?, ?)");
        $stmt_ins->execute([$title, $message, $target_user_id]);

        echo json_encode(['success' => true, 'message' => 'Notification sent successfully!']);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'bulk_create_seats') {
        $row_label = strtoupper(trim($_POST['row_label'] ?? 'A'));
        $start_num = (int)($_POST['start_num'] ?? 1);
        $end_num = (int)($_POST['end_num'] ?? 10);
        $format_digits = (int)($_POST['format_digits'] ?? 2);

        if (empty($row_label) || $start_num <= 0 || $end_num < $start_num) {
            echo json_encode(['success' => false, 'message' => 'Invalid range parameters.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $created_count = 0;
        $skipped_count = 0;

        $stmt_check = $pdo->prepare("SELECT id FROM seats WHERE seat_number = ?");
        $stmt_insert = $pdo->prepare("INSERT INTO seats (seat_number, row_label, is_active) VALUES (?, ?, 1)");

        for ($i = $start_num; $i <= $end_num; $i++) {
            $num_str = str_pad($i, $format_digits, '0', STR_PAD_LEFT);
            $seat_no = $row_label . '-' . $num_str;

            $stmt_check->execute([$seat_no]);
            if (!$stmt_check->fetch()) {
                $stmt_insert->execute([$seat_no, $row_label]);
                $created_count++;
            } else {
                $skipped_count++;
            }
        }

        echo json_encode([
            'success' => true,
            'message' => "Created $created_count new seat desks in Row $row_label ($start_num to $end_num)!"
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'delete_seat') {
        $seat_id = (int)($_POST['seat_id'] ?? 0);
        if ($seat_id > 0) {
            $pdo->prepare("DELETE FROM allocations WHERE seat_id = ?")->execute([$seat_id]);
            $pdo->prepare("DELETE FROM seats WHERE id = ?")->execute([$seat_id]);
            echo json_encode(['success' => true, 'message' => 'Seat desk deleted successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid seat ID.']);
        }
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_all_complaints') {
        $complaints = $pdo->query("
            SELECT c.*, u.name as student_name, u.phone as student_phone
            FROM complaints c
            JOIN users u ON c.user_id = u.id
            ORDER BY c.id DESC
        ")->fetchAll();
        echo json_encode(['success' => true, 'complaints' => $complaints]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'update_complaint_status') {
        $complaint_id = (int)($_POST['complaint_id'] ?? 0);
        $status = trim($_POST['status'] ?? 'resolved');

        if ($complaint_id > 0) {
            $stmt_upd = $pdo->prepare("UPDATE complaints SET status = ? WHERE id = ?");
            $stmt_upd->execute([$status, $complaint_id]);

            $stmt_c = $pdo->prepare("SELECT user_id, subject FROM complaints WHERE id = ?");
            $stmt_c->execute([$complaint_id]);
            $comp = $stmt_c->fetch();
            if ($comp) {
                $status_label = strtoupper(str_replace('_', ' ', $status));
                $pdo->prepare("
                    INSERT INTO notifications (title, message, user_id)
                    VALUES ('Ticket Status Updated', 'Your support ticket #" . sprintf("%04d", $complaint_id) . " (" . $comp['subject'] . ") has been marked as " . $status_label . ".', ?)
                ")->execute([$comp['user_id']]);
            }

            echo json_encode(['success' => true, 'message' => "Ticket status updated to $status!"]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid complaint ticket ID.']);
        }
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_fee_payments') {
        require_once __DIR__ . '/../config/auth.php';
        // Fetch fee status for all students with active allocations
        $allocations = $pdo->query("
            SELECT a.id as allocation_id, a.user_id, a.start_date, u.name as student_name, u.phone as student_phone, u.email as student_email,
                   s.seat_number, s.row_label, sh.name as shift_name, sh.fee_amount
            FROM allocations a
            JOIN users u ON a.user_id = u.id
            JOIN seats s ON a.seat_id = s.id
            JOIN shifts sh ON a.shift_id = sh.id
            WHERE a.status = 'active'
            ORDER BY u.name ASC
        ")->fetchAll();

        $payments_list = [];
        $total_collected = 0;
        $pending_count = 0;
        $overdue_count = 0;

        foreach ($allocations as $alloc) {
            $fee_status = get_student_fee_status($pdo, $alloc['allocation_id'], $alloc['start_date']);

            if ($fee_status['status'] === 'overdue') {
                $overdue_count++;
            } elseif ($fee_status['status'] === 'pending') {
                $pending_count++;
            } elseif ($fee_status['status'] === 'paid') {
                $total_collected += (float)$alloc['fee_amount'];
            }

            $payments_list[] = [
                'allocation_id' => (int)$alloc['allocation_id'],
                'user_id' => (int)$alloc['user_id'],
                'student_name' => $alloc['student_name'],
                'student_phone' => $alloc['student_phone'],
                'student_email' => $alloc['student_email'],
                'seat_number' => $alloc['seat_number'],
                'row_label' => $alloc['row_label'],
                'shift_name' => $alloc['shift_name'],
                'fee_amount' => (float)$alloc['fee_amount'],
                'month_year' => $fee_status['target_month'],
                'month_year_label' => $fee_status['target_month_label'],
                'due_date' => $fee_status['due_date'],
                'payment_status' => $fee_status['status'],
                'label' => $fee_status['label'],
                'is_advance' => $fee_status['is_advance'],
            ];
        }

        echo json_encode([
            'success' => true,
            'stats' => [
                'total_collected' => $total_collected,
                'pending_count' => $pending_count,
                'overdue_count' => $overdue_count,
                'total_active' => count($allocations),
            ],
            'payments' => $payments_list,
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'record_fee_payment') {
        require_once __DIR__ . '/../config/auth.php';
        $allocation_id = (int)($_POST['allocation_id'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $payment_mode = trim($_POST['payment_mode'] ?? 'Cash');
        $month_year = trim($_POST['month_year'] ?? '');

        if ($allocation_id <= 0 || $user_id <= 0 || $amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid fee payment details provided.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Fetch student start date
        $stmt_alloc = $pdo->prepare("SELECT start_date FROM allocations WHERE id = ?");
        $stmt_alloc->execute([$allocation_id]);
        $alloc = $stmt_alloc->fetch();
        $start_date = $alloc['start_date'] ?? date('Y-m-d');

        // Check if submitted month_year is empty OR already paid by this student
        if (!empty($month_year)) {
            $stmt_check = $pdo->prepare("SELECT id FROM fee_payments WHERE user_id = ? AND month_year = ? AND payment_status = 'paid'");
            $stmt_check->execute([$user_id, $month_year]);
            if ($stmt_check->fetch()) {
                // Submitted month is already paid, clear it so fee_status calculates next unpaid cycle
                $month_year = '';
            }
        }

        if (empty($month_year)) {
            $fee_status = get_student_fee_status($pdo, $user_id, $start_date);
            $month_year = $fee_status['target_month'];
        }

        // Calculate due date for the specified month_year
        $start_day = (int)date('d', strtotime($start_date));
        $ym_parts = explode('-', $month_year);
        $target_y = (int)($ym_parts[0] ?? date('Y'));
        $target_m = (int)($ym_parts[1] ?? date('m'));
        $days_in_m = (int)date('t', strtotime(sprintf("%04d-%02d-01", $target_y, $target_m)));
        $actual_day = min($start_day, $days_in_m);
        $due_date = sprintf("%04d-%02d-%02d", $target_y, $target_m, $actual_day);

        $receipt_no = "REC-" . date('Ymd') . "-" . rand(1000, 9999);
        $today = date('Y-m-d');

        $stmt = $pdo->prepare("SELECT id FROM fee_payments WHERE user_id = ? AND month_year = ? AND payment_status != 'paid'");
        $stmt->execute([$user_id, $month_year]);
        $existing = $stmt->fetch();

        if ($existing) {
            $update = $pdo->prepare("
                UPDATE fee_payments 
                SET amount = ?, paid_date = ?, payment_status = 'paid', payment_mode = ?, receipt_no = ?, due_date = ? 
                WHERE id = ?
            ");
            $update->execute([$amount, $today, $payment_mode, $receipt_no, $due_date, $existing['id']]);
        } else {
            $insert = $pdo->prepare("
                INSERT INTO fee_payments (allocation_id, user_id, month_year, amount, due_date, paid_date, payment_status, payment_mode, receipt_no) 
                VALUES (?, ?, ?, ?, ?, ?, 'paid', ?, ?)
            ");
            $insert->execute([$allocation_id, $user_id, $month_year, $amount, $due_date, $today, $payment_mode, $receipt_no]);
        }

        $month_label = date('F Y', strtotime($month_year . '-01'));

        // Notify Student
        $pdo->prepare("
            INSERT INTO notifications (title, message, user_id)
            VALUES ('Monthly Fee Payment Received 💳', 'Thank you! Fee payment of ₹" . number_format($amount, 2) . " for " . $month_label . " has been recorded. Receipt No: " . $receipt_no . "', ?)
        ")->execute([$user_id]);

        echo json_encode([
            'success' => true,
            'message' => "Payment of ₹$amount for $month_label recorded successfully!",
            'receipt_no' => $receipt_no,
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_student_fee_history') {
        $student_id = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
        $stmt_pay = $pdo->prepare("
            SELECT fp.*, s.seat_number, sh.name as shift_name
            FROM fee_payments fp
            LEFT JOIN allocations a ON fp.allocation_id = a.id
            LEFT JOIN seats s ON a.seat_id = s.id
            LEFT JOIN shifts sh ON a.shift_id = sh.id
            WHERE fp.user_id = ?
              AND NOT (fp.payment_status != 'paid' AND EXISTS (
                  SELECT 1 FROM fee_payments fp2 
                  WHERE fp2.user_id = fp.user_id 
                    AND fp2.month_year = fp.month_year 
                    AND fp2.payment_status = 'paid'
              ))
            ORDER BY fp.due_date DESC, fp.id DESC
            LIMIT 12
        ");
        $stmt_pay->execute([$student_id]);
        $payments = $stmt_pay->fetchAll();

        echo json_encode([
            'success' => true,
            'fee_history' => $payments
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'backup_db') {
        $tenant_ctx = resolve_tenant_context();
        $db_file = $tenant_ctx['db_path'];
        $current_code = strtolower($tenant_ctx['library_code']);

        if (!file_exists($db_file)) {
            die("Database file not found for tenant " . strtoupper($current_code));
        }

        $timestamp = date('Ymd_His');
        $filename = "tenant_" . $current_code . "_backup_" . $timestamp . ".sqlite";
        $temp_backup = sys_get_temp_dir() . '/' . $filename;
        if (file_exists($temp_backup)) @unlink($temp_backup);

        try {
            $pdo->exec("VACUUM INTO " . $pdo->quote($temp_backup));
        } catch (Exception $e) {
            copy($db_file, $temp_backup);
        }

        $target_file = file_exists($temp_backup) ? $temp_backup : $db_file;

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($target_file));
        readfile($target_file);

        if (file_exists($temp_backup)) {
            @unlink($temp_backup);
        }
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_database_backup_info') {
        $tenant_ctx = resolve_tenant_context();
        $db_file = $tenant_ctx['db_path'];
        $current_code = strtolower($tenant_ctx['library_code']);

        if (!file_exists($db_file)) {
            echo json_encode(['success' => false, 'message' => 'Database file not found for tenant ' . strtoupper($current_code)]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $tables = ['users', 'parent_student_links', 'seats', 'shifts', 'allocations', 'fee_payments', 'attendance', 'complaints', 'chat_messages', 'notifications', 'system_settings', 'schema_migrations'];
        $counts = [];
        foreach ($tables as $t) {
            try {
                $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
            } catch (Exception $e) {
                $counts[$t] = 0;
            }
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? 'library-management-hmwx.onrender.com';
        $base_url = "$protocol://$host";
        $timestamp = date('Ymd_His');
        $sha256 = file_exists($db_file) ? hash_file('sha256', $db_file) : '';
        
        $integrity_status = 'ok';
        try {
            $integrity_status = (string)$pdo->query("PRAGMA integrity_check")->fetchColumn();
        } catch (Exception $e) {
            $integrity_status = $e->getMessage();
        }

        echo json_encode([
            'success' => true,
            'filename' => "tenant_" . $current_code . "_backup_" . $timestamp . ".sqlite",
            'db_size_bytes' => filesize($db_file),
            'db_size_formatted' => round(filesize($db_file) / 1024, 2) . " KB",
            'sha256' => $sha256,
            'integrity_status' => $integrity_status,
            'table_counts' => $counts,
            'backup_url' => "$base_url/api/json_admin_actions.php?action=backup_db&code=" . strtoupper($current_code)
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_db_fingerprint') {
        $req_role = strtolower(trim($_GET['user_role'] ?? ($_POST['user_role'] ?? ($_SESSION['user_role'] ?? ''))));
        $req_id = (int)($_GET['user_id'] ?? ($_POST['user_id'] ?? ($_SESSION['user_id'] ?? 0)));
        $is_admin_user = ($req_role === 'admin') || ($req_id === 1) || is_admin();
        if (!$is_admin_user && $req_id > 0) {
            try {
                $is_admin_user = ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE id = $req_id AND role = 'admin'")->fetchColumn() > 0);
            } catch (Exception $e) {}
        }
        if (!$is_admin_user) {
            safe_http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'ACCESS DENIED: Diagnostic fingerprint is restricted to Admin access only.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $summary = get_db_identity_summary($pdo);

        $deven_seat = $pdo->query("
            SELECT s.seat_number 
            FROM allocations a 
            JOIN seats s ON a.seat_id = s.id 
            WHERE a.user_id = 6 AND a.status = 'active'
        ")->fetchColumn();

        $a03_active = (bool)$pdo->query("
            SELECT COUNT(*) 
            FROM allocations a 
            JOIN seats s ON a.seat_id = s.id 
            WHERE s.seat_number = 'A-03' AND a.status = 'active'
        ")->fetchColumn();

        echo json_encode([
            'success' => true,
            'fingerprint' => array_merge($summary, [
                'deven_swami_seat' => $deven_seat ?: 'Unassigned',
                'seat_a03_status' => $a03_active ? 'OCCUPIED' : 'AVAILABLE',
                'server_time' => date('Y-m-d H:i:s')
            ])
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'restore_db') {
        $user_role = strtolower(trim($_SESSION['user_role'] ?? ''));
        if ($user_role !== 'admin' && !is_admin()) {
            safe_http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'ACCESS DENIED: Database restore is restricted to Admin access only.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if (db_is_postgres()) {
            echo json_encode(['success' => false, 'message' => 'SQLite file-based database restore is not applicable in PostgreSQL mode.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES['backup_file']['tmp_name'];
            $db_file = DB_PATH;
            $db_dir = dirname($db_file);

            try {
                // 1. Validate uploaded SQLite file integrity
                $test_pdo = new PDO("sqlite:" . $tmp_name);
                $test_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $integrity = $test_pdo->query("PRAGMA integrity_check")->fetchColumn();
                if ($integrity !== 'ok') {
                    throw new Exception("Uploaded database file failed integrity check: $integrity");
                }
                $users_count = (int)$test_pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
                $test_pdo = null;

                // 2. PHASE 2 requirement: Create timestamped backup of current Render DB before restoring
                if (file_exists($db_file)) {
                    $timestamp = date('Ymd_His');
                    $backup_file = $db_dir . '/library_backup_pre_restore_' . $timestamp . '.db';
                    try {
                        $current_pdo = new PDO("sqlite:" . $db_file);
                        $current_pdo->exec("VACUUM INTO '" . str_replace("'", "''", $backup_file) . "'");
                        $current_pdo = null;
                    } catch (Exception $e) {
                        copy($db_file, $backup_file);
                    }

                    // Verify integrity of pre-restore backup
                    if (file_exists($backup_file)) {
                        $b_pdo = new PDO("sqlite:" . $backup_file);
                        $b_integrity = $b_pdo->query("PRAGMA integrity_check")->fetchColumn();
                        $b_pdo = null;
                        if ($b_integrity !== 'ok') {
                            throw new Exception("Pre-restore database backup integrity check failed!");
                        }
                    }
                }

                // Close global PDO connection before replacing database file
                $pdo = null;

                // 3. PHASE 3: Transfer uploaded database atomically to DB_PATH
                if (copy($tmp_name, $db_file)) {
                    // Verify post-migration integrity
                    $post_pdo = new PDO("sqlite:" . $db_file);
                    $post_integrity = $post_pdo->query("PRAGMA integrity_check")->fetchColumn();
                    $post_pdo = null;

                    if ($post_integrity !== 'ok') {
                        throw new Exception("Post-migration database integrity check failed!");
                    }

                    echo json_encode([
                        'success' => true,
                        'message' => "Database successfully migrated/restored! ($users_count users loaded)",
                        'db_path' => $db_file
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to copy uploaded database file to ' . $db_file]);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Migration/Restore failed: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'No database backup file uploaded.']);
        }
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_admin_chat_threads') {
        try {
            $sub_30_days = db_now_sub_days(30);
            $sub_48_hours = db_now_sub_hours(48);
            $pdo->exec("DELETE FROM complaints WHERE created_at IS NOT NULL AND created_at != '' AND created_at < $sub_30_days");
            $pdo->exec("DELETE FROM notifications WHERE created_at IS NOT NULL AND created_at != '' AND created_at < $sub_48_hours");
            $pdo->exec("DELETE FROM chat_messages WHERE created_at IS NOT NULL AND created_at != '' AND created_at < $sub_48_hours");
        } catch (Exception $e) {}

        $admin_id = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
        if ($admin_id <= 0) $admin_id = 1;

        $stmt = $pdo->query("
            SELECT u.id as student_id, u.name as student_name, u.phone, s.seat_number, sh.name as shift_name,
                   (SELECT message FROM chat_messages 
                    WHERE (sender_id = u.id AND receiver_id = $admin_id) OR (sender_id = $admin_id AND receiver_id = u.id) 
                    ORDER BY id DESC LIMIT 1) as last_message,
                   (SELECT created_at FROM chat_messages 
                    WHERE (sender_id = u.id AND receiver_id = $admin_id) OR (sender_id = $admin_id AND receiver_id = u.id) 
                    ORDER BY id DESC LIMIT 1) as last_message_time,
                   (SELECT COUNT(*) FROM chat_messages 
                    WHERE sender_id = u.id AND (receiver_id = $admin_id OR receiver_id = 1 OR receiver_id = 0) AND (is_read = 0 OR is_read = '0')) as unread_count
            FROM users u
            LEFT JOIN allocations a ON u.id = a.user_id AND a.status = 'active'
            LEFT JOIN seats s ON a.seat_id = s.id
            LEFT JOIN shifts sh ON a.shift_id = sh.id
            WHERE u.role = 'student' AND (u.is_deleted IS NULL OR u.is_deleted = 0)
            ORDER BY last_message_time DESC, u.name ASC
        ");
        $threads = $stmt->fetchAll();

        echo json_encode(['success' => true, 'threads' => $threads]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_admin_chat_messages') {
        $student_id = (int)($_GET['student_id'] ?? ($_POST['student_id'] ?? 0));
        $admin_id = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
        if ($admin_id <= 0) $admin_id = 1;

        if ($student_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Mark student's messages as read unconditionally
        $pdo->exec("UPDATE chat_messages SET is_read = 1 WHERE sender_id = $student_id OR receiver_id = $student_id");

        // Mark all notification popups for admin as read so alerts stop repeating
        $pdo->exec("UPDATE notifications SET is_read = 1 WHERE user_id = $admin_id OR user_id = 1 OR user_id = 0");

        save_db_snapshot($pdo);

        $stmt = $pdo->prepare("
            SELECT cm.*, u_send.name as sender_name
            FROM chat_messages cm
            JOIN users u_send ON cm.sender_id = u_send.id
            WHERE (cm.sender_id = ? AND cm.receiver_id = ?) OR (cm.sender_id = ? AND cm.receiver_id = ?)
            ORDER BY cm.id ASC
        ");
        $stmt->execute([$student_id, $admin_id, $admin_id, $student_id]);
        $messages = $stmt->fetchAll();

        $stmt_u = $pdo->prepare("SELECT id, name, phone, status FROM users WHERE id = ?");
        $stmt_u->execute([$student_id]);
        $student = $stmt_u->fetch();

        echo json_encode([
            'success' => true,
            'messages' => $messages,
            'student' => $student,
            'admin_id' => $admin_id
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'send_admin_chat_message') {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $msg_text = trim($_POST['message'] ?? '');

        if ($student_id <= 0 || empty($msg_text)) {
            echo json_encode(['success' => false, 'message' => 'Invalid student ID or message content.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $admin_id = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
        if ($admin_id <= 0) $admin_id = 1;

        $stmt = $pdo->prepare("INSERT INTO chat_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$admin_id, $student_id, $msg_text]);
        $msg_id = $pdo->lastInsertId();

        // Also insert notification for student so app gets push notification pop-up
        $pdo->prepare("
            INSERT INTO notifications (title, message, user_id)
            VALUES ('New Message from Admin 💬', ?, ?)
        ")->execute([$msg_text, $student_id]);

        save_db_snapshot($pdo);

        echo json_encode(['success' => true, 'message_id' => $msg_id]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_all_students') {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN father_name TEXT");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN address TEXT");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN preparation_for TEXT");
        } catch (Exception $e) {}

        $stmt = $pdo->query("
            SELECT u.id, u.name, u.email, u.phone, u.father_name, u.address, u.emergency_contact, u.preparation_for,
                   u.id_proof_type, u.id_proof_no, u.status, u.registered_device_id, u.created_at,
                   s.seat_number, s.row_label, sh.name as shift_name, sh.fee_amount, a.start_date
            FROM users u
            LEFT JOIN allocations a ON u.id = a.user_id AND a.status IN ('active', 'hold', 'waiting', 'approved')
            LEFT JOIN seats s ON a.seat_id = s.id
            LEFT JOIN shifts sh ON a.shift_id = sh.id
            WHERE u.role = 'student' AND (u.is_deleted IS NULL OR u.is_deleted = 0)
            ORDER BY u.id DESC
        ");
        $students = $stmt->fetchAll();

        echo json_encode(['success' => true, 'students' => $students]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_seats_with_status') {
        $shift_id = (int)($_POST['shift_id'] ?? ($_GET['shift_id'] ?? 1));
        $student_id = (int)($_POST['student_id'] ?? ($_GET['student_id'] ?? 0));

        $seats = $pdo->query("SELECT id, seat_number, row_label FROM seats WHERE is_active = 1 ORDER BY row_label ASC, seat_number ASC")->fetchAll(PDO::FETCH_ASSOC);

        $alloc_stmt = $pdo->prepare("
            SELECT a.seat_id, a.user_id, u.name as occupant_name
            FROM allocations a
            JOIN users u ON a.user_id = u.id
            WHERE a.shift_id = ? AND a.status = 'active'
        ");
        $alloc_stmt->execute([$shift_id]);
        $allocs = $alloc_stmt->fetchAll(PDO::FETCH_ASSOC);

        $alloc_map = [];
        foreach ($allocs as $al) {
            $alloc_map[$al['seat_id']] = $al;
        }

        $result_seats = [];
        foreach ($seats as $seat) {
            $s_id = (int)$seat['id'];
            $status = 'AVAILABLE';
            $occupant = null;

            if (isset($alloc_map[$s_id])) {
                $al = $alloc_map[$s_id];
                if ((int)$al['user_id'] === $student_id) {
                    $status = 'CURRENT';
                    $occupant = $al['occupant_name'];
                } else {
                    $status = 'OCCUPIED';
                    $occupant = $al['occupant_name'];
                }
            }

            $result_seats[] = [
                'id' => $s_id,
                'seat_number' => $seat['seat_number'],
                'row_label' => $seat['row_label'],
                'status' => $status,
                'occupant_name' => $occupant
            ];
        }

        echo json_encode(['success' => true, 'seats' => $result_seats]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'update_student') {
        $student_id = (int)($_POST['student_id'] ?? ($_GET['student_id'] ?? 0));
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $father_name = trim($_POST['father_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $emergency_contact = trim($_POST['emergency_contact'] ?? '');
        $preparation_for = trim($_POST['preparation_for'] ?? '');
        $new_seat_id = isset($_POST['seat_id']) ? (int)$_POST['seat_id'] : 0;
        $target_shift_id = isset($_POST['shift_id']) ? (int)$_POST['shift_id'] : 0;

        if ($student_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid student ID.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        try {
            $pdo->beginTransaction();

            // 1. Validate student exists
            $stmt_stu = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'student'");
            $stmt_stu->execute([$student_id]);
            $stu = $stmt_stu->fetch();
            if (!$stu) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Student record not found.']);
                if (defined('IN_TEST_SUITE')) return; else exit();
            }

            $old_seat_no = null;
            $new_seat_no = null;

            // Fetch student's current active allocation
            $stmt_curr = $pdo->prepare("
                SELECT a.id as alloc_id, a.seat_id, a.shift_id, s.seat_number
                FROM allocations a
                JOIN seats s ON a.seat_id = s.id
                WHERE a.user_id = ? AND a.status = 'active'
                ORDER BY a.id DESC LIMIT 1
            ");
            $stmt_curr->execute([$student_id]);
            $curr_alloc = $stmt_curr->fetch();
            if ($curr_alloc) {
                $old_seat_no = $curr_alloc['seat_number'];
            }

            // If target_shift_id not provided, use current shift_id or default 1
            if ($target_shift_id <= 0) {
                $target_shift_id = $curr_alloc ? (int)$curr_alloc['shift_id'] : 1;
            }

            // 2. If seat reassignment requested
            if ($new_seat_id > 0) {
                // Fetch new seat details
                $stmt_seat = $pdo->prepare("SELECT id, seat_number FROM seats WHERE id = ? AND is_active = 1");
                $stmt_seat->execute([$new_seat_id]);
                $seat_obj = $stmt_seat->fetch();
                if (!$seat_obj) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Selected seat desk is invalid or inactive.']);
                    if (defined('IN_TEST_SUITE')) return; else exit();
                }
                $new_seat_no = $seat_obj['seat_number'];

                // Check if seat change or shift change is required
                $need_reassign = true;
                if ($curr_alloc && (int)$curr_alloc['seat_id'] === $new_seat_id && (int)$curr_alloc['shift_id'] === $target_shift_id) {
                    $need_reassign = false;
                }

                if ($need_reassign) {
                    // 3. Double-allocation prevention check
                    $stmt_check = $pdo->prepare("
                        SELECT a.id, u.name as occupant_name, s.seat_number, sh.name as shift_name
                        FROM allocations a
                        JOIN users u ON a.user_id = u.id
                        JOIN seats s ON a.seat_id = s.id
                        JOIN shifts sh ON a.shift_id = sh.id
                        WHERE a.seat_id = ? AND a.shift_id = ? AND a.status = 'active' AND a.user_id != ?
                    ");
                    $stmt_check->execute([$new_seat_id, $target_shift_id, $student_id]);
                    $occupied = $stmt_check->fetch();
                    if ($occupied) {
                        $pdo->rollBack();
                        echo json_encode([
                            'success' => false,
                            'message' => "Seat {$occupied['seat_number']} is already assigned to {$occupied['occupant_name']} in {$occupied['shift_name']}."
                        ]);
                        if (defined('IN_TEST_SUITE')) return; else exit();
                    }

                    // 4. Release/End previous active allocations
                    $pdo->prepare("UPDATE allocations SET status = 'cancelled' WHERE user_id = ? AND status = 'active'")->execute([$student_id]);
                    $pdo->prepare("DELETE FROM allocations WHERE user_id = ? AND status = 'pending'")->execute([$student_id]);

                    // 5. Create new active allocation
                    $cur_date = db_current_date();
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO allocations (user_id, seat_id, shift_id, start_date, status, notes)
                        VALUES (?, ?, ?, $cur_date, 'active', 'Seat reassigned by Admin')
                    ");
                    $stmt_ins->execute([$student_id, $new_seat_id, $target_shift_id]);

                    // Insert notification for student
                    try {
                        $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'Seat Assigned 🪑', ?)")
                            ->execute([$student_id, "Admin updated your assigned seat to Desk $new_seat_no."]);
                    } catch (Exception $e) {}
                }
            }

            // 6. Update student profile in users table
            $stmt_upd = $pdo->prepare("
                UPDATE users
                SET name = ?, phone = ?, email = ?, father_name = ?, address = ?, emergency_contact = ?, preparation_for = ?, status = 'approved'
                WHERE id = ? AND role = 'student'
            ");
            $stmt_upd->execute([$name, $phone, $email, $father_name, $address, $emergency_contact, $preparation_for, $student_id]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => $new_seat_no ? "Student profile and seat ($new_seat_no) updated successfully." : 'Student profile updated successfully.',
                'student_id' => $student_id,
                'old_seat' => $old_seat_no,
                'new_seat' => $new_seat_no ?? $old_seat_no
            ]);
            if (defined('IN_TEST_SUITE')) return; else exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

    } elseif ($action === 'delete_student' || $action === 'soft_delete_student') {
        $student_id = (int)($_POST['student_id'] ?? ($_GET['student_id'] ?? 0));
        if ($student_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid student ID.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt_name = $pdo->prepare("SELECT name FROM users WHERE id = ? AND role = 'student'");
        $stmt_name->execute([$student_id]);
        $student_name = $stmt_name->fetchColumn();

        if (!$student_name) {
            echo json_encode(['success' => false, 'message' => 'Student record not found.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE users SET is_deleted = 1, deleted_at = ? WHERE id = ? AND role = 'student'")->execute([$now, $student_id]);
        $pdo->prepare("UPDATE allocations SET status = 'cancelled' WHERE user_id = ?")->execute([$student_id]);

        echo json_encode(['success' => true, 'message' => "Student '$student_name' moved to Recycle Bin (Kept for 60 days)."]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_recycle_bin_students') {
        // Auto-purge items older than 60 days
        try {
            $sub_60_days = db_now_sub_days(60);
            $old_ids = $pdo->query("SELECT id FROM users WHERE role = 'student' AND is_deleted = 1 AND deleted_at < $sub_60_days")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($old_ids)) {
                $in_clause = implode(',', array_fill(0, count($old_ids), '?'));
                $pdo->prepare("DELETE FROM fee_payments WHERE user_id IN ($in_clause)")->execute($old_ids);
                $pdo->prepare("DELETE FROM attendance WHERE user_id IN ($in_clause)")->execute($old_ids);
                $pdo->prepare("DELETE FROM allocations WHERE user_id IN ($in_clause)")->execute($old_ids);
                $pdo->prepare("DELETE FROM complaints WHERE user_id IN ($in_clause)")->execute($old_ids);
                $pdo->prepare("DELETE FROM notifications WHERE user_id IN ($in_clause)")->execute($old_ids);
                $pdo->prepare("DELETE FROM users WHERE id IN ($in_clause)")->execute($old_ids);
            }
        } catch (Exception $e) {}

        $days_left_expr = db_days_left_expression('u.deleted_at', $pdo);
        $stmt = $pdo->query("
            SELECT u.id, u.name, u.email, u.phone, u.father_name, u.address, u.emergency_contact, u.preparation_for,
                   u.id_proof_type, u.id_proof_no, u.status, u.deleted_at, u.registered_device_id, u.created_at,
                   $days_left_expr as days_left
            FROM users u
            WHERE u.role = 'student' AND u.is_deleted = 1
            ORDER BY u.deleted_at DESC
        ");
        $deleted_students = $stmt->fetchAll();

        echo json_encode(['success' => true, 'students' => $deleted_students]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'restore_student') {
        $student_id = (int)($_POST['student_id'] ?? ($_GET['student_id'] ?? 0));
        if ($student_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid student ID.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt_name = $pdo->prepare("SELECT name FROM users WHERE id = ? AND role = 'student'");
        $stmt_name->execute([$student_id]);
        $student_name = $stmt_name->fetchColumn();

        if (!$student_name) {
            echo json_encode(['success' => false, 'message' => 'Student record not found.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $pdo->prepare("UPDATE users SET is_deleted = 0, deleted_at = NULL, status = 'pending' WHERE id = ? AND role = 'student'")->execute([$student_id]);

        echo json_encode(['success' => true, 'message' => "Student '$student_name' restored to active list successfully."]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'permanent_delete_student') {
        $student_id = (int)($_POST['student_id'] ?? ($_GET['student_id'] ?? 0));
        if ($student_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid student ID.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt_name = $pdo->prepare("SELECT name FROM users WHERE id = ? AND role = 'student'");
        $stmt_name->execute([$student_id]);
        $student_name = $stmt_name->fetchColumn();

        if (!$student_name) {
            echo json_encode(['success' => false, 'message' => 'Student record not found.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        // Purge student record from all tables
        $pdo->prepare("DELETE FROM fee_payments WHERE user_id = ?")->execute([$student_id]);
        $pdo->prepare("DELETE FROM attendance WHERE user_id = ?")->execute([$student_id]);
        $pdo->prepare("DELETE FROM allocations WHERE user_id = ?")->execute([$student_id]);
        $pdo->prepare("DELETE FROM complaints WHERE user_id = ?")->execute([$student_id]);
        $pdo->prepare("DELETE FROM chat_messages WHERE sender_id = ? OR receiver_id = ?")->execute([$student_id, $student_id]);
        $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$student_id]);
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'student'")->execute([$student_id]);

        echo json_encode(['success' => true, 'message' => "Student record '$student_name' permanently deleted from database."]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_admin_notifications') {
        $req_user_id = (int)($_GET['user_id'] ?? ($_POST['user_id'] ?? 0));
        if ($req_user_id > 0) {
            $user_role = $pdo->query("SELECT role FROM users WHERE id = $req_user_id")->fetchColumn();
            if ($user_role !== 'admin') {
                echo json_encode(['success' => true, 'admin_notifications' => []]);
                if (defined('IN_TEST_SUITE')) return; else exit();
            }
        }

        // Fetch new pending student registrations, open support tickets, unread direct chat messages, and admin notifications
        $admin_id = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
        if ($admin_id <= 0) $admin_id = 1;

        $pending = $pdo->query("SELECT id, name, phone, created_at FROM users WHERE role = 'student' AND status = 'pending' ORDER BY id DESC LIMIT 5")->fetchAll();
        $open_complaints = $pdo->query("SELECT c.id, c.subject, c.category, u.name as student_name, c.created_at FROM complaints c JOIN users u ON c.user_id = u.id WHERE (c.status IS NULL OR c.status = '' OR c.status = 'open') ORDER BY c.id DESC LIMIT 5")->fetchAll();
        $unread_chats = $pdo->query("SELECT m.id, m.message, m.sender_id, u.name as student_name, m.created_at FROM chat_messages m JOIN users u ON m.sender_id = u.id WHERE (m.receiver_id = $admin_id OR m.receiver_id = 1 OR m.receiver_id = 0) AND (m.is_read = 0 OR m.is_read IS NULL) ORDER BY m.id DESC LIMIT 5")->fetchAll();
        $direct_notifs = $pdo->query("SELECT id, title, message, created_at FROM notifications WHERE user_id = $admin_id OR user_id = 0 ORDER BY id DESC LIMIT 5")->fetchAll();

        $admin_notifs = [];

        foreach ($pending as $p) {
            $admin_notifs[] = [
                'id' => 1000000 + (int)$p['id'],
                'type' => 'registration',
                'title' => '🎓 New Student Registration Pending!',
                'message' => "Student {$p['name']} (Phone: {$p['phone']}) registered. Click to assign seat desk.",
                'created_at' => $p['created_at']
            ];
        }

        foreach ($open_complaints as $c) {
            $admin_notifs[] = [
                'id' => 2000000 + (int)$c['id'],
                'type' => 'complaint',
                'title' => '⚠️ New Facility Support Request!',
                'message' => "{$c['student_name']} logged ticket: {$c['subject']} ({$c['category']}).",
                'created_at' => $c['created_at']
            ];
        }

        foreach ($unread_chats as $ch) {
            $admin_notifs[] = [
                'id' => 3000000 + (int)$ch['id'],
                'type' => 'chat',
                'title' => "💬 New Direct Message from {$ch['student_name']}",
                'message' => $ch['message'],
                'created_at' => $ch['created_at']
            ];
        }

        foreach ($direct_notifs as $dn) {
            $admin_notifs[] = [
                'id' => 4000000 + (int)$dn['id'],
                'type' => 'notice',
                'title' => $dn['title'],
                'message' => $dn['message'],
                'created_at' => $dn['created_at']
            ];
        }

        // Sort all admin notifications in strict LIFO order (Latest / Newest at top)
        usort($admin_notifs, function($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        echo json_encode([
            'success' => true,
            'admin_notifications' => $admin_notifs
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_app_settings') {
        $settings = [];
        try {
            $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
            foreach ($rows as $r) {
                $settings[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Exception $e) {}

        echo json_encode([
            'success' => true,
            'settings' => [
                'app_name' => $settings['app_name'] ?? 'Self Study Library',
                'app_logo_url' => $settings['app_logo_url'] ?? '',
                'app_tagline' => $settings['app_tagline'] ?? 'Quiet Environment & High-Speed Wi-Fi'
            ]
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'update_app_settings') {
        $app_name = trim($_POST['app_name'] ?? '');
        $app_logo_url = trim($_POST['app_logo_url'] ?? '');
        $app_tagline = trim($_POST['app_tagline'] ?? '');

        if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $uploads_dir = __DIR__ . '/../assets/uploads';
            if (!file_exists($uploads_dir)) {
                @mkdir($uploads_dir, 0777, true);
            }
            $ext = pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION);
            $filename = 'app_logo_' . time() . '.' . ($ext ?: 'png');
            $target_path = $uploads_dir . '/' . $filename;
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $target_path)) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
                $host = $_SERVER['HTTP_HOST'] ?? 'library-management-hmwx.onrender.com';
                $app_logo_url = "$protocol://$host/assets/uploads/$filename";
            }
        }

        try {
            if ($app_name !== '') {
                db_upsert_system_setting($pdo, 'app_name', $app_name);
            }
            if ($app_logo_url !== '') {
                db_upsert_system_setting($pdo, 'app_logo_url', $app_logo_url);
            }
            if ($app_tagline !== '') {
                db_upsert_system_setting($pdo, 'app_tagline', $app_tagline);
            }

            echo json_encode([
                'success' => true,
                'message' => 'App branding settings updated successfully!',
                'app_name' => $app_name,
                'app_logo_url' => $app_logo_url,
                'app_tagline' => $app_tagline
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to update app settings: ' . $e->getMessage()]);
        }
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'restore_database') {
        $res = restore_db_snapshot($pdo);
        $total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
        echo json_encode([
            'success' => $res,
            'message' => $res ? "Database snapshot restored successfully with $total_students students!" : "Failed to restore snapshot.",
            'total_students' => (int)$total_students
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_parents') {
        $parents = $pdo->query("
            SELECT id, name, email, phone, status, created_at
            FROM users
            WHERE role = 'parent' AND (is_deleted IS NULL OR is_deleted = 0)
            ORDER BY id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($parents as &$p) {
            $p['linked_students'] = get_parent_linked_students($pdo, $p['id']);
        }

        $all_students = $pdo->query("
            SELECT id, name, email, phone
            FROM users
            WHERE role = 'student' AND (is_deleted IS NULL OR is_deleted = 0)
            ORDER BY name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'parents' => $parents,
            'all_students' => $all_students
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'create_parent') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $relationship = trim($_POST['relationship'] ?? 'Father');
        $status = trim($_POST['status'] ?? 'approved');
        if (!in_array($status, ['approved', 'active', 'disabled', 'pending'])) {
            $status = 'approved';
        }

        $student_ids = $_POST['student_ids'] ?? [];
        if (is_string($student_ids)) {
            $student_ids = json_decode($student_ids, true) ?: [$student_ids];
        }

        if (empty($name) || empty($email) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Parent Name, Email, and Password are required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt_check->execute([$email]);
        if ($stmt_check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'An account with this email already exists in this tenant.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        try {
            $pdo->beginTransaction();
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt_ins = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, status) VALUES (?, ?, ?, ?, 'parent', ?)");
            $stmt_ins->execute([$name, $email, $phone, $hashed, $status]);
            $parent_id = $pdo->lastInsertId();

            if (!empty($student_ids) && is_array($student_ids)) {
                $stmt_v = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' AND (is_deleted IS NULL OR is_deleted = 0)");
                $stmt_link = $pdo->prepare("INSERT INTO parent_student_links (parent_user_id, student_user_id, relationship, status) VALUES (?, ?, ?, 'active')");
                foreach ($student_ids as $sid) {
                    $sid = (int)$sid;
                    if ($sid > 0) {
                        $stmt_v->execute([$sid]);
                        if ($stmt_v->fetch()) {
                            try { $stmt_link->execute([$parent_id, $sid, $relationship]); } catch (Exception $ex) {}
                        }
                    }
                }
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Parent account created successfully and linked to students.',
                'parent_id' => $parent_id
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Failed to create parent account: ' . $e->getMessage()]);
        }
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'edit_parent') {
        $parent_id = (int)($_POST['parent_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $status = trim($_POST['status'] ?? 'approved');

        if ($parent_id <= 0 || empty($name) || empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Parent ID, Name, and Email are required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt_check->execute([$email, $parent_id]);
        if ($stmt_check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Another account with this email already exists.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt_upd = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, status = ? WHERE id = ? AND role = 'parent'");
        $stmt_upd->execute([$name, $email, $phone, $status, $parent_id]);

        echo json_encode(['success' => true, 'message' => 'Parent details updated successfully.']);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'link_parent_student') {
        $parent_id = (int)($_POST['parent_id'] ?? 0);
        $student_id = (int)($_POST['student_id'] ?? 0);
        $relationship = trim($_POST['relationship'] ?? 'Father');

        if ($parent_id <= 0 || $student_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Valid Parent ID and Student ID are required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt_v = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' AND (is_deleted IS NULL OR is_deleted = 0)");
        $stmt_v->execute([$student_id]);
        if (!$stmt_v->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Student ID is not valid in current tenant.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt_link = $pdo->prepare("INSERT INTO parent_student_links (parent_user_id, student_user_id, relationship, status) VALUES (?, ?, ?, 'active')");
        try {
            $stmt_link->execute([$parent_id, $student_id, $relationship]);
            echo json_encode(['success' => true, 'message' => 'Student linked to Parent successfully.']);
        } catch (Exception $e) {
            $pdo->prepare("UPDATE parent_student_links SET status = 'active', relationship = ? WHERE parent_user_id = ? AND student_user_id = ?")->execute([$relationship, $parent_id, $student_id]);
            echo json_encode(['success' => true, 'message' => 'Parent-student link updated to active.']);
        }
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'unlink_parent_student') {
        $parent_id = (int)($_POST['parent_id'] ?? 0);
        $student_id = (int)($_POST['student_id'] ?? 0);

        if ($parent_id <= 0 || $student_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Valid Parent ID and Student ID are required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt_del = $pdo->prepare("DELETE FROM parent_student_links WHERE parent_user_id = ? AND student_user_id = ?");
        $stmt_del->execute([$parent_id, $student_id]);

        echo json_encode(['success' => true, 'message' => 'Student unlinked from Parent successfully.']);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'toggle_parent_status') {
        $parent_id = (int)($_POST['parent_id'] ?? 0);
        if ($parent_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Valid Parent ID required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ? AND role = 'parent'");
        $stmt->execute([$parent_id]);
        $curr_status = $stmt->fetchColumn();

        if (!$curr_status) {
            echo json_encode(['success' => false, 'message' => 'Parent user not found.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $new_status = ($curr_status === 'approved' || $curr_status === 'active') ? 'disabled' : 'approved';
        $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$new_status, $parent_id]);

        echo json_encode([
            'success' => true,
            'message' => "Parent account status updated to $new_status.",
            'new_status' => $new_status
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'reset_parent_password') {
        $parent_id = (int)($_POST['parent_id'] ?? 0);
        $new_pass = trim($_POST['new_password'] ?? '');

        if ($parent_id <= 0 || empty($new_pass)) {
            echo json_encode(['success' => false, 'message' => 'Parent ID and New Password are required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'parent'")->execute([$hashed, $parent_id]);

        echo json_encode(['success' => true, 'message' => 'Parent password reset successfully.']);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'get_parent_activity') {
        $parent_id = (int)($_GET['parent_id'] ?? ($_POST['parent_id'] ?? 0));
        if ($parent_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Parent ID is required.']);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $linked_students = get_parent_linked_students($pdo, $parent_id);
        $student_ids = array_column($linked_students, 'id');

        $attendance = [];
        $fees = [];
        $complaints = [];
        $chat = [];

        if (!empty($student_ids)) {
            $in_clause = implode(',', array_fill(0, count($student_ids), '?'));

            $stmt_att = $pdo->prepare("SELECT * FROM attendance WHERE user_id IN ($in_clause) ORDER BY date DESC LIMIT 30");
            $stmt_att->execute($student_ids);
            $attendance = $stmt_att->fetchAll(PDO::FETCH_ASSOC);

            $stmt_fees = $pdo->prepare("SELECT * FROM fee_payments WHERE user_id IN ($in_clause) ORDER BY payment_date DESC LIMIT 30");
            $stmt_fees->execute($student_ids);
            $fees = $stmt_fees->fetchAll(PDO::FETCH_ASSOC);

            $stmt_comp = $pdo->prepare("SELECT * FROM complaints WHERE user_id IN ($in_clause) ORDER BY id DESC LIMIT 30");
            $stmt_comp->execute($student_ids);
            $complaints = $stmt_comp->fetchAll(PDO::FETCH_ASSOC);

            $stmt_chat = $pdo->prepare("
                SELECT cm.*, u_send.name as sender_name
                FROM chat_messages cm
                JOIN users u_send ON cm.sender_id = u_send.id
                WHERE cm.sender_id IN ($in_clause) OR cm.receiver_id IN ($in_clause) OR cm.sender_id = ? OR cm.receiver_id = ?
                ORDER BY cm.id DESC LIMIT 30
            ");
            $params = array_merge($student_ids, [$parent_id, $parent_id]);
            $stmt_chat->execute($params);
            $chat = $stmt_chat->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'success' => true,
            'linked_students' => $linked_students,
            'attendance' => $attendance,
            'fees' => $fees,
            'complaints' => $complaints,
            'chat' => $chat
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid admin action specified.']);
        if (defined('IN_TEST_SUITE')) return; else exit();
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    if (defined('IN_TEST_SUITE')) return; else exit();
}
?>
