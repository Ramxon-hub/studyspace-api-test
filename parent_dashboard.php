<?php
// parent_dashboard.php - Web Parent Portal

$page_title = "Parent Portal - Study Library";
require_once __DIR__ . '/includes/header.php';
require_parent();

$parent_id = $_SESSION['user_id'];
$linked_students = get_parent_linked_students($pdo, $parent_id);

$selected_student_id = (int)($_GET['student_id'] ?? 0);
if ($selected_student_id <= 0 && !empty($linked_students)) {
    $selected_student_id = (int)$linked_students[0]['id'];
}

// Verify IDOR access
$access_valid = false;
$current_student = null;
if ($selected_student_id > 0 && verify_parent_student_access($pdo, $parent_id, $selected_student_id)) {
    $access_valid = true;
    foreach ($linked_students as $st) {
        if ($st['id'] == $selected_student_id) {
            $current_student = $st;
            break;
        }
    }
}
?>

<div style="max-width: 1100px; margin: 0 auto; padding: 20px 10px;">
    
    <!-- Welcome Header Banner -->
    <div class="card" style="border-left: 4px solid #8b5cf6; margin-bottom: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <h2><i class="fas fa-user-shield" style="color: #8b5cf6;"></i> Parent Portal</h2>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 4px;">
                    Welcome, <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong>! Real-time attendance, fee status, and library updates for your linked children.
                </p>
            </div>
            
            <?php if (count($linked_students) > 1): ?>
                <!-- Student Switcher Dropdown for Multi-Child Parents -->
                <div style="display: flex; align-items: center; gap: 8px; background: var(--bg-surface-elevated, #f1f5f9); padding: 8px 12px; border-radius: 8px;">
                    <label style="font-weight: 600; font-size: 0.85rem; color: var(--text-main);"><i class="fas fa-child"></i> Select Child:</label>
                    <select class="form-control" style="width: auto; padding: 6px 12px; font-weight: 600;" onchange="location.href='parent_dashboard.php?student_id=' + this.value;">
                        <?php foreach ($linked_students as $ls): ?>
                            <option value="<?php echo $ls['id']; ?>" <?php echo $ls['id'] == $selected_student_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ls['name']); ?> <?php echo !empty($ls['seat_number']) ? '(Seat ' . $ls['seat_number'] . ')' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($linked_students)): ?>
        <div class="card" style="text-align: center; padding: 50px 20px;">
            <i class="fas fa-user-graduate" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 15px;"></i>
            <h3>No Student Accounts Linked</h3>
            <p style="color: var(--text-muted); margin-top: 8px;">
                Your parent account is active, but no student records are currently linked to your profile.<br>
                Please contact the Library Administrator to link your child's student account.
            </p>
        </div>
    <?php elseif (!$access_valid || !$current_student): ?>
        <div class="card" style="text-align: center; padding: 40px; border-left: 4px solid #ef4444;">
            <i class="fas fa-exclamation-triangle" style="font-size: 2.5rem; color: #ef4444; margin-bottom: 10px;"></i>
            <h3>Access Denied</h3>
            <p style="color: var(--text-muted);">The selected student record does not belong to your parent account.</p>
            <a href="parent_dashboard.php" class="btn btn-primary" style="margin-top: 15px;">Return to Portal</a>
        </div>
    <?php else: ?>

        <?php
        // Fetch Student Metrics
        $student_id = (int)$current_student['id'];

        // Seat & Shift Allocation
        $stmt_alloc = $pdo->prepare("
            SELECT a.*, s.seat_number, s.row_label, s.remarks as seat_remarks, sh.name as shift_name, sh.start_time, sh.end_time, sh.fee_amount
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
        $stmt_m_att = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date LIKE ? ORDER BY date DESC");
        $stmt_m_att->execute([$student_id, $cur_month . '%']);
        $monthly_atts = $stmt_m_att->fetchAll(PDO::FETCH_ASSOC);

        $present_cnt = 0;
        foreach ($monthly_atts as $ma) {
            if ($ma['status'] === 'present') $present_cnt++;
        }

        // Fee Status
        $fee_status = get_student_fee_status($pdo, $student_id);

        // 12-Month Payment Matrix
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
        $stmt_n = $pdo->prepare("SELECT * FROM notifications WHERE user_id = 0 OR user_id = ? ORDER BY id DESC LIMIT 10");
        $stmt_n->execute([$student_id]);
        $notifs = $stmt_n->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <!-- Student Overview Hero Card -->
        <div class="card" style="margin-bottom: 20px; background: linear-gradient(135deg, var(--bg-surface-elevated, #f8fafc) 0%, var(--bg-card, #ffffff) 100%);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div style="width: 54px; height: 54px; border-radius: 50%; background: rgba(139, 92, 246, 0.15); color: #8b5cf6; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: bold;">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div>
                        <h3 style="margin: 0;"><?php echo htmlspecialchars($current_student['name']); ?></h3>
                        <div style="color: var(--text-muted); font-size: 0.88rem; margin-top: 3px;">
                            <i class="fas fa-phone"></i> <?php echo htmlspecialchars($current_student['phone'] ?: 'N/A'); ?> &bull; 
                            <i class="fas fa-book-reader"></i> Prep: <?php echo htmlspecialchars($current_student['preparation_for'] ?: 'General Study'); ?>
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <?php if ($alloc): ?>
                        <div class="badge badge-info" style="padding: 8px 14px; font-size: 0.9rem;">
                            <i class="fas fa-chair"></i> Desk: <strong><?php echo htmlspecialchars($alloc['seat_number']); ?></strong> (Row <?php echo htmlspecialchars($alloc['row_label']); ?>)
                        </div>
                        <div class="badge badge-primary" style="padding: 8px 14px; font-size: 0.9rem;">
                            <i class="fas fa-clock"></i> Shift: <strong><?php echo htmlspecialchars($alloc['shift_name']); ?></strong> (<?php echo htmlspecialchars($alloc['start_time'] . ' - ' . $alloc['end_time']); ?>)
                        </div>
                    <?php else: ?>
                        <div class="badge badge-secondary" style="padding: 8px 14px; font-size: 0.9rem;">
                            <i class="fas fa-chair"></i> Seat: Pending Allotment
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 3-Column Metrics Grid -->
        <div class="grid-3" style="margin-bottom: 24px;">
            
            <!-- Card 1: Today's Live Attendance -->
            <div class="stat-card" style="flex-direction: column; align-items: flex-start;">
                <div style="display: flex; justify-content: space-between; width: 100%; align-items: center; margin-bottom: 10px;">
                    <span style="font-weight: 600; font-size: 0.9rem; color: var(--text-muted);"><i class="fas fa-calendar-day"></i> Today's Attendance</span>
                    <?php if ($today_att && $today_att['status'] === 'present'): ?>
                        <span class="badge badge-success"><i class="fas fa-check-circle"></i> Present Inside</span>
                    <?php else: ?>
                        <span class="badge badge-warning"><i class="fas fa-clock"></i> Not Checked In</span>
                    <?php endif; ?>
                </div>

                <div style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin-bottom: 6px;">
                    <?php if ($today_att && !empty($today_att['check_in_time'])): ?>
                        In: <?php echo date('h:i A', strtotime($today_att['check_in_time'])); ?>
                        <?php if (!empty($today_att['check_out_time'])): ?>
                            &bull; Out: <?php echo date('h:i A', strtotime($today_att['check_out_time'])); ?>
                        <?php endif; ?>
                    <?php else: ?>
                        No check-in recorded today
                    <?php endif; ?>
                </div>

                <div style="font-size: 0.82rem; color: var(--text-muted);">
                    Monthly Attendance: <strong><?php echo $present_cnt; ?> Days Present</strong> in <?php echo date('F Y'); ?>
                </div>
            </div>

            <!-- Card 2: Fee Payment Status -->
            <div class="stat-card" style="flex-direction: column; align-items: flex-start;">
                <div style="display: flex; justify-content: space-between; width: 100%; align-items: center; margin-bottom: 10px;">
                    <span style="font-weight: 600; font-size: 0.9rem; color: var(--text-muted);"><i class="fas fa-rupee-sign"></i> Fee Payment Status</span>
                    <span class="badge <?php echo $fee_status['badge_class']; ?>"><?php echo htmlspecialchars($fee_status['label']); ?></span>
                </div>

                <div style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin-bottom: 6px;">
                    Target Month: <?php echo htmlspecialchars($fee_status['target_month_label']); ?>
                </div>

                <div style="font-size: 0.82rem; color: var(--text-muted);">
                    Due Date: <strong><?php echo format_date($fee_status['due_date']); ?></strong>
                    <?php if ($alloc): ?>
                        &bull; Rate: <?php echo format_currency($alloc['fee_amount']); ?>/mo
                    <?php endif; ?>
                </div>
            </div>

            <!-- Card 3: Shift & Desk Info -->
            <div class="stat-card" style="flex-direction: column; align-items: flex-start;">
                <div style="display: flex; justify-content: space-between; width: 100%; align-items: center; margin-bottom: 10px;">
                    <span style="font-weight: 600; font-size: 0.9rem; color: var(--text-muted);"><i class="fas fa-desktop"></i> Assigned Library Desk</span>
                    <span class="badge badge-info">Active Allotment</span>
                </div>

                <div style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin-bottom: 6px;">
                    <?php echo $alloc ? 'Desk ' . htmlspecialchars($alloc['seat_number']) . ' (Row ' . htmlspecialchars($alloc['row_label']) . ')' : 'Unassigned'; ?>
                </div>

                <div style="font-size: 0.82rem; color: var(--text-muted);">
                    Shift Timing: <strong><?php echo $alloc ? htmlspecialchars($alloc['start_time'] . ' - ' . $alloc['end_time']) : 'N/A'; ?></strong>
                </div>
            </div>

        </div>

        <!-- 12-Month Fee Billing Matrix -->
        <div class="card" style="margin-bottom: 24px;">
            <h3><i class="fas fa-calendar-alt" style="color: var(--accent-primary);"></i> 12-Month Fee Payment Matrix</h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 16px;">
                Complete billing status overview showing past payments and upcoming fee cycle targets.
            </p>

            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px;">
                <?php foreach ($matrix as $m): ?>
                    <div style="padding: 12px; border-radius: 8px; border: 1px solid var(--border-color, #e2e8f0); background: <?php echo $m['is_paid'] ? 'rgba(34, 197, 94, 0.08)' : ($m['month_year'] == date('Y-m') ? 'rgba(245, 158, 11, 0.08)' : 'var(--bg-surface-elevated, #f8fafc)'); ?>;">
                        <div style="font-weight: 700; font-size: 0.9rem; color: var(--text-main); margin-bottom: 4px;">
                            <?php echo htmlspecialchars($m['month_label']); ?>
                        </div>
                        <?php if ($m['is_paid']): ?>
                            <span class="badge badge-success" style="font-size: 0.75rem;"><i class="fas fa-check"></i> Paid</span>
                            <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 4px;">
                                Paid: <?php echo format_date($m['paid_date']); ?><br>
                                Amount: <?php echo format_currency($m['amount']); ?>
                            </div>
                        <?php elseif ($m['month_year'] == date('Y-m')): ?>
                            <span class="badge badge-warning" style="font-size: 0.75rem;"><i class="fas fa-clock"></i> Current</span>
                            <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 4px;">
                                Due this month
                            </div>
                        <?php elseif ($m['month_year'] < date('Y-m')): ?>
                            <span class="badge badge-danger" style="font-size: 0.75rem;"><i class="fas fa-times"></i> Unpaid</span>
                            <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 4px;">
                                Overdue
                            </div>
                        <?php else: ?>
                            <span class="badge badge-secondary" style="font-size: 0.75rem;"><i class="fas fa-calendar"></i> Upcoming</span>
                            <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 4px;">
                                Future cycle
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Two-Column Layout: Attendance Log & Notifications -->
        <div class="grid-2">
            
            <!-- Left: Current Month Attendance Log -->
            <div class="card">
                <h3><i class="fas fa-history" style="color: #059669;"></i> <?php echo date('F Y'); ?> Attendance Log</h3>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 12px;">
                    Daily check-in and check-out records for current month.
                </p>

                <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                    <table class="custom-table" style="font-size: 0.85rem;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Check-In</th>
                                <th>Check-Out</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($monthly_atts)): ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--text-muted); padding: 20px;">No attendance records found for this month.</td></tr>
                            <?php else: ?>
                                <?php foreach ($monthly_atts as $mat): ?>
                                    <tr>
                                        <td><strong><?php echo format_date($mat['date']); ?></strong></td>
                                        <td><?php echo !empty($mat['check_in_time']) ? date('h:i A', strtotime($mat['check_in_time'])) : '--'; ?></td>
                                        <td><?php echo !empty($mat['check_out_time']) ? date('h:i A', strtotime($mat['check_out_time'])) : '--'; ?></td>
                                        <td>
                                            <?php if ($mat['status'] === 'present'): ?>
                                                <span class="badge badge-success" style="font-size: 0.75rem;">Present</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger" style="font-size: 0.75rem;">Absent</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right: Student Notifications & Notices -->
            <div class="card">
                <h3><i class="fas fa-bell" style="color: #0284c7;"></i> Notices & Announcements</h3>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 12px;">
                    Official library announcements and personal notices.
                </p>

                <div style="max-height: 300px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px;">
                    <?php if (empty($notifs)): ?>
                        <div style="text-align: center; color: var(--text-muted); padding: 30px;">
                            No notifications received yet.
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifs as $nf): ?>
                            <div style="padding: 10px 12px; border-radius: 6px; background: var(--bg-surface-elevated, #f8fafc); border-left: 3px solid var(--accent-primary);">
                                <div style="font-weight: 600; font-size: 0.88rem; color: var(--text-main);">
                                    <?php echo htmlspecialchars($nf['title']); ?>
                                </div>
                                <div style="font-size: 0.82rem; color: var(--text-muted); margin-top: 2px;">
                                    <?php echo htmlspecialchars($nf['message']); ?>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px; text-align: right;">
                                    <?php echo format_date($nf['created_at']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
