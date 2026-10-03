<?php
/**
 * StudySpace Backup & Disaster Recovery Manager
 * Phase 15 — Production Hardening, Backup, Recovery & Disaster-Recovery
 */

require_once __DIR__ . '/master_db.php';
require_once __DIR__ . '/tenant_router.php';

class BackupManager {

    /**
     * Get default secure backup directory
     */
    public static function getBackupDir() {
        $dir = __DIR__ . '/../data/backups';
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        // Ensure .htaccess exists inside backups directory to deny public HTTP access
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
        return realpath($dir) ?: $dir;
    }

    /**
     * Create a new backup for Master DB or a Tenant DB
     */
    public static function createBackup($scope = 'master', $libraryCode = null, $customDestDir = null) {
        $masterPdo = get_master_pdo();
        $targetDir = $customDestDir ? $customDestDir : self::getBackupDir();

        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $timestamp = date('Ymd_His');

        if ($scope === 'master') {
            $sourceFile = __DIR__ . '/../data/studyspace_master.sqlite';
            $fileName = "studyspace_master_{$timestamp}.sqlite";
            $libCodeParam = null;
        } else {
            if (empty($libraryCode)) {
                throw new Exception("Library code required for tenant backup");
            }
            $stmt_cfg = $masterPdo->prepare("SELECT db_file FROM tenant_db_configs WHERE library_code = ?");
            $stmt_cfg->execute([strtoupper($libraryCode)]);
            $dbConfig = $stmt_cfg->fetch(PDO::FETCH_ASSOC);

            if (!$dbConfig || empty($dbConfig['db_file'])) {
                throw new Exception("Tenant DB configuration not found for library: {$libraryCode}");
            }
            $sourceFile = TenantDatabaseFactory::resolveTenantDbPath($libraryCode, $dbConfig['db_file'] ?? null);
            $fileName = "tenant_{$libraryCode}_{$timestamp}.sqlite";
            $libCodeParam = strtoupper($libraryCode);
        }

        if (!file_exists($sourceFile) || !is_readable($sourceFile)) {
            throw new Exception("Backup failed: Source database file does not exist or is unreadable ({$sourceFile})");
        }

        $destFile = $targetDir . '/' . $fileName;

        // Perform atomic file copy
        if (!copy($sourceFile, $destFile)) {
            throw new Exception("Backup failed: Could not copy database to destination ({$destFile})");
        }

        $fileSize = filesize($destFile);
        if ($fileSize === false || $fileSize === 0) {
            if (file_exists($destFile)) unlink($destFile);
            throw new Exception("Backup failed: Created backup file is empty (0 bytes)");
        }

        $checksum = hash_file('sha256', $destFile);
        $backupId = 'BU-' . strtoupper($scope) . '-' . ($libCodeParam ? $libCodeParam . '-' : '') . date('YmdHis');

        // Record metadata in Master DB
        $stmt = $masterPdo->prepare("
            INSERT INTO backup_metadata (backup_id, scope, library_code, file_name, file_path, file_size, checksum, schema_version, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'v7', 'CREATED')
        ");
        $stmt->execute([$backupId, $scope, $libCodeParam, $fileName, $destFile, $fileSize, $checksum]);

        log_super_admin_action($masterPdo, 'BACKUP_CREATED', 'superadmin', $libCodeParam, 'SUCCESS', [
            'backup_id' => $backupId,
            'scope' => $scope,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'checksum' => $checksum
        ]);

        return [
            'success' => true,
            'backup_id' => $backupId,
            'scope' => $scope,
            'library_code' => $libCodeParam,
            'file_name' => $fileName,
            'file_path' => $destFile,
            'file_size' => $fileSize,
            'checksum' => $checksum,
            'status' => 'CREATED'
        ];
    }

    /**
     * Verify backup integrity (Existence, Checksum, SQLite Integrity, Schema)
     */
    public static function verifyBackup($backupId) {
        $masterPdo = get_master_pdo();
        $stmt = $masterPdo->prepare("SELECT * FROM backup_metadata WHERE backup_id = ?");
        $stmt->execute([$backupId]);
        $meta = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$meta) {
            return ['success' => false, 'error' => "Backup record not found for ID: {$backupId}"];
        }

        $filePath = $meta['file_path'];

        // 1. File existence & readability check
        if (!file_exists($filePath) || !is_readable($filePath)) {
            self::updateStatus($masterPdo, $backupId, 'FAILED', "File missing or unreadable at {$filePath}");
            return ['success' => false, 'error' => "Backup file missing or unreadable", 'status' => 'FAILED'];
        }

        // 2. Non-zero size check
        $actualSize = filesize($filePath);
        if ($actualSize === 0) {
            self::updateStatus($masterPdo, $backupId, 'FAILED', "Backup file size is 0 bytes");
            return ['success' => false, 'error' => "Backup file is empty (0 bytes)", 'status' => 'FAILED'];
        }

        // 3. Cryptographic Checksum SHA-256 match
        $actualChecksum = hash_file('sha256', $filePath);
        if ($actualChecksum !== $meta['checksum']) {
            self::updateStatus($masterPdo, $backupId, 'FAILED', "Checksum mismatch: Expected {$meta['checksum']}, got {$actualChecksum}");
            return ['success' => false, 'error' => "Checksum mismatch detected! Possible file corruption.", 'status' => 'FAILED'];
        }

        // 4. SQLite Integrity PRAGMA check
        try {
            $pdo = new PDO("sqlite:" . $filePath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $integrity = $pdo->query("PRAGMA integrity_check;")->fetchColumn();
            if (strtolower($integrity) !== 'ok') {
                self::updateStatus($masterPdo, $backupId, 'FAILED', "PRAGMA integrity_check failed: {$integrity}");
                return ['success' => false, 'error' => "SQLite integrity check failed: {$integrity}", 'status' => 'FAILED'];
            }

            // 5. Schema verification
            if ($meta['scope'] === 'master') {
                $requiredTables = ['libraries', 'tenant_db_configs', 'subscriptions', 'invoices', 'manual_payments', 'subscription_renewal_requests', 'library_branding', 'support_settings', 'super_admin_audit_logs'];
            } else {
                $requiredTables = ['users', 'seats', 'shifts', 'allocations', 'fee_payments', 'attendance', 'complaints', 'notifications', 'chat_messages', 'system_settings', 'parent_student_links', 'schema_migrations'];
            }

            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            $missing = array_diff($requiredTables, $tables);

            if (!empty($missing)) {
                $err = "Missing required schema tables: " . implode(', ', $missing);
                self::updateStatus($masterPdo, $backupId, 'FAILED', $err);
                return ['success' => false, 'error' => $err, 'status' => 'FAILED'];
            }

        } catch (Exception $e) {
            self::updateStatus($masterPdo, $backupId, 'FAILED', "Database error: " . $e->getMessage());
            return ['success' => false, 'error' => "Database verify exception: " . $e->getMessage(), 'status' => 'FAILED'];
        }

        // Mark VERIFIED
        self::updateStatus($masterPdo, $backupId, 'VERIFIED', null, true);

        log_super_admin_action($masterPdo, 'BACKUP_VERIFIED', 'superadmin', $meta['library_code'], 'SUCCESS', [
            'backup_id' => $backupId,
            'status' => 'VERIFIED'
        ]);

        return [
            'success' => true,
            'backup_id' => $backupId,
            'status' => 'VERIFIED',
            'verified_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Isolated Restore Drill into a temporary recovery database
     */
    public static function runRestoreDrill($backupId, $tempRestorePath = null) {
        $masterPdo = get_master_pdo();
        $stmt = $masterPdo->prepare("SELECT * FROM backup_metadata WHERE backup_id = ?");
        $stmt->execute([$backupId]);
        $meta = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$meta) {
            return ['success' => false, 'error' => "Backup metadata not found for ID: {$backupId}"];
        }

        // First verify backup
        $verify = self::verifyBackup($backupId);
        if (!$verify['success']) {
            return ['success' => false, 'error' => "Cannot restore unverified or corrupted backup: " . $verify['error']];
        }

        $sourceBackupPath = $meta['file_path'];
        $tempDb = $tempRestorePath ? $tempRestorePath : __DIR__ . '/../data/temp_recovery_drill_' . date('YmdHis') . '.sqlite';

        if (file_exists($tempDb)) {
            unlink($tempDb);
        }

        // Copy backup to isolated restore environment
        if (!copy($sourceBackupPath, $tempDb)) {
            return ['success' => false, 'error' => "Failed to copy backup file to isolated restore target"];
        }

        try {
            $pdo = new PDO("sqlite:" . $tempDb);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // PRAGMA checks
            $integrity = $pdo->query("PRAGMA integrity_check;")->fetchColumn();
            if (strtolower($integrity) !== 'ok') {
                throw new Exception("PRAGMA integrity_check failed on restored DB: {$integrity}");
            }

            $fkCheck = $pdo->query("PRAGMA foreign_key_check;")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($fkCheck)) {
                throw new Exception("Foreign key violations found in restored DB: " . json_encode($fkCheck));
            }

            // Application level read tests
            if ($meta['scope'] === 'master') {
                $libCount = (int)$pdo->query("SELECT COUNT(*) FROM libraries")->fetchColumn();
                $subCount = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions")->fetchColumn();
                $invCount = (int)$pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
                $auditCount = (int)$pdo->query("SELECT COUNT(*) FROM super_admin_audit_logs")->fetchColumn();

                $details = [
                    'libraries' => $libCount,
                    'subscriptions' => $subCount,
                    'invoices' => $invCount,
                    'audit_logs' => $auditCount
                ];
            } else {
                $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
                $seatCount = (int)$pdo->query("SELECT COUNT(*) FROM seats")->fetchColumn();
                $allocCount = (int)$pdo->query("SELECT COUNT(*) FROM allocations")->fetchColumn();
                $feeCount = (int)$pdo->query("SELECT COUNT(*) FROM fee_payments")->fetchColumn();

                $details = [
                    'users' => $userCount,
                    'seats' => $seatCount,
                    'allocations' => $allocCount,
                    'fee_payments' => $feeCount
                ];
            }

            // Cleanup isolated test database safely
            unset($pdo);
            if (file_exists($tempDb)) {
                unlink($tempDb);
            }

            self::updateStatus($masterPdo, $backupId, 'RESTORED_TEST');

            log_super_admin_action($masterPdo, 'RESTORE_DRILL_COMPLETED', 'superadmin', $meta['library_code'], 'SUCCESS', [
                'backup_id' => $backupId,
                'scope' => $meta['scope'],
                'details' => $details
            ]);

            return [
                'success' => true,
                'backup_id' => $backupId,
                'status' => 'RESTORED_TEST',
                'details' => $details,
                'isolated_test_cleaned' => true
            ];

        } catch (Exception $e) {
            if (file_exists($tempDb)) {
                unlink($tempDb);
            }
            return ['success' => false, 'error' => "Restore drill failed: " . $e->getMessage()];
        }
    }

    /**
     * List all recorded backups
     */
    public static function listBackups($scope = null, $libraryCode = null) {
        $masterPdo = get_master_pdo();
        $sql = "SELECT * FROM backup_metadata WHERE 1=1";
        $params = [];

        if ($scope) {
            $sql .= " AND scope = ?";
            $params[] = $scope;
        }
        if ($libraryCode) {
            $sql .= " AND library_code = ?";
            $params[] = strtoupper($libraryCode);
        }

        $sql .= " ORDER BY id DESC";
        $stmt = $masterPdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function updateStatus($masterPdo, $backupId, $status, $errorMsg = null, $setVerifiedAt = false) {
        if ($setVerifiedAt) {
            $stmt = $masterPdo->prepare("UPDATE backup_metadata SET status = ?, verified_at = CURRENT_TIMESTAMP, error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE backup_id = ?");
            $stmt->execute([$status, $errorMsg, $backupId]);
        } else {
            $stmt = $masterPdo->prepare("UPDATE backup_metadata SET status = ?, error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE backup_id = ?");
            $stmt->execute([$status, $errorMsg, $backupId]);
        }
    }
}
