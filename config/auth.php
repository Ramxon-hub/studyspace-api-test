<?php
// config/auth.php - Session and authentication management

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// 50-Meter GPS Geofencing Constants (Keshav Library)
if (!defined('LIBRARY_LAT')) define('LIBRARY_LAT', 28.0087395);
if (!defined('LIBRARY_LNG')) define('LIBRARY_LNG', 73.2924508);
if (!defined('GEOFENCE_RADIUS_METERS')) define('GEOFENCE_RADIUS_METERS', 50.0);

/**
 * Haversine formula to calculate distance between two GPS coordinates in meters
 */
function calculate_geofence_distance($lat1, $lon1, $lat2 = LIBRARY_LAT, $lon2 = LIBRARY_LNG) {
    $earth_radius = 6371000; // Earth radius in meters
    $dLat = deg2rad($lat2 - (float)$lat1);
    $dLon = deg2rad($lon2 - (float)$lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad((float)$lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earth_radius * $c;
}


function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function current_user() {
    global $pdo;
    if (!is_logged_in()) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function is_admin() {
    $user = current_user();
    return $user && $user['role'] === 'admin';
}

function is_parent() {
    $user = current_user();
    return $user && $user['role'] === 'parent';
}

function require_login() {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit();
    }
}

function require_admin() {
    require_login();
    if (!is_admin()) {
        header("Location: student_dashboard.php");
        exit();
    }
}

function require_parent() {
    require_login();
    if (!is_parent()) {
        header("Location: login.php");
        exit();
    }
}

/**
 * Fetch all active linked students for a given Parent user ID
 */
function get_parent_linked_students($pdo, $parent_id) {
    if (!$parent_id) return [];
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.name, u.email, u.phone, u.father_name, u.status, u.preparation_for, u.created_at,
                   a.id as allocation_id, s.seat_number, s.row_label, sh.name as shift_name, sh.start_time, sh.end_time, sh.fee_amount
            FROM parent_student_links l
            JOIN users u ON l.student_user_id = u.id
            LEFT JOIN allocations a ON u.id = a.user_id AND (a.status = 'active' OR a.status = 'approved')
            LEFT JOIN seats s ON a.seat_id = s.id
            LEFT JOIN shifts sh ON a.shift_id = sh.id
            WHERE l.parent_user_id = ? AND l.status = 'active' AND (u.is_deleted IS NULL OR u.is_deleted = 0)
            ORDER BY u.name ASC
        ");
        $stmt->execute([(int)$parent_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Server-side IDOR Guard: Verify whether a given student record belongs to the active parent account
 */
function verify_parent_student_access($pdo, $parent_id, $student_id) {
    if (!$parent_id || !$student_id) return false;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM parent_student_links l
            JOIN users u ON l.student_user_id = u.id
            WHERE l.parent_user_id = ? AND l.student_user_id = ? 
              AND l.status = 'active' AND (u.is_deleted IS NULL OR u.is_deleted = 0)
        ");
        $stmt->execute([(int)$parent_id, (int)$student_id]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function format_currency($amount) {
    return '₹' . number_format($amount, 2);
}

function format_date($date_str) {
    if (!$date_str) return 'N/A';
    return date('d M Y', strtotime($date_str));
}

// Calculate due date for the current billing cycle based on student start date
function get_current_due_date($start_date) {
    $start = new DateTime($start_date);
    $today = new DateTime();
    
    // Day of month registered
    $day_of_month = (int)$start->format('d');
    
    // Construct due date for current month & year
    $year = (int)$today->format('Y');
    $month = (int)$today->format('m');
    
    // Check total days in current month (Natively supported without extra calendar extension)
    $days_in_month = (int)date('t', strtotime(sprintf("%04d-%02d-01", $year, $month)));
    $actual_day = min($day_of_month, $days_in_month);
    
    $current_cycle_due = new DateTime(sprintf("%04d-%02d-%02d", $year, $month, $actual_day));
    
    // If today is past this month's due date, check if paid or overdue
    return $current_cycle_due->format('Y-m-d');
}

// Helper to determine fee status badge and next payment target for a student
function get_student_fee_status($pdo, $allocation_id_or_user_id, $start_date = null) {
    if (!$allocation_id_or_user_id) {
        return [
            'status' => 'pending',
            'label' => 'Pending Allotment',
            'badge_class' => 'badge-secondary',
            'due_date' => date('Y-m-d'),
            'target_month' => date('Y-m'),
            'target_month_label' => date('F Y'),
            'is_advance' => false,
            'last_paid_due' => null
        ];
    }
    
    $param_id = (int)$allocation_id_or_user_id;
    $user_id = $param_id;

    // Check if param_id is a valid student user_id directly
    $stmt_check_user = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt_check_user->execute([$param_id]);
    $is_direct_user = (bool)$stmt_check_user->fetchColumn();

    if ($is_direct_user) {
        $user_id = $param_id;
        $stmt_alloc = $pdo->prepare("SELECT start_date FROM allocations WHERE user_id = ? AND (status = 'active' OR status = 'approved') ORDER BY id DESC LIMIT 1");
        $stmt_alloc->execute([$user_id]);
        $alloc_row = $stmt_alloc->fetch();
        if ($alloc_row && empty($start_date)) {
            $start_date = $alloc_row['start_date'];
        }
    } else {
        // Resolve user_id and start_date if an allocation_id was passed
        $stmt_alloc = $pdo->prepare("SELECT user_id, start_date FROM allocations WHERE id = ?");
        $stmt_alloc->execute([$param_id]);
        $alloc_row = $stmt_alloc->fetch();
        if ($alloc_row) {
            $user_id = (int)$alloc_row['user_id'];
            if (empty($start_date)) {
                $start_date = $alloc_row['start_date'];
            }
        }
    }

    // Always resolve to student's earliest allocation start date for cycle calculation
    $stmt_user_start = $pdo->prepare("
        SELECT COALESCE(MIN(a.start_date), MIN(u.created_at), CURRENT_DATE)
        FROM users u
        LEFT JOIN allocations a ON u.id = a.user_id AND a.start_date IS NOT NULL
        WHERE u.id = ?
        GROUP BY u.id, u.created_at
    ");
    $stmt_user_start->execute([$user_id]);
    $earliest_start_date = $stmt_user_start->fetchColumn();
    if (!empty($earliest_start_date)) {
        $start_date = $earliest_start_date;
    }

    if (!$start_date) {
        $start_date = date('Y-m-d');
    }
    
    $start = new DateTime($start_date);
    $day_of_month = (int)$start->format('d');
    
    // Fetch all paid records for this student (by user_id) regardless of seat allocation changes
    $stmt = $pdo->prepare("SELECT month_year, paid_date, due_date, amount, payment_mode, receipt_no FROM fee_payments WHERE user_id = ? AND payment_status = 'paid' ORDER BY month_year ASC");
    $stmt->execute([$user_id]);
    $paid_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $paid_months = array_column($paid_records, 'month_year');
    $current_month_str = date('Y-m');
    $is_current_paid = in_array($current_month_str, $paid_months);
    $latest_paid_month = !empty($paid_months) ? max($paid_months) : null;
    
    // Scan up to 48 months starting from registration start date or earliest paid month
    $scan_start_date = $start_date;
    if (!empty($paid_months)) {
        $earliest_paid = min($paid_months);
        if (date('Y-m', strtotime($scan_start_date)) > $earliest_paid) {
            $scan_start_date = $earliest_paid . '-' . sprintf("%02d", min(28, $day_of_month));
        }
    }

    $curr_dt = new DateTime(date('Y-m-01', strtotime($scan_start_date)));
    $today = new DateTime();
    $today_str = $today->format('Y-m-d');
    
    $first_unpaid_month = null;
    $first_unpaid_due_date = null;
    $next_unpaid_after_latest = null;
    $next_unpaid_after_latest_due = null;
    $last_paid_due_date = null;

    for ($i = 0; $i < 48; $i++) {
        $m_str = $curr_dt->format('Y-m');
        $days_in_m = (int)$curr_dt->format('t');
        $actual_day = min($day_of_month, $days_in_m);
        $due_str = sprintf("%s-%02d", $m_str, $actual_day);

        if (in_array($m_str, $paid_months)) {
            $last_paid_due_date = $due_str;
        } else {
            if (!$first_unpaid_month) {
                $first_unpaid_month = $m_str;
                $first_unpaid_due_date = $due_str;
            }
            if ($latest_paid_month && $m_str > $latest_paid_month && !$next_unpaid_after_latest) {
                $next_unpaid_after_latest = $m_str;
                $next_unpaid_after_latest_due = $due_str;
            }
        }
        $curr_dt->modify('+1 month');
    }

    if (!$first_unpaid_month) {
        $first_unpaid_month = date('Y-m');
        $first_unpaid_due_date = date('Y-m-d');
    }

    // Determine status:
    // If the student has paid for the current month OR has paid for any future month (latest_paid_month >= current_month_str):
    if ($is_current_paid || ($latest_paid_month && $latest_paid_month >= $current_month_str)) {
        $target_m = $next_unpaid_after_latest ?: $first_unpaid_month;
        $target_due = $next_unpaid_after_latest_due ?: $first_unpaid_due_date;

        return [
            'status' => 'paid',
            'label' => 'Paid (Valid till ' . date('d M Y', strtotime($target_due)) . ')',
            'badge_class' => 'badge-success',
            'due_date' => $target_due,
            'target_month' => $target_m,
            'target_month_label' => date('F Y', strtotime($target_m . '-01')) . ' (Advance)',
            'is_advance' => true,
            'last_paid_due' => $last_paid_due_date
        ];
    } else {
        // If current month is unpaid and no future advance payment covers it:
        $target_m = $first_unpaid_month;
        $target_due = $first_unpaid_due_date;

        if ($today_str > $target_due) {
            $days_overdue = (int)floor((strtotime($today_str) - strtotime($target_due)) / 86400);
            $days_overdue = max(1, $days_overdue);
            return [
                'status' => 'overdue',
                'label' => "Overdue ($days_overdue days)",
                'badge_class' => 'badge-danger',
                'due_date' => $target_due,
                'target_month' => $target_m,
                'target_month_label' => date('F Y', strtotime($target_m . '-01')),
                'is_advance' => false,
                'last_paid_due' => $last_paid_due_date
            ];
        } else {
            return [
                'status' => 'pending',
                'label' => 'Due Soon (' . date('d M Y', strtotime($target_due)) . ')',
                'badge_class' => 'badge-warning',
                'due_date' => $target_due,
                'target_month' => $target_m,
                'target_month_label' => date('F Y', strtotime($target_m . '-01')),
                'is_advance' => false,
                'last_paid_due' => $last_paid_due_date
            ];
        }
    }
}
?>
