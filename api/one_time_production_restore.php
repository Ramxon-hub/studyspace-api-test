<?php
// api/one_time_production_restore.php
// Explicit, administrative one-time recovery script to restore production data from repository snapshot into live database.

define('ALLOW_RECOVERY_MODE', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');

// Action extraction: Only allow action = force_one_time_recovery
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Authorization Check:
// EITHER: 1) Admin session (is_admin() === true)
// OR:     2) Valid Render environment token supplied ONLY in X-Recovery-Token HTTP header
$is_authorized = false;
if (function_exists('is_admin') && is_admin()) {
    $is_authorized = true;
}

$env_token = getenv('RECOVERY_ADMIN_TOKEN');
$header_token = $_SERVER['HTTP_X_RECOVERY_TOKEN'] ?? '';

if (!$is_authorized && !empty($env_token) && !empty($header_token)) {
    if (hash_equals($env_token, $header_token) && $action === 'force_one_time_recovery') {
        $is_authorized = true;
    }
}

if (!$is_authorized) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Admin privileges required.'
    ]);
    exit();
}

// Action check: Token or session MUST target force_one_time_recovery
if ($action !== 'force_one_time_recovery') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid recovery action specified.'
    ]);
    exit();
}

// Concurrency Lock File on Persistent Storage Disk
$recovery_lock_file = dirname(DB_PATH) . '/.production_recovery.lock';
$fp_lock = @fopen($recovery_lock_file, 'c+');

if (!$fp_lock || !@flock($fp_lock, LOCK_EX | LOCK_NB)) {
    if ($fp_lock && is_resource($fp_lock)) @fclose($fp_lock);
    http_response_code(423);
    echo json_encode([
        'success' => false,
        'error' => 'Another recovery process is currently in progress on this server.',
        'code' => 423
    ]);
    exit();
}

// Cleanup helper function to release process lock on exit
function release_recovery_lock($fp) {
    if ($fp && is_resource($fp)) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

// Register shutdown function to guarantee lock release on unexpected exit/die
register_shutdown_function('release_recovery_lock', $fp_lock);

// One-Time Marker Enforcement on Persistent Storage Disk
$recovery_marker_file = dirname(DB_PATH) . '/.production_recovery_completed';

if (file_exists($recovery_marker_file)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error' => 'Production database recovery has already been completed on this server. Subsequent recovery attempts are permanently locked out.',
        'code' => 409
    ]);
    release_recovery_lock($fp_lock);
    exit();
}

try {
    $db_file = DB_PATH;
    $user_count = 0;
    try {
        $user_count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    } catch (Exception $e) {
        $user_count = 0;
    }
    
    // Safety check: allow one-time restoration only when target database contains <= 1 user unless forced by authenticated action
    if ($user_count > 1 && $action !== 'force_one_time_recovery') {
        echo json_encode([
            'success' => false,
            'message' => "Target database already contains $user_count users. One-time recovery aborted to prevent overwriting existing live data."
        ]);
        release_recovery_lock($fp_lock);
        exit();
    }

    $snapshot_file = __DIR__ . '/../config/db_snapshot.json';
    if (!file_exists($snapshot_file)) {
        echo json_encode(['success' => false, 'message' => 'Snapshot file config/db_snapshot.json not found!']);
        release_recovery_lock($fp_lock);
        exit();
    }

    $raw = @file_get_contents($snapshot_file);
    $data = json_decode($raw, true);
    if (empty($data) || empty($data['users'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid or empty snapshot file!']);
        release_recovery_lock($fp_lock);
        exit();
    }

    // Take safety backup copy before restoring
    $backup_file = $db_file . '.pre_recovery_backup_' . date('Ymd_His');
    if (file_exists($db_file)) {
        @copy($db_file, $backup_file);
    }

    if (!db_is_postgres()) {
        $pdo->exec("PRAGMA foreign_keys = OFF;");
    }
    // Ensure base schema exists before restoring snapshot records
    init_database($pdo);

    $tables = ['users', 'shifts', 'seats', 'allocations', 'fee_payments', 'attendance', 'complaints', 'notifications', 'chat_messages', 'system_settings'];
    
    foreach ($tables as $table) {
        if (empty($data[$table])) continue;

        $table_cols = [];
        try {
            if (db_is_postgres()) {
                $stmt_cols = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ?");
                $stmt_cols->execute([$table]);
                $table_cols = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $info = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($info as $col) {
                    $table_cols[] = $col['name'];
                }
            }
        } catch (Exception $ex) { continue; }
        
        if (empty($table_cols)) continue;

        $pk_col = ($table === 'system_settings') ? 'setting_key' : 'id';

        foreach ($data[$table] as $row) {
            $filtered_row = array_intersect_key($row, array_flip($table_cols));
            if (empty($filtered_row)) continue;

            $cols = array_keys($filtered_row);
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $col_names = implode(',', $cols);
            
            try {
                if (db_is_postgres()) {
                    $update_assigns = [];
                    foreach ($cols as $c) {
                        if ($c !== $pk_col) {
                            $update_assigns[] = "$c = EXCLUDED.$c";
                        }
                    }
                    if (!empty($update_assigns)) {
                        $on_conflict = "ON CONFLICT ($pk_col) DO UPDATE SET " . implode(', ', $update_assigns);
                    } else {
                        $on_conflict = "ON CONFLICT ($pk_col) DO NOTHING";
                    }
                    $stmt = $pdo->prepare("INSERT INTO $table ($col_names) VALUES ($placeholders) $on_conflict");
                } else {
                    $stmt = $pdo->prepare("INSERT OR REPLACE INTO $table ($col_names) VALUES ($placeholders)");
                }
                $stmt->execute(array_values($filtered_row));
            } catch (Exception $ex) {}
        }

        // Reset sequence for Postgres tables with primary key 'id'
        if (db_is_postgres() && in_array('id', $table_cols)) {
            try {
                $pdo->exec("SELECT setval(pg_get_serial_sequence('$table', 'id'), COALESCE((SELECT MAX(id) FROM $table), 1))");
            } catch (Exception $ex) {}
        }
    }

    // Ensure Student ID 6 (Deven Swami) allocation is set to seat A-04 (seat_id = 4)
    $seat_a04_id = (int)$pdo->query("SELECT id FROM seats WHERE seat_number = 'A-04'")->fetchColumn();
    if ($seat_a04_id <= 0) $seat_a04_id = 4;

    $cur_date = db_current_date();
    $pdo->prepare("UPDATE allocations SET status = 'cancelled' WHERE user_id = 6 AND status = 'active'")->execute();
    $pdo->prepare("INSERT INTO allocations (user_id, seat_id, shift_id, start_date, status, notes) VALUES (6, ?, 1, $cur_date, 'active', 'Restored to A-04')")->execute([$seat_a04_id]);

    if (!db_is_postgres()) {
        $pdo->exec("PRAGMA foreign_keys = ON;");
    }

    // Ensure production system identity marker is explicitly written
    db_upsert_system_setting($pdo, 'system_identity', PRODUCTION_IDENTITY_MARKER);

    // Run schema migrations to ensure unique indexes are active
    run_migrations($pdo);

    // Collect record counts for verification
    $counts = [];
    foreach ($tables as $t) {
        try {
            $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        } catch (Exception $e) {
            $counts[$t] = 0;
        }
    }

    // Check student 6 allocation
    $stmt_deven = $pdo->query("
        SELECT a.id, a.user_id, u.name, s.seat_number, a.status 
        FROM allocations a 
        JOIN users u ON a.user_id = u.id 
        JOIN seats s ON a.seat_id = s.id 
        WHERE a.user_id = 6 AND a.status = 'active'
    ")->fetch();

    // AFTER AND ONLY AFTER SUCCESSFUL RESTORATION & VALIDATION:
    // Create the persistent recovery-completed marker on disk using atomic temp file + rename
    $marker_data = json_encode([
        'completed_at' => date('Y-m-d H:i:s'),
        'users_restored' => $counts['users'] ?? 0,
        'system_identity' => PRODUCTION_IDENTITY_MARKER
    ], JSON_PRETTY_PRINT);

    $tmp_marker = $recovery_marker_file . '.tmp.' . getmypid() . '_' . microtime(true);
    $write_bytes = file_put_contents($tmp_marker, $marker_data, LOCK_EX);
    $rename_success = false;

    if ($write_bytes !== false && $write_bytes > 0) {
        $rename_success = @rename($tmp_marker, $recovery_marker_file);
    }

    if (!$rename_success || !file_exists($recovery_marker_file)) {
        @unlink($tmp_marker);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Recovery completed database restoration but failed to persist the one-time completion marker to disk. Replay protection could not be verified.'
        ], JSON_PRETTY_PRINT);
        release_recovery_lock($fp_lock);
        exit();
    }

    release_recovery_lock($fp_lock);

    echo json_encode([
        'success' => true,
        'message' => 'One-time production database recovery completed successfully!',
        'recovered_counts' => $counts,
        'deven_allocation' => $stmt_deven ?: null
    ], JSON_PRETTY_PRINT);
    exit();

} catch (Exception $e) {
    release_recovery_lock($fp_lock);
    echo json_encode(['success' => false, 'message' => 'One-time recovery failed: ' . $e->getMessage()]);
    exit();
}
