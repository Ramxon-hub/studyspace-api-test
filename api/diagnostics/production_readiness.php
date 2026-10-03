<?php
// api/diagnostics/production_readiness.php — Safe Read-Only Production Readiness Diagnostic Endpoint

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    require_once __DIR__ . '/../../config/master_db.php';
    require_once __DIR__ . '/../../config/tenant_router.php';

    // 1. Master Database Check
    $masterPath = get_master_db_path();
    $masterExists = file_exists($masterPath);
    $masterReadable = $masterExists && is_readable($masterPath);

    $masterPdo = null;
    $masterConnOk = false;
    if ($masterReadable) {
        try {
            $masterPdo = get_master_pdo();
            $stmt = $masterPdo->query("SELECT 1");
            if ($stmt && $stmt->fetchColumn() == 1) {
                $masterConnOk = true;
            }
        } catch (Exception $e) {
            $masterConnOk = false;
        }
    }

    // 2. Tenant LIB001 Resolution Check
    $lib1Exists = false;
    $lib1Readable = false;
    $tenantResolutionStatus = "FAILED";

    if ($masterConnOk) {
        try {
            $stmtCfg = $masterPdo->prepare("SELECT db_file FROM tenant_db_configs WHERE library_code = 'LIB001'");
            $stmtCfg->execute();
            $cfg = $stmtCfg->fetch(PDO::FETCH_ASSOC);

            $configuredDbFile = ($cfg && !empty($cfg['db_file'])) ? $cfg['db_file'] : null;
            $lib1Path = TenantDatabaseFactory::resolveTenantDbPath('LIB001', $configuredDbFile);

            if (!empty($lib1Path) && file_exists($lib1Path)) {
                $lib1Exists = true;
                if (is_readable($lib1Path)) {
                    $lib1Readable = true;
                }
            }

            // Verify full tenant connection resolution
            TenantDatabaseFactory::clearCache();
            $tenantPdo = TenantDatabaseFactory::getTenantConnection('LIB001');
            if ($tenantPdo !== null) {
                $tStmt = $tenantPdo->query("SELECT 1");
                if ($tStmt && $tStmt->fetchColumn() == 1) {
                    $tenantResolutionStatus = "RESOLVED";
                }
            }
        } catch (Exception $e) {
            $tenantResolutionStatus = "FAILED";
        }
    }

    // 3. Config & Environment Verification
    $configStatus = ($masterConnOk && $masterReadable) ? "VALID" : "INVALID";
    $env = (defined('APP_ENV') && !empty(APP_ENV)) ? APP_ENV : 'production';
    $apiStatus = "ONLINE";

    $allPassed = (
        $masterExists &&
        $masterReadable &&
        $masterConnOk &&
        $lib1Exists &&
        $lib1Readable &&
        $tenantResolutionStatus === "RESOLVED" &&
        $configStatus === "VALID" &&
        $apiStatus === "ONLINE"
    );

    http_response_code($allPassed ? 200 : 500);

    echo json_encode([
        'success' => $allPassed,
        'master_db_exists' => $masterExists,
        'master_db_readable' => $masterReadable,
        'tenant_lib001_exists' => $lib1Exists,
        'tenant_lib001_readable' => $lib1Readable,
        'tenant_resolution_status' => $tenantResolutionStatus,
        'config_status' => $configStatus,
        'environment' => $env,
        'api_status' => $apiStatus
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'master_db_exists' => false,
        'master_db_readable' => false,
        'tenant_lib001_exists' => false,
        'tenant_lib001_readable' => false,
        'tenant_resolution_status' => "FAILED",
        'config_status' => "INVALID",
        'environment' => defined('APP_ENV') ? APP_ENV : 'production',
        'api_status' => "OFFLINE"
    ], JSON_PRETTY_PRINT);
}
