<?php
// api/json_tenant_billing.php - Tenant Billing & Subscription Customer Portal API (Phase 14 Customer Journey)

if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Library-Code');
    header('Content-Type: application/json');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if (!headers_sent()) http_response_code(200);
    if (defined('IN_TEST_SUITE')) return; else exit();
}

require_once __DIR__ . '/../config/master_db.php';
require_once __DIR__ . '/../config/tenant_router.php';

if (session_status() === PHP_SESSION_NONE) @session_start();

$raw_input = file_get_contents('php://input');
$input = !empty($raw_input) ? (json_decode($raw_input, true) ?: []) : [];
$_POST = array_merge($_POST, $input);
$_GET = array_merge($_GET, $input);

$action = $_GET['action'] ?? ($_POST['action'] ?? 'library_subscription');
$master_pdo = get_master_pdo();

// Helper to fetch Support Settings from Master DB
function get_support_settings_map($master_pdo) {
    $rows = $master_pdo->query("SELECT setting_key, setting_value FROM support_settings")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $map[$r['setting_key']] = $r['setting_value'];
    }
    return [
        'support_name' => $map['support_name'] ?? 'StudySpace SaaS Billing & Support',
        'support_email' => $map['support_email'] ?? 'support@studyspace.com',
        'support_phone' => $map['support_phone'] ?? '+91 98765 43210',
        'support_whatsapp' => $map['support_whatsapp'] ?? '+91 98765 43210',
        'support_message' => $map['support_message'] ?? 'Please contact StudySpace billing administrator for payment assistance or subscription renewals.'
    ];
}

// 1. Resolve & Enforce Tenant Context (IDOR Protection)
// Priority: Authenticated Session library_code -> Requested code
$code = $_SESSION['library_code'] ?? ($_SERVER['HTTP_X_LIBRARY_CODE'] ?? ($_GET['library_code'] ?? ($_POST['library_code'] ?? '')));
$code = strtoupper(trim((string)$code));

// If session exists for normal Library Admin/User, force session library_code to prevent cross-tenant IDOR access
if (!empty($_SESSION['library_code']) && empty($_SESSION['is_super_admin']) && $_SESSION['role'] !== 'super_admin') {
    $code = strtoupper(trim($_SESSION['library_code']));
}

if (empty($code)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Library code is required.']);
    if (defined('IN_TEST_SUITE')) return; else exit();
}

try {
    if ($action === 'library_subscription') {
        $stmt = $master_pdo->prepare("
            SELECT l.library_code, l.name, l.status as library_status, l.created_at as onboarded_at,
                   s.id as subscription_id, s.plan_name, s.max_students, s.valid_until, s.status as sub_status, s.created_at as sub_start,
                   b.logo_url, b.primary_color, b.contact_phone, b.tagline
            FROM libraries l
            LEFT JOIN subscriptions s ON l.library_code = s.library_code
            LEFT JOIN library_branding b ON l.library_code = b.library_code
            WHERE l.library_code = ?
        ");
        $stmt->execute([$code]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Library subscription not found for code: $code"]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $today = date('Y-m-d');
        $valid_until = $sub['valid_until'] ?? $today;
        $diff_days = (int)ceil((strtotime($valid_until) - strtotime($today)) / 86400);

        // Expiry State Calculation Rule (Backend Authoritative)
        $expiry_state = 'ACTIVE';
        if ($sub['library_status'] === 'suspended') {
            $expiry_state = 'SUSPENDED';
        } elseif ($diff_days <= 0 || ($sub['sub_status'] ?? '') === 'expired') {
            $expiry_state = 'EXPIRED';
        } elseif ($diff_days <= 7) {
            $expiry_state = 'CRITICAL';
        } elseif ($diff_days <= 30) {
            $expiry_state = 'EXPIRING_SOON';
        }

        // Check if there is an active pending renewal request
        $stmtReq = $master_pdo->prepare("SELECT request_id, requested_plan, status, created_at FROM subscription_renewal_requests WHERE library_code = ? AND status IN ('PENDING', 'CONTACTED', 'PAYMENT_PENDING') ORDER BY id DESC LIMIT 1");
        $stmtReq->execute([$code]);
        $pending_renewal = $stmtReq->fetch(PDO::FETCH_ASSOC);

        // Check overall payment status
        $pending_invoices = (int)$master_pdo->query("SELECT COUNT(*) FROM invoices WHERE library_code = '$code' AND status IN ('ISSUED', 'PAYMENT PENDING')")->fetchColumn();
        $payment_status = ($pending_invoices > 0) ? 'PENDING' : 'PAID';

        $support = get_support_settings_map($master_pdo);

        echo json_encode([
            'success' => true,
            'subscription' => [
                'library_code' => $sub['library_code'],
                'library_name' => $sub['name'],
                'plan_name' => $sub['plan_name'] ?? 'MONTHLY',
                'max_students' => (int)($sub['max_students'] ?? 100),
                'valid_until' => $valid_until,
                'days_remaining' => max(0, $diff_days),
                'expiry_state' => $expiry_state,
                'library_status' => $sub['library_status'],
                'sub_status' => $sub['sub_status'] ?? 'active',
                'payment_status' => $payment_status,
                'branding' => [
                    'logo_url' => $sub['logo_url'] ?? '',
                    'primary_color' => $sub['primary_color'] ?? '#1D4ED8',
                    'tagline' => $sub['tagline'] ?? 'Self Study Hall'
                ],
                'pending_renewal_request' => $pending_renewal ?: null,
                'support' => $support
            ]
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'library_invoices') {
        $stmt = $master_pdo->prepare("SELECT id, invoice_number, library_code, plan_id, amount, currency, invoice_date, due_date, status, payment_reference, payment_method, paid_at, created_at FROM invoices WHERE library_code = ? ORDER BY id DESC");
        $stmt->execute([$code]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'library_code' => $code, 'invoices' => $invoices]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'library_payments') {
        $stmt = $master_pdo->prepare("
            SELECT p.id, p.payment_id, p.invoice_id, p.library_code, p.amount, p.currency, p.payment_method, p.payment_reference, p.payment_date, p.status, p.rejection_reason, p.verified_at, p.created_at, i.invoice_number, i.plan_id
            FROM manual_payments p
            LEFT JOIN invoices i ON p.invoice_id = i.id
            WHERE p.library_code = ?
            ORDER BY p.id DESC
        ");
        $stmt->execute([$code]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'library_code' => $code, 'payments' => $payments]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'library_subscription_history') {
        $stmt = $master_pdo->prepare("
            SELECT id, action, super_admin, result_status, metadata, created_at
            FROM super_admin_audit_logs
            WHERE library_code = ? AND action IN ('SUBSCRIPTION_ACTIVATED', 'SUBSCRIPTION_RENEWED', 'subscription_updated', 'library_created')
            ORDER BY id DESC
        ");
        $stmt->execute([$code]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'library_code' => $code, 'history' => $history]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } elseif ($action === 'create_renewal_request') {
        $requested_plan = trim($_POST['requested_plan'] ?? 'YEARLY');
        $admin_note = trim($_POST['notes'] ?? ($_POST['admin_note'] ?? ''));
        $requested_by = $_SESSION['email'] ?? ($_SESSION['username'] ?? 'admin');

        // Check if a PENDING renewal request already exists
        $chk = $master_pdo->prepare("SELECT request_id FROM subscription_renewal_requests WHERE library_code = ? AND status IN ('PENDING', 'CONTACTED', 'PAYMENT_PENDING')");
        $chk->execute([$code]);
        $existing = $chk->fetchColumn();

        if ($existing) {
            echo json_encode([
                'success' => true,
                'message' => 'Renewal request is already pending for your library account.',
                'request_id' => $existing,
                'status' => 'PENDING',
                'already_exists' => true
            ]);
            if (defined('IN_TEST_SUITE')) return; else exit();
        }

        $stmtSub = $master_pdo->prepare("SELECT id FROM subscriptions WHERE library_code = ?");
        $stmtSub->execute([$code]);
        $sub_id = $stmtSub->fetchColumn();

        $request_id = 'REQ-REN-' . $code . '-' . date('YmdHis') . '-' . rand(100, 999);

        $stmtIns = $master_pdo->prepare("
            INSERT INTO subscription_renewal_requests (request_id, library_code, current_subscription_id, requested_plan, requested_by, status, admin_note)
            VALUES (?, ?, ?, ?, ?, 'PENDING', ?)
        ");
        $stmtIns->execute([$request_id, $code, $sub_id ?: null, $requested_plan, $requested_by, $admin_note]);

        log_super_admin_action($master_pdo, 'RENEWAL_REQUEST_CREATED', $requested_by, $code, 'SUCCESS', [
            'request_id' => $request_id,
            'requested_plan' => $requested_plan
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Renewal request submitted successfully. StudySpace administrator will contact you shortly.',
            'request_id' => $request_id,
            'status' => 'PENDING'
        ]);
        if (defined('IN_TEST_SUITE')) return; else exit();

    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid tenant billing action specified.']);
        if (defined('IN_TEST_SUITE')) return; else exit();
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    if (defined('IN_TEST_SUITE')) return; else exit();
}
