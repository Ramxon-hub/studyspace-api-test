<?php
// config/env.php - Centralized Environment Configuration
require_once __DIR__ . '/tenant_config.php';

if (file_exists(__DIR__ . '/env_local.php')) {
    require_once __DIR__ . '/env_local.php';
} elseif (file_exists(__DIR__ . '/../env_local.php')) {
    require_once __DIR__ . '/../env_local.php';
}

if (!function_exists('env_get')) {
    function env_get($key, $default = null) {
        $local_const = 'LOCAL_' . strtoupper($key);
        if (defined($local_const)) {
            return constant($local_const);
        }

        if (function_exists('getenv')) {
            $val = getenv($key);
            if ($val !== false && $val !== null && $val !== '') {
                return $val;
            }
        }

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }

        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }

        return $default;
    }
}

if (!defined('APP_ENV')) {
    define('APP_ENV', env_get('APP_ENV', 'production'));
}

if (!defined('DB_DRIVER')) {
    $env_driver = strtolower(env_get('DB_DRIVER', ''));
    if (in_array($env_driver, ['postgres', 'postgresql', 'pgsql'])) {
        define('DB_DRIVER', 'postgres');
    } elseif (in_array($env_driver, ['mysql', 'mariadb'])) {
        define('DB_DRIVER', 'mysql');
    } else {
        define('DB_DRIVER', 'sqlite');
    }
}

if (!defined('DB_PATH')) {
    $env_db_path = env_get('DB_PATH');
    if ($env_db_path) {
        define('DB_PATH', $env_db_path);
    } elseif (defined('APP_ENV') && APP_ENV === 'development') {
        define('DB_PATH', __DIR__ . '/../library.db');
    } else {
        // Authoritative Production Database Path on Render Persistent Disk
        define('DB_PATH', '/var/www/html/data/library.db');
    }
}

// Populate environment variables and constants so downstream code can access them seamlessly
foreach (['DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'APP_ENV'] as $env_var) {
    $resolved_val = env_get($env_var);
    if ($resolved_val !== null) {
        $_ENV[$env_var] = $resolved_val;
        $_SERVER[$env_var] = $resolved_val;
        if (function_exists('putenv')) {
            @putenv("$env_var=$resolved_val");
        }
        if (function_exists('apache_setenv')) {
            @apache_setenv($env_var, $resolved_val);
        }
    }
}

if (!defined('DB_HOST')) define('DB_HOST', env_get('DB_HOST', '127.0.0.1'));
if (!defined('DB_PORT')) define('DB_PORT', env_get('DB_PORT', '3306'));
if (!defined('DB_NAME')) define('DB_NAME', env_get('DB_NAME', 'library_db'));
if (!defined('DB_USER')) define('DB_USER', env_get('DB_USER', 'root'));
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', env_get('DB_PASSWORD', ''));

if (!defined('ALLOW_DB_RESET')) {
    $allow_reset = env_get('ALLOW_DB_RESET');
    define('ALLOW_DB_RESET', $allow_reset === 'true' || $allow_reset === true);
}

if (!defined('PRODUCTION_IDENTITY_MARKER')) {
    define('PRODUCTION_IDENTITY_MARKER', 'PRODUCTION_STUDYSPACE_AUTHORITATIVE');
}

if (!class_exists('ApiConfig')) {
    class ApiConfig {
        public static $baseUrl = 'https://library-management-hmwx.onrender.com';
    }
}
?>
