<?php
// api/json_student_actions.php - Mobile REST API for Student Dashboard & Actions

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

// Support raw JSON body or POST form-data
$input = json_decode(file_get_contents('php://input'), true);
if (!empty($input)) {
    $_POST = array_merge($_POST, $input);
    $_GET = array_merge($_GET, $input);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$user_id = (int)($_GET['user_id'] ?? ($_POST['user_id'] ?? 0));

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'User ID is required.']);
    exit();
}

try {
    if ($action === 'get_dashboard') {
        // Fetch user profile
        $stmt_u = $pdo->prepare("SELECT id, name, email, phone, status, created_at FROM users WHERE id = ?");
        $stmt_u->execute([$user_id]);
        $user = $stmt_u->fetch();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit();
        }

        // Fetch active seat allocation for student
        $stmt_alloc = $pdo->prepare("
            SELECT a.*, s.seat_number, s.row_label, sh.name as shift_name, sh.start_time, sh.end_time, sh.fee_amount
            FROM allocations a
            JOIN seats s ON a.seat_id = s.id
            JOIN shifts sh ON a.shift_id = sh.id
            WHERE a.user_id = ? AND (a.status = 'active' OR a.status = 'approved')
            ORDER BY a.id DESC LIMIT 1
        ");
        $stmt_alloc->execute([$user_id]);
        $alloc = $stmt_alloc->fetch() ?: null;

        if ($alloc && ($user['status'] === 'pending' || $user['status'] === 'hold')) {
            $pdo->prepare("UPDATE users SET status = 'approved' WHERE id = ?")->execute([$user_id]);
            $user['status'] = 'approved';
        }

        $user['seat_number'] = $alloc ? $alloc['seat_number'] : null;
        $user['shift'] = $alloc ? [
            'id' => $alloc['shift_id'],
            'name' => $alloc['shift_name'],
            'timing' => $alloc['start_time'] . ' - ' . $alloc['end_time'],
            'fee' => $alloc['fee_amount']
        ] : null;

        // Fetch today's attendance log
        $today = date('Y-m-d');
        $stmt_att = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
        $stmt_att->execute([$user_id, $today]);
        $today_att = $stmt_att->fetch();

        // Fetch fee status
        $fee_details = [
            'amount' => 0,
            'due_date' => 'N/A',
            'status' => 'pending',
            'label' => 'Pending Allotment'
        ];
        if ($alloc) {
            $fee_info = get_student_fee_status($pdo, $alloc['id'], $alloc['start_date']);
            $fee_details = [
                'amount' => $alloc['fee_amount'] ?? 0,
                'due_date' => $fee_info['due_date'] ?? 'N/A',
                'status' => $fee_info['status'] ?? 'pending',
                'label' => $fee_info['label'] ?? 'Pending'
            ];
        }

        // Auto-delete notifications & chat messages older than 48 hours, complaints older than 30 days
        try {
            $sub_30_days = db_now_sub_days(30);
            $sub_48_hours = db_now_sub_hours(48);
            $pdo->exec("DELETE FROM complaints WHERE created_at IS NOT NULL AND created_at != '' AND created_at < $sub_30_days");
            $pdo->exec("DELETE FROM notifications WHERE created_at IS NOT NULL AND created_at != '' AND created_at < $sub_48_hours");
            $pdo->exec("DELETE FROM chat_messages WHERE created_at IS NOT NULL AND created_at != '' AND created_at < $sub_48_hours");
        } catch (Exception $e) {}

        // Fetch notifications
        $stmt_notif = $pdo->prepare("
            SELECT * FROM notifications 
            WHERE user_id = 0 OR user_id = ? 
            ORDER BY id DESC LIMIT 10
        ");
        $stmt_notif->execute([$user_id]);
        $notifications = $stmt_notif->fetchAll();

        // Fetch complaints / support tickets
        $stmt_comp = $pdo->prepare("SELECT * FROM complaints WHERE user_id = ? ORDER BY id DESC");
        $stmt_comp->execute([$user_id]);
        $complaints = $stmt_comp->fetchAll();

        // Fetch unread chat count from admin
        $admin_id = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
        if ($admin_id <= 0) $admin_id = 1;

        $stmt_u_chat = $pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
        $stmt_u_chat->execute([$admin_id, $user_id]);
        $unread_chat_count = (int)$stmt_u_chat->fetchColumn();

        echo json_encode([
            'success' => true,
            'user' => $user,
            'allocation' => $alloc,
            'today_attendance' => $today_att ?: null,
            'fee_details' => $fee_details,
            'notifications' => $notifications,
            'complaints' => $complaints,
            'unread_chat_count' => $unread_chat_count
        ]);
        exit();

    } elseif ($action === 'get_complaints') {
        $stmt_comp = $pdo->prepare("SELECT * FROM complaints WHERE user_id = ? ORDER BY id DESC");
        $stmt_comp->execute([$user_id]);
        $complaints = $stmt_comp->fetchAll();
        echo json_encode(['success' => true, 'complaints' => $complaints]);
        exit();

    } elseif ($action === 'mark_notification_read') {
        $notif_id = (int)($_POST['notif_id'] ?? ($_GET['notif_id'] ?? 0));
        if ($notif_id > 0) {
            $stmt_m = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
            $stmt_m->execute([$notif_id]);
        } else {
            $stmt_m = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? OR user_id = 0");
            $stmt_m->execute([$user_id]);
        }
        save_db_snapshot($pdo);
        echo json_encode(['success' => true]);
        exit();

    } elseif ($action === 'checkin') {
        $today = date('Y-m-d');
        $device_time = trim($_POST['device_time'] ?? date('H:i:s'));
        $lat = isset($_POST['latitude']) ? (float)$_POST['latitude'] : 0.0;
        $lng = isset($_POST['longitude']) ? (float)$_POST['longitude'] : 0.0;

        if ($lat == 0.0 || $lng == 0.0) {
            echo json_encode(['success' => false, 'message' => 'Location permission & GPS coordinates required for check-in. Please turn on GPS on your device.']);
            exit();
        }

        $distance = calculate_geofence_distance($lat, $lng);
        if ($distance > GEOFENCE_RADIUS_METERS) {
            $dist_text = round($distance, 1) > 1000 ? round($distance / 1000, 2) . ' km' : round($distance, 1) . ' meters';
            echo json_encode(['success' => false, 'message' => "Check-in Failed: You are $dist_text away from " . LIBRARY_SHORT_NAME . ". Check-in is only permitted within " . (int)GEOFENCE_RADIUS_METERS . " meters of the library."]);
            exit();
        }

        // Check if already checked in today without check out
        $stmt_att = $pdo->prepare("SELECT id, check_in_time, check_out_time FROM attendance WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
        $stmt_att->execute([$user_id, $today]);
        $att = $stmt_att->fetch();

        if ($att && empty($att['check_out_time'])) {
            echo json_encode(['success' => false, 'message' => 'You are already checked in!']);
            exit();
        }

        $stmt_ins = $pdo->prepare("INSERT INTO attendance (user_id, date, check_in_time, status) VALUES (?, ?, ?, 'present')");
        $stmt_ins->execute([$user_id, $today, $device_time]);

        echo json_encode(['success' => true, 'message' => 'Check-in successful at ' . $device_time]);
        exit();

    } elseif ($action === 'checkout') {
        $today = date('Y-m-d');
        $device_time = trim($_POST['device_time'] ?? date('H:i:s'));
        $lat = isset($_POST['latitude']) ? (float)$_POST['latitude'] : 0.0;
        $lng = isset($_POST['longitude']) ? (float)$_POST['longitude'] : 0.0;
        $is_auto = isset($_POST['auto_checkout']) && $_POST['auto_checkout'] == '1';

        if (!$is_auto) {
            if ($lat == 0.0 || $lng == 0.0) {
                echo json_encode(['success' => false, 'message' => 'Location permission & GPS coordinates required for check-out. Please turn on GPS on your device.']);
                exit();
            }

            $distance = calculate_geofence_distance($lat, $lng);
            if ($distance > GEOFENCE_RADIUS_METERS) {
                $dist_text = round($distance, 1) > 1000 ? round($distance / 1000, 2) . ' km' : round($distance, 1) . ' meters';
                echo json_encode(['success' => false, 'message' => "Check-out Warning: You are $dist_text away from " . LIBRARY_SHORT_NAME . ". Auto Check-out processing..."]);
            }
        }

        $stmt_att = $pdo->prepare("SELECT id, check_in_time, check_out_time FROM attendance WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
        $stmt_att->execute([$user_id, $today]);
        $att = $stmt_att->fetch();

        if (!$att || !empty($att['check_out_time'])) {
            echo json_encode(['success' => false, 'message' => 'No active check-in session found to check out.']);
            exit();
        }

        $stmt_upd = $pdo->prepare("UPDATE attendance SET check_out_time = ? WHERE id = ?");
        $stmt_upd->execute([$device_time, $att['id']]);

        $msg = $is_auto ? 'Auto Check-Out completed as you moved beyond 50m of library campus.' : 'Check-out successful at ' . $device_time;
        echo json_encode(['success' => true, 'message' => $msg]);
        exit();


    } elseif ($action === 'submit_complaint') {
        $category = trim($_POST['category'] ?? 'General');
        $subject = trim($_POST['subject'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($subject) || empty($description)) {
            echo json_encode(['success' => false, 'message' => 'Please fill in both subject and description.']);
            exit();
        }

        $stmt_comp = $pdo->prepare("INSERT INTO complaints (user_id, category, subject, description, status) VALUES (?, ?, ?, ?, 'open')");
        $stmt_comp->execute([$user_id, $category, $subject, $description]);

        // Dispatch instant alert notification to Admin
        try {
            $admin_id = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
            if ($admin_id <= 0) $admin_id = 1;

            $stu = $pdo->query("SELECT name FROM users WHERE id = $user_id")->fetch(PDO::FETCH_ASSOC);
            $stu_name = $stu['name'] ?? ('Student #' . $user_id);

            $stmt_notif = $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
            $stmt_notif->execute([
                $admin_id,
                "⚠️ New Complaint: " . $stu_name,
                "[$category] $subject: $description"
            ]);
        } catch (Exception $e) {}

        echo json_encode(['success' => true, 'message' => 'Your complaint/feedback has been submitted to Admin.']);
        exit();

    } elseif ($action === 'get_fee_history') {
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
        $stmt_pay->execute([$user_id]);
        $payments = $stmt_pay->fetchAll();

        echo json_encode([
            'success' => true,
            'fee_history' => $payments
        ]);
        exit();

    } elseif ($action === 'get_chat_messages') {
        // Auto purge 48 hours old messages
        try {
            $sub_48_hours = db_now_sub_hours(48);
            $pdo->exec("DELETE FROM chat_messages WHERE created_at IS NOT NULL AND created_at != '' AND created_at < $sub_48_hours");
        } catch (Exception $e) {}

        $admin_id = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
        if ($admin_id <= 0) $admin_id = 1;

        // Mark admin messages to student as read
        $pdo->prepare("UPDATE chat_messages SET is_read = 1 WHERE receiver_id = ? OR (sender_id IN (SELECT id FROM users WHERE role = 'admin') AND receiver_id = ?)")
            ->execute([$user_id, $user_id]);

        // Mark notifications from admin as read for student
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND title LIKE '%Admin%'")
            ->execute([$user_id]);

        $stmt = $pdo->prepare("
            SELECT cm.*, u_send.name as sender_name, u_send.role as sender_role
            FROM chat_messages cm
            JOIN users u_send ON cm.sender_id = u_send.id
            WHERE (cm.sender_id = ? AND cm.receiver_id = ?) OR (cm.sender_id = ? AND cm.receiver_id = ?)
            ORDER BY cm.created_at ASC, cm.id ASC
        ");
        $stmt->execute([$user_id, $admin_id, $admin_id, $user_id]);
        $messages = $stmt->fetchAll();

        echo json_encode(['success' => true, 'messages' => $messages, 'admin_id' => $admin_id]);
        exit();

    } elseif ($action === 'send_chat_message') {
        $msg_text = trim($_POST['message'] ?? '');
        if (empty($msg_text)) {
            echo json_encode(['success' => false, 'message' => 'Message text cannot be empty.']);
            exit();
        }

        $admin_id = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
        if ($admin_id <= 0) $admin_id = 1;

        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO chat_messages (sender_id, receiver_id, message, is_read, created_at) VALUES (?, ?, ?, 0, ?)");
        $stmt->execute([$user_id, $admin_id, $msg_text, $now]);
        $msg_id = $pdo->lastInsertId();

        // Get student name for admin notification
        $stmt_sname = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $stmt_sname->execute([$user_id]);
        $sname = $stmt_sname->fetchColumn() ?: "Student";

        // Insert notification row for Admin so status bar / alerts fire
        $pdo->prepare("INSERT INTO notifications (title, message, user_id) VALUES (?, ?, ?)")
            ->execute(["💬 New Message from $sname", $msg_text, $admin_id]);

        save_db_snapshot($pdo);

        echo json_encode(['success' => true, 'message_id' => $msg_id]);
        exit();

    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid student action specified.']);
        exit();
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit();
}
?>
