<?php
// includes/header.php - Common layout header with theme toggle & notification popup dropdown

require_once __DIR__ . '/../config/auth.php';
$user = current_user();

// Fetch unread notifications for header bell
$unread_count = 0;
$header_notifs = [];

if (is_logged_in()) {
    $uid = $user['id'];
    if ($user['role'] === 'admin') {
        $stmt_hn = $pdo->query("SELECT n.*, u.name as student_name FROM notifications n LEFT JOIN users u ON n.user_id = u.id ORDER BY n.id DESC LIMIT 5");
    } else {
        $stmt_hn = $pdo->prepare("SELECT * FROM notifications WHERE user_id = 0 OR user_id = ? ORDER BY id DESC LIMIT 5");
        $stmt_hn->execute([$uid]);
    }
    $header_notifs = $stmt_hn->fetchAll();
    $unread_count = count($header_notifs);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - ' . htmlspecialchars(LIBRARY_NAME) : htmlspecialchars(LIBRARY_NAME); ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">

    <!-- Anti-Inspect & DevTools Lock Security Script -->
    <script>
    (function() {
        // 1. Disable Right Click Context Menu
        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            return false;
        }, true);

        // 2. Disable Keyboard Shortcuts (F12, Inspect, Console, View Source, Save)
        document.addEventListener('keydown', function(e) {
            if (
                e.keyCode === 123 || e.key === 'F12' ||
                (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74 || e.keyCode === 67 || e.key === 'I' || e.key === 'i' || e.key === 'J' || e.key === 'j' || e.key === 'C' || e.key === 'c')) ||
                (e.ctrlKey && (e.keyCode === 85 || e.key === 'U' || e.key === 'u')) ||
                (e.ctrlKey && (e.keyCode === 83 || e.key === 'S' || e.key === 's')) ||
                (e.metaKey && e.altKey && (e.key === 'I' || e.key === 'i' || e.key === 'J' || e.key === 'j' || e.key === 'C' || e.key === 'c'))
            ) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        }, true);

        // 3. Disable Dragging & Selection
        document.addEventListener('dragstart', function(e) { e.preventDefault(); }, true);

        // 4. DevTools Active Debugger Trap & Freeze
        setInterval(function() {
            var startTime = performance.now();
            (function() {}.constructor('debugger')());
            var endTime = performance.now();
            if (endTime - startTime > 100) {
                if (document.body) {
                    document.body.innerHTML = '<div style="display:flex; flex-direction:column; justify-content:center; align-items:center; height:100vh; background:#0f172a; color:#f87171; font-family:sans-serif; text-align:center; padding:20px;">' +
                        '<h1 style="font-size:32px; margin-bottom:10px;">🔒 Security Lock Active</h1>' +
                        '<p style="font-size:18px; color:#cbd5e1;">Developer Tools / Inspect Element is strictly disabled on this portal.</p>' +
                        '<p style="font-size:14px; color:#94a3b8; margin-top:15px;">Please close Developer Tools and refresh the page to continue using <?php echo htmlspecialchars(LIBRARY_SHORT_NAME); ?>.</p>' +
                        '</div>';
                }
            }
        }, 400);

        // 5. Console Wiping Protection
        if (window.console) {
            var emptyFn = function() {};
            window.console.log = emptyFn;
            window.console.warn = emptyFn;
            window.console.error = emptyFn;
            window.console.info = emptyFn;
            window.console.debug = emptyFn;
            window.console.table = emptyFn;
        }
    })();
    </script>
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <a href="index.php" class="brand-logo">
                <i class="<?php echo htmlspecialchars(LIBRARY_ICON_CLASS); ?>"></i>
                <span><?php echo htmlspecialchars(LIBRARY_SHORT_NAME); ?> <small style="font-size:0.6rem; color:var(--accent-primary); display:block; line-height:1;"><?php echo htmlspecialchars(LIBRARY_TAGLINE); ?></small></span>
            </a>

            <ul class="nav-links">
                <li><a href="index.php" class="nav-link"><i class="fas fa-home"></i> Home</a></li>
                <?php if (is_logged_in()): ?>
                    <?php if (is_admin()): ?>
                        <li><a href="admin_dashboard.php" class="nav-link"><i class="fas fa-chart-line"></i> Admin Dashboard</a></li>
                    <?php elseif (is_parent()): ?>
                        <li><a href="parent_dashboard.php" class="nav-link"><i class="fas fa-user-shield"></i> Parent Portal</a></li>
                    <?php else: ?>
                        <li><a href="student_dashboard.php" class="nav-link"><i class="fas fa-id-card"></i> My Dashboard</a></li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>

            <div class="nav-user">
                <!-- Theme Toggle Switch -->
                <button id="themeToggleBtn" class="theme-toggle-btn" title="Toggle Light / Dark Mode">
                    <i class="fas fa-moon"></i>
                </button>

                <?php if (is_logged_in()): ?>
                    <!-- Notification Bell with Dropdown Popup -->
                    <div class="notif-dropdown-wrapper">
                        <button id="notifBellBtn" class="theme-toggle-btn" title="Notifications & Notices">
                            <i class="fas fa-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="notif-badge"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </button>

                        <div id="notifDropdown" class="notif-dropdown-menu">
                            <div class="notif-header">
                                <strong><i class="fas fa-bell"></i> Notifications</strong>
                                <span class="badge badge-info"><?php echo $unread_count; ?> Recent</span>
                            </div>
                            <div class="notif-body">
                                <?php if (empty($header_notifs)): ?>
                                    <div style="padding: 16px; text-align: center; color: var(--text-muted); font-size: 0.85rem;">
                                        No notifications yet.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($header_notifs as $hn): ?>
                                        <div class="notif-item">
                                            <div style="font-weight: 600; font-size: 0.88rem; color: var(--text-main);">
                                                <?php echo htmlspecialchars($hn['title']); ?>
                                            </div>
                                            <div style="font-size: 0.82rem; color: var(--text-muted); margin-top: 2px;">
                                                <?php echo htmlspecialchars($hn['message']); ?>
                                            </div>
                                            <div style="font-size: 0.72rem; color: var(--text-dim); margin-top: 4px;">
                                                <i class="far fa-clock"></i> <?php echo date('d M, g:i A', strtotime($hn['created_at'])); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div class="notif-footer">
                                <?php if (is_admin()): ?>
                                    <a href="admin_dashboard.php?tab=notifs">View All Notifications in Admin &rarr;</a>
                                <?php else: ?>
                                    <a href="student_dashboard.php?tab=tabNotifs">View All Notices &rarr;</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="user-badge">
                        <i class="fas fa-user-circle"></i>
                        <span><?php echo htmlspecialchars($user['name']); ?></span>
                        <span class="role-pill <?php echo $user['role'] === 'admin' ? 'role-admin' : 'role-student'; ?>">
                            <?php echo strtoupper($user['role']); ?>
                        </span>
                    </div>
                    <a href="api/auth.php?action=logout" class="btn btn-secondary btn-sm"><i class="fas fa-sign-out-alt"></i> Logout</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-secondary btn-sm"><i class="fas fa-sign-in-alt"></i> Login</a>
                    <a href="login.php?tab=register" class="btn btn-primary btn-sm"><i class="fas fa-user-plus"></i> Join Library</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>
    <div class="main-wrapper">
<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['msg']); ?></div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
<?php endif; ?>
