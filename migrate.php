<?php
declare(strict_types=1);
require_once 'includes/db.php';

/**
 * Robustly adds a column to a table if it doesn't already exist.
 */
function addColumnIfNotExists(PDO $db, string $table, string $column, string $definition): bool {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($stmt->fetch()) {
            return false; // Already exists
        }
        $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        return true;
    } catch (Throwable $e) {
        echo "Error adding column $column to $table: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Robustly modifies a column's definition.
 */
function modifyColumn(PDO $db, string $table, string $column, string $definition): void {
    try {
        $db->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` $definition");
    } catch (Throwable $e) {
        echo "Error modifying column $column in $table: " . $e->getMessage() . "\n";
    }
}

try {
    $db = db();
    echo "Starting database migrations...\n";

    // 1. Update user_profiles
    $columns = [
        'kyc_status' => "ENUM('none', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'none'",
        'kyc_id_type' => "VARCHAR(50) DEFAULT NULL",
        'kyc_id_path' => "VARCHAR(255) DEFAULT NULL",
        'kyc_snapshot_path' => "VARCHAR(255) DEFAULT NULL",
        'kyc_id_expiry' => "DATE DEFAULT NULL",
        'kyc_rejection_reason' => "TEXT DEFAULT NULL",
        'bank_name' => "VARCHAR(100) DEFAULT NULL",
        'bank_code' => "VARCHAR(20) DEFAULT NULL",
        'account_number' => "VARCHAR(20) DEFAULT NULL",
        'account_name' => "VARCHAR(200) DEFAULT NULL"
    ];

    foreach ($columns as $col => $def) {
        if (addColumnIfNotExists($db, 'user_profiles', $col, $def)) {
            echo "Added $col to user_profiles.\n";
        }
    }

    // 2. Update payouts
    $payoutColumns = [
        'rejection_reason' => "TEXT DEFAULT NULL",
        'appeal_message' => "TEXT DEFAULT NULL",
        'paystack_transfer_code' => "VARCHAR(255) DEFAULT NULL",
        'paystack_reference' => "VARCHAR(255) DEFAULT NULL"
    ];

    foreach ($payoutColumns as $col => $def) {
        if (addColumnIfNotExists($db, 'payouts', $col, $def)) {
            echo "Added $col to payouts.\n";
        }
    }

    // Update ENUM for status in payouts
    modifyColumn($db, 'payouts', 'status', "ENUM('pending', 'claimed', 'processing', 'completed', 'failed', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending'");
    echo "Attempted to update payouts status enum.\n";

    // 3. Update users table for Google Auth if missing
    if (addColumnIfNotExists($db, 'users', 'google_id', "VARCHAR(255) DEFAULT NULL AFTER email")) {
        try {
            $db->exec("ALTER TABLE `users` ADD KEY `idx_users_google` (`google_id`)");
            echo "Added google_id and index to users.\n";
        } catch (Throwable $e) {
            echo "Error adding index to users: " . $e->getMessage() . "\n";
        }
    }

    // 4. Update site_settings for missing keys
    $settings = [
        'google_client_id' => ',
        'google_client_secret' => ',
        'cron_key' => 'default_cron_key_123'
    ];

    $stmt = $db->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settings as $key => $val) {
        $stmt->execute([$key, $val]);
    }
    echo "Site settings updated.\n";

    echo "Database migrations completed successfully.\n";

} catch (Throwable $e) {
    echo "FATAL ERROR during migration: " . $e->getMessage() . "\n";
    exit(1);
}
