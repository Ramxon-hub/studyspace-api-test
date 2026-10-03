<?php
// config/db.php - Database connection, non-destructive migrations, and production data safety
require_once __DIR__ . '/env.php';

$db_driver = defined('DB_DRIVER') ? DB_DRIVER : 'sqlite';
$db_file = DB_PATH;
$db_dir = dirname($db_file);

// Cross-Database Helper Functions for Dialect Abstraction
function db_driver() {
    return defined('DB_DRIVER') ? DB_DRIVER : 'sqlite';
}

function db_is_sqlite() {
    return db_driver() === 'sqlite';
}

function db_is_postgres() {
    return db_driver() === 'postgres';
}

function db_is_mysql() {
    return db_driver() === 'mysql';
}

function db_get_driver($pdo = null) {
    if ($pdo instanceof PDO) {
        return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return strtolower((string)$GLOBALS['pdo']->getAttribute(PDO::ATTR_DRIVER_NAME));
    }
    return strtolower(db_driver());
}

function db_now_sub_days($days, $pdo = null) {
    $days = (int)$days;
    $driver = db_get_driver($pdo);
    if ($driver === 'pgsql' || $driver === 'postgres') {
        return "NOW() - INTERVAL '$days days'";
    } elseif ($driver === 'mysql') {
        return "DATE_SUB(NOW(), INTERVAL $days DAY)";
    }
    return "DATETIME('now', '-$days days')";
}

function db_now_sub_hours($hours, $pdo = null) {
    $hours = (int)$hours;
    $driver = db_get_driver($pdo);
    if ($driver === 'pgsql' || $driver === 'postgres') {
        return "NOW() - INTERVAL '$hours hours'";
    } elseif ($driver === 'mysql') {
        return "DATE_SUB(NOW(), INTERVAL $hours HOUR)";
    }
    return "DATETIME('now', '-$hours hours')";
}

function db_current_date($pdo = null) {
    $driver = db_get_driver($pdo);
    if ($driver === 'pgsql' || $driver === 'postgres' || $driver === 'mysql') {
        return "CURRENT_DATE";
    }
    return "DATE('now')";
}

function db_days_left_expression($deleted_at_col = 'u.deleted_at', $pdo = null) {
    $driver = db_get_driver($pdo);
    if ($driver === 'pgsql' || $driver === 'postgres') {
        return "GREATEST(0, CAST(60 - EXTRACT(DAY FROM (NOW() - $deleted_at_col)) AS INTEGER))";
    } elseif ($driver === 'mysql') {
        return "GREATEST(0, CAST(60 - DATEDIFF(NOW(), $deleted_at_col) AS SIGNED))";
    }
    return "MAX(0, CAST(60 - (julianday(DATE('now')) - julianday(DATE($deleted_at_col))) AS INTEGER))";
}

function db_upsert_system_setting($pdo, $key, $value) {
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'pgsql' || $driver === 'postgres') {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value
        ");
        return $stmt->execute([$key, $value]);
    } elseif ($driver === 'mysql') {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        return $stmt->execute([$key, $value]);
    } else {
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        return $stmt->execute([$key, $value]);
    }
}

function db_insert_ignore_setting($pdo, $key, $value) {
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'pgsql' || $driver === 'postgres') {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON CONFLICT (setting_key) DO NOTHING
        ");
        return $stmt->execute([$key, $value]);
    } elseif ($driver === 'mysql') {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO system_settings (setting_key, setting_value)
            VALUES (?, ?)
        ");
        return $stmt->execute([$key, $value]);
    } else {
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        return $stmt->execute([$key, $value]);
    }
}

// Multi-tenant database connection management is handled dynamically by TenantDatabaseFactory in tenant_router.php

function verify_production_db_identity($pdo) {
    if (defined('ALLOW_RECOVERY_MODE') && ALLOW_RECOVERY_MODE === true) {
        return true;
    }
    if (!defined('APP_ENV') || APP_ENV !== 'production') {
        return true;
    }

    $identity_valid = false;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_identity'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val === PRODUCTION_IDENTITY_MARKER) {
            $identity_valid = true;
        }
    } catch (Exception $e) {
        $identity_valid = false;
    }

    $is_fresh_pattern = false;
    try {
        $u_cnt = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $st_cnt = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
        $se_cnt = (int)$pdo->query("SELECT COUNT(*) FROM seats")->fetchColumn();
        if ($u_cnt <= 1 && $st_cnt == 0 && $se_cnt == 40) {
            $is_fresh_pattern = true;
        }
    } catch (Exception $e) {
        $is_fresh_pattern = true;
    }

    if (!$identity_valid || $is_fresh_pattern) {
        http_response_code(503);
        header('Content-Type: application/json');
        die(json_encode([
            'success' => false,
            'error' => 'Production database identity mismatch. Service temporarily unavailable.',
            'db_path' => DB_PATH
        ]));
    }

    return true;
}

function get_db_identity_summary($pdo) {
    $db_file = DB_PATH;
    $exists = file_exists($db_file);
    $size = $exists ? filesize($db_file) : 0;
    
    $users_count = 0;
    $students_count = 0;
    $active_students = 0;
    $allocations_count = 0;
    $schema_ver = 0;
    $system_identity = null;

    if ($exists) {
        try {
            $users_count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $students_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
            $active_students = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND (is_deleted IS NULL OR is_deleted = 0)")->fetchColumn();
            $allocations_count = (int)$pdo->query("SELECT COUNT(*) FROM allocations WHERE status = 'active'")->fetchColumn();
            $schema_ver = (int)$pdo->query("SELECT MAX(version) FROM schema_migrations")->fetchColumn();
            $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'system_identity'");
            if ($stmt) {
                $system_identity = $stmt->fetchColumn() ?: null;
            }
        } catch (Exception $e) {}
    }

    return [
        'db_path' => $db_file,
        'exists' => $exists,
        'file_size_bytes' => $size,
        'file_size_formatted' => round($size / 1024, 2) . " KB",
        'schema_version' => $schema_ver,
        'system_identity' => $system_identity,
        'users_count' => $users_count,
        'students_count' => $students_count,
        'active_students' => $active_students,
        'active_allocations' => $allocations_count
    ];
}

function save_db_snapshot($pdo) {
    if (defined('APP_ENV') && APP_ENV === 'production') {
        return; // Production mode strictly prevents modifying dev snapshot file
    }
    try {
        $snapshot_file = __DIR__ . '/db_snapshot.json';
        $user_count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if (file_exists($snapshot_file)) {
            $existing_raw = @file_get_contents($snapshot_file);
            $existing_data = json_decode($existing_raw, true);
            $existing_user_count = !empty($existing_data['users']) ? count($existing_data['users']) : 0;
            if ($user_count == 0 && $existing_user_count > 0) {
                return; // Prevent saving an empty/corrupted user snapshot
            }
        }
        $tables = ['users', 'shifts', 'seats', 'allocations', 'fee_payments', 'attendance', 'complaints', 'notifications', 'chat_messages', 'system_settings'];
        $data = [];
        foreach ($tables as $t) {
            try {
                $data[$t] = $pdo->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $data[$t] = [];
            }
        }
        @file_put_contents($snapshot_file, json_encode($data, JSON_PRETTY_PRINT));
    } catch (Exception $e) {}
}

register_shutdown_function(function() {
    if (defined('APP_ENV') && APP_ENV === 'development' && isset($_SERVER['REQUEST_METHOD']) && in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE']) && isset($GLOBALS['pdo'])) {
        save_db_snapshot($GLOBALS['pdo']);
    }
});

function restore_db_snapshot($pdo) {
    if (defined('APP_ENV') && APP_ENV === 'production') {
        return false; // Production mode strictly forbids restoring snapshot JSON over live DB
    }
    $snapshot_file = __DIR__ . '/db_snapshot.json';
    if (!file_exists($snapshot_file)) return false;
    
    $raw = @file_get_contents($snapshot_file);
    if (empty($raw)) return false;
    
    $data = json_decode($raw, true);
    if (empty($data) || empty($data['users'])) return false;

    try {
        if (db_is_sqlite()) {
            $pdo->exec("PRAGMA foreign_keys = OFF;");
        } elseif (db_is_mysql()) {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        }
        foreach ($data as $table => $rows) {
            if (empty($rows)) continue;
            
            $table_cols = [];
            try {
                if (db_is_postgres() || db_is_mysql()) {
                    $stmt_col = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ?");
                    $stmt_col->execute([$table]);
                    $table_cols = $stmt_col->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $info = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($info as $col) {
                        $table_cols[] = $col['name'];
                    }
                }
            } catch (Exception $ex) { continue; }
            
            if (empty($table_cols)) continue;

            foreach ($rows as $row) {
                $filtered_row = array_intersect_key($row, array_flip($table_cols));
                if (empty($filtered_row)) continue;

                $cols = array_keys($filtered_row);
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $col_names = implode(',', $cols);
                
                try {
                    if (db_is_postgres()) {
                        $stmt = $pdo->prepare("INSERT INTO $table ($col_names) VALUES ($placeholders) ON CONFLICT DO NOTHING");
                    } elseif (db_is_mysql()) {
                        $stmt = $pdo->prepare("INSERT IGNORE INTO $table ($col_names) VALUES ($placeholders)");
                    } else {
                        $stmt = $pdo->prepare("INSERT OR IGNORE INTO $table ($col_names) VALUES ($placeholders)");
                    }
                    $stmt->execute(array_values($filtered_row));
                } catch (Exception $ex) {}
            }
        }
        if (db_is_sqlite()) {
            $pdo->exec("PRAGMA foreign_keys = ON;");
        } elseif (db_is_mysql()) {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Function to initialize database tables and seed data if empty
function init_database($pdo) {
    if (db_is_postgres()) {
        $pk_type = "SERIAL PRIMARY KEY";
        $real_type = "NUMERIC(10,2)";
        $dt_type = "TIMESTAMP";
    } elseif (db_is_mysql()) {
        $pk_type = "INT AUTO_INCREMENT PRIMARY KEY";
        $real_type = "DECIMAL(10,2)";
        $dt_type = "DATETIME";
    } else {
        $pk_type = "INTEGER PRIMARY KEY AUTOINCREMENT";
        $real_type = "REAL";
        $dt_type = "DATETIME";
    }

    // 1. Users table (Admin & Students)
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id $pk_type,
        name TEXT NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        phone TEXT NOT NULL,
        password TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'student',
        emergency_contact TEXT,
        id_proof_type TEXT,
        id_proof_no TEXT,
        status TEXT NOT NULL DEFAULT 'pending',
        otp_code TEXT,
        otp_expires_at $dt_type,
        registered_device_id TEXT,
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP
    )");

    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN otp_code TEXT");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN otp_expires_at $dt_type");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN registered_device_id TEXT");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN father_name TEXT");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN address TEXT");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_deleted INTEGER DEFAULT 0");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN deleted_at $dt_type");
    } catch (Exception $e) {}

    // Auto-purge items in Recycle Bin older than 60 days
    try {
        $sub_60_days = db_now_sub_days(60);
        $old_ids = $pdo->query("SELECT id FROM users WHERE role = 'student' AND is_deleted = 1 AND deleted_at < $sub_60_days")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($old_ids)) {
            $in_clause = implode(',', array_fill(0, count($old_ids), '?'));
            $pdo->prepare("DELETE FROM fee_payments WHERE user_id IN ($in_clause)")->execute($old_ids);
            $pdo->prepare("DELETE FROM attendance WHERE user_id IN ($in_clause)")->execute($old_ids);
            $pdo->prepare("DELETE FROM allocations WHERE user_id IN ($in_clause)")->execute($old_ids);
            $pdo->prepare("DELETE FROM complaints WHERE user_id IN ($in_clause)")->execute($old_ids);
            $pdo->prepare("DELETE FROM notifications WHERE user_id IN ($in_clause)")->execute($old_ids);
            $pdo->prepare("DELETE FROM users WHERE id IN ($in_clause)")->execute($old_ids);
        }
    } catch (Exception $e) {}
    // Auto-purge Help Complaints/Tickets older than 1 month (30 days)
    try {
        $pdo->exec("DELETE FROM complaints WHERE created_at < " . db_now_sub_days(30));
    } catch (Exception $e) {}

    // 2. Shifts table
    $pdo->exec("CREATE TABLE IF NOT EXISTS shifts (
        id $pk_type,
        name TEXT NOT NULL,
        start_time TEXT NOT NULL,
        end_time TEXT NOT NULL,
        fee_amount $real_type NOT NULL,
        is_active INTEGER DEFAULT 1
    )");

    // 3. Seats table (Desks)
    $pdo->exec("CREATE TABLE IF NOT EXISTS seats (
        id $pk_type,
        seat_number VARCHAR(50) UNIQUE NOT NULL,
        row_label TEXT NOT NULL DEFAULT 'A',
        is_active INTEGER DEFAULT 1,
        remarks TEXT
    )");

    // 4. Allocations table
    $pdo->exec("CREATE TABLE IF NOT EXISTS allocations (
        id $pk_type,
        user_id INTEGER NOT NULL,
        seat_id INTEGER NOT NULL,
        shift_id INTEGER NOT NULL,
        start_date DATE NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        notes TEXT,
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (seat_id) REFERENCES seats(id) ON DELETE CASCADE,
        FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE
    )");

    // 5. Fee Payments table
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_payments (
        id $pk_type,
        allocation_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        month_year TEXT NOT NULL,
        amount $real_type NOT NULL,
        due_date DATE NOT NULL,
        paid_date DATE,
        payment_status TEXT NOT NULL DEFAULT 'pending',
        payment_mode TEXT,
        receipt_no VARCHAR(100) UNIQUE,
        remarks TEXT,
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (allocation_id) REFERENCES allocations(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // 6. Attendance table
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
        id $pk_type,
        user_id INTEGER NOT NULL,
        date DATE NOT NULL,
        check_in_time TEXT,
        check_out_time TEXT,
        status TEXT DEFAULT 'present',
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // 7. Complaints table
    $pdo->exec("CREATE TABLE IF NOT EXISTS complaints (
        id $pk_type,
        user_id INTEGER NOT NULL,
        category TEXT NOT NULL,
        subject TEXT NOT NULL,
        description TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'open',
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // 8. Notifications table
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id $pk_type,
        user_id INTEGER DEFAULT 0,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        is_read INTEGER DEFAULT 0,
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP
    )");

    // 9. Direct Chat Messages table
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id $pk_type,
        sender_id INTEGER NOT NULL,
        receiver_id INTEGER NOT NULL,
        message TEXT NOT NULL,
        is_read INTEGER DEFAULT 0,
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // 10. System Settings table (Dynamic App Name & App Logo)
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT
    )");

    db_insert_ignore_setting($pdo, 'app_name', 'Self Study Library');
    db_insert_ignore_setting($pdo, 'app_logo_url', '');
    db_insert_ignore_setting($pdo, 'app_tagline', 'Quiet Environment & High-Speed Wi-Fi');
    db_insert_ignore_setting($pdo, 'system_identity', PRODUCTION_IDENTITY_MARKER);

    // 11. Parent-Student Link Table for Parent Portal
    $pdo->exec("CREATE TABLE IF NOT EXISTS parent_student_links (
        id $pk_type,
        parent_user_id INTEGER NOT NULL,
        student_user_id INTEGER NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        updated_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(parent_user_id, student_user_id),
        FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // HIGH PERFORMANCE INDEXES FOR 100+ CONCURRENT USERS
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_phone ON users(phone)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_parent_student_parent ON parent_student_links(parent_user_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_parent_student_student ON parent_student_links(student_user_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_role_status ON users(role, status, is_deleted)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_allocations_user_status ON allocations(user_id, status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_allocations_seat_shift ON allocations(seat_id, shift_id, status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_attendance_user_date ON attendance(user_id, date)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fee_payments_alloc_month ON fee_payments(allocation_id, month_year)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fee_payments_user_month ON fee_payments(user_id, month_year)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_chat_messages_participants ON chat_messages(sender_id, receiver_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id, is_read)");
    } catch (Exception $e) {}

    // SEED INITIAL SHIFTS ACCORDING TO USER'S TIMING SPECIFICATION
    $shift_count = $pdo->query("SELECT COUNT(*) FROM shifts")->fetchColumn();
    if ($shift_count == 0) {
        $stmt = $pdo->prepare("INSERT INTO shifts (name, start_time, end_time, fee_amount) VALUES (?, ?, ?, ?)");
        $stmt->execute(['Morning Half Day Shift', '08:00', '14:00', 600.00]);
        $stmt->execute(['Afternoon / Evening Shift', '14:00', '21:00', 600.00]);
        $stmt->execute(['Full Day', '08:00', '22:00', 700.00]);
        $stmt->execute(['Full Day (24 Hours)', '00:00', '23:59', 1200.00]);
    }

    // Seed Seats
    $seat_count = $pdo->query("SELECT COUNT(*) FROM seats")->fetchColumn();
    if ($seat_count == 0) {
        $stmt = $pdo->prepare("INSERT INTO seats (seat_number, row_label, remarks) VALUES (?, ?, ?)");
        $rows = ['A', 'B', 'C', 'D'];
        foreach ($rows as $row) {
            for ($i = 1; $i <= 10; $i++) {
                $seat_num = sprintf("%s-%02d", $row, $i);
                $stmt->execute([$seat_num, $row, "Standard Ergonomic Desk with Charging Socket"]);
            }
        }
    }

    // Seed Admin User ONLY on fresh database where no users exist
    $user_total = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($user_total == 0) {
        if (defined('APP_ENV') && APP_ENV === 'deplexo_test') {
            $admin_pass = password_hash('testadmin123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, status) VALUES (?, ?, ?, ?, 'admin', 'approved')");
            $stmt->execute(['Deplexo Test Admin', 'test_admin@deplexo.com', '9999900000', $admin_pass]);
        } else {
            $admin_pass = password_hash('admin2003', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, status) VALUES (?, ?, ?, ?, 'admin', 'approved')");
            $stmt->execute(['Library Owner Admin', 'admin@library.com', '9876543210', $admin_pass]);
        }
    }

    // Ensure primary key IDs are populated for SQLite rowid compatibility
    try {
        $pdo->exec("UPDATE users SET id = rowid WHERE id IS NULL");
        $pdo->exec("UPDATE seats SET id = rowid WHERE id IS NULL");
        $pdo->exec("UPDATE shifts SET id = rowid WHERE id IS NULL");
    } catch (Exception $e) {}

    // Ensure extra user table columns exist without altering existing business data
    $alter_cols = [
        'otp_expires_at' => $dt_type,
        'registered_device_id' => 'TEXT',
        'father_name' => 'TEXT',
        'address' => 'TEXT',
        'is_deleted' => 'INTEGER DEFAULT 0',
        'deleted_at' => $dt_type,
        'preparation_for' => 'TEXT'
    ];
    foreach ($alter_cols as $c_name => $c_type) {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN $c_name $c_type"); } catch (Exception $e) {}
    }
    try { $pdo->exec("UPDATE users SET is_deleted = 0 WHERE is_deleted IS NULL"); } catch (Exception $e) {}

    // Auto-purge chat_messages and notifications strictly older than 48 hours
    try {
        $sub_48 = db_now_sub_hours(48);
        $pdo->exec("DELETE FROM chat_messages WHERE created_at IS NOT NULL AND created_at != '' AND created_at < $sub_48");
        $pdo->exec("DELETE FROM notifications WHERE created_at IS NOT NULL AND created_at != '' AND created_at < $sub_48");
    } catch (Exception $e) {}
}

function run_migrations($pdo) {
    $pk_type = db_is_postgres() ? "SERIAL PRIMARY KEY" : "INTEGER PRIMARY KEY AUTOINCREMENT";
    $dt_type = db_is_postgres() ? "TIMESTAMP" : "DATETIME";

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            version INTEGER PRIMARY KEY,
            migration_name TEXT NOT NULL,
            applied_at $dt_type DEFAULT CURRENT_TIMESTAMP
        )");

        $applied = $pdo->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
        if ($applied === false) $applied = [];

        $migrations = [
            1 => [
                'name' => 'add_user_extra_fields',
                'sql' => function($pdo) {
                    try { $pdo->exec("ALTER TABLE users ADD COLUMN father_name TEXT"); } catch(Exception $e){}
                    try { $pdo->exec("ALTER TABLE users ADD COLUMN address TEXT"); } catch(Exception $e){}
                    try { $pdo->exec("ALTER TABLE users ADD COLUMN is_deleted INTEGER DEFAULT 0"); } catch(Exception $e){}
                    try { $pdo->exec("ALTER TABLE users ADD COLUMN deleted_at DATETIME"); } catch(Exception $e){}
                }
            ],
            2 => [
                'name' => 'ensure_shifts_migration',
                'sql' => function($pdo) {
                    try {
                        $pdo->exec("UPDATE shifts SET name = 'Full Day', start_time = '08:00', end_time = '22:00' WHERE id = 3 AND name LIKE '%24 Hours%'");
                        $stmt_check4 = $pdo->query("SELECT id FROM shifts WHERE id = 4")->fetch();
                        if (!$stmt_check4) {
                            $pdo->exec("INSERT INTO shifts (id, name, start_time, end_time, fee_amount, is_active) VALUES (4, 'Full Day (24 Hours)', '00:00', '23:59', 1200.00, 0)");
                        }
                    } catch (Exception $e) {}
                }
            ],
            3 => [
                'name' => 'enforce_single_active_allocation_constraints',
                'sql' => function($pdo) {
                    try {
                        // De-duplicate any active allocations so that only the latest active allocation per user remains active
                        $dups = $pdo->query("
                            SELECT user_id, MAX(id) as keep_id 
                            FROM allocations 
                            WHERE status = 'active' 
                            GROUP BY user_id 
                            HAVING COUNT(*) > 1
                        ")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($dups as $d) {
                            $stmt = $pdo->prepare("UPDATE allocations SET status = 'cancelled' WHERE user_id = ? AND status = 'active' AND id != ?");
                            $stmt->execute([$d['user_id'], $d['keep_id']]);
                        }
                        // Create partial unique index on allocations: maximum 1 active allocation per student
                        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_allocations_active_user ON allocations(user_id) WHERE status = 'active'");
                        // Create partial unique index on allocations: maximum 1 active student per seat desk per shift
                        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_allocations_active_seat_shift ON allocations(seat_id, shift_id) WHERE status = 'active'");
                    } catch (Exception $e) {}
                }
            ],
            4 => [
                'name' => 'normalize_is_deleted_nulls',
                'sql' => function($pdo) {
                    try {
                        $pdo->exec("UPDATE users SET is_deleted = 0 WHERE is_deleted IS NULL");
                    } catch (Exception $e) {}
                }
            ],
            5 => [
                'name' => 'add_preparation_for_column',
                'sql' => function($pdo) {
                    try {
                        $pdo->exec("ALTER TABLE users ADD COLUMN preparation_for TEXT");
                    } catch (Exception $e) {}
                }
            ],
            6 => [
                'name' => 'add_production_system_identity',
                'sql' => function($pdo) {
                    try {
                        $key_type = (db_is_postgres() || db_is_mysql()) ? "VARCHAR(100) PRIMARY KEY" : "TEXT PRIMARY KEY";
                        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (setting_key $key_type, setting_value TEXT)");
                        db_insert_ignore_setting($pdo, 'system_identity', PRODUCTION_IDENTITY_MARKER);
                    } catch (Exception $e) {}
                }
            ],
            7 => [
                'name' => 'add_parent_portal_support',
                'sql' => function($pdo) {
                    try {
                        $pk_type = db_is_postgres() ? "SERIAL PRIMARY KEY" : (db_is_mysql() ? "INTEGER AUTO_INCREMENT PRIMARY KEY" : "INTEGER PRIMARY KEY AUTOINCREMENT");
                        $dt_type = db_is_postgres() ? "TIMESTAMP" : "DATETIME";
                        
                        $pdo->exec("CREATE TABLE IF NOT EXISTS parent_student_links (
                            id $pk_type,
                            parent_user_id INTEGER NOT NULL,
                            student_user_id INTEGER NOT NULL,
                            status VARCHAR(20) NOT NULL DEFAULT 'active',
                            created_at $dt_type DEFAULT CURRENT_TIMESTAMP,
                            updated_at $dt_type DEFAULT CURRENT_TIMESTAMP,
                            UNIQUE(parent_user_id, student_user_id),
                            FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
                            FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE
                        )");

                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_parent_student_parent ON parent_student_links(parent_user_id)");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_parent_student_student ON parent_student_links(student_user_id)");
                    } catch (Exception $e) {}
                }
            ]
        ];

        foreach ($migrations as $ver => $m) {
            if (!in_array($ver, $applied) && !in_array((string)$ver, $applied, true)) {
                try {
                    $m['sql']($pdo);
                } catch (Exception $ex) {}
                try {
                    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
                    if ($driver === 'pgsql' || $driver === 'postgres') {
                        $stmt = $pdo->prepare("INSERT INTO schema_migrations (version, migration_name) VALUES (?, ?) ON CONFLICT (version) DO NOTHING");
                    } elseif ($driver === 'mysql') {
                        $stmt = $pdo->prepare("INSERT IGNORE INTO schema_migrations (version, migration_name) VALUES (?, ?)");
                    } else {
                        $stmt = $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (version, migration_name) VALUES (?, ?)");
                    }
                    $stmt->execute([$ver, $m['name']]);
                } catch (Exception $ex) {}
            }
        }
    } catch (Exception $e) {}
}

require_once __DIR__ . '/master_db.php';
require_once __DIR__ . '/tenant_router.php';

// Resolve active tenant database connection handle dynamically via TenantDatabaseFactory
$tenant_context = resolve_tenant_context();
$pdo = $tenant_context['pdo'];
$current_tenant_code = $tenant_context['library_code'];
?>
