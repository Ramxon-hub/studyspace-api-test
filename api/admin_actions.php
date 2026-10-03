<?php
// api/admin_actions.php - Admin Management API Actions

require_once __DIR__ . '/../config/auth.php';
require_admin();

$action = $_REQUEST['action'] ?? '';

// 1. APPROVE STUDENT REGISTRATION & ALLOT SEAT + SHIFT
if ($action === 'approve_student') {
    $user_id = (int)$_POST['user_id'];
    $seat_id = (int)$_POST['seat_id'];
    $shift_id = (int)$_POST['shift_id'];

    // Preserve original start_date for student if re-allotting seat
    $stmt_orig_start = $pdo->prepare("SELECT start_date FROM allocations WHERE user_id = ? AND start_date IS NOT NULL ORDER BY id ASC LIMIT 1");
    $stmt_orig_start->execute([$user_id]);
    $existing_start_date = $stmt_orig_start->fetchColumn();
    $start_date = !empty($existing_start_date) ? $existing_start_date : trim($_POST['start_date'] ?? date('Y-m-d'));

    if (!$user_id || !$seat_id || !$shift_id) {
        header("Location: ../admin_dashboard.php?tab=students&error=" . urlencode("User, Seat and Shift must be selected."));
        exit();
    }

    $check_stmt = $pdo->prepare("
        SELECT a.id, u.name as student_name, s.seat_number, sh.name as shift_name
        FROM allocations a
        JOIN users u ON a.user_id = u.id
        JOIN seats s ON a.seat_id = s.id
        JOIN shifts sh ON a.shift_id = sh.id
        WHERE a.seat_id = ? AND a.shift_id = ? AND a.status = 'active' AND a.user_id != ?
    ");
    $check_stmt->execute([$seat_id, $shift_id, $user_id]);
    $occupied = $check_stmt->fetch();
    if ($occupied) {
        header("Location: ../admin_dashboard.php?tab=students&error=" . urlencode("Seat Desk " . $occupied['seat_number'] . " is ALREADY OCCUPIED by " . $occupied['student_name'] . " in " . $occupied['shift_name'] . "! Please choose an available seat desk."));
        exit();
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE users SET status = 'approved' WHERE id = ?");
        $stmt->execute([$user_id]);

        // Cancel previous active allocations for user to ensure single active allocation
        $pdo->prepare("UPDATE allocations SET status = 'cancelled' WHERE user_id = ? AND status = 'active'")->execute([$user_id]);

        $stmt_alloc = $pdo->prepare("INSERT INTO allocations (user_id, seat_id, shift_id, start_date, status) VALUES (?, ?, ?, ?, 'active')");
        $stmt_alloc->execute([$user_id, $seat_id, $shift_id, $start_date]);
        $allocation_id = $pdo->lastInsertId();

        $shift = $pdo->prepare("SELECT fee_amount FROM shifts WHERE id = ?");
        $shift->execute([$shift_id]);
        $fee_amount = $shift->fetchColumn();

        $current_month = date('Y-m');
        $due_date = get_current_due_date($start_date);

        // Check if student (by user_id) ALREADY has ANY fee payment record for current_month (paid or pending)
        $check_fee = $pdo->prepare("SELECT id FROM fee_payments WHERE user_id = ? AND month_year = ?");
        $check_fee->execute([$user_id, $current_month]);
        if (!$check_fee->fetch()) {
            $stmt_fee = $pdo->prepare("INSERT INTO fee_payments (allocation_id, user_id, month_year, amount, due_date, payment_status) VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt_fee->execute([$allocation_id, $user_id, $current_month, $fee_amount, $due_date]);
        }

        $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)")
            ->execute([$user_id, 'Seat Allotment Approved 🎉', 'Your library membership registration has been approved! Your desk seat and shift timing have been assigned.']);

        $pdo->commit();

        header("Location: ../admin_dashboard.php?tab=students&msg=" . urlencode("Student approved and seat allotted successfully!"));
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: ../admin_dashboard.php?tab=students&error=" . urlencode("Failed to allot seat: " . $e->getMessage()));
        exit();
    }
}

// 2. PUT STUDENT ON HOLD / WAITING
if ($action === 'hold_student') {
    $user_id = (int)$_POST['user_id'];
    $pdo->prepare("UPDATE users SET status = 'hold' WHERE id = ?")->execute([$user_id]);
    $pdo->prepare("UPDATE allocations SET status = 'hold' WHERE user_id = ?")->execute([$user_id]);
    
    $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)")
        ->execute([$user_id, 'Status Update: On Hold', 'Your library seat allocation status has been updated to On Hold / Waiting List by Admin.']);

    header("Location: ../admin_dashboard.php?tab=students&msg=" . urlencode("Student status changed to On Hold / Waiting."));
    exit();
}

// 3. CANCEL STUDENT ALLOCATION & FREE SEAT
if ($action === 'cancel_student') {
    $user_id = (int)$_POST['user_id'];
    $pdo->prepare("UPDATE users SET status = 'cancelled' WHERE id = ?")->execute([$user_id]);
    $pdo->prepare("UPDATE allocations SET status = 'cancelled' WHERE user_id = ?")->execute([$user_id]);

    header("Location: ../admin_dashboard.php?tab=students&msg=" . urlencode("Student allocation cancelled and seat freed."));
    exit();
}

// 4. RECORD MONTHLY FEE PAYMENT
if ($action === 'record_payment') {
    $allocation_id = (int)$_POST['allocation_id'];
    $user_id = (int)$_POST['user_id'];
    $amount = (float)$_POST['amount'];
    $payment_mode = trim($_POST['payment_mode'] ?? 'Cash');
    $month_year = trim($_POST['month_year'] ?? '');

    if (!$allocation_id || !$user_id || !$amount) {
        header("Location: ../admin_dashboard.php?tab=fees&error=" . urlencode("Invalid payment data."));
        exit();
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

    // Calculate due date for month_year
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
    $payment = $stmt->fetch();

    if ($payment) {
        $update = $pdo->prepare("UPDATE fee_payments SET amount = ?, paid_date = ?, payment_status = 'paid', payment_mode = ?, receipt_no = ?, due_date = ? WHERE id = ?");
        $update->execute([$amount, $today, $payment_mode, $receipt_no, $due_date, $payment['id']]);
    } else {
        $insert = $pdo->prepare("INSERT INTO fee_payments (allocation_id, user_id, month_year, amount, due_date, paid_date, payment_status, payment_mode, receipt_no) VALUES (?, ?, ?, ?, ?, ?, 'paid', ?, ?)");
        $insert->execute([$allocation_id, $user_id, $month_year, $amount, $due_date, $today, $payment_mode, $receipt_no]);
    }

    $month_label = date('F Y', strtotime($month_year . '-01'));

    $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)")
        ->execute([$user_id, 'Monthly Fee Payment Received 💳', "Thank you! Fee payment of ₹$amount for $month_label has been recorded. Receipt No: $receipt_no"]);

    header("Location: ../admin_dashboard.php?tab=fees&msg=" . urlencode("Fee payment for $month_label recorded successfully! Receipt Generated: " . $receipt_no));
    exit();
}

// 5. SEND NOTIFICATION (BROADCAST ALL OR SPECIFIC STUDENT)
if ($action === 'send_notification') {
    $recipient_type = trim($_POST['recipient_type'] ?? 'all');
    $user_id = (int)($_POST['target_user_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (empty($title) || empty($message)) {
        header("Location: ../admin_dashboard.php?tab=notifs&error=" . urlencode("Title and Message are required."));
        exit();
    }

    if ($recipient_type === 'all') {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (0, ?, ?)");
        $stmt->execute([$title, $message]);
        $msg_text = "Broadcast notice sent to ALL students successfully!";
    } else {
        if (!$user_id) {
            header("Location: ../admin_dashboard.php?tab=notifs&error=" . urlencode("Please select a specific student."));
            exit();
        }
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $title, $message]);
        $msg_text = "Notification sent to selected student successfully!";
    }

    header("Location: ../admin_dashboard.php?tab=notifs&msg=" . urlencode($msg_text));
    exit();
}

// 6. ADMIN MANUAL ATTENDANCE CHECK-IN
if ($action === 'admin_checkin') {
    $user_id = (int)$_POST['user_id'];
    $today = date('Y-m-d');
    $time = date('H:i:s');

    $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
    $stmt->execute([$user_id, $today]);
    $att = $stmt->fetch();

    if ($att) {
        header("Location: ../admin_dashboard.php?tab=attendance&error=" . urlencode("Student is already checked in today."));
    } else {
        $insert = $pdo->prepare("INSERT INTO attendance (user_id, date, check_in_time, status) VALUES (?, ?, ?, 'present')");
        $insert->execute([$user_id, $today, $time]);
        header("Location: ../admin_dashboard.php?tab=attendance&msg=" . urlencode("Student checked in manually by Admin at $time!"));
    }
    exit();
}

// 7. ADMIN MANUAL ATTENDANCE CHECK-OUT
if ($action === 'admin_checkout') {
    $user_id = (int)$_POST['user_id'];
    $today = date('Y-m-d');
    $time = date('H:i:s');

    $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
    $stmt->execute([$user_id, $today]);
    $att = $stmt->fetch();

    if ($att) {
        $update = $pdo->prepare("UPDATE attendance SET check_out_time = ? WHERE id = ?");
        $update->execute([$time, $att['id']]);
        header("Location: ../admin_dashboard.php?tab=attendance&msg=" . urlencode("Student marked as Checked-Out / Left Hall by Admin."));
    } else {
        header("Location: ../admin_dashboard.php?tab=attendance&error=" . urlencode("Student has not checked in today."));
    }
    exit();
}

// 8. EDIT / UPDATE EXISTING SHIFT TIMINGS & FEES
if ($action === 'edit_shift') {
    $shift_id = (int)$_POST['shift_id'];
    $name = trim($_POST['name']);
    $start_time = trim($_POST['start_time']);
    $end_time = trim($_POST['end_time']);
    $fee_amount = (float)$_POST['fee_amount'];

    if (!$shift_id || empty($name) || empty($start_time) || empty($end_time) || $fee_amount <= 0) {
        header("Location: ../admin_dashboard.php?tab=settings&error=" . urlencode("Valid shift details required."));
        exit();
    }

    $stmt = $pdo->prepare("UPDATE shifts SET name = ?, start_time = ?, end_time = ?, fee_amount = ? WHERE id = ?");
    $stmt->execute([$name, $start_time, $end_time, $fee_amount, $shift_id]);

    header("Location: ../admin_dashboard.php?tab=settings&msg=" . urlencode("Shift details updated successfully!"));
    exit();
}

// 9. TOGGLE SHIFT ACTIVE STATUS
if ($action === 'toggle_shift') {
    $shift_id = (int)$_POST['shift_id'];
    $status = (int)$_POST['status'];

    $stmt = $pdo->prepare("UPDATE shifts SET is_active = ? WHERE id = ?");
    $stmt->execute([$status, $shift_id]);

    header("Location: ../admin_dashboard.php?tab=settings&msg=" . urlencode("Shift active status updated."));
    exit();
}

// 10. DOWNLOAD DATABASE BACKUP (.db)
if ($action === 'backup_db') {
    $db_file = DB_PATH;
    if (!file_exists($db_file)) {
        die("Database file not found at " . $db_file);
    }

    $timestamp = date('Ymd_His');
    $filename = "library_backup_" . $timestamp . ".db";
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
    exit();
}

// 11. RESTORE DATABASE BACKUP (.db / .sqlite)
if ($action === 'restore_db') {
    if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['backup_file']['tmp_name'];
        $db_file = DB_PATH;
        
        try {
            $test_pdo = new PDO("sqlite:" . $tmp_name);
            $test_pdo->query("SELECT COUNT(*) FROM users");
            
            $test_pdo = null;
            $pdo = null;

            copy($tmp_name, $db_file);
            header("Location: ../admin_dashboard.php?tab=settings&msg=" . urlencode("Database backup restored successfully!"));
        } catch (Exception $e) {
            header("Location: ../admin_dashboard.php?tab=settings&error=" . urlencode("Invalid SQLite database backup file."));
        }
    } else {
        header("Location: ../admin_dashboard.php?tab=settings&error=" . urlencode("Please upload a valid database backup file."));
    }
    exit();
}

// 12. EXPORT STUDENT RECORDS TO EXCEL (CSV)
if ($action === 'export_csv') {
    $stmt = $pdo->query("
        SELECT u.id, u.name, u.email, u.phone, u.emergency_contact, u.id_proof_type, u.id_proof_no, u.status, u.created_at,
               s.seat_number, sh.name as shift_name, sh.fee_amount, a.start_date
        FROM users u
        LEFT JOIN allocations a ON u.id = a.user_id AND a.status IN ('active', 'hold', 'waiting')
        LEFT JOIN seats s ON a.seat_id = s.id
        LEFT JOIN shifts sh ON a.shift_id = sh.id
        WHERE u.role = 'student'
        ORDER BY u.id ASC
    ");
    $students = $stmt->fetchAll();

    $filename = "students_export_" . date('Y-m-d') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'Student ID', 'Full Name', 'Email', 'Phone', 'Emergency Contact',
        'ID Proof Type', 'ID Proof No', 'Status', 'Allotted Desk', 'Shift Name',
        'Monthly Fee', 'Joining Date', 'Registration Date'
    ]);

    foreach ($students as $s) {
        fputcsv($output, [
            '#STU-' . sprintf("%04d", $s['id']),
            $s['name'],
            $s['email'],
            $s['phone'],
            $s['emergency_contact'] ?? '',
            $s['id_proof_type'] ?? '',
            $s['id_proof_no'] ?? '',
            strtoupper($s['status']),
            $s['seat_number'] ? 'Desk ' . $s['seat_number'] : 'Unassigned',
            $s['shift_name'] ?? 'N/A',
            $s['fee_amount'] ? '₹' . $s['fee_amount'] : 'N/A',
            $s['start_date'] ?? '',
            $s['created_at']
        ]);
    }
    fclose($output);
    exit();
}

// 13. IMPORT STUDENT RECORDS FROM EXCEL (CSV)
if ($action === 'import_csv') {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($tmp_name, 'r');
        if ($handle !== FALSE) {
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $headers = fgetcsv($handle);
            $imported_count = 0;
            $pass_default = password_hash('student123', PASSWORD_DEFAULT);

            $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt_insert = $pdo->prepare("INSERT INTO users (name, email, phone, emergency_contact, id_proof_type, id_proof_no, password, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'student', 'pending')");

            while (($row = fgetcsv($handle)) !== FALSE) {
                if (count($row) >= 3) {
                    $name = trim($row[0]);
                    $email = trim($row[1]);
                    $phone = trim($row[2]);
                    $emergency = trim($row[3] ?? '');
                    $id_type = trim($row[4] ?? 'Aadhaar');
                    $id_no = trim($row[5] ?? '');

                    if (!empty($name) && !empty($email) && !empty($phone)) {
                        $stmt_check->execute([$email]);
                        if (!$stmt_check->fetch()) {
                            $stmt_insert->execute([$name, $email, $phone, $emergency, $id_type, $id_no, $pass_default]);
                            $imported_count++;
                        }
                    }
                }
            }
            fclose($handle);
            header("Location: ../admin_dashboard.php?tab=students&msg=" . urlencode("Successfully imported $imported_count student records from CSV!"));
        } else {
            header("Location: ../admin_dashboard.php?tab=students&error=" . urlencode("Failed to read CSV file."));
        }
    } else {
        header("Location: ../admin_dashboard.php?tab=students&error=" . urlencode("Please upload a valid CSV file."));
    }
    exit();
}

// 14. ADD NEW SEAT / DESK
if ($action === 'add_seat') {
    $seat_number = strtoupper(trim($_POST['seat_number']));
    $row_label = strtoupper(trim($_POST['row_label']));
    $remarks = trim($_POST['remarks'] ?? '');

    if (empty($seat_number) || empty($row_label)) {
        header("Location: ../admin_dashboard.php?tab=settings&error=" . urlencode("Seat number and row are required."));
        exit();
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO seats (seat_number, row_label, remarks) VALUES (?, ?, ?)");
        $stmt->execute([$seat_number, $row_label, $remarks]);
        header("Location: ../admin_dashboard.php?tab=settings&msg=" . urlencode("New seat desk added successfully!"));
    } catch (PDOException $e) {
        header("Location: ../admin_dashboard.php?tab=settings&error=" . urlencode("Seat number already exists."));
    }
    exit();
}

// 15. ADD NEW SHIFT
if ($action === 'add_shift') {
    $name = trim($_POST['name']);
    $start_time = trim($_POST['start_time']);
    $end_time = trim($_POST['end_time']);
    $fee_amount = (float)$_POST['fee_amount'];

    if (empty($name) || empty($start_time) || empty($end_time) || $fee_amount <= 0) {
        header("Location: ../admin_dashboard.php?tab=settings&error=" . urlencode("Valid shift details required."));
        exit();
    }

    $stmt = $pdo->prepare("INSERT INTO shifts (name, start_time, end_time, fee_amount) VALUES (?, ?, ?, ?)");
    $stmt->execute([$name, $start_time, $end_time, $fee_amount]);

    header("Location: ../admin_dashboard.php?tab=settings&msg=" . urlencode("New shift added successfully!"));
    exit();
}

// 16. UPDATE COMPLAINT STATUS
if ($action === 'update_complaint') {
    $complaint_id = (int)$_POST['complaint_id'];
    $status = trim($_POST['status']);

    $stmt = $pdo->prepare("UPDATE complaints SET status = ? WHERE id = ?");
    $stmt->execute([$status, $complaint_id]);

    header("Location: ../admin_dashboard.php?tab=complaints&msg=" . urlencode("Complaint status updated to " . ucfirst($status)));
    exit();
}

// 17. BULK CREATE SEATS RANGE (e.g. Row E, 1 to 10 -> E-01 to E-10)
if ($action === 'bulk_create_seats') {
    $row_label = strtoupper(trim($_POST['row_label'] ?? 'A'));
    $start_num = (int)($_POST['start_num'] ?? 1);
    $end_num = (int)($_POST['end_num'] ?? 10);
    $format_digits = (int)($_POST['format_digits'] ?? 2);

    if (empty($row_label) || $start_num <= 0 || $end_num < $start_num) {
        header("Location: ../admin_dashboard.php?tab=settings&error=" . urlencode("Invalid range parameters. End number must be greater than start number."));
        exit();
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

    $msg = "Bulk Creation Complete! Created $created_count new seat desks in Row $row_label ($start_num to $end_num).";
    if ($skipped_count > 0) {
        $msg .= " ($skipped_count seats already existed)";
    }

    header("Location: ../admin_dashboard.php?tab=settings&msg=" . urlencode($msg));
    exit();
}

// 18. DELETE SINGLE SEAT DESK
if ($action === 'delete_seat') {
    $seat_id = (int)$_POST['seat_id'];
    if ($seat_id) {
        $pdo->prepare("DELETE FROM allocations WHERE seat_id = ?")->execute([$seat_id]);
        $pdo->prepare("DELETE FROM seats WHERE id = ?")->execute([$seat_id]);
        header("Location: ../admin_dashboard.php?tab=settings&msg=" . urlencode("Seat desk deleted successfully."));
    } else {
        header("Location: ../admin_dashboard.php?tab=settings&error=" . urlencode("Invalid seat selected."));
    }
    exit();
}

// 19. BULK DELETE ENTIRE ROW OF SEATS
if ($action === 'delete_row_seats') {
    $row_label = strtoupper(trim($_POST['row_label'] ?? ''));
    if (!empty($row_label)) {
        $stmt_seats = $pdo->prepare("SELECT id FROM seats WHERE row_label = ?");
        $stmt_seats->execute([$row_label]);
        $seat_ids = $stmt_seats->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($seat_ids)) {
            $in_clause = implode(',', array_fill(0, count($seat_ids), '?'));
            $pdo->prepare("DELETE FROM allocations WHERE seat_id IN ($in_clause)")->execute($seat_ids);
            $pdo->prepare("DELETE FROM seats WHERE id IN ($in_clause)")->execute($seat_ids);
        }

        header("Location: ../admin_dashboard.php?tab=settings&msg=" . urlencode("All seats in Row $row_label deleted successfully."));
    } else {
        header("Location: ../admin_dashboard.php?tab=settings&error=" . urlencode("Row label required."));
    }
    exit();
}

// 20. MOVE STUDENT TO RECYCLE BIN (SOFT DELETE - KEPT FOR 60 DAYS)
if ($action === 'delete_student' || $action === 'soft_delete_student') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    if ($user_id > 0) {
        $stmt_name = $pdo->prepare("SELECT name FROM users WHERE id = ? AND role = 'student'");
        $stmt_name->execute([$user_id]);
        $name = $stmt_name->fetchColumn();

        if ($name) {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE users SET is_deleted = 1, deleted_at = ? WHERE id = ? AND role = 'student'")->execute([$now, $user_id]);
            $pdo->prepare("UPDATE allocations SET status = 'cancelled' WHERE user_id = ?")->execute([$user_id]);

            header("Location: ../admin_dashboard.php?tab=students&msg=" . urlencode("Student '$name' moved to Recycle Bin! (Will be kept for 60 days)"));
        } else {
            header("Location: ../admin_dashboard.php?tab=students&error=" . urlencode("Student record not found."));
        }
    } else {
        header("Location: ../admin_dashboard.php?tab=students&error=" . urlencode("Invalid student ID."));
    }
    exit();
}

// 21. RESTORE STUDENT FROM RECYCLE BIN
if ($action === 'restore_student') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    if ($user_id > 0) {
        $stmt_name = $pdo->prepare("SELECT name FROM users WHERE id = ? AND role = 'student'");
        $stmt_name->execute([$user_id]);
        $name = $stmt_name->fetchColumn();

        if ($name) {
            $pdo->prepare("UPDATE users SET is_deleted = 0, deleted_at = NULL, status = 'pending' WHERE id = ? AND role = 'student'")->execute([$user_id]);
            header("Location: ../admin_dashboard.php?tab=recyclebin&msg=" . urlencode("Student '$name' restored successfully to Active list!"));
        } else {
            header("Location: ../admin_dashboard.php?tab=recyclebin&error=" . urlencode("Student record not found."));
        }
    } else {
        header("Location: ../admin_dashboard.php?tab=recyclebin&error=" . urlencode("Invalid student ID."));
    }
    exit();
}

// 22. PERMANENTLY DELETE STUDENT RECORD FROM RECYCLE BIN
if ($action === 'permanent_delete_student') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    if ($user_id > 0) {
        $stmt_name = $pdo->prepare("SELECT name FROM users WHERE id = ? AND role = 'student'");
        $stmt_name->execute([$user_id]);
        $name = $stmt_name->fetchColumn();

        if ($name) {
            $pdo->prepare("DELETE FROM fee_payments WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM attendance WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM allocations WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM complaints WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM chat_messages WHERE sender_id = ? OR receiver_id = ?")->execute([$user_id, $user_id]);
            $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'student'")->execute([$user_id]);

            header("Location: ../admin_dashboard.php?tab=recyclebin&msg=" . urlencode("Student '$name' permanently deleted from database!"));
        } else {
            header("Location: ../admin_dashboard.php?tab=recyclebin&error=" . urlencode("Student record not found."));
        }
    } else {
        header("Location: ../admin_dashboard.php?tab=recyclebin&error=" . urlencode("Invalid student ID."));
    }
    exit();
}
?>
