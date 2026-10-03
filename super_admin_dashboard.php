<?php
// super_admin_dashboard.php - Master SaaS Platform Super Admin Control Panel (Phase 5 Operations)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/master_db.php';
require_once __DIR__ . '/config/tenant_router.php';

// Super Admin Authentication Guard
if (empty($_SESSION['is_super_admin'])) {
    header("Location: super_admin_login.php");
    exit();
}

$master_pdo = get_master_pdo();

// Compute Master DB Summary Metrics
$today = date('Y-m-d');
$total_libs = (int)$master_pdo->query("SELECT COUNT(*) FROM libraries")->fetchColumn();
$active_libs = (int)$master_pdo->query("SELECT COUNT(*) FROM libraries WHERE status = 'active'")->fetchColumn();
$suspended_libs = (int)$master_pdo->query("SELECT COUNT(*) FROM libraries WHERE status = 'suspended'")->fetchColumn();
$trial_libs = (int)$master_pdo->query("
    SELECT COUNT(DISTINCT l.library_code) FROM libraries l
    LEFT JOIN subscriptions s ON l.library_code = s.library_code
    WHERE LOWER(s.plan_name) LIKE '%trial%' OR s.status = 'trial'
")->fetchColumn();
$expired_libs = (int)$master_pdo->query("
    SELECT COUNT(DISTINCT l.library_code) FROM libraries l
    LEFT JOIN subscriptions s ON l.library_code = s.library_code
    WHERE s.valid_until < '$today' OR s.status = 'expired'
")->fetchColumn();
$provisioning_failures = (int)$master_pdo->query("SELECT COUNT(*) FROM libraries WHERE provisioning_status = 'FAILED'")->fetchColumn();

// Manual Payments & Collections Metrics (Phase 13)
$pending_payments_count = (int)$master_pdo->query("SELECT COUNT(*) FROM manual_payments WHERE status = 'PENDING'")->fetchColumn();
$verified_payments_count = (int)$master_pdo->query("SELECT COUNT(*) FROM manual_payments WHERE status = 'VERIFIED'")->fetchColumn();
$total_manual_collections = (float)$master_pdo->query("SELECT COALESCE(SUM(amount), 0.00) FROM manual_payments WHERE status = 'VERIFIED'")->fetchColumn();

// Fetch Invoices & Manual Payments
$invoices_stmt = $master_pdo->query("
    SELECT i.*, l.name as library_name 
    FROM invoices i 
    LEFT JOIN libraries l ON i.library_code = l.library_code 
    ORDER BY i.id DESC LIMIT 100
");
$invoices = $invoices_stmt ? $invoices_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$payments_stmt = $master_pdo->query("
    SELECT p.*, i.invoice_number, i.plan_id, l.name as library_name 
    FROM manual_payments p 
    LEFT JOIN invoices i ON p.invoice_id = i.id 
    LEFT JOIN libraries l ON p.library_code = l.library_code 
    ORDER BY p.id DESC LIMIT 100
");
$manual_payments = $payments_stmt ? $payments_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch Registered Libraries
$stmt = $master_pdo->query("
    SELECT l.id, l.library_code, l.name, l.contact_person, l.phone, l.email, l.address,
           l.status, l.provisioning_status, l.provisioning_error, l.db_connection_status,
           l.schema_version, l.last_connection_check, l.last_provisioning_attempt, l.created_at,
           s.plan_name, s.max_students, s.valid_until, s.status as sub_status,
           b.logo_url, b.primary_color, b.contact_phone, b.address as branding_address, b.tagline,
           c.db_driver, c.db_file
    FROM libraries l
    LEFT JOIN subscriptions s ON l.library_code = s.library_code
    LEFT JOIN library_branding b ON l.library_code = b.library_code
    LEFT JOIN tenant_db_configs c ON l.library_code = c.library_code
    ORDER BY l.id ASC
");
$libraries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Master Audit Logs
$audit_stmt = $master_pdo->query("SELECT id, action, super_admin, library_code, result_status, metadata, created_at FROM super_admin_audit_logs ORDER BY id DESC LIMIT 50");
$audit_logs = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudySpace Multi-Library SaaS — Super Admin Operations Control Panel</title>
    
    <!-- Google Fonts & FontAwesome -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg: #0F172A;
            --sidebar-bg: #1E293B;
            --card-bg: #1E293B;
            --card-border: #334155;
            --primary: #3B82F6;
            --primary-hover: #2563EB;
            --accent-green: #10B981;
            --accent-yellow: #F59E0B;
            --accent-red: #EF4444;
            --text-main: #F8FAFC;
            --text-sub: #94A3B8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background: var(--bg); color: var(--text-main); display: flex; min-height: 100vh; }

        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--card-border);
            padding: 24px 18px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .sidebar-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 32px; padding: 0 8px; }
        .sidebar-brand i { font-size: 28px; color: var(--primary); }
        .sidebar-brand h2 { font-family: 'Outfit', sans-serif; font-size: 18px; color: var(--text-main); }
        .sidebar-nav { display: flex; flex-direction: column; gap: 6px; }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--text-sub);
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .nav-item:hover, .nav-item.active { background: rgba(59, 130, 246, 0.15); color: var(--primary); }
        .nav-item i { width: 20px; text-align: center; font-size: 16px; }

        .main-wrapper { flex: 1; display: flex; flex-direction: column; }
        .topbar {
            height: 64px;
            background: var(--sidebar-bg);
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
        }
        .topbar h1 { font-family: 'Outfit', sans-serif; font-size: 20px; }
        .user-profile { display: flex; align-items: center; gap: 12px; }
        .user-profile span { font-size: 14px; color: var(--text-sub); }
        .btn-logout {
            padding: 8px 14px;
            background: rgba(239, 68, 68, 0.15);
            color: var(--accent-red);
            border: 1px solid var(--accent-red);
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .content { padding: 32px; flex: 1; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        .stat-icon.blue { background: rgba(59, 130, 246, 0.15); color: var(--primary); }
        .stat-icon.green { background: rgba(16, 185, 129, 0.15); color: var(--accent-green); }
        .stat-icon.red { background: rgba(239, 68, 68, 0.15); color: var(--accent-red); }
        .stat-icon.yellow { background: rgba(245, 158, 11, 0.15); color: var(--accent-yellow); }
        .stat-info h4 { font-size: 24px; font-family: 'Outfit', sans-serif; }
        .stat-info p { font-size: 13px; color: var(--text-sub); margin-top: 2px; }

        .tab-pane { display: none; }
        .tab-pane.active { display: block; }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .card-header h3 { font-family: 'Outfit', sans-serif; font-size: 18px; display: flex; align-items: center; gap: 10px; }

        .btn {
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
        }
        .btn-primary { background: var(--primary); color: #FFF; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-success { background: var(--accent-green); color: #FFF; }
        .btn-warning { background: var(--accent-yellow); color: #FFF; }
        .btn-danger { background: var(--accent-red); color: #FFF; }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { padding: 12px 16px; font-size: 12px; text-transform: uppercase; color: var(--text-sub); border-bottom: 1px solid var(--card-border); }
        td { padding: 14px 16px; font-size: 14px; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: middle; }
        tr:hover td { background: rgba(255,255,255,0.02); }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-active { background: rgba(16, 185, 129, 0.15); color: var(--accent-green); border: 1px solid var(--accent-green); }
        .badge-suspended { background: rgba(239, 68, 68, 0.15); color: var(--accent-red); border: 1px solid var(--accent-red); }
        .badge-warning { background: rgba(245, 158, 11, 0.15); color: var(--accent-yellow); border: 1px solid var(--accent-yellow); }

        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 14px;
            width: 100%;
            max-width: 580px;
            padding: 28px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h3 { font-family: 'Outfit', sans-serif; font-size: 18px; }
        .close-modal { font-size: 20px; color: var(--text-sub); cursor: pointer; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
        .form-group-full { grid-column: span 2; }
        .form-group label { display: block; font-size: 12px; color: var(--text-sub); margin-bottom: 6px; }
        .form-control {
            width: 100%;
            padding: 10px 12px;
            background: #0F172A;
            border: 1px solid var(--card-border);
            border-radius: 6px;
            color: #FFF;
            font-size: 13px;
            outline: none;
        }
        .form-control:focus { border-color: var(--primary); }
    </style>
</head>
<body>

<aside class="sidebar">
    <div>
        <div class="sidebar-brand">
            <i class="fas fa-cubes"></i>
            <h2>StudySpace Master</h2>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-item active" onclick="showTab('dashboardTab', this)">
                <i class="fas fa-chart-pie"></i> Dashboard
            </div>
            <div class="nav-item" onclick="showTab('librariesTab', this)">
                <i class="fas fa-building"></i> Library Directory
            </div>
            <div class="nav-item" onclick="showTab('subscriptionsTab', this)">
                <i class="fas fa-file-invoice-dollar"></i> Subscriptions & Plans
            </div>
            <div class="nav-item" onclick="showTab('invoicesTab', this)">
                <i class="fas fa-file-invoice"></i> Invoices & Billing
            </div>
            <div class="nav-item" onclick="showTab('paymentsTab', this)">
                <i class="fas fa-money-check-alt"></i> Manual Payments
            </div>
            <div class="nav-item" onclick="showTab('brandingTab', this)">
                <i class="fas fa-palette"></i> Dynamic Branding
            </div>
            <div class="nav-item" onclick="showTab('databaseTab', this)">
                <i class="fas fa-database"></i> Tenant Databases
            </div>
            <div class="nav-item" onclick="showTab('adminsTab', this)">
                <i class="fas fa-user-shield"></i> Tenant Admins
            </div>
            <div class="nav-item" onclick="showTab('auditTab', this)">
                <i class="fas fa-history"></i> Master Audit Logs
            </div>
        </nav>
    </div>
    <div style="padding: 12px 8px; font-size: 12px; color: var(--text-sub);">
        StudySpace Multi-Tenant SaaS v2.0
    </div>
</aside>

<div class="main-wrapper">
    <div class="topbar">
        <h1>Super Admin Operations & Management</h1>
        <div class="user-profile">
            <span><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($_SESSION['super_admin_username'] ?? 'superadmin'); ?></span>
            <a href="super_admin_logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div class="content">

        <!-- Summary Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-building"></i></div>
                <div class="stat-info">
                    <h4><?php echo $total_libs; ?></h4>
                    <p>Total Libraries</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <h4><?php echo $active_libs; ?></h4>
                    <p>Active Tenants</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-ban"></i></div>
                <div class="stat-info">
                    <h4><?php echo $suspended_libs; ?></h4>
                    <p>Suspended Tenants</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon yellow"><i class="fas fa-clock"></i></div>
                <div class="stat-info">
                    <h4><?php echo $trial_libs; ?> / <?php echo $expired_libs; ?></h4>
                    <p>Trial / Expired</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon yellow"><i class="fas fa-exclamation-circle"></i></div>
                <div class="stat-info">
                    <h4><?php echo $pending_payments_count; ?></h4>
                    <p>Pending Payments</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-wallet"></i></div>
                <div class="stat-info">
                    <h4>₹<?php echo number_format($total_manual_collections, 2); ?></h4>
                    <p>Manual Collections</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-database"></i></div>
                <div class="stat-info">
                    <h4><?php echo $total_libs; ?></h4>
                    <p>Provisioned DBs</p>
                </div>
            </div>
        </div>

        <!-- 1. DASHBOARD OVERVIEW TAB -->
        <div id="dashboardTab" class="tab-pane active">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Platform Tenants Overview</h3>
                    <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Onboard New Library</button>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Library Name</th>
                                <th>Contact Person</th>
                                <th>Plan</th>
                                <th>Valid Until</th>
                                <th>Status</th>
                                <th>Provision Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($libraries as $l): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($l['library_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($l['name']); ?></td>
                                <td><?php echo htmlspecialchars($l['contact_person'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($l['plan_name'] ?? 'Standard'); ?></td>
                                <td><?php echo htmlspecialchars($l['valid_until'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if ($l['status'] === 'active'): ?>
                                        <span class="badge badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-suspended">Suspended</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $ps = $l['provisioning_status'] ?? 'READY'; ?>
                                    <span class="badge <?php echo $ps === 'READY' ? 'badge-active' : ($ps === 'FAILED' ? 'badge-suspended' : 'badge-warning'); ?>">
                                        <?php echo $ps; ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="showQrModal('<?php echo $l['library_code']; ?>', '<?php echo htmlspecialchars($l['name'], ENT_QUOTES); ?>')"><i class="fas fa-qrcode"></i> QR</button>
                                    <?php if ($l['status'] === 'active'): ?>
                                        <button class="btn btn-sm btn-danger" onclick="toggleStatus('<?php echo $l['library_code']; ?>', 'suspend')"><i class="fas fa-ban"></i> Suspend</button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-success" onclick="toggleStatus('<?php echo $l['library_code']; ?>', 'activate')"><i class="fas fa-check"></i> Activate</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 2. LIBRARIES DIRECTORY TAB -->
        <div id="librariesTab" class="tab-pane">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-building"></i> Registered Library Directory</h3>
                    <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Library</button>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Library Name</th>
                                <th>Contact Person</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($libraries as $l): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($l['library_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($l['name']); ?></td>
                                <td><?php echo htmlspecialchars($l['contact_person'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($l['phone'] ?? $l['contact_phone'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($l['email'] ?? 'N/A'); ?></td>
                                <td><span class="badge <?php echo $l['status'] === 'active' ? 'badge-active' : 'badge-suspended'; ?>"><?php echo ucfirst($l['status']); ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="openEditModal('<?php echo $l['library_code']; ?>', '<?php echo htmlspecialchars($l['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($l['contact_person'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($l['phone'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($l['email'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($l['status'] ?? 'active', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($l['tagline'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($l['primary_color'] ?? '#1D4ED8', ENT_QUOTES); ?>')"><i class="fas fa-edit"></i> Edit</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 3. SUBSCRIPTIONS TAB -->
        <div id="subscriptionsTab" class="tab-pane">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-file-invoice-dollar"></i> Subscriptions & Commercial Licensing</h3>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Library Name</th>
                                <th>Plan Name</th>
                                <th>Capacity</th>
                                <th>Expiry Date</th>
                                <th>Sub Status</th>
                                <th>Manage Plan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($libraries as $l): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($l['library_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($l['name']); ?></td>
                                <td><?php echo htmlspecialchars($l['plan_name'] ?? 'MONTHLY'); ?></td>
                                <td><?php echo (int)($l['max_students'] ?? 100); ?> Students</td>
                                <td><?php echo htmlspecialchars($l['valid_until'] ?? 'N/A'); ?></td>
                                <td><span class="badge badge-active"><?php echo ucfirst($l['sub_status'] ?? 'active'); ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-warning" onclick="editSub('<?php echo $l['library_code']; ?>', '<?php echo htmlspecialchars($l['plan_name'] ?? 'MONTHLY', ENT_QUOTES); ?>', '<?php echo (int)($l['max_students'] ?? 100); ?>', '<?php echo htmlspecialchars($l['valid_until'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($l['sub_status'] ?? 'active', ENT_QUOTES); ?>')"><i class="fas fa-clock"></i> Update Sub</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 3B. INVOICES & BILLING TAB (Phase 13) -->
        <div id="invoicesTab" class="tab-pane">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-file-invoice"></i> Commercial Invoices</h3>
                    <button class="btn btn-primary" onclick="openCreateInvoiceModal()"><i class="fas fa-plus"></i> Create Invoice</button>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Library</th>
                                <th>Plan</th>
                                <th>Amount</th>
                                <th>Invoice Date</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Payment Method</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $inv): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($inv['invoice_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($inv['library_name'] ?? $inv['library_code']); ?> (<code><?php echo htmlspecialchars($inv['library_code']); ?></code>)</td>
                                <td><span class="badge badge-active"><?php echo htmlspecialchars($inv['plan_id']); ?></span></td>
                                <td><strong>₹<?php echo number_format($inv['amount'], 2); ?></strong></td>
                                <td><?php echo htmlspecialchars($inv['invoice_date']); ?></td>
                                <td><?php echo htmlspecialchars($inv['due_date']); ?></td>
                                <td>
                                    <?php 
                                        $st = strtoupper($inv['status']);
                                        $badge_cls = ($st === 'PAID') ? 'badge-active' : (($st === 'PAYMENT PENDING') ? 'badge-warning' : (($st === 'CANCELLED') ? 'badge-suspended' : 'badge-warning'));
                                    ?>
                                    <span class="badge <?php echo $badge_cls; ?>"><?php echo $st; ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($inv['payment_method'] ?? 'Manual'); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="viewReceipt('<?php echo htmlspecialchars($inv['invoice_number'], ENT_QUOTES); ?>')"><i class="fas fa-receipt"></i> Receipt</button>
                                    <?php if ($inv['status'] !== 'PAID' && $inv['status'] !== 'CANCELLED'): ?>
                                        <button class="btn btn-sm btn-success" onclick="openRecordPaymentModal('<?php echo $inv['id']; ?>', '<?php echo $inv['library_code']; ?>', '<?php echo htmlspecialchars($inv['invoice_number'], ENT_QUOTES); ?>', '<?php echo $inv['amount']; ?>', '<?php echo $inv['plan_id']; ?>')"><i class="fas fa-coins"></i> Record Pay</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 3C. MANUAL PAYMENTS TAB (Phase 13) -->
        <div id="paymentsTab" class="tab-pane">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-money-check-alt"></i> Manual Payments & Receipts</h3>
                    <button class="btn btn-primary" onclick="openRecordPaymentModal('', '', '', '', '')"><i class="fas fa-plus"></i> Record Manual Payment</button>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Payment ID</th>
                                <th>Invoice #</th>
                                <th>Library</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference / UTR</th>
                                <th>Payment Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($manual_payments as $p): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($p['payment_id']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($p['invoice_number'] ?? ('ID#' . $p['invoice_id'])); ?></strong></td>
                                <td><?php echo htmlspecialchars($p['library_name'] ?? $p['library_code']); ?> (<code><?php echo htmlspecialchars($p['library_code']); ?></code>)</td>
                                <td><strong>₹<?php echo number_format($p['amount'], 2); ?></strong></td>
                                <td><span class="badge badge-active"><?php echo htmlspecialchars($p['payment_method']); ?></span></td>
                                <td><code><?php echo htmlspecialchars($p['payment_reference'] ?: 'N/A'); ?></code></td>
                                <td><?php echo htmlspecialchars($p['payment_date']); ?></td>
                                <td>
                                    <?php
                                        $pst = strtoupper($p['status']);
                                        $pbadge = ($pst === 'VERIFIED') ? 'badge-active' : (($pst === 'PENDING') ? 'badge-warning' : 'badge-suspended');
                                    ?>
                                    <span class="badge <?php echo $pbadge; ?>"><?php echo $pst; ?></span>
                                </td>
                                <td>
                                    <?php if ($p['status'] === 'PENDING'): ?>
                                        <button class="btn btn-sm btn-success" onclick="openVerifyModal('<?php echo $p['payment_id']; ?>', '<?php echo htmlspecialchars($p['library_code'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($p['invoice_number'] ?? '', ENT_QUOTES); ?>', '<?php echo $p['amount']; ?>', '<?php echo htmlspecialchars($p['payment_method'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($p['payment_reference'] ?? '', ENT_QUOTES); ?>')"><i class="fas fa-check"></i> Verify</button>
                                        <button class="btn btn-sm btn-danger" onclick="openRejectModal('<?php echo $p['payment_id']; ?>')"><i class="fas fa-times"></i> Reject</button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-primary" onclick="viewReceipt('<?php echo htmlspecialchars($p['invoice_number'] ?? '', ENT_QUOTES); ?>')"><i class="fas fa-receipt"></i> Details</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 4. BRANDING TAB -->
        <div id="brandingTab" class="tab-pane">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-palette"></i> Per-Library Dynamic Branding</h3>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Tagline</th>
                                <th>Accent Color</th>
                                <th>Contact Phone</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($libraries as $l): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($l['library_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($l['name']); ?></td>
                                <td><?php echo htmlspecialchars($l['tagline'] ?? 'Self Study Hall'); ?></td>
                                <td><span style="display:inline-block; width:14px; height:14px; background:<?php echo htmlspecialchars($l['primary_color'] ?? '#1D4ED8'); ?>; border-radius:3px; margin-right:6px;"></span> <?php echo htmlspecialchars($l['primary_color'] ?? '#1D4ED8'); ?></td>
                                <td><?php echo htmlspecialchars($l['contact_phone'] ?? 'N/A'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 5. TENANT DATABASES TAB -->
        <div id="databaseTab" class="tab-pane">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-database"></i> Tenant Databases & Provisioning Status</h3>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Driver</th>
                                <th>Database Target</th>
                                <th>Provision State</th>
                                <th>Conn State</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($libraries as $l): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($l['library_code']); ?></strong></td>
                                <td><span class="badge badge-active"><?php echo strtoupper($l['db_driver'] ?? 'SQLITE'); ?></span></td>
                                <td><code style="font-size:12px; color:var(--text-sub);"><?php $disp_path = !empty($l['db_file']) ? ('data/' . basename($l['db_file'])) : 'data/tenant_' . strtolower($l['library_code']) . '.sqlite'; echo htmlspecialchars($disp_path); ?></code></td>
                                <td>
                                    <?php $ps = $l['provisioning_status'] ?? 'READY'; ?>
                                    <span class="badge <?php echo $ps === 'READY' ? 'badge-active' : ($ps === 'FAILED' ? 'badge-suspended' : 'badge-warning'); ?>"><?php echo $ps; ?></span>
                                </td>
                                <td>
                                    <?php $cs = $l['db_connection_status'] ?? 'CONNECTED'; ?>
                                    <span class="badge <?php echo $cs === 'CONNECTED' ? 'badge-active' : 'badge-suspended'; ?>"><?php echo $cs; ?></span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="provisionDb('<?php echo $l['library_code']; ?>')"><i class="fas fa-cogs"></i> Provision / Verify</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 6. TENANT ADMINS TAB -->
        <div id="adminsTab" class="tab-pane">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-user-shield"></i> Tenant Admin Credential Lifecycle</h3>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Library Code</th>
                                <th>Library Name</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($libraries as $l): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($l['library_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($l['name']); ?></td>
                                <td><span class="badge badge-active"><?php echo ucfirst($l['status']); ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="openCreateAdminModal('<?php echo $l['library_code']; ?>')"><i class="fas fa-user-plus"></i> Create Admin</button>
                                    <button class="btn btn-sm btn-warning" onclick="openResetPassModal('<?php echo $l['library_code']; ?>', '<?php echo htmlspecialchars($l['email'] ?? '', ENT_QUOTES); ?>')"><i class="fas fa-key"></i> Reset Password</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 7. MASTER AUDIT LOGS TAB -->
        <div id="auditTab" class="tab-pane">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Master Super Admin Audit Logs</h3>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Timestamp</th>
                                <th>Super Admin</th>
                                <th>Action</th>
                                <th>Target Library</th>
                                <th>Status</th>
                                <th>Metadata</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($audit_logs as $a): ?>
                            <tr>
                                <td>#<?php echo $a['id']; ?></td>
                                <td><?php echo htmlspecialchars($a['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($a['super_admin']); ?></td>
                                <td><strong><?php echo htmlspecialchars($a['action']); ?></strong></td>
                                <td><?php echo htmlspecialchars($a['library_code'] ?? 'N/A'); ?></td>
                                <td><span class="badge <?php echo $a['result_status'] === 'SUCCESS' ? 'badge-active' : 'badge-suspended'; ?>"><?php echo htmlspecialchars($a['result_status']); ?></span></td>
                                <td><code style="font-size:11px; color:var(--text-sub);"><?php echo htmlspecialchars($a['metadata']); ?></code></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Modal: Onboard New Library -->
<div id="addModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Onboard New Library Tenant</h3>
            <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
        </div>
        <form id="addLibraryForm" onsubmit="submitAddLibrary(event)">
            <div class="form-grid">
                <div class="form-group">
                    <label>Library Code (Unique)</label>
                    <input type="text" name="library_code" class="form-control" placeholder="LIB003" required pattern="[A-Za-z0-9_-]{3,50}">
                </div>
                <div class="form-group">
                    <label>Library Display Name</label>
                    <input type="text" name="name" class="form-control" placeholder="City Central Library" required>
                </div>
                <div class="form-group">
                    <label>Contact Person</label>
                    <input type="text" name="contact_person" class="form-control" placeholder="John Doe">
                </div>
                <div class="form-group">
                    <label>Contact Phone</label>
                    <input type="text" name="phone" class="form-control" placeholder="+91 98765 43210">
                </div>
                <div class="form-group">
                    <label>Contact Email</label>
                    <input type="email" name="email" class="form-control" placeholder="admin@lib003.com">
                </div>
                <div class="form-group">
                    <label>SaaS Plan</label>
                    <select name="plan_name" class="form-control">
                        <option value="TRIAL">TRIAL (14-Day Free)</option>
                        <option value="MONTHLY" selected>MONTHLY (Standard)</option>
                        <option value="YEARLY">YEARLY (Annual Premium)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Initial Admin Username</label>
                    <input type="text" name="admin_username" class="form-control" placeholder="admin_lib003">
                </div>
                <div class="form-group">
                    <label>Initial Admin Password</label>
                    <input type="password" name="admin_password" class="form-control" placeholder="AdminPass123!">
                </div>
                <div class="form-group-full form-group">
                    <label>Library Address</label>
                    <input type="text" name="address" class="form-control" placeholder="Main Road, Sector 5">
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-cogs"></i> Onboard Library & Provision Database</button>
        </form>
    </div>
</div>

<!-- Modal: Record Manual Payment (Phase 13) -->
<div id="recordPaymentModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-coins"></i> Record Manual Payment</h3>
            <span class="close-modal" onclick="closeModal('recordPaymentModal')">&times;</span>
        </div>
        <form id="recordPaymentForm" onsubmit="submitRecordPayment(event)">
            <div class="form-grid">
                <div class="form-group">
                    <label>Library Code</label>
                    <select name="library_code" id="rp_library_code" class="form-control" required onchange="onPaymentLibChange()">
                        <option value="">-- Select Library --</option>
                        <?php foreach ($libraries as $l): ?>
                            <option value="<?php echo htmlspecialchars($l['library_code']); ?>"><?php echo htmlspecialchars($l['name']); ?> (<?php echo htmlspecialchars($l['library_code']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Target Invoice</label>
                    <select name="invoice_id" id="rp_invoice_id" class="form-control" required onchange="onPaymentInvoiceChange()">
                        <option value="">-- Select Invoice --</option>
                        <?php foreach ($invoices as $inv): if ($inv['status'] !== 'PAID' && $inv['status'] !== 'CANCELLED'): ?>
                            <option value="<?php echo $inv['id']; ?>" data-lib="<?php echo htmlspecialchars($inv['library_code']); ?>" data-amount="<?php echo $inv['amount']; ?>">
                                <?php echo htmlspecialchars($inv['invoice_number']); ?> (₹<?php echo number_format($inv['amount'], 2); ?>)
                            </option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Payment Amount (₹)</label>
                    <input type="number" step="0.01" name="amount" id="rp_amount" class="form-control" placeholder="299.99" required>
                </div>
                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="payment_method" id="rp_payment_method" class="form-control" required>
                        <option value="manual_cash">Cash (manual_cash)</option>
                        <option value="manual_upi" selected>UPI (manual_upi)</option>
                        <option value="manual_bank_transfer">Bank Transfer (manual_bank_transfer)</option>
                        <option value="manual_other">Other (manual_other)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Payment Reference / UTR Number</label>
                    <input type="text" name="payment_reference" id="rp_payment_reference" class="form-control" placeholder="UTR1234567890">
                </div>
                <div class="form-group">
                    <label>Payment Date</label>
                    <input type="date" name="payment_date" id="rp_payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group-full form-group">
                    <label>Notes / Proof Reference</label>
                    <input type="text" name="notes" class="form-control" placeholder="Verified via GPay UTR screenshot">
                </div>
            </div>
            <button type="submit" class="btn btn-success" style="width: 100%; justify-content: center;"><i class="fas fa-check-circle"></i> Record Manual Payment (Status: PENDING)</button>
        </form>
    </div>
</div>

<!-- Modal: Create Invoice (Phase 13) -->
<div id="createInvoiceModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-file-invoice"></i> Issue New Commercial Invoice</h3>
            <span class="close-modal" onclick="closeModal('createInvoiceModal')">&times;</span>
        </div>
        <form id="createInvoiceForm" onsubmit="submitCreateInvoice(event)">
            <div class="form-grid">
                <div class="form-group">
                    <label>Library Code</label>
                    <select name="library_code" class="form-control" required>
                        <option value="">-- Select Library --</option>
                        <?php foreach ($libraries as $l): ?>
                            <option value="<?php echo htmlspecialchars($l['library_code']); ?>"><?php echo htmlspecialchars($l['name']); ?> (<?php echo htmlspecialchars($l['library_code']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>SaaS Plan</label>
                    <select name="plan_id" class="form-control" onchange="onPlanSelectChange(this)">
                        <option value="MONTHLY" selected>MONTHLY (₹29.99)</option>
                        <option value="YEARLY">YEARLY (₹299.99)</option>
                        <option value="TRIAL">TRIAL (₹0.00)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Amount (INR)</label>
                    <input type="number" step="0.01" name="amount" id="ci_amount" class="form-control" value="29.99" required>
                </div>
                <div class="form-group">
                    <label>Due Date</label>
                    <input type="date" name="due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-paper-plane"></i> Issue Invoice</button>
        </form>
    </div>
</div>

<!-- Modal: Verify Payment (Phase 13) -->
<div id="verifyPaymentModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-check-circle"></i> Verify Payment & Activate Subscription</h3>
            <span class="close-modal" onclick="closeModal('verifyPaymentModal')">&times;</span>
        </div>
        <div style="padding:10px 0 20px 0;">
            <p style="color:var(--text-sub); font-size:14px; margin-bottom:16px;">Are you sure you want to verify this manual payment? This action will mark the invoice <strong>PAID</strong> and activate/renew the library's subscription.</p>
            <div style="background:#0F172A; border:1px solid var(--card-border); padding:16px; border-radius:8px; font-size:13px; margin-bottom:20px;">
                <p><strong>Payment ID:</strong> <span id="vp_payment_id"></span></p>
                <p><strong>Library Code:</strong> <span id="vp_lib_code"></span></p>
                <p><strong>Invoice Number:</strong> <span id="vp_invoice_no"></span></p>
                <p><strong>Amount:</strong> ₹<span id="vp_amount"></span></p>
                <p><strong>Method:</strong> <span id="vp_method"></span></p>
                <p><strong>Reference/UTR:</strong> <span id="vp_ref"></span></p>
            </div>
            <div style="display:flex; gap:12px;">
                <button type="button" class="btn btn-danger" onclick="closeModal('verifyPaymentModal')" style="flex:1; justify-content:center;">Cancel</button>
                <button type="button" class="btn btn-success" onclick="executeVerifyPayment()" style="flex:2; justify-content:center;"><i class="fas fa-check"></i> Confirm & Activate Subscription</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Reject Payment (Phase 13) -->
<div id="rejectPaymentModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-times-circle"></i> Reject Manual Payment</h3>
            <span class="close-modal" onclick="closeModal('rejectPaymentModal')">&times;</span>
        </div>
        <form onsubmit="executeRejectPayment(event)">
            <input type="hidden" id="rp_reject_payment_id">
            <div class="form-group" style="margin-bottom:16px;">
                <label>Rejection Reason (Required)</label>
                <textarea id="rp_reject_reason" class="form-control" rows="3" placeholder="e.g. Invalid UTR reference or payment not received in bank account." required></textarea>
            </div>
            <div style="display:flex; gap:12px;">
                <button type="button" class="btn btn-primary" onclick="closeModal('rejectPaymentModal')" style="flex:1; justify-content:center;">Cancel</button>
                <button type="submit" class="btn btn-danger" style="flex:2; justify-content:center;"><i class="fas fa-times"></i> Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Receipt / Invoice Details (Phase 13) -->
<div id="receiptModal" class="modal-overlay">
    <div class="modal" style="max-width:640px;">
        <div class="modal-header">
            <h3><i class="fas fa-receipt"></i> Official Subscription Receipt</h3>
            <span class="close-modal" onclick="closeModal('receiptModal')">&times;</span>
        </div>
        <div id="receiptModalContent" style="padding:10px 0;">
            <!-- Rendered dynamically -->
        </div>
    </div>
</div>

<!-- Modal: Edit Library -->
<div id="editModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Library Tenant Details</h3>
            <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
        </div>
        <form id="editLibraryForm" onsubmit="submitEditLibrary(event)">
            <input type="hidden" name="library_code" id="edit_lib_code">
            <div class="form-grid">
                <div class="form-group">
                    <label>Library Code</label>
                    <input type="text" id="edit_lib_code_display" class="form-control" disabled>
                </div>
                <div class="form-group">
                    <label>Library Display Name</label>
                    <input type="text" name="name" id="edit_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Contact Person</label>
                    <input type="text" name="contact_person" id="edit_contact_person" class="form-control">
                </div>
                <div class="form-group">
                    <label>Contact Phone</label>
                    <input type="text" name="phone" id="edit_phone" class="form-control">
                </div>
                <div class="form-group">
                    <label>Contact Email</label>
                    <input type="email" name="email" id="edit_email" class="form-control">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit_status" class="form-control">
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tagline</label>
                    <input type="text" name="tagline" id="edit_tagline" class="form-control">
                </div>
                <div class="form-group">
                    <label>Primary Accent Color</label>
                    <input type="color" name="primary_color" id="edit_primary_color" class="form-control" style="height:42px; padding:4px;">
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-save"></i> Save Library Details</button>
        </form>
    </div>
</div>

<!-- Modal: Update Subscription -->
<div id="subModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-clock"></i> Update Subscription & Capacity</h3>
            <span class="close-modal" onclick="closeModal('subModal')">&times;</span>
        </div>
        <form id="editSubForm" onsubmit="submitEditSub(event)">
            <input type="hidden" name="library_code" id="sub_lib_code">
            <div class="form-grid">
                <div class="form-group">
                    <label>Library Code</label>
                    <input type="text" id="sub_lib_code_display" class="form-control" disabled>
                </div>
                <div class="form-group">
                    <label>Plan Name</label>
                    <select name="plan_name" id="sub_plan_name" class="form-control">
                        <option value="TRIAL">TRIAL</option>
                        <option value="MONTHLY">MONTHLY</option>
                        <option value="YEARLY">YEARLY</option>
                        <option value="CUSTOM">CUSTOM</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Capacity (Max Students)</label>
                    <input type="number" name="max_students" id="sub_max_students" class="form-control" min="1" required>
                </div>
                <div class="form-group">
                    <label>Subscription Expiry Date</label>
                    <input type="date" name="valid_until" id="sub_valid_until" class="form-control" required>
                </div>
                <div class="form-group-full form-group">
                    <label>Subscription Status</label>
                    <select name="status" id="sub_status" class="form-control">
                        <option value="active">Active</option>
                        <option value="expired">Expired</option>
                        <option value="trial">Trial</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-warning" style="width: 100%; justify-content: center;"><i class="fas fa-check-circle"></i> Update Subscription</button>
        </form>
    </div>
</div>

<!-- Modal: Create Tenant Admin -->
<div id="createAdminModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-user-plus"></i> Create Tenant Admin Account</h3>
            <span class="close-modal" onclick="closeModal('createAdminModal')">&times;</span>
        </div>
        <form id="createAdminForm" onsubmit="submitCreateAdmin(event)">
            <input type="hidden" name="library_code" id="ca_lib_code">
            <div class="form-grid">
                <div class="form-group">
                    <label>Library Code</label>
                    <input type="text" id="ca_lib_display" class="form-control" disabled>
                </div>
                <div class="form-group">
                    <label>Admin Display Name</label>
                    <input type="text" name="name" id="ca_name" class="form-control" placeholder="Admin - Library" required>
                </div>
                <div class="form-group">
                    <label>Admin Email / Username</label>
                    <input type="email" name="email" id="ca_email" class="form-control" placeholder="admin@lib.com" required>
                </div>
                <div class="form-group">
                    <label>Admin Password</label>
                    <input type="password" name="password" id="ca_password" class="form-control" placeholder="StrongPassword123!" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;"><i class="fas fa-user-check"></i> Create Admin Credentials</button>
        </form>
    </div>
</div>

<!-- Modal: Reset Tenant Admin Password -->
<div id="resetPassModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-key"></i> Reset Tenant Admin Password</h3>
            <span class="close-modal" onclick="closeModal('resetPassModal')">&times;</span>
        </div>
        <form id="resetPassForm" onsubmit="submitResetPass(event)">
            <input type="hidden" name="library_code" id="rp_reset_lib_code">
            <div class="form-grid">
                <div class="form-group">
                    <label>Library Code</label>
                    <input type="text" id="rp_reset_lib_display" class="form-control" disabled>
                </div>
                <div class="form-group">
                    <label>Target Admin Email / Username</label>
                    <input type="text" name="email" id="rp_reset_email" class="form-control" placeholder="admin@lib.com" required>
                </div>
                <div class="form-group-full form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" id="rp_reset_new_password" class="form-control" placeholder="Enter new strong password" required>
                </div>
            </div>
            <button type="submit" class="btn btn-danger" style="width: 100%; justify-content: center;"><i class="fas fa-sync-alt"></i> Reset Password</button>
        </form>
    </div>
</div>

<script>
function showTab(tabId, el) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    el.classList.add('active');
}

function openAddModal() {
    document.getElementById('addModal').classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

function showQrModal(code, name) {
    document.getElementById('qrContent').innerText = `STUDYSPACE:${code}`;
    document.getElementById('qrDesc').innerText = `Scan in StudySpace Flutter App to auto-select ${name} (${code})`;
    document.getElementById('qrModal').classList.add('active');
}

function openCreateAdminModal(code) {
    document.getElementById('ca_lib_code').value = code;
    document.getElementById('ca_lib_display').value = code;
    document.getElementById('createAdminModal').classList.add('active');
}

function openCreateInvoiceModal() {
    document.getElementById('createInvoiceModal').classList.add('active');
}

function onPlanSelectChange(el) {
    const val = el.value;
    const amt = document.getElementById('ci_amount');
    if (val === 'YEARLY') amt.value = '299.99';
    else if (val === 'MONTHLY') amt.value = '29.99';
    else if (val === 'TRIAL') amt.value = '0.00';
}

function openRecordPaymentModal(invId, libCode, invNo, amount, planId) {
    if (libCode) document.getElementById('rp_library_code').value = libCode;
    if (invId) document.getElementById('rp_invoice_id').value = invId;
    if (amount) document.getElementById('rp_amount').value = amount;
    document.getElementById('recordPaymentModal').classList.add('active');
}

function onPaymentInvoiceChange() {
    const sel = document.getElementById('rp_invoice_id');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.lib) {
        document.getElementById('rp_library_code').value = opt.dataset.lib;
        document.getElementById('rp_amount').value = opt.dataset.amount;
    }
}

function onPaymentLibChange() {
    // Optional filter
}

let activeVerifyPaymentId = null;
function openVerifyModal(payId, libCode, invNo, amount, method, ref) {
    activeVerifyPaymentId = payId;
    document.getElementById('vp_payment_id').innerText = payId;
    document.getElementById('vp_lib_code').innerText = libCode;
    document.getElementById('vp_invoice_no').innerText = invNo;
    document.getElementById('vp_amount').innerText = amount;
    document.getElementById('vp_method').innerText = method;
    document.getElementById('vp_ref').innerText = ref || 'N/A';
    document.getElementById('verifyPaymentModal').classList.add('active');
}

async function executeVerifyPayment() {
    if (!activeVerifyPaymentId) return;
    const params = new URLSearchParams();
    params.append('payment_id', activeVerifyPaymentId);

    const res = await fetch('api/json_super_admin.php?action=verify_manual_payment', {
        method: 'POST',
        body: params
    });
    const data = await res.json();
    if (data.success) {
        alert(data.message + `\nNew Subscription Expiry: ${data.subscription_expiry}`);
        closeModal('verifyPaymentModal');
        location.reload();
    } else {
        alert('Verification Error: ' + data.error);
    }
}

function openRejectModal(payId) {
    document.getElementById('rp_reject_payment_id').value = payId;
    document.getElementById('rejectPaymentModal').classList.add('active');
}

async function executeRejectPayment(e) {
    e.preventDefault();
    const payId = document.getElementById('rp_reject_payment_id').value;
    const reason = document.getElementById('rp_reject_reason').value;

    const params = new URLSearchParams();
    params.append('payment_id', payId);
    params.append('rejection_reason', reason);

    const res = await fetch('api/json_super_admin.php?action=reject_manual_payment', {
        method: 'POST',
        body: params
    });
    const data = await res.json();
    if (data.success) {
        alert(data.message);
        closeModal('rejectPaymentModal');
        location.reload();
    } else {
        alert('Rejection Error: ' + data.error);
    }
}

async function submitRecordPayment(e) {
    e.preventDefault();
    const params = new URLSearchParams(new FormData(e.target));
    const res = await fetch('api/json_super_admin.php?action=create_manual_payment', {
        method: 'POST',
        body: params
    });
    const data = await res.json();
    if (data.success) {
        alert(data.message);
        closeModal('recordPaymentModal');
        location.reload();
    } else {
        alert('Error: ' + data.error);
    }
}

async function submitCreateInvoice(e) {
    e.preventDefault();
    const params = new URLSearchParams(new FormData(e.target));
    const res = await fetch('api/json_super_admin.php?action=create_invoice', {
        method: 'POST',
        body: params
    });
    const data = await res.json();
    if (data.success) {
        alert(data.message);
        closeModal('createInvoiceModal');
        location.reload();
    } else {
        alert('Error: ' + data.error);
    }
}

async function viewReceipt(invNo) {
    if (!invNo) return;
    const res = await fetch(`api/json_super_admin.php?action=list_invoices&library_code=`);
    const data = await res.json();
    let inv = null;
    if (data.success && data.invoices) {
        inv = data.invoices.find(i => i.invoice_number === invNo);
    }
    if (!inv) {
        alert("Invoice not found: " + invNo);
        return;
    }

    const html = `
        <div style="background:#0F172A; border:1px solid var(--card-border); padding:20px; border-radius:10px; color:#FFF;">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--card-border); padding-bottom:12px; margin-bottom:16px;">
                <div>
                    <h2 style="font-family:'Outfit',sans-serif; color:var(--primary); font-size:20px;">StudySpace Multi-Tenant SaaS</h2>
                    <p style="font-size:12px; color:var(--text-sub);">Official SaaS Subscription Receipt</p>
                </div>
                <span class="badge ${inv.status === 'PAID' ? 'badge-active' : 'badge-warning'}" style="font-size:14px;">${inv.status}</span>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:13px; margin-bottom:16px;">
                <p><strong>Invoice Number:</strong> ${inv.invoice_number}</p>
                <p><strong>Library Code:</strong> ${inv.library_code}</p>
                <p><strong>SaaS Plan:</strong> ${inv.plan_id}</p>
                <p><strong>Amount:</strong> ₹${parseFloat(inv.amount).toFixed(2)} ${inv.currency || 'INR'}</p>
                <p><strong>Invoice Date:</strong> ${inv.invoice_date}</p>
                <p><strong>Due Date:</strong> ${inv.due_date}</p>
                <p><strong>Payment Method:</strong> ${inv.payment_method || 'Manual'}</p>
                <p><strong>Payment Ref / UTR:</strong> ${inv.payment_reference || 'N/A'}</p>
                <p><strong>Paid At:</strong> ${inv.paid_at || 'Pending Verification'}</p>
            </div>
            <div style="text-align:right; border-top:1px solid var(--card-border); padding-top:12px;">
                <button onclick="window.print()" class="btn btn-sm btn-primary"><i class="fas fa-print"></i> Print Receipt</button>
            </div>
        </div>
    `;
    document.getElementById('receiptModalContent').innerHTML = html;
    document.getElementById('receiptModal').classList.add('active');
}

async function toggleStatus(code, action) {
    if (!confirm(`Are you sure you want to ${action} library ${code}?`)) return;
    const res = await fetch(`api/json_super_admin.php?action=${action}_library`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `library_code=${code}`
    });
    const data = await res.json();
    if (data.success) {
        alert(data.message);
        location.reload();
    } else {
        alert('Error: ' + data.error);
    }
}

async function submitAddLibrary(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const params = new URLSearchParams(formData);

    const res = await fetch('api/json_super_admin.php?action=create_library', {
        method: 'POST',
        body: params
    });
    const data = await res.json();
    if (data.success) {
        alert(data.message);
        location.reload();
    } else {
        alert('Error: ' + data.error);
    }
}

async function submitCreateAdmin(e) {
    e.preventDefault();
    const params = new URLSearchParams(new FormData(e.target));
    const res = await fetch('api/json_super_admin.php?action=create_library_admin', {
        method: 'POST',
        body: params
    });
    const data = await res.json();
    if (data.success) {
        alert(data.message);
        closeModal('createAdminModal');
        location.reload();
    } else {
        alert('Error: ' + data.error);
    }
}

async function provisionDb(code) {
    const res = await fetch(`api/json_super_admin.php?action=provision_database`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `library_code=${code}`
    });
    const data = await res.json();
    if (data.success) {
        const s = data.db_status;
        alert(`Database Provisioning Verified for ${s.library_code}:\n- Engine: ${s.driver}\n- Schema Version: ${s.schema_version}\n- Tables Count: ${s.table_count}\n- Registered Users: ${s.total_users}`);
        location.reload();
    } else {
        alert('Database Error: ' + data.error);
    }
}

function openEditModal(code, name, contact, phone, email, status, tagline, color) {
    document.getElementById('edit_lib_code').value = code;
    document.getElementById('edit_lib_code_display').value = code;
    document.getElementById('edit_name').value = name || '';
    document.getElementById('edit_contact_person').value = contact || '';
    document.getElementById('edit_phone').value = phone || '';
    document.getElementById('edit_email').value = email || '';
    if (status) document.getElementById('edit_status').value = status;
    if (tagline) document.getElementById('edit_tagline').value = tagline;
    if (color) document.getElementById('edit_primary_color').value = color;
    document.getElementById('editModal').classList.add('active');
}

async function submitEditLibrary(e) {
    e.preventDefault();
    const params = new URLSearchParams(new FormData(e.target));
    const res = await fetch('api/json_super_admin.php?action=update_library', {
        method: 'POST',
        body: params
    });
    const data = await res.json();
    if (data.success) {
        alert(data.message);
        closeModal('editModal');
        location.reload();
    } else {
        alert('Error: ' + data.error);
    }
}

function editSub(code, planName, maxStudents, validUntil, subStatus) {
    document.getElementById('sub_lib_code').value = code;
    document.getElementById('sub_lib_code_display').value = code;
    if (planName) document.getElementById('sub_plan_name').value = planName;
    if (maxStudents) document.getElementById('sub_max_students').value = maxStudents;
    if (validUntil) document.getElementById('sub_valid_until').value = validUntil;
    if (subStatus) document.getElementById('sub_status').value = subStatus;
    document.getElementById('subModal').classList.add('active');
}

async function submitEditSub(e) {
    e.preventDefault();
    const params = new URLSearchParams(new FormData(e.target));
    const res = await fetch('api/json_super_admin.php?action=update_subscription', {
        method: 'POST',
        body: params
    });
    const data = await res.json();
    if (data.success) {
        alert(data.message);
        closeModal('subModal');
        location.reload();
    } else {
        alert('Error: ' + data.error);
    }
}

function openResetPassModal(code, adminEmail) {
    document.getElementById('rp_reset_lib_code').value = code;
    document.getElementById('rp_reset_lib_display').value = code;
    document.getElementById('rp_reset_email').value = adminEmail || `admin@${code.toLowerCase()}.com`;
    document.getElementById('rp_reset_new_password').value = '';
    document.getElementById('resetPassModal').classList.add('active');
}

async function submitResetPass(e) {
    e.preventDefault();
    const params = new URLSearchParams(new FormData(e.target));
    const res = await fetch('api/json_super_admin.php?action=reset_admin_password', {
        method: 'POST',
        body: params
    });
    const data = await res.json();
    if (data.success) {
        alert(data.message);
        closeModal('resetPassModal');
        location.reload();
    } else {
        alert('Error: ' + data.error);
    }
}
</script>

</body>
</html>
