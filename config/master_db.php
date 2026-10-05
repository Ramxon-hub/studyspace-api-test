<?php
// config/master_db.php - Central Master Control Database Connection & Schema Management

require_once __DIR__ . '/env.php';

function get_master_db_path() {
    if (defined('MASTER_DB_PATH')) {
        return MASTER_DB_PATH;
    }
    if (file_exists('/data/studyspace_master.sqlite')) {
        return '/data/studyspace_master.sqlite';
    }
    if (defined('APP_ENV') && APP_ENV === 'deplexo_test') {
        $dir = '/data';
        if (!file_exists($dir)) {
            @mkdir($dir, 0777, true);
        }
        return '/data/studyspace_master_test.sqlite';
    }
    if (is_dir('/data')) {
        return '/data/studyspace_master.sqlite';
    }
    $dir = __DIR__ . '/../data';
    if (!file_exists($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . '/studyspace_master.sqlite';
}

function get_master_pdo() {
    static $master_pdo = null;
    if ($master_pdo !== null) {
        return $master_pdo;
    }

    $db_driver = defined('DB_DRIVER') ? DB_DRIVER : 'sqlite';

    if ($db_driver === 'mysql' && defined('MASTER_DB_NAME')) {
        $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
        $port = defined('DB_PORT') ? DB_PORT : 3306;
        $dbname = MASTER_DB_NAME;
        $user = defined('DB_USER') ? DB_USER : 'root';
        $pass = defined('DB_PASSWORD') ? DB_PASSWORD : '';
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

        $master_pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } else {
        $master_db_file = get_master_db_path();
        $master_pdo = new PDO("sqlite:" . $master_db_file);
        $master_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $master_pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $master_pdo->exec("PRAGMA foreign_keys = ON;");
        $master_pdo->exec("PRAGMA journal_mode = WAL;");
        $master_pdo->exec("PRAGMA busy_timeout = 10000;");
    }

    init_master_database($master_pdo);
    return $master_pdo;
}

function init_master_database($master_pdo) {
    static $initialized = false;
    if ($initialized) return;
    $initialized = true;

    $driver = strtolower($master_pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $is_mysql = ($driver === 'mysql');
    $pk_type = $is_mysql ? "INT AUTO_INCREMENT PRIMARY KEY" : "INTEGER PRIMARY KEY AUTOINCREMENT";
    $dt_type = $is_mysql ? "DATETIME" : "DATETIME";

    // 1. Libraries Table
    $master_pdo->exec("CREATE TABLE IF NOT EXISTS libraries (
        id $pk_type,
        library_code VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(255) NOT NULL,
        contact_person VARCHAR(255),
        phone VARCHAR(50),
        email VARCHAR(255),
        address TEXT,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        provisioning_status VARCHAR(50) NOT NULL DEFAULT 'READY',
        provisioning_error TEXT,
        db_connection_status VARCHAR(50) NOT NULL DEFAULT 'CONNECTED',
        schema_version VARCHAR(50) DEFAULT '1.0.0',
        last_connection_check $dt_type,
        last_provisioning_attempt $dt_type,
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP
    )");

    // Ensure columns exist if table was already created in earlier phases
    $existing_cols = [];
    if (!$is_mysql) {
        $cols = $master_pdo->query("PRAGMA table_info(libraries)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) { $existing_cols[] = $c['name']; }
    } else {
        $cols = $master_pdo->query("SHOW COLUMNS FROM libraries")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) { $existing_cols[] = $c['Field']; }
    }

    $add_col = function($col, $type) use ($master_pdo, $existing_cols) {
        if (!in_array($col, $existing_cols)) {
            try { $master_pdo->exec("ALTER TABLE libraries ADD COLUMN $col $type"); } catch (Exception $e) {}
        }
    };

    $add_col('contact_person', 'VARCHAR(255)');
    $add_col('phone', 'VARCHAR(50)');
    $add_col('email', 'VARCHAR(255)');
    $add_col('address', 'TEXT');
    $add_col('provisioning_status', "VARCHAR(50) DEFAULT 'READY'");
    $add_col('provisioning_error', 'TEXT');
    $add_col('db_connection_status', "VARCHAR(50) DEFAULT 'CONNECTED'");
    $add_col('schema_version', "VARCHAR(50) DEFAULT '1.0.0'");
    $add_col('last_connection_check', $dt_type);
    $add_col('last_provisioning_attempt', $dt_type);

    // 2. Tenant Database Configs Table
    $master_pdo->exec("CREATE TABLE IF NOT EXISTS tenant_db_configs (
        id $pk_type,
        library_code VARCHAR(50) NOT NULL UNIQUE,
        db_driver VARCHAR(20) NOT NULL DEFAULT 'sqlite',
        db_host VARCHAR(255),
        db_port INT,
        db_name VARCHAR(255),
        db_user VARCHAR(255),
        db_pass VARCHAR(255),
        db_file VARCHAR(550),
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (library_code) REFERENCES libraries(library_code) ON DELETE CASCADE
    )");

    // 3. Subscriptions Table
    $master_pdo->exec("CREATE TABLE IF NOT EXISTS subscriptions (
        id $pk_type,
        library_code VARCHAR(50) NOT NULL,
        plan_name VARCHAR(100) NOT NULL DEFAULT 'Standard',
        max_students INT NOT NULL DEFAULT 100,
        valid_until DATE NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (library_code) REFERENCES libraries(library_code) ON DELETE CASCADE
    )");

    // 4. Library Branding Table
    $master_pdo->exec("CREATE TABLE IF NOT EXISTS library_branding (
        id $pk_type,
        library_code VARCHAR(50) NOT NULL UNIQUE,
        logo_url TEXT,
        primary_color VARCHAR(20) DEFAULT '#1D4ED8',
        contact_phone VARCHAR(50),
        address TEXT,
        tagline TEXT,
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (library_code) REFERENCES libraries(library_code) ON DELETE CASCADE
    )");

    // 5. Super Admins Table
    $master_pdo->exec("CREATE TABLE IF NOT EXISTS super_admins (
        id $pk_type,
        username VARCHAR(100) NOT NULL UNIQUE,
        email VARCHAR(255) NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP
    )");

    // 6. Subscription Plans Table
    $master_pdo->exec("CREATE TABLE IF NOT EXISTS plans (
        id $pk_type,
        plan_id VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        duration_days INT NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        max_students INT NOT NULL DEFAULT 100,
        description TEXT,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP
    )");

    // 7. Super Admin Audit Logs Table
    $master_pdo->exec("CREATE TABLE IF NOT EXISTS super_admin_audit_logs (
        id $pk_type,
        action VARCHAR(100) NOT NULL,
        super_admin VARCHAR(100) NOT NULL DEFAULT 'superadmin',
        library_code VARCHAR(50),
        result_status VARCHAR(50) NOT NULL DEFAULT 'SUCCESS',
        metadata TEXT,
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP
    )");

    // 8. Commercial Invoices & Payment Records Table
    $master_pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
        id $pk_type,
        invoice_number VARCHAR(100) NOT NULL UNIQUE,
        library_code VARCHAR(50) NOT NULL,
        plan_id VARCHAR(50) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        currency VARCHAR(10) DEFAULT 'INR',
        invoice_date DATE NOT NULL,
        due_date DATE NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
        payment_reference VARCHAR(255),
        payment_method VARCHAR(100),
        paid_at $dt_type,
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (library_code) REFERENCES libraries(library_code) ON DELETE CASCADE
    )");

    // 9. Manual Payments Table (Phase 13 Manual Billing Workflow)
    $master_pdo->exec("CREATE TABLE IF NOT EXISTS manual_payments (
        id $pk_type,
        payment_id VARCHAR(100) NOT NULL UNIQUE,
        invoice_id INT NOT NULL,
        library_code VARCHAR(50) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        currency VARCHAR(10) DEFAULT 'INR',
        payment_method VARCHAR(50) NOT NULL,
        payment_reference VARCHAR(255),
        payment_date DATE NOT NULL,
        notes TEXT,
        proof_reference TEXT,
        received_by_super_admin VARCHAR(100) DEFAULT 'superadmin',
        status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
        rejection_reason TEXT,
        verified_at $dt_type,
        gateway VARCHAR(50) DEFAULT NULL,
        gateway_order_id VARCHAR(100) DEFAULT NULL,
        gateway_payment_id VARCHAR(100) DEFAULT NULL,
        gateway_transaction_id VARCHAR(100) DEFAULT NULL,
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        updated_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (library_code) REFERENCES libraries(library_code) ON DELETE CASCADE,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
    )");

    // 10. Subscription Renewal Requests Table (Phase 14 Customer Journey)
    $master_pdo->exec("CREATE TABLE IF NOT EXISTS subscription_renewal_requests (
        id $pk_type,
        request_id VARCHAR(100) NOT NULL UNIQUE,
        library_code VARCHAR(50) NOT NULL,
        current_subscription_id INT,
        requested_plan VARCHAR(50) NOT NULL DEFAULT 'YEARLY',
        requested_by VARCHAR(100) DEFAULT 'admin',
        status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
        admin_note TEXT,
        processed_at $dt_type,
        processed_by VARCHAR(100),
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        updated_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (library_code) REFERENCES libraries(library_code) ON DELETE CASCADE
    )");

    // 11. Support Settings Table (Phase 14 Configurable Contact Support)
    $master_pdo->exec("CREATE TABLE IF NOT EXISTS support_settings (
        id $pk_type,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT,
        updated_at $dt_type DEFAULT CURRENT_TIMESTAMP
    )");

    // 12. Backup Metadata Table (Phase 15 Production Hardening & Disaster Recovery)
    $master_pdo->exec("CREATE TABLE IF NOT EXISTS backup_metadata (
        id $pk_type,
        backup_id VARCHAR(100) NOT NULL UNIQUE,
        scope VARCHAR(50) NOT NULL,
        library_code VARCHAR(50),
        file_name VARCHAR(255) NOT NULL,
        file_path TEXT NOT NULL,
        file_size INT NOT NULL DEFAULT 0,
        checksum VARCHAR(64) NOT NULL,
        schema_version VARCHAR(20) DEFAULT 'v7',
        status VARCHAR(30) NOT NULL DEFAULT 'CREATED',
        verified_at $dt_type,
        error_message TEXT,
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        updated_at $dt_type DEFAULT CURRENT_TIMESTAMP
    )");

    // 13. APK Build Metadata Table (Phase 42 True Library-Isolated Architecture)
    $master_pdo->exec("CREATE TABLE IF NOT EXISTS apk_build_metadata (
        id $pk_type,
        build_id VARCHAR(100) NOT NULL UNIQUE,
        library_code VARCHAR(50) NOT NULL,
        flavor_name VARCHAR(100) NOT NULL,
        package_id VARCHAR(255) NOT NULL,
        version_name VARCHAR(50) DEFAULT '1.0.0',
        build_number INT DEFAULT 1,
        artifact_path TEXT,
        sha256_hash VARCHAR(64),
        status VARCHAR(30) NOT NULL DEFAULT 'CONFIGURED',
        created_at $dt_type DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (library_code) REFERENCES libraries(library_code) ON DELETE CASCADE
    )");

    // Seed Default Support Settings if empty
    $supp_count = (int)$master_pdo->query("SELECT COUNT(*) FROM support_settings")->fetchColumn();
    if ($supp_count === 0) {
        $default_support = [
            'support_name' => 'StudySpace SaaS Billing & Support',
            'support_email' => 'support@studyspace.com',
            'support_phone' => '+91 98765 43210',
            'support_whatsapp' => '+91 98765 43210',
            'support_message' => 'Please contact StudySpace billing administrator for payment assistance or subscription renewals.'
        ];
        $stmt_s = $master_pdo->prepare("INSERT INTO support_settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($default_support as $k => $v) {
            $stmt_s->execute([$k, $v]);
        }
    }


    // Seed default Subscription Plans if none exist
    $plan_count = (int)$master_pdo->query("SELECT COUNT(*) FROM plans")->fetchColumn();
    if ($plan_count === 0) {
        $stmt_p = $master_pdo->prepare("INSERT INTO plans (plan_id, name, duration_days, price, max_students, description, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
        $stmt_p->execute(['TRIAL', 'Free Trial Plan', 14, 0.00, 50, '14-day full feature trial']);
        $stmt_p->execute(['MONTHLY', 'Monthly SaaS Plan', 30, 29.99, 100, 'Standard monthly plan for small to medium study halls']);
        $stmt_p->execute(['YEARLY', 'Yearly SaaS Plan', 365, 299.99, 300, 'Discounted annual plan with priority support and 300 capacity']);
    }

    // Seed default Super Admin user if none exists
    $sa_count = (int)$master_pdo->query("SELECT COUNT(*) FROM super_admins")->fetchColumn();
    if ($sa_count === 0) {
        $stmt = $master_pdo->prepare("INSERT INTO super_admins (username, email, password_hash) VALUES (?, ?, ?)");
        $stmt->execute(['superadmin', 'superadmin@studyspace.com', password_hash('superadmin123', PASSWORD_DEFAULT)]);
    }

    // Seed Default Test Tenants: LIB001 and LIB002 or LIBTEST
    if (defined('APP_ENV') && APP_ENV === 'deplexo_test') {
        seed_deplexo_test_tenant($master_pdo);
    } else {
        seed_test_tenants_in_master($master_pdo);
    }
}

function seed_deplexo_test_tenant($master_pdo) {
    $t = [
        'code' => 'LIBTEST',
        'name' => 'Deplexo Test Library',
        'status' => 'active',
        'db_driver' => 'sqlite',
        'db_file' => 'data/studyspace_test.sqlite',
        'plan' => 'Test SaaS Plan',
        'max_students' => 100,
        'valid_until' => '2030-12-31',
        'logo_url' => '',
        'primary_color' => '#1D4ED8',
        'phone' => '+91 99999 00000',
        'address' => 'Deplexo Test Environment',
        'tagline' => 'Deplexo Isolated Test Environment'
    ];

    $stmt = $master_pdo->prepare("SELECT COUNT(*) FROM libraries WHERE library_code = ?");
    $stmt->execute([$t['code']]);
    if ((int)$stmt->fetchColumn() === 0) {
        $master_pdo->prepare("INSERT INTO libraries (library_code, name, status) VALUES (?, ?, ?)")
                   ->execute([$t['code'], $t['name'], $t['status']]);

        $master_pdo->prepare("INSERT INTO tenant_db_configs (library_code, db_driver, db_file) VALUES (?, ?, ?)")
                   ->execute([$t['code'], $t['db_driver'], $t['db_file']]);

        $master_pdo->prepare("INSERT INTO subscriptions (library_code, plan_name, max_students, valid_until, status) VALUES (?, ?, ?, ?, 'active')")
                   ->execute([$t['code'], $t['plan'], $t['max_students'], $t['valid_until']]);

        $master_pdo->prepare("INSERT INTO library_branding (library_code, logo_url, primary_color, contact_phone, address, tagline) VALUES (?, ?, ?, ?, ?, ?)")
                   ->execute([$t['code'], $t['logo_url'], $t['primary_color'], $t['phone'], $t['address'], $t['tagline']]);
    }
}

function log_super_admin_action($master_pdo, $action, $super_admin = 'superadmin', $library_code = null, $result_status = 'SUCCESS', $metadata = '') {
    try {
        if (is_array($metadata) || is_object($metadata)) {
            $metadata = json_encode($metadata);
        }
        $stmt = $master_pdo->prepare("INSERT INTO super_admin_audit_logs (action, super_admin, library_code, result_status, metadata) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$action, $super_admin, $library_code, $result_status, (string)$metadata]);
    } catch (Exception $e) {
        // Silent catch for audit log failure
    }
}

function seed_test_tenants_in_master($master_pdo) {
    $data_dir = __DIR__ . '/../data';
    if (!file_exists($data_dir)) {
        @mkdir($data_dir, 0777, true);
    }

    $default_tenants = [
        [
            'code' => 'LIB001',
            'name' => 'Demo Library 1',
            'status' => 'active',
            'db_driver' => 'sqlite',
            'db_file' => 'data/tenant_lib001.sqlite',
            'plan' => 'Gold SaaS Plan',
            'max_students' => 200,
            'valid_until' => '2030-12-31',
            'logo_url' => '',
            'primary_color' => '#1D4ED8',
            'phone' => '+91 98765 00001',
            'address' => '123 Education Hub, Sector 1, City',
            'tagline' => 'Premier Self-Study Environment - Library 1'
        ],
        [
            'code' => 'LIB002',
            'name' => 'Demo Library 2',
            'status' => 'active',
            'db_driver' => 'sqlite',
            'db_file' => 'data/tenant_lib002.sqlite',
            'plan' => 'Platinum SaaS Plan',
            'max_students' => 500,
            'valid_until' => '2030-12-31',
            'logo_url' => '',
            'primary_color' => '#10B981',
            'phone' => '+91 98765 00002',
            'address' => '456 Knowledge Park, Block B, City',
            'tagline' => 'Quiet & Focused Reading Space - Library 2'
        ]
    ];

    foreach ($default_tenants as $t) {
        $stmt = $master_pdo->prepare("SELECT COUNT(*) FROM libraries WHERE library_code = ?");
        $stmt->execute([$t['code']]);
        if ((int)$stmt->fetchColumn() === 0) {
            $master_pdo->prepare("INSERT INTO libraries (library_code, name, status) VALUES (?, ?, ?)")
                       ->execute([$t['code'], $t['name'], $t['status']]);

            $master_pdo->prepare("INSERT INTO tenant_db_configs (library_code, db_driver, db_file) VALUES (?, ?, ?)")
                       ->execute([$t['code'], $t['db_driver'], $t['db_file']]);

            $master_pdo->prepare("INSERT INTO subscriptions (library_code, plan_name, max_students, valid_until, status) VALUES (?, ?, ?, ?, 'active')")
                       ->execute([$t['code'], $t['plan'], $t['max_students'], $t['valid_until']]);

            $master_pdo->prepare("INSERT INTO library_branding (library_code, logo_url, primary_color, contact_phone, address, tagline) VALUES (?, ?, ?, ?, ?, ?)")
                       ->execute([$t['code'], $t['logo_url'], $t['primary_color'], $t['phone'], $t['address'], $t['tagline']]);
        }
    }
}
