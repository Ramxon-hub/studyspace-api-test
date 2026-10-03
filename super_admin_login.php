<?php
// super_admin_login.php - Super Admin Authentication Gateway

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/master_db.php';

// Redirect if already logged in as Super Admin
if (!empty($_SESSION['is_super_admin'])) {
    header("Location: super_admin_dashboard.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        try {
            $master_pdo = get_master_pdo();
            $stmt = $master_pdo->prepare("SELECT * FROM super_admins WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password_hash'])) {
                $_SESSION['is_super_admin'] = true;
                $_SESSION['super_admin_id'] = $admin['id'];
                $_SESSION['super_admin_username'] = $admin['username'];
                $_SESSION['super_admin_email'] = $admin['email'];

                header("Location: super_admin_dashboard.php");
                exit();
            } else {
                $error = 'Invalid Super Admin credentials.';
            }
        } catch (Exception $e) {
            $error = 'Authentication system error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login - StudySpace SaaS Master</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-color: #0F172A;
            --card-bg: #1E293B;
            --primary: #3B82F6;
            --primary-hover: #2563EB;
            --text-main: #F8FAFC;
            --text-muted: #94A3B8;
            --border: #334155;
            --danger: #EF4444;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .login-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            width: 100%;
            max-width: 420px;
            padding: 36px 30px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);
        }
        .brand-header {
            text-align: center;
            margin-bottom: 28px;
        }
        .brand-header i {
            font-size: 42px;
            color: var(--primary);
            margin-bottom: 12px;
        }
        .brand-header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 24px;
            color: var(--text-main);
        }
        .brand-header p {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .input-group {
            position: relative;
        }
        .input-group i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }
        .form-control {
            width: 100%;
            padding: 12px 14px 12px 42px;
            background: #0F172A;
            border: 1px solid var(--border);
            border-radius: 8px;
            color: #FFF;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-control:focus {
            border-color: var(--primary);
        }
        .btn-submit {
            width: 100%;
            padding: 13px;
            background: var(--primary);
            color: #FFF;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-submit:hover {
            background: var(--primary-hover);
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid var(--danger);
            color: #FCA5A5;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="brand-header">
        <i class="fas fa-server"></i>
        <h1>StudySpace SaaS Master</h1>
        <p>Central Platform Super Admin Control Panel</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert-error">
            <i class="fas fa-exclamation-triangle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" action="super_admin_login.php">
        <div class="form-group">
            <label for="username">Super Admin Username / Email</label>
            <div class="input-group">
                <i class="fas fa-user-shield"></i>
                <input type="text" id="username" name="username" class="form-control" placeholder="superadmin" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>
        </div>

        <button type="submit" class="btn-submit">
            <i class="fas fa-key"></i> Authenticate Super Admin
        </button>
    </form>
</div>

</body>
</html>
