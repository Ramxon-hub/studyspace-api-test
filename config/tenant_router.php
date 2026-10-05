<?php
// config/tenant_router.php - Multi-Tenant Router, Resolution & Database Connection Factory

require_once __DIR__ . '/master_db.php';

function is_dir_writable_safe($dir) {
    if (empty($dir) || !is_dir($dir)) return false;
    $test_file = rtrim($dir, '/\\') . '/.wtest_' . uniqid();
    $fp = @fopen($test_file, 'w');
    if ($fp !== false) {
        @fclose($fp);
        @unlink($test_file);
        return true;
    }
    return false;
}

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

            $target_file = $db_file;
            if (file_exists($db_file) && !is_writable($db_file)) {
                $writable_fallback = sys_get_temp_dir() . '/' . basename($db_file);
                if (!file_exists($writable_fallback) || filemtime($db_file) > filemtime($writable_fallback)) {
                    @copy($db_file, $writable_fallback);
                }
                @chmod($writable_fallback, 0777);
                $target_file = $writable_fallback;
            }

            try {
                $tenant_pdo = new PDO("sqlite:" . $target_file);
                $tenant_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $tenant_pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                try { $tenant_pdo->exec("PRAGMA foreign_keys = ON;"); } catch (Exception $e) {}
                try { $tenant_pdo->exec("PRAGMA journal_mode = DELETE;"); } catch (Exception $e) {}
                try { $tenant_pdo->exec("PRAGMA busy_timeout = 10000;"); } catch (Exception $e) {}
                try { $tenant_pdo->exec("PRAGMA synchronous = NORMAL;"); } catch (Exception $e) {}
            } catch (Exception $pdo_err) {
                // If opening target file failed for any OS permission reason, copy to temp directory and open
                $fallback_file = sys_get_temp_dir() . '/' . basename($db_file);
                if (file_exists($db_file) && is_readable($db_file)) {
                    @copy($db_file, $fallback_file);
                    @chmod($fallback_file, 0777);
                    $tenant_pdo = new PDO("sqlite:" . $fallback_file);
                    $tenant_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $tenant_pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    try { $tenant_pdo->exec("PRAGMA foreign_keys = ON;"); } catch (Exception $e) {}
                    try { $tenant_pdo->exec("PRAGMA journal_mode = DELETE;"); } catch (Exception $e) {}
                    try { $tenant_pdo->exec("PRAGMA busy_timeout = 10000;"); } catch (Exception $e) {}
                    try { $tenant_pdo->exec("PRAGMA synchronous = NORMAL;"); } catch (Exception $e) {}
                } else {
                    throw $pdo_err;
                }
            }
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
        $lc_code = strtolower($library_code);
        
        $data_dir = realpath(__DIR__ . '/../data') ?: (__DIR__ . '/../data');
        $filename = !empty($configured_file) ? basename($configured_file) : ('tenant_' . $lc_code . '.sqlite');

        $candidates = [
            '/data/' . $filename,
            '/data/tenant_' . $lc_code . '.sqlite',
            '/var/www/html/data/' . $filename,
            '/var/www/html/data/tenant_' . $lc_code . '.sqlite',
            $data_dir . '/' . $filename,
            $data_dir . '/tenant_' . $lc_code . '.sqlite',
            sys_get_temp_dir() . '/tenant_' . $lc_code . '.sqlite',
            '/tmp/tenant_' . $lc_code . '.sqlite'
        ];

        if (!empty($configured_file) && file_exists($configured_file)) {
            array_unshift($candidates, $configured_file);
        }

        // 1. Check existing database files first across all candidate locations
        foreach ($candidates as $candidate) {
            if (!empty($candidate) && file_exists($candidate)) {
                @chmod($candidate, 0777);
                return $candidate;
            }
        }

        // 2. If database file does not exist, select the first directory writable by process user
        $write_targets = [
            '/data/tenant_' . $lc_code . '.sqlite',
            $data_dir . '/tenant_' . $lc_code . '.sqlite',
            sys_get_temp_dir() . '/tenant_' . $lc_code . '.sqlite',
            '/tmp/tenant_' . $lc_code . '.sqlite'
        ];

        foreach ($write_targets as $target) {
            $dir = dirname($target);
            if (!file_exists($dir)) {
                @mkdir($dir, 0777, true);
            }
            @chmod($dir, 0777);
            if (is_dir_writable_safe($dir)) {
                return $target;
            }
        }

        return sys_get_temp_dir() . '/tenant_' . $lc_code . '.sqlite';
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

    // Support raw JSON body parsing before resolving parameters
    if (empty($_POST) && !empty($_SERVER['REQUEST_METHOD']) && in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH'])) {
        $json_raw = @file_get_contents('php://input');
        if (!empty($json_raw)) {
            $json_data = json_decode($json_raw, true);
            if (is_array($json_data)) {
                $_POST = array_merge($_POST, $json_data);
                $_GET = array_merge($_GET, $json_data);
            }
        }
    }

    // 1. Determine incoming requested library code
    $header_code = $_SERVER['HTTP_X_LIBRARY_CODE'] ?? ($_SERVER['X_LIBRARY_CODE'] ?? null);
    $param_code = $_GET['code'] ?? ($_GET['library_code'] ?? ($_POST['library_code'] ?? ($_GET['tenant_code'] ?? ($_POST['tenant_code'] ?? null))));
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
