<?php
// student_dashboard.php - Private Student Portal

$page_title = "Student Dashboard";
require_once __DIR__ . '/includes/header.php';
require_login();

$user = current_user();

// Fetch student allocation details
$stmt_alloc = $pdo->prepare("
    SELECT a.*, s.seat_number, s.row_label, sh.name as shift_name, sh.start_time, sh.end_time, sh.fee_amount
    FROM allocations a
    JOIN seats s ON a.seat_id = s.id
    JOIN shifts sh ON a.shift_id = sh.id
    WHERE a.user_id = ?
    ORDER BY a.id DESC LIMIT 1
");
$stmt_alloc->execute([$user['id']]);
$allocation = $stmt_alloc->fetch();

// Calculate Monthly Fee Status
$fee_info = null;
if ($allocation) {
    $fee_info = get_student_fee_status($pdo, $allocation['id'], $allocation['start_date']);
}

// Fetch Fee History (Last 12 Months)
$payments = [];
if ($user) {
    $stmt_pay = $pdo->prepare("
        SELECT fp.* 
        FROM fee_payments fp 
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
    $stmt_pay->execute([$user['id']]);
    $payments = $stmt_pay->fetchAll();
}

// Auto-purge notifications & chat messages older than 48 hours, complaints older than 30 days
try {
    $sub_30_days = db_now_sub_days(30);
    $sub_48_hours = db_now_sub_hours(48);
    $pdo->exec("DELETE FROM complaints WHERE created_at IS NOT NULL AND created_at != '' AND created_at < $sub_30_days");
    $pdo->exec("DELETE FROM notifications WHERE created_at IS NOT NULL AND created_at != '' AND created_at < $sub_48_hours");
    $pdo->exec("DELETE FROM chat_messages WHERE created_at IS NOT NULL AND created_at != '' AND created_at < $sub_48_hours");
} catch (Exception $e) {}

// Fetch Today's Attendance
$today = date('Y-m-d');
$stmt_att = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ?");
$stmt_att->execute([$user['id'], $today]);
$today_att = $stmt_att->fetch();

// Fetch Complaints
$stmt_comp = $pdo->prepare("SELECT * FROM complaints WHERE user_id = ? ORDER BY id DESC");
$stmt_comp->execute([$user['id']]);
$complaints = $stmt_comp->fetchAll();

// Fetch Notifications (Broadcast user_id=0 OR Personal user_id=$user['id'])
$stmt_notifs = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = 0 OR user_id = ? 
    ORDER BY id DESC
");
$stmt_notifs->execute([$user['id']]);
$student_notifs = $stmt_notifs->fetchAll();
$notif_count = count($student_notifs);

$active_tab = $_GET['tab'] ?? 'tabSeatInfo';
?>

<!-- Welcome Banner -->
<div class="card" style="border-left: 4px solid var(--accent-primary);">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
        <div>
            <h2>Welcome, <?php echo htmlspecialchars($user['name']); ?> 👋</h2>
            <p style="color: var(--text-muted); font-size: 0.9rem;">
                Student ID: #STU-<?php echo sprintf("%04d", $user['id']); ?> &bull; Phone: <?php echo htmlspecialchars($user['phone']); ?>
            </p>
        </div>
        <div>
            <?php
            $status_class = 'badge-warning';
            if ($user['status'] === 'approved') $status_class = 'badge-success';
            if ($user['status'] === 'hold') $status_class = 'badge-warning';
            if ($user['status'] === 'cancelled') $status_class = 'badge-danger';
            ?>
            <span class="badge <?php echo $status_class; ?>" style="font-size: 0.88rem; padding: 8px 16px;">
                <i class="fas fa-info-circle"></i> Membership Status: <?php echo strtoupper($user['status']); ?>
            </span>
        </div>
    </div>
</div>

<!-- Overview Cards Grid -->
<div class="grid-4">
    <!-- Allotted Seat Card -->
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(79, 70, 229, 0.1); color: var(--accent-primary);">
            <i class="fas fa-chair"></i>
        </div>
        <div>
            <div class="stat-value">
                <?php echo $allocation ? htmlspecialchars($allocation['seat_number']) : 'Unassigned'; ?>
            </div>
            <div class="stat-label">Your Allotted Seat Desk</div>
        </div>
    </div>

    <!-- Assigned Shift Card -->
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(2, 132, 199, 0.1); color: #0284c7;">
            <i class="fas fa-clock"></i>
        </div>
        <div>
            <div class="stat-value" style="font-size: 1.1rem; font-weight: 600;">
                <?php echo $allocation ? htmlspecialchars($allocation['shift_name']) : 'N/A'; ?>
            </div>
            <div class="stat-label">
                <?php if ($allocation): ?>
                    <?php echo date('g:i A', strtotime($allocation['start_time'])); ?> - <?php echo date('g:i A', strtotime($allocation['end_time'])); ?>
                <?php else: ?>
                    Awaiting Admin Approval
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Fee Due Date Card -->
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: #b45309;">
            <i class="fas fa-calendar-alt"></i>
        </div>
        <div>
            <div class="stat-value" style="font-size: 1.15rem; font-weight: 600;">
                <?php echo $fee_info ? date('d M Y', strtotime($fee_info['due_date'])) : 'N/A'; ?>
            </div>
            <div class="stat-label">Next Monthly Fee Renewal</div>
        </div>
    </div>

    <!-- Monthly Fee Status Card -->
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #047857;">
            <i class="fas fa-receipt"></i>
        </div>
        <div>
            <?php if ($fee_info): ?>
                <span class="badge <?php echo $fee_info['badge_class']; ?>" style="font-size: 0.88rem;">
                    <?php echo $fee_info['label']; ?>
                </span>
                <div class="stat-label" style="margin-top: 4px;">Cycle: <?php echo date('M Y'); ?></div>
            <?php else: ?>
                <div class="stat-value" style="font-size: 0.95rem;">Pending Allotment</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Main Student Tabs -->
<div class="card" style="margin-top: 24px;">
    <div class="tab-navigation">
        <button class="tab-btn <?php echo $active_tab === 'tabSeatInfo' ? 'active' : ''; ?>" data-tab="tabSeatInfo"><i class="fas fa-id-badge"></i> My Seat & Membership Details</button>
        <button class="tab-btn <?php echo $active_tab === 'tabNotifs' ? 'active' : ''; ?>" data-tab="tabNotifs"><i class="fas fa-bell"></i> Notices & Alerts (<?php echo $notif_count; ?>)</button>
        <button class="tab-btn <?php echo $active_tab === 'tabFees' ? 'active' : ''; ?>" data-tab="tabFees"><i class="fas fa-file-invoice-dollar"></i> Monthly Fee Ledger</button>
        <button class="tab-btn <?php echo $active_tab === 'tabAttendance' ? 'active' : ''; ?>" data-tab="tabAttendance"><i class="fas fa-user-check"></i> Daily Attendance</button>
        <button class="tab-btn <?php echo $active_tab === 'tabSupport' ? 'active' : ''; ?>" data-tab="tabSupport"><i class="fas fa-headset"></i> Facility Support Desk</button>
        <button class="tab-btn <?php echo $active_tab === 'tabChat' ? 'active' : ''; ?>" data-tab="tabChat"><i class="fas fa-comments" style="color: #06b6d4;"></i> Direct Admin Chat</button>
    </div>

    <!-- TAB 1: PERSONAL SEAT & MEMBERSHIP INFO -->
    <div id="tabSeatInfo" class="tab-pane <?php echo $active_tab === 'tabSeatInfo' ? 'active' : ''; ?>">
        <?php if ($user['status'] === 'pending'): ?>
            <div class="alert alert-info">
                <i class="fas fa-hourglass-half"></i> <strong>Request Submitted:</strong> Your membership request has been sent to Admin. Admin will allot your specific seat desk and shift timing shortly.
            </div>
        <?php elseif ($user['status'] === 'hold'): ?>
            <div class="alert alert-warning">
                <i class="fas fa-pause-circle"></i> <strong>On Hold:</strong> Your seat allocation is currently placed on Hold / Waiting List by Admin. Contact library reception for assistance.
            </div>
        <?php elseif ($user['status'] === 'cancelled'): ?>
            <div class="alert alert-danger">
                <i class="fas fa-times-circle"></i> <strong>Cancelled:</strong> Your library seat membership has been cancelled.
            </div>
        <?php endif; ?>

        <?php if ($allocation && $user['status'] === 'approved'): ?>
            <div class="my-seat-hero">
                <div class="my-seat-badge">
                    <i class="fas fa-chair" style="font-size: 1.2rem; margin-bottom: 2px;"></i>
                    <span><?php echo htmlspecialchars($allocation['seat_number']); ?></span>
                </div>

                <h2 style="margin-bottom: 8px;">Desk <?php echo htmlspecialchars($allocation['seat_number']); ?> (Row <?php echo htmlspecialchars($allocation['row_label']); ?>)</h2>
                <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 20px;">
                    Assigned Shift: <strong><?php echo htmlspecialchars($allocation['shift_name']); ?></strong> (<?php echo date('g:i A', strtotime($allocation['start_time'])); ?> - <?php echo date('g:i A', strtotime($allocation['end_time'])); ?>)
                </p>

                <div class="grid-3" style="max-width: 700px; margin: 0 auto; text-align: left;">
                    <div style="background: var(--bg-surface); padding: 16px; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                        <label class="form-label" style="margin:0;">Joining Date</label>
                        <strong><?php echo format_date($allocation['start_date']); ?></strong>
                    </div>
                    <div style="background: var(--bg-surface); padding: 16px; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                        <label class="form-label" style="margin:0;">Monthly Fee Rate</label>
                        <strong><?php echo format_currency($allocation['fee_amount']); ?>/mo</strong>
                    </div>
                    <div style="background: var(--bg-surface); padding: 16px; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                        <label class="form-label" style="margin:0;">Personal Locker / Plug</label>
                        <strong>Power Socket Included</strong>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                <i class="fas fa-chair fa-3x" style="color: var(--border-color); margin-bottom: 12px;"></i>
                <p>No active seat desk allotted yet. Please wait for Admin approval.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- TAB 2: NOTICES & ANNOUNCEMENTS -->
    <div id="tabNotifs" class="tab-pane <?php echo $active_tab === 'tabNotifs' ? 'active' : ''; ?>">
        <h3><i class="fas fa-bullhorn" style="color: var(--accent-primary);"></i> Library Notices & Admin Announcements</h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;">
            Broadcast announcements and direct messages sent to you by Admin.
        </p>

        <?php if (empty($student_notifs)): ?>
            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                <i class="far fa-bell-slash fa-3x" style="color: var(--border-color); margin-bottom: 12px;"></i>
                <p>No notices or announcements at this time.</p>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 14px;">
                <?php foreach ($student_notifs as $n): ?>
                    <div style="background: var(--bg-surface-elevated); padding: 18px 20px; border-radius: var(--radius-md); border: 1px solid var(--border-color); border-left: 4px solid var(--accent-primary);">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 6px;">
                            <h4 style="font-size: 1.05rem;">
                                <?php echo htmlspecialchars($n['title']); ?>
                                <?php if ($n['user_id'] == 0): ?>
                                    <span class="badge badge-info" style="font-size:0.7rem; margin-left:8px;"><i class="fas fa-globe"></i> Broadcast</span>
                                <?php else: ?>
                                    <span class="badge badge-success" style="font-size:0.7rem; margin-left:8px;"><i class="fas fa-envelope"></i> Direct Message</span>
                                <?php endif; ?>
                            </h4>
                            <span style="font-size: 0.8rem; color: var(--text-dim);"><?php echo format_date($n['created_at']); ?></span>
                        </div>
                        <p style="font-size: 0.92rem; color: var(--text-main); line-height: 1.5; margin: 0;">
                            <?php echo nl2br(htmlspecialchars($n['message'])); ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- TAB 3: MONTHLY FEE LEDGER -->
    <div id="tabFees" class="tab-pane <?php echo $active_tab === 'tabFees' ? 'active' : ''; ?>">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom: 16px;">
            <div>
                <h3><i class="fas fa-file-invoice" style="color: var(--accent-primary);"></i> Monthly Fee Schedule & 12-Month History</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
                    Your monthly fee renewal date recurs on the <strong><?php echo $allocation ? date('jS', strtotime($allocation['start_date'])) : '1st'; ?> of every month</strong>.
                </p>
            </div>
            <a href="receipt_statement.php" target="_blank" class="btn btn-secondary btn-sm">
                <i class="fas fa-file-pdf" style="color:#ef4444;"></i> Download 12-Month PDF Statement
            </a>
        </div>

        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Cycle Month</th>
                        <th>Amount</th>
                        <th>Due Date</th>
                        <th>Payment Status</th>
                        <th>Payment Mode</th>
                        <th>Receipt No</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No fee records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td><strong><?php echo date('F Y', strtotime($p['month_year'] . '-01')); ?></strong></td>
                                <td><?php echo format_currency($p['amount']); ?></td>
                                <td><?php echo format_date($p['due_date']); ?></td>
                                <td>
                                    <?php if ($p['payment_status'] === 'paid'): ?>
                                        <span class="badge badge-success">Paid</span>
                                    <?php elseif ($p['payment_status'] === 'overdue'): ?>
                                        <span class="badge badge-danger">Overdue</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($p['payment_mode'] ?? 'N/A'); ?></td>
                                <td><code><?php echo htmlspecialchars($p['receipt_no'] ?? 'N/A'); ?></code></td>
                                <td>
                                    <?php if ($p['payment_status'] === 'paid' && $p['receipt_no']): ?>
                                        <a href="receipt.php?receipt_no=<?php echo urlencode($p['receipt_no']); ?>" target="_blank" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-print"></i> View Receipt
                                        </a>
                                    <?php else: ?>
                                        <span style="font-size: 0.8rem; color: var(--text-muted);">Pay at Admin Desk</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TAB 4: DAILY ATTENDANCE -->
    <div id="tabAttendance" class="tab-pane <?php echo $active_tab === 'tabAttendance' ? 'active' : ''; ?>">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
            <div>
                <h3><i class="fas fa-user-check" style="color: var(--accent-primary);"></i> Daily Entry & Exit Attendance Log</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted);">Records your exact device PC/mobile timestamp when checking in or out.</p>
            </div>
            
            <div style="display: flex; gap: 12px;">
                <form id="formCheckIn" action="api/student_actions.php" method="POST" style="display:inline;" onsubmit="return submitWithLocation(event, this);">
                    <input type="hidden" name="action" value="checkin">
                    <input type="hidden" name="device_time" id="checkinDeviceTime" value="">
                    <input type="hidden" name="latitude" id="checkinLat" value="">
                    <input type="hidden" name="longitude" id="checkinLng" value="">
                    <button type="submit" class="btn btn-success" <?php echo ($today_att && $today_att['check_in_time']) ? 'disabled title="Already checked in today"' : ''; ?>>
                        <i class="fas fa-sign-in-alt"></i> Check-In Now (<span id="pcClockCheckin"></span>)
                    </button>
                </form>

                <form id="formCheckOut" action="api/student_actions.php" method="POST" style="display:inline;" onsubmit="return submitWithLocation(event, this);">
                    <input type="hidden" name="action" value="checkout">
                    <input type="hidden" name="device_time" id="checkoutDeviceTime" value="">
                    <input type="hidden" name="latitude" id="checkoutLat" value="">
                    <input type="hidden" name="longitude" id="checkoutLng" value="">
                    <button type="submit" class="btn btn-danger" <?php echo (!$today_att || $today_att['check_out_time']) ? 'disabled title="Check-in required first"' : ''; ?>>
                        <i class="fas fa-sign-out-alt"></i> Check-Out Now
                    </button>
                </form>
            </div>
        </div>

        <?php if ($today_att): ?>
            <div class="card" style="background: var(--bg-surface-elevated); padding: 18px; margin-bottom: 20px; border: 1px solid var(--accent-primary);">
                <h4 style="color: var(--accent-primary);"><i class="fas fa-clock"></i> Today's Device Recorded Log (<?php echo date('d M Y'); ?>)</h4>
                <p style="margin-top: 6px; font-size: 0.92rem;">
                    <strong>Check-In Time:</strong> <span class="badge badge-success"><?php echo date('g:i:s A', strtotime($today_att['check_in_time'])); ?></span> &bull; 
                    <strong>Check-Out Time:</strong> <?php echo $today_att['check_out_time'] ? '<span class="badge badge-info">' . date('g:i:s A', strtotime($today_att['check_out_time'])) . '</span>' : '<span class="badge badge-warning">Currently Studying in Hall</span>'; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <!-- TAB 5: FACILITY COMPLAINT DESK -->
    <div id="tabSupport" class="tab-pane <?php echo $active_tab === 'tabSupport' ? 'active' : ''; ?>">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
            <div>
                <h3><i class="fas fa-headset" style="color: var(--accent-primary);"></i> Facility Support Desk</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted);">Report any issue regarding Wi-Fi, AC cooling, desk light, or cleanliness.</p>
            </div>
            <button class="btn btn-primary" onclick="openModal('modalNewComplaint')">
                <i class="fas fa-plus-circle"></i> Submit New Issue Ticket
            </button>
        </div>

        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Ticket ID</th>
                        <th>Category</th>
                        <th>Subject</th>
                        <th>Description</th>
                        <th>Submitted Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($complaints)): ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No complaint tickets logged.</td></tr>
                    <?php else: ?>
                        <?php foreach ($complaints as $c): ?>
                            <tr>
                                <td>#TKT-<?php echo sprintf("%04d", $c['id']); ?></td>
                                <td><span class="badge badge-info"><?php echo htmlspecialchars($c['category']); ?></span></td>
                                <td><strong><?php echo htmlspecialchars($c['subject']); ?></strong></td>
                                <td style="max-width: 280px; font-size: 0.85rem;"><?php echo htmlspecialchars($c['description']); ?></td>
                                <td><?php echo format_date($c['created_at']); ?></td>
                                <td>
                                    <?php if ($c['status'] === 'resolved'): ?>
                                        <span class="badge badge-success">Resolved</span>
                                    <?php elseif ($c['status'] === 'in_progress'): ?>
                                        <span class="badge badge-warning">In Progress</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Open</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TAB 6: DIRECT ADMIN CHAT -->
    <div id="tabChat" class="tab-pane <?php echo $active_tab === 'tabChat' ? 'active' : ''; ?>">
        <div style="margin-bottom: 16px;">
            <h3><i class="fas fa-comments" style="color: #06b6d4;"></i> Direct Chat with Library Admin</h3>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
                Send direct instant messages to library administration for quick support, questions, or assistance.
            </p>
        </div>

        <div class="card" style="padding: 0; overflow: hidden; border: 1px solid var(--border-color); background: var(--bg-surface-elevated);">
            <!-- Chat Header -->
            <div style="padding: 14px 20px; background: rgba(6, 182, 212, 0.08); border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 38px; height: 38px; border-radius: 50%; background: #06b6d4; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; font-weight: bold;">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div>
                        <strong style="font-size: 0.98rem; display: block;"><?php echo htmlspecialchars(LIBRARY_NAME); ?> Administration Desk</strong>
                        <span style="font-size: 0.78rem; color: var(--text-muted);">Direct Live Messenger</span>
                    </div>
                </div>
                <span class="badge badge-success" style="font-size: 0.78rem; padding: 6px 12px;">
                    <i class="fas fa-circle" style="font-size: 0.5rem; margin-right: 4px;"></i> Online
                </span>
            </div>

            <!-- Chat History Scroll Box -->
            <div id="studentChatMessagesBox" style="height: 380px; padding: 18px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; background: var(--bg-surface);">
                <div style="text-align: center; color: var(--text-muted); margin-top: 40px;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p style="margin-top: 8px;">Loading chat messages...</p>
                </div>
            </div>

            <!-- Input Bar -->
            <div style="padding: 14px 18px; background: var(--bg-surface-elevated); border-top: 1px solid var(--border-color); display: flex; gap: 10px; align-items: center;">
                <input type="text" id="studentChatInput" class="form-control" placeholder="Type your message to Admin..." onkeypress="if(event.key === 'Enter') sendStudentChatMessage();" style="border-radius: 20px; padding: 10px 18px; flex: 1;">
                <button type="button" id="btnStudentSendChat" onclick="sendStudentChatMessage();" class="btn btn-primary" style="border-radius: 20px; padding: 10px 22px;">
                    <i class="fas fa-paper-plane"></i> Send
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Submit New Complaint Ticket -->
<div id="modalNewComplaint" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-headset"></i> Submit Facility Issue Ticket</h3>
            <button class="modal-close">&times;</button>
        </div>
        <form action="api/student_actions.php" method="POST">
            <input type="hidden" name="action" value="submit_complaint">
            
            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category" class="form-control" required>
                    <option value="AC/Cooling">AC / Cooling Issue</option>
                    <option value="WiFi">Wi-Fi / Internet Slow</option>
                    <option value="Desk/Chair">Desk Light / Chair Damage</option>
                    <option value="Cleanliness">Washroom / Cleanliness</option>
                    <option value="Noise">Noise Disturbance</option>
                    <option value="Other">Other Query</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Issue Subject</label>
                <input type="text" name="subject" class="form-control" placeholder="Brief subject" required>
            </div>

            <div class="form-group">
                <label class="form-label">Detailed Description</label>
                <textarea name="description" class="form-control" rows="4" placeholder="Explain the problem..." required></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px;">
                <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit Ticket</button>
            </div>
        </form>
    </div>
</div>

<script>
function updatePCClock() {
    const elem = document.getElementById('pcClockCheckin');
    if (elem) {
        elem.textContent = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }
}
setInterval(updatePCClock, 1000);
updatePCClock();

function submitWithLocation(e, form) {
    e.preventDefault();
    form.device_time.value = new Date().toLocaleTimeString('en-GB');
    
    if (!navigator.geolocation) {
        alert("Geolocation is not supported by your browser. Geofence check-in requires location services.");
        return false;
    }
    
    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Getting GPS Location...';
    
    navigator.geolocation.getCurrentPosition(
        function(pos) {
            form.latitude.value = pos.coords.latitude;
            form.longitude.value = pos.coords.longitude;
            form.submit();
        },
        function(err) {
            btn.disabled = false;
            btn.innerHTML = originalText;
            alert("Location Permission Denied or Failed! Check-in/out requires GPS location permission: " + err.message);
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
    return false;
}

/* --- STUDENT DIRECT ADMIN CHAT SYSTEM --- */
const currentStudentUserId = <?php echo (int)$user['id']; ?>;
let studentChatInterval = null;

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;")
               .replace(/</g, "&lt;")
               .replace(/>/g, "&gt;")
               .replace(/"/g, "&quot;")
               .replace(/'/g, "&#039;");
}

function loadStudentChatMessages() {
    fetch(`api/json_student_actions.php?action=get_chat_messages&user_id=${currentStudentUserId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const box = document.getElementById('studentChatMessagesBox');
                if (!box) return;
                const msgs = data.messages || [];
                if (msgs.length === 0) {
                    box.innerHTML = `<div style="text-align: center; color: var(--text-muted); margin-top: 40px;">
                        <i class="far fa-comments fa-3x" style="color: var(--border-color); margin-bottom: 8px; display: block;"></i>
                        <p>No chat history yet. Send a message below to talk directly with Admin!</p>
                    </div>`;
                    return;
                }

                let html = '';
                msgs.forEach(m => {
                    const isMe = parseInt(m.sender_id) === currentStudentUserId;
                    const align = isMe ? 'flex-end' : 'flex-start';
                    const bg = isMe ? 'var(--accent-primary)' : 'var(--bg-surface-elevated)';
                    const color = isMe ? '#ffffff' : 'var(--text-main)';
                    const border = isMe ? 'none' : '1px solid var(--border-color)';
                    const senderTag = isMe ? 'You' : 'Admin Desk 🛡️';

                    html += `
                        <div style="display: flex; justify-content: ${align}; width: 100%;">
                            <div style="max-width: 75%; padding: 10px 14px; border-radius: 14px; background: ${bg}; color: ${color}; border: ${border}; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                                <div style="font-weight: 600; font-size: 0.75rem; margin-bottom: 4px; opacity: 0.85;">${senderTag}</div>
                                <div style="font-size: 0.92rem; line-height: 1.4; word-break: break-word;">${escapeHtml(m.message)}</div>
                                <div style="font-size: 0.68rem; opacity: 0.7; text-align: right; margin-top: 4px;">${m.created_at || ''}</div>
                            </div>
                        </div>
                    `;
                });

                const wasAtBottom = box.scrollHeight - box.clientHeight <= box.scrollTop + 80;
                box.innerHTML = html;
                if (wasAtBottom || !box.dataset.loadedOnce) {
                    box.scrollTop = box.scrollHeight;
                    box.dataset.loadedOnce = 'true';
                }
            }
        })
        .catch(err => console.error("Error loading student chat:", err));
}

function sendStudentChatMessage() {
    const input = document.getElementById('studentChatInput');
    const msg = input.value.trim();
    if (!msg) return;

    const btn = document.getElementById('btnStudentSendChat');
    btn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'send_chat_message');
    formData.append('user_id', currentStudentUserId);
    formData.append('message', msg);

    fetch('api/json_student_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        if (data.success) {
            input.value = '';
            loadStudentChatMessages();
        } else {
            alert(data.message || 'Failed to send message.');
        }
    })
    .catch(err => {
        btn.disabled = false;
        alert('Network connection error.');
    });
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            if (this.getAttribute('data-tab') === 'tabChat') {
                loadStudentChatMessages();
                if (!studentChatInterval) {
                    studentChatInterval = setInterval(loadStudentChatMessages, 3000);
                }
            } else {
                if (studentChatInterval) {
                    clearInterval(studentChatInterval);
                    studentChatInterval = null;
                }
            }
        });
    });

    <?php if ($active_tab === 'tabChat'): ?>
    loadStudentChatMessages();
    studentChatInterval = setInterval(loadStudentChatMessages, 3000);
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
