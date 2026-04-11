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
        'google_client_id' => '',
        'google_client_secret' => '',
        'cron_key' => 'default_cron_key_123'
    ];

    $stmt = $db->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settings as $key => $val) {
        $stmt->execute([$key, $val]);
    }
    echo "Site settings updated.\n";

    // 5. Create notifications table if not exists
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `notifications` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` int(11) UNSIGNED NOT NULL,
            `type` varchar(50) NOT NULL DEFAULT 'info',
            `title` varchar(255) NOT NULL,
            `message` text NOT NULL,
            `link` varchar(500) DEFAULT NULL,
            `is_read` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_notif_user` (`user_id`),
            KEY `idx_notif_read` (`is_read`),
            KEY `idx_notif_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "notifications table ensured.\n";
    } catch (Throwable $e) {
        echo "Error creating notifications table: " . $e->getMessage() . "\n";
    }

    // 6. Create payout_requests table if not exists
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `payout_requests` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` int(11) UNSIGNED NOT NULL,
            `amount` decimal(12,2) NOT NULL,
            `status` enum('pending','approved','rejected','cancelled','on_hold') NOT NULL DEFAULT 'pending',
            `bank_name` varchar(200) DEFAULT NULL,
            `bank_code` varchar(20) DEFAULT NULL,
            `account_number` varchar(20) DEFAULT NULL,
            `account_name` varchar(200) DEFAULT NULL,
            `rejection_reason` text DEFAULT NULL,
            `cancel_reason` text DEFAULT NULL,
            `appeal_message` text DEFAULT NULL,
            `admin_note` text DEFAULT NULL,
            `paystack_reference` varchar(255) DEFAULT NULL,
            `paystack_transfer_code` varchar(255) DEFAULT NULL,
            `processed_by` int(11) UNSIGNED DEFAULT NULL,
            `processed_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_pr_user` (`user_id`),
            KEY `idx_pr_status` (`status`),
            KEY `idx_pr_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "payout_requests table ensured.\n";
    } catch (Throwable $e) {
        echo "Error creating payout_requests table: " . $e->getMessage() . "\n";
    }

    // 7. Add new site settings for payment features
    $paymentSettings = [
        'paystack_fee_percent'   => '0',
        'paystack_fee_flat'      => '0',
        'min_withdrawal_amount'  => '1000',
        'max_withdrawal_amount'  => '500000',
        'withdrawal_fee_percent' => '0',
        'withdrawal_fee_flat'    => '0',
    ];
    $stmt = $db->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($paymentSettings as $key => $val) {
        $stmt->execute([$key, $val]);
    }
    echo "Payment settings ensured.\n";

    // 8. Add admin_note column to payout_requests (in case table already existed without it)
    addColumnIfNotExists($db, 'payout_requests', 'admin_note', 'TEXT DEFAULT NULL');

    echo "Database migrations completed successfully.\n";

} catch (Throwable $e) {
    echo "FATAL ERROR during migration: " . $e->getMessage() . "\n";
    exit(1);
}
