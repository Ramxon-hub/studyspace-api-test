<?php
// config/tenant_router.php - Multi-Tenant Router, Resolution & Database Connection Factory

require_once __DIR__ . '/master_db.php';

class TenantDatabaseFactory {
    private static $tenant_connections = [];

    /**
     * Get or create a PDO connection to an isolated tenant database
     */
    public static function getTenantConnection($library_code) {
        $library_code = strtoupper(trim((string)$library_code));
        if (!preg_match('/^[A-Z0-9_-]{3,50}$/', $library_code)) {
            throw new Exception("Invalid library code format: " . htmlspecialchars($library_code));
        }

        if (isset(self::$tenant_connections[$library_code])) {
            return self::$tenant_connections[$library_code];
        }

        $master_pdo = get_master_pdo();

        // 1. Verify Library Existence & Status
        $stmt_lib = $master_pdo->prepare("SELECT id, name, status FROM libraries WHERE library_code = ?");
        $stmt_lib->execute([$library_code]);
        $lib = $stmt_lib->fetch();

        if (!$lib) {
            throw new Exception("Unregistered library code: " . $library_code);
        }

        if ($lib['status'] === 'suspended') {
            throw new Exception("Library account is currently suspended: " . $library_code);
        }

        if ($lib['status'] !== 'active') {
            throw new Exception("Library account is inactive: " . $library_code);
        }

        // 2. Verify Subscription Validity
        $stmt_sub = $master_pdo->prepare("SELECT plan_name, max_students, valid_until, status FROM subscriptions WHERE library_code = ?");
        $stmt_sub->execute([$library_code]);
        $sub = $stmt_sub->fetch();

        if ($sub) {
            if ($sub['status'] === 'expired' || ($sub['valid_until'] && $sub['valid_until'] < date('Y-m-d'))) {
                throw new Exception("Library subscription has expired for: " . $library_code);
            }
        }

        // 3. Resolve Database Credentials from Master DB
        $stmt_cfg = $master_pdo->prepare("SELECT db_driver, db_host, db_port, db_name, db_user, db_pass, db_file FROM tenant_db_configs WHERE library_code = ?");
        $stmt_cfg->execute([$library_code]);
        $cfg = $stmt_cfg->fetch();

        if (!$cfg) {
            throw new Exception("Missing database configuration for library: " . $library_code);
        }

        $driver = strtolower($cfg['db_driver'] ?: 'sqlite');

        if ($driver === 'mysql') {
            $host = $cfg['db_host'] ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
            $port = $cfg['db_port'] ?: (defined('DB_PORT') ? DB_PORT : 3306);
            $dbname = $cfg['db_name'] ?: 'studyspace_tenant_' . strtolower($library_code);
            $user = $cfg['db_user'] ?: (defined('DB_USER') ? DB_USER : 'root');
            $pass = $cfg['db_pass'] !== null ? $cfg['db_pass'] : (defined('DB_PASSWORD') ? DB_PASSWORD : '');
            $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

            $tenant_pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } elseif ($driver === 'postgres') {
            $host = $cfg['db_host'] ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
            $port = $cfg['db_port'] ?: (defined('DB_PORT') ? DB_PORT : 5432);
            $dbname = $cfg['db_name'] ?: 'studyspace_tenant_' . strtolower($library_code);
            $user = $cfg['db_user'] ?: (defined('DB_USER') ? DB_USER : 'postgres');
            $pass = $cfg['db_pass'] !== null ? $cfg['db_pass'] : '';
            $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

            $tenant_pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } else {
            // Default: SQLite Database Per Tenant
            $db_file = self::resolveTenantDbPath($library_code, $cfg['db_file'] ?? null);
            $dir = dirname($db_file);
            if (!file_exists($dir)) {
                @mkdir($dir, 0777, true);
            }
            $tenant_pdo = new PDO("sqlite:" . $db_file);
            $tenant_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $tenant_pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $tenant_pdo->exec("PRAGMA foreign_keys = ON;");
            $tenant_pdo->exec("PRAGMA journal_mode = WAL;");
            $tenant_pdo->exec("PRAGMA synchronous = NORMAL;");
        }

        // Initialize tenant schema (all 12 tables + migrations)
        if (function_exists('init_database')) {
            init_database($tenant_pdo);
        }
        if (function_exists('run_migrations')) {
            run_migrations($tenant_pdo);
        }

        self::$tenant_connections[$library_code] = $tenant_pdo;
        return $tenant_pdo;
    }

    /**
     * Resolve the absolute path to a tenant SQLite database file in an environment-aware manner.
     * Prevents cross-environment path mismatches when absolute host paths differ.
     */
    public static function resolveTenantDbPath($library_code, $configured_file = null) {
        $library_code = strtoupper(trim((string)$library_code));
        
        // 1. Direct match if configured path exists on the host
        if (!empty($configured_file) && file_exists($configured_file)) {
            return $configured_file;
        }

        // 2. Direct match on Deplexo persistent disk mount (/data/filename.sqlite)
        if (!empty($configured_file)) {
            $deplexo_path = '/data/' . basename($configured_file);
            if (file_exists($deplexo_path)) {
                return $deplexo_path;
            }
        }
        $deplexo_fallback = '/data/tenant_' . strtolower($library_code) . '.sqlite';
        if (file_exists($deplexo_fallback)) {
            return $deplexo_fallback;
        }

        // 3. Absolute path specification (e.g., /data/studyspace_test.sqlite)
        if (!empty($configured_file) && (strpos($configured_file, '/') === 0 || strpos($configured_file, ':\\') !== false)) {
            return $configured_file;
        }

        // 4. Resolve relative to app data directory using filename
        $data_dir = realpath(__DIR__ . '/../data') ?: (__DIR__ . '/../data');
        if (!empty($configured_file)) {
            $filename = basename($configured_file);
            $candidate = $data_dir . '/' . $filename;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        // 5. Fallback standard naming pattern
        if (defined('APP_ENV') && APP_ENV === 'deplexo_test') {
            return '/data/studyspace_test.sqlite';
        }
        return $data_dir . '/tenant_' . strtolower($library_code) . '.sqlite';
    }

    public static function clearCache() {
        self::$tenant_connections = [];
    }
}

/**
 * Resolve the current tenant context from HTTP request headers, sessions, or parameters.
 * Enforces strict server-side tenant isolation to prevent header spoofing / IDOR cross-tenant access.
 */
function resolve_tenant_context() {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    // 1. Determine incoming requested library code
    $header_code = $_SERVER['HTTP_X_LIBRARY_CODE'] ?? ($_SERVER['X_LIBRARY_CODE'] ?? null);
    $param_code = $_GET['code'] ?? ($_GET['library_code'] ?? ($_POST['library_code'] ?? null));
    $requested_code = !empty($header_code) ? $header_code : (!empty($param_code) ? $param_code : null);

    // 2. Strict Authenticated Session Enforcement
    // If user is already authenticated in session, the tenant is IMMUTABLE to session library_code!
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['library_code'])) {
        $authenticated_code = $_SESSION['library_code'];
        
        // If client manually attempts to pass a differing X-Library-Code header or parameter, REJECT immediately!
        if (!empty($requested_code) && strtoupper(trim($requested_code)) !== strtoupper(trim($authenticated_code))) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'CROSS-TENANT ACCESS FORBIDDEN: Authenticated session belongs to tenant ' . $authenticated_code . '. Header/Parameter spoofing detected.'
            ]);
            exit();
        }
        $target_code = $authenticated_code;
    } else {
        // Unauthenticated request (Login, Registration, Public Tenant Info)
        $default_code = (defined('APP_ENV') && APP_ENV === 'deplexo_test') ? 'LIBTEST' : 'LIB001';
        $target_code = !empty($requested_code) ? strtoupper(trim($requested_code)) : $default_code;
    }

    try {
        $pdo = TenantDatabaseFactory::getTenantConnection($target_code);
        if (!defined('CURRENT_TENANT_CODE')) {
            define('CURRENT_TENANT_CODE', $target_code);
        }
        return [
            'library_code' => $target_code,
            'pdo' => $pdo
        ];
    } catch (Exception $e) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit();
    }
}
