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
                   l.relationship,
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

/**
 * Computes full-day in/out attendance timeline for a student on a specific date.
 * Accurately tracks multiple entry/exit cycles, total duration, current inside/outside status.
 */
function compute_student_day_timeline($pdo, $student_id, $target_date = null) {
    if (empty($target_date)) {
        $target_date = date('Y-m-d');
    }

    $student_id = (int)$student_id;

    // 1. Fetch all discrete events from attendance_events
    $stmt = $pdo->prepare("
        SELECT id, user_id, event_date, event_time, event_type, source, notes, created_at
        FROM attendance_events
        WHERE user_id = ? AND event_date = ?
        ORDER BY event_time ASC, id ASC
    ");
    $stmt->execute([$student_id, $target_date]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Backward-compatible fallback to legacy attendance table if no attendance_events exist
    if (empty($events)) {
        $stmt_att = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
        $stmt_att->execute([$student_id, $target_date]);
        $legacy = $stmt_att->fetch(PDO::FETCH_ASSOC);
        if ($legacy && !empty($legacy['check_in_time'])) {
            $events[] = [
                'id' => 0,
                'user_id' => $student_id,
                'event_date' => $target_date,
                'event_time' => $legacy['check_in_time'],
                'event_type' => 'CHECK_IN',
                'source' => 'LEGACY',
                'notes' => 'Recorded attendance session'
            ];
            if (!empty($legacy['check_out_time'])) {
                $events[] = [
                    'id' => 0,
                    'user_id' => $student_id,
                    'event_date' => $target_date,
                    'event_time' => $legacy['check_out_time'],
                    'event_type' => 'CHECK_OUT',
                    'source' => 'LEGACY',
                    'notes' => 'Recorded attendance session'
                ];
            }
        }
    }

    $formatted_events = [];
    $check_in_count = 0;
    $check_out_count = 0;
    $total_inside_seconds = 0;
    $open_check_in_ts = null;
    $first_arrival = null;
    $final_exit = null;
    $incomplete_pairs = false;

    $status_note = '';

    foreach ($events as $ev) {
        $type = strtoupper($ev['event_type']);
        $raw_time = $ev['event_time'];
        $event_ts = (strpos($raw_time, '-') !== false) ? strtotime($raw_time) : strtotime($target_date . ' ' . $raw_time);
        $time_str = date('H:i:s', $event_ts);

        if ($type === 'CHECK_IN') {
            $check_in_count++;
            if ($first_arrival === null) {
                $first_arrival = $time_str;
            }
            if ($open_check_in_ts !== null) {
                // Consecutive check-in without an exit: mark incomplete pair
                $incomplete_pairs = true;
            }
            $open_check_in_ts = $event_ts;

            $label = ($check_in_count === 1) ? 'First Arrival (Check-In)' : 'Re-Entry #' . ($check_in_count - 1);
            $formatted_events[] = [
                'id' => (int)$ev['id'],
                'event_date' => $target_date,
                'event_time' => $time_str,
                'time_formatted' => date('h:i A', $event_ts),
                'event_type' => 'CHECK_IN',
                'event_label' => $label,
                'source' => $ev['source'] ?? 'SELF',
                'notes' => $ev['notes'] ?? ''
            ];
        } elseif ($type === 'CHECK_OUT') {
            $check_out_count++;
            $final_exit = $time_str;
            if ($open_check_in_ts !== null) {
                $duration = $event_ts - $open_check_in_ts;
                if ($duration > 0) {
                    $total_inside_seconds += $duration;
                }
                $open_check_in_ts = null;
            } else {
                // Checkout without prior check-in: mark incomplete pair
                $incomplete_pairs = true;
            }

            $label = 'Exit / Step-Out #' . $check_out_count;
            $formatted_events[] = [
                'id' => (int)$ev['id'],
                'event_date' => $target_date,
                'event_time' => $time_str,
                'time_formatted' => date('h:i A', $event_ts),
                'event_type' => 'CHECK_OUT',
                'event_label' => $label,
                'source' => $ev['source'] ?? 'SELF',
                'notes' => $ev['notes'] ?? ''
            ];
        }
    }

    // Determine current status
    $current_status = 'NOT_CHECKED_IN';
    $status_label = 'Not Checked In';

    if (!empty($events)) {
        $last_ev = end($events);
        if (strtoupper($last_ev['event_type']) === 'CHECK_IN') {
            $current_status = 'INSIDE_LIBRARY';
            $status_label = 'Inside Library';
            // Student is currently inside: calculate duration up to current time if target_date is today
            if ($target_date === date('Y-m-d') && $open_check_in_ts !== null) {
                $cur_ts = time();
                if ($cur_ts > $open_check_in_ts) {
                    $total_inside_seconds += ($cur_ts - $open_check_in_ts);
                }
            } elseif ($open_check_in_ts !== null) {
                // Unclosed session on past day
                $incomplete_pairs = true;
                $status_note = 'Student checked in but did not log check-out before day ended.';
            }
        } else {
            $current_status = 'OUTSIDE_LIBRARY';
            $status_label = 'Outside Library';
        }
    }

    $hrs = floor($total_inside_seconds / 3600);
    $mins = floor(($total_inside_seconds % 3600) / 60);
    $total_inside_formatted = ($hrs > 0) ? "{$hrs}h {$mins}m" : "{$mins}m";
    if ($total_inside_seconds == 0 && empty($events)) {
        $total_inside_formatted = "0m";
    }

    return [
        'date' => $target_date,
        'current_status' => $current_status,
        'status_label' => $status_label,
        'status_note' => $status_note,
        'first_arrival' => $first_arrival ? date('h:i A', strtotime($target_date . ' ' . $first_arrival)) : null,
        'first_arrival_formatted' => $first_arrival ? date('h:i A', strtotime($target_date . ' ' . $first_arrival)) : null,
        'final_exit' => ($current_status === 'OUTSIDE_LIBRARY' && $final_exit) ? date('h:i A', strtotime($target_date . ' ' . $final_exit)) : null,
        'final_exit_formatted' => ($current_status === 'OUTSIDE_LIBRARY' && $final_exit) ? date('h:i A', strtotime($target_date . ' ' . $final_exit)) : null,
        'total_inside_seconds' => (int)$total_inside_seconds,
        'total_inside_minutes' => (int)round($total_inside_seconds / 60),
        'total_inside_formatted' => $total_inside_formatted,
        'cycles_count' => max($check_in_count, $check_out_count),
        'exit_count' => $check_out_count,
        'reentry_count' => max(0, $check_in_count - 1),
        'incomplete_pairs' => $incomplete_pairs,
        'total_events' => count($formatted_events),
        'events_count' => count($formatted_events),
        'events' => $formatted_events
    ];
}

/**
 * Computes monthly attendance calendar and breakdown with daily timelines
 */
function get_student_monthly_attendance_summary($pdo, $student_id, $month = null) {
    if (empty($month)) {
        $month = date('Y-m');
    }

    $student_id = (int)$student_id;

    // Fetch distinct active dates from both attendance_events and attendance
    $dates = [];

    $stmt1 = $pdo->prepare("SELECT DISTINCT event_date FROM attendance_events WHERE user_id = ? AND event_date LIKE ?");
    $stmt1->execute([$student_id, $month . '%']);
    while ($row = $stmt1->fetchColumn()) {
        $dates[$row] = true;
    }

    $stmt2 = $pdo->prepare("SELECT DISTINCT date FROM attendance WHERE user_id = ? AND date LIKE ?");
    $stmt2->execute([$student_id, $month . '%']);
    while ($row = $stmt2->fetchColumn()) {
        $dates[$row] = true;
    }

    $date_keys = array_keys($dates);
    rsort($date_keys);

    $days = [];
    $present_count = 0;
    $partial_count = 0;

    foreach ($date_keys as $dt) {
        $tl = compute_student_day_timeline($pdo, $student_id, $dt);
        
        $day_status = 'present';
        if ($tl['incomplete_pairs'] || $tl['total_inside_seconds'] < 1800) {
            $day_status = 'partial';
            $partial_count++;
        } else {
            $present_count++;
        }

        $days[] = [
            'date' => $dt,
            'day_name' => date('D', strtotime($dt)),
            'day_number' => (int)date('d', strtotime($dt)),
            'status' => $day_status,
            'status_label' => ucfirst($day_status),
            'first_arrival' => $tl['first_arrival_formatted'],
            'final_exit' => $tl['final_exit_formatted'],
            'total_inside_formatted' => $tl['total_inside_formatted'],
            'events_count' => $tl['events_count'],
            'current_status' => $tl['current_status'],
            'incomplete_pairs' => $tl['incomplete_pairs'],
            'timeline' => $tl
        ];
    }

    return [
        'month' => $month,
        'month_label' => date('F Y', strtotime($month . '-01')),
        'days_in_month' => (int)date('t', strtotime($month . '-01')),
        'total_active_days' => count($days),
        'present_days' => $present_count,
        'partial_days' => $partial_count,
        'days' => $days
    ];
}
?>
