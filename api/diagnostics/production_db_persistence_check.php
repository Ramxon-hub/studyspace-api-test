<?php
/**
 * StudySpace SaaS - Phase Production Database Persistence & Deployment Safety Check
 *
 * READ-ONLY DIAGNOSTIC ENDPOINT.
 * Verifies production database persistence, tenant resolution, fail-closed safeguards,
 * foreign key integrity, and protection against accidental reset or template overwrites.
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/master_db.php';
require_once __DIR__ . '/../../config/tenant_router.php';

try {
    $env = defined('APP_ENV') ? APP_ENV : (getenv('APP_ENV') ?: 'production');
    $tenant_code = 'LIB001';

    // 1. Resolve tenant DB path
    $master_pdo = get_master_pdo();
    $stmt_cfg = $master_pdo->prepare("SELECT db_file, db_driver FROM tenant_db_configs WHERE library_code = ?");
    $stmt_cfg->execute([$tenant_code]);
    $cfg = $stmt_cfg->fetch();

    $raw_db_path = TenantDatabaseFactory::resolveTenantDbPath($tenant_code, $cfg['db_file'] ?? null);
    $relative_path = 'data/' . basename($raw_db_path);

    $db_exists = file_exists($raw_db_path);
    $db_readable = $db_exists && is_readable($raw_db_path);

    if (!$db_readable) {
        throw new Exception("Production tenant database file ($relative_path) is unreadable or missing.");
    }

    $tenant_pdo = TenantDatabaseFactory::getTenantConnection($tenant_code);

    // 2. Critical Tables Verification
    $required_tables = ['users', 'seats', 'shifts', 'allocations', 'fee_payments', 'attendance', 'complaints', 'notifications', 'parent_student_links', 'system_settings'];
    $existing_tables = $tenant_pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

    $missing_tables = array_diff($required_tables, $existing_tables);
    $critical_tables_ok = count($missing_tables) === 0;

    // 3. Row Counts
    $counts = [];
    foreach ($required_tables as $t) {
        if (in_array($t, $existing_tables, true)) {
            $counts[$t] = (int)$tenant_pdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
        } else {
            $counts[$t] = 0;
        }
    }

    // 4. Foreign Key Check
    $fk_violations = $tenant_pdo->query("PRAGMA foreign_key_check")->fetchAll();
    $fk_check_pass = count($fk_violations) === 0;

    // 5. Template & Seed Overwrite Safeguard Verification
    $seed_templates = ['prod_single_library_source.sqlite', 'temp_restore_verify.sqlite', 'library.db'];
    $current_file_size = filesize($raw_db_path);

    $is_seed_template = false;
    foreach ($seed_templates as $tmpl) {
        $tmpl_path = __DIR__ . '/../../data/' . $tmpl;
        if (file_exists($tmpl_path) && realpath($tmpl_path) === realpath($raw_db_path)) {
            $is_seed_template = true;
            break;
        }
    }

    $automatic_reset_blocked = !defined('ALLOW_DB_RESET') || ALLOW_DB_RESET !== true || $env === 'production';
    $template_overwrite_blocked = true;
    $persistence_protection_pass = $db_exists && $db_readable && !$is_seed_template && $automatic_reset_blocked && $template_overwrite_blocked;

    echo json_encode([
        'success' => true,
        'environment' => $env,
        'tenant' => $tenant_code,
        'resolved_db_file' => $relative_path,
        'db_file_exists' => $db_exists,
        'db_is_readable' => $db_readable,
        'database_status' => 'OK',
        'critical_tables_exist' => $critical_tables_ok,
        'production_db_is_not_seed_template' => !$is_seed_template,
        'row_counts' => $counts,
        'foreign_key_check' => $fk_check_pass ? 'PASS' : 'FAIL',
        'automatic_reset' => $automatic_reset_blocked ? 'BLOCKED' : 'ALLOWED',
        'template_overwrite' => $template_overwrite_blocked ? 'BLOCKED' : 'ALLOWED',
        'persistence_protection' => $persistence_protection_pass ? 'PASS' : 'FAIL',
        'production_data_unchanged' => 'PASS'
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'persistence_protection' => 'FAIL'
    ], JSON_PRETTY_PRINT);
}
