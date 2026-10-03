<?php
// login.php - Login and Student Registration page

$page_title = "Login / Register - Study Library";
require_once __DIR__ . '/includes/header.php';

if (is_logged_in()) {
    if (is_admin()) {
        header("Location: admin_dashboard.php");
    } elseif (is_parent()) {
        header("Location: parent_dashboard.php");
    } else {
        header("Location: student_dashboard.php");
    }
    exit();
}

$active_tab = isset($_GET['tab']) && $_GET['tab'] === 'register' ? 'register' : 'login';
$selected_shift = (int)($_GET['shift'] ?? 1);

// Fetch shifts
$shifts = $pdo->query("SELECT * FROM shifts WHERE is_active = 1")->fetchAll();
?>

<div style="max-width: 540px; margin: 30px auto;">
    <div class="card">
        <!-- Tab Header -->
        <div class="tab-navigation" style="justify-content: center;">
            <button class="tab-btn <?php echo $active_tab === 'login' ? 'active' : ''; ?>" data-tab="loginTab">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
            <button class="tab-btn <?php echo $active_tab === 'register' ? 'active' : ''; ?>" data-tab="registerTab">
                <i class="fas fa-user-plus"></i> Student Registration Request
            </button>
        </div>

        <!-- LOGIN TAB -->
        <div id="loginTab" class="tab-pane <?php echo $active_tab === 'login' ? 'active' : ''; ?>">
            <h3 style="text-align: center; margin-bottom: 20px;">Welcome Back to <?php echo htmlspecialchars(LIBRARY_SHORT_NAME); ?></h3>
            
            <form action="api/auth.php" method="POST">
                <input type="hidden" name="action" value="login">
                
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-envelope"></i> Email Address</label>
                    <input type="email" name="email" class="form-control" placeholder="Enter your registered email" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fas fa-lock"></i> Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; margin-top: 10px;">
                    <i class="fas fa-sign-in-alt"></i> Account Login
                </button>
            </form>
        </div>

        <!-- REGISTER TAB -->
        <div id="registerTab" class="tab-pane <?php echo $active_tab === 'register' ? 'active' : ''; ?>">
            <h3 style="text-align: center; margin-bottom: 10px;">Join <?php echo htmlspecialchars(LIBRARY_SHORT_NAME); ?></h3>
            <p style="text-align: center; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;">
                Submit your registration request. Admin will review and allot your study desk seat.
            </p>

            <form action="api/auth.php" method="POST">
                <input type="hidden" name="action" value="register">

                <div class="form-group">
                    <label class="form-label"><i class="fas fa-user"></i> Full Name *</label>
                    <input type="text" name="name" class="form-control" placeholder="Enter student full name" required>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-envelope"></i> Email Address *</label>
                        <input type="email" name="email" class="form-control" placeholder="student@gmail.com" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-phone"></i> Mobile Phone *</label>
                        <input type="tel" name="phone" class="form-control" placeholder="10-digit mobile no." required>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-user-shield"></i> Emergency Contact</label>
                        <input type="tel" name="emergency_contact" class="form-control" placeholder="Parent/Guardian Phone">
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-id-card"></i> ID Proof Type</label>
                        <select name="id_proof_type" class="form-control">
                            <option value="Aadhaar">Aadhaar Card</option>
                            <option value="Voter ID">Voter ID</option>
                            <option value="Driving License">Driving License</option>
                            <option value="Student ID">College/School ID</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fas fa-file-alt"></i> ID Proof Document No.</label>
                    <input type="text" name="id_proof_no" class="form-control" placeholder="Document ID Number">
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fas fa-clock"></i> Select Preferred Study Shift Timing *</label>
                    <select name="preferred_shift_id" class="form-control" required>
                        <?php foreach ($shifts as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo $s['id'] == $selected_shift ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['name']); ?> (<?php echo date('g:i A', strtotime($s['start_time'])); ?> - <?php echo date('g:i A', strtotime($s['end_time'])); ?>) - <?php echo format_currency($s['fee_amount']); ?>/mo
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="alert alert-info" style="font-size: 0.82rem; margin-bottom: 16px;">
                    <i class="fas fa-info-circle"></i> <strong>Seat Allotment Note:</strong> Specific seat desk number will be assigned by Admin after reviewing your registration request.
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fas fa-lock"></i> Set Account Password *</label>
                    <input type="password" name="password" class="form-control" placeholder="Create password" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; margin-top: 10px;">
                    <i class="fas fa-paper-plane"></i> Submit Registration Request
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
