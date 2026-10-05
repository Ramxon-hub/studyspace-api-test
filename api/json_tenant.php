<?php
// api/json_tenant.php - Public API Endpoint to Resolve Library Information & Dynamic Branding

if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Library-Code');
    header('Content-Type: application/json');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if (!headers_sent()) http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/master_db.php';

$code = $_GET['code'] ?? ($_GET['library_code'] ?? ($_SERVER['HTTP_X_LIBRARY_CODE'] ?? 'LIB001'));
$code = strtoupper(trim((string)$code));
$action = $_GET['action'] ?? '';

try {
    $master_pdo = get_master_pdo();

    if (isset($_GET['list']) || $action === 'list' || $code === 'ALL') {
        $stmt = $master_pdo->query("
            SELECT l.library_code as code, l.name, l.status,
                   b.logo_url, b.primary_color, b.contact_phone as phone, b.address, b.tagline
            FROM libraries l
            LEFT JOIN library_branding b ON l.library_code = b.library_code
            WHERE l.status = 'active'
            ORDER BY l.id ASC
        ");
        $libs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'libraries' => $libs]);
        exit();
    }

    $stmt = $master_pdo->prepare("
        SELECT l.library_code, l.name, l.status,
               b.logo_url, b.primary_color, b.contact_phone, b.address, b.tagline,
               s.plan_name, s.valid_until, s.status as sub_status
        FROM libraries l
        LEFT JOIN library_branding b ON l.library_code = b.library_code
        LEFT JOIN subscriptions s ON l.library_code = s.library_code
        WHERE l.library_code = ?
    ");
    $stmt->execute([$code]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$info) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Unregistered library code: ' . $code]);
        exit();
    }

    if ($info['status'] === 'suspended') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Library account is suspended: ' . $code]);
        exit();
    }

    if (($info['valid_until'] && $info['valid_until'] < date('Y-m-d')) || ($info['sub_status'] ?? '') === 'expired') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Library subscription has expired: ' . $code]);
        exit();
    }

    // Return safe, client-facing branding & details ONLY
    // DO NOT expose DB host, DB name, DB username, DB password, or DB path!
    echo json_encode([
        'success' => true,
        'library' => [
            'code' => $info['library_code'],
            'name' => $info['name'],
            'status' => $info['status'],
            'tagline' => $info['tagline'] ?? 'Self Study Hall',
            'logo_url' => $info['logo_url'] ?? '',
            'primary_color' => $info['primary_color'] ?? '#1D4ED8',
            'phone' => $info['contact_phone'] ?? '',
            'address' => $info['address'] ?? '',
            'plan_name' => $info['plan_name'] ?? 'Standard',
            'valid_until' => $info['valid_until'] ?? null
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to retrieve library info: ' . $e->getMessage()]);
}
