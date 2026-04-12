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

    // 9. New contest_entries columns
    $entryColumns = [
        'proof_subscribe_path' => 'VARCHAR(500) DEFAULT NULL',
        'proof_comment_path'   => 'VARCHAR(500) DEFAULT NULL',
        'proof_like_path'      => 'VARCHAR(500) DEFAULT NULL',
        'bot_score'            => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'bot_flags'            => 'VARCHAR(1000) DEFAULT NULL',
        'submission_ip'        => 'VARCHAR(45) DEFAULT NULL',
        'submission_ua'        => 'VARCHAR(500) DEFAULT NULL',
    ];
    foreach ($entryColumns as $col => $def) {
        if (addColumnIfNotExists($db, 'contest_entries', $col, $def)) {
            echo "Added $col to contest_entries.\n";
        }
    }

    // 10. winner_takes_all column for contests
    if (addColumnIfNotExists($db, 'contests', 'winner_takes_all', 'TINYINT(1) NOT NULL DEFAULT 0')) {
        echo "Added winner_takes_all to contests.\n";
    }

    // 11. Insert payout_approval_pin security setting if not exists
    try {
        $db->prepare(
            "INSERT IGNORE INTO security_settings (setting_key, setting_value) VALUES ('payout_approval_pin', '')"
        )->execute();
        echo "Ensured payout_approval_pin security setting.\n";
    } catch (Throwable $e) {
        echo "Error inserting payout_approval_pin: " . $e->getMessage() . "\n";
    }

    // 12. Create uploads/proofs directory
    $proofsDir = __DIR__ . '/uploads/proofs';
    if (!is_dir($proofsDir)) {
        if (mkdir($proofsDir, 0755, true)) {
            echo "Created uploads/proofs directory.\n";
        } else {
            echo "Warning: Could not create uploads/proofs directory.\n";
        }
    }

    // 13. Create ad_packages table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `ad_packages` (
          `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
          `name` varchar(255) NOT NULL,
          `description` text DEFAULT NULL,
          `price` decimal(12,2) NOT NULL,
          `duration_days` int(11) UNSIGNED NOT NULL DEFAULT 30,
          `features` text DEFAULT NULL COMMENT 'JSON array of feature strings',
          `placement_zones` varchar(500) DEFAULT NULL COMMENT 'JSON array: homepage, contests, sidebar etc',
          `max_ads` int(11) UNSIGNED NOT NULL DEFAULT 1,
          `is_active` tinyint(1) NOT NULL DEFAULT 1,
          `sort_order` int(11) UNSIGNED NOT NULL DEFAULT 0,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_ap_active` (`is_active`),
          KEY `idx_ap_sort` (`sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "ad_packages table ensured.\n";
    } catch (Throwable $e) {
        echo "Error creating ad_packages table: " . $e->getMessage() . "\n";
    }

    // 14. Create movie_ads table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `movie_ads` (
          `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id` int(11) UNSIGNED NOT NULL,
          `package_id` int(11) UNSIGNED NOT NULL,
          `movie_title` varchar(255) NOT NULL,
          `tagline` varchar(500) DEFAULT NULL,
          `description` text DEFAULT NULL,
          `genre` varchar(100) DEFAULT NULL,
          `release_date` date DEFAULT NULL,
          `trailer_url` varchar(2000) DEFAULT NULL COMMENT 'YouTube/video URL for preview',
          `poster_path` varchar(500) DEFAULT NULL COMMENT 'Uploaded movie poster/e-flyer',
          `flyer_path` varchar(500) DEFAULT NULL COMMENT 'Uploaded e-flyer image',
          `contact_email` varchar(255) DEFAULT NULL,
          `contact_phone` varchar(30) DEFAULT NULL,
          `website_url` varchar(2000) DEFAULT NULL,
          `payment_method` enum('online','manual') NOT NULL DEFAULT 'online',
          `payment_status` enum('unpaid','pending_verification','paid') NOT NULL DEFAULT 'unpaid',
          `payment_reference` varchar(255) DEFAULT NULL,
          `manual_deposit_proof` varchar(500) DEFAULT NULL COMMENT 'Uploaded proof of bank transfer',
          `manual_deposit_amount` decimal(12,2) DEFAULT NULL,
          `manual_deposit_note` text DEFAULT NULL,
          `status` enum('draft','pending_review','approved','rejected','expired','cancelled') NOT NULL DEFAULT 'draft',
          `review_note` text DEFAULT NULL,
          `reviewed_by` int(11) UNSIGNED DEFAULT NULL,
          `reviewed_at` datetime DEFAULT NULL,
          `starts_at` datetime DEFAULT NULL,
          `expires_at` datetime DEFAULT NULL,
          `impression_count` int(11) UNSIGNED NOT NULL DEFAULT 0,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_ma_user` (`user_id`),
          KEY `idx_ma_package` (`package_id`),
          KEY `idx_ma_status` (`status`),
          KEY `idx_ma_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "movie_ads table ensured.\n";
    } catch (Throwable $e) {
        echo "Error creating movie_ads table: " . $e->getMessage() . "\n";
    }

    // 15. Create upload directories for movie ads
    foreach (['uploads/movie-posters', 'uploads/movie-flyers', 'uploads/deposit-proofs'] as $dir) {
        $fullPath = __DIR__ . '/' . $dir;
        if (!is_dir($fullPath)) {
            if (mkdir($fullPath, 0755, true)) {
                echo "Created $dir directory.\n";
            } else {
                echo "Warning: Could not create $dir directory.\n";
            }
        }
    }

    // 16. Insert ad bank account settings
    $adSettings = [
        'ad_bank_name'    => '',
        'ad_bank_account' => '',
        'ad_bank_number'  => '',
    ];
    $stmt = $db->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($adSettings as $key => $val) {
        $stmt->execute([$key, $val]);
    }
    echo "Ad bank settings ensured.\n";

    // 17. Create payhub_virtual_accounts table
    $db->exec("CREATE TABLE IF NOT EXISTS `payhub_virtual_accounts` (
      `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` int(11) UNSIGNED NOT NULL,
      `account_number` varchar(20) NOT NULL,
      `account_name` varchar(255) NOT NULL,
      `bank_name` varchar(255) NOT NULL,
      `payhub_reference` varchar(255) DEFAULT NULL,
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `idx_pva_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "payhub_virtual_accounts table ensured.\n";

    // 18. Add payhub_reference column to contests
    addColumnIfNotExists($db, 'contests', 'payhub_reference', "VARCHAR(255) DEFAULT NULL");

    // 19. Insert default PayHub settings
    foreach ([
        'payhub_base_url'          => 'https://payhub.datagifting.com.ng',
        'payhub_api_key'           => '',
        'payhub_merchant_id'       => '',
        'preferred_payout_gateway' => 'paystack',
    ] as $key => $val) {
        $db->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $val]);
    }
    echo "PayHub settings inserted.\n";

    // 20. Create featured_contest_plans table
    $db->exec("CREATE TABLE IF NOT EXISTS `featured_contest_plans` (
      `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
      `name` varchar(100) NOT NULL,
      `description` varchar(500) DEFAULT NULL,
      `price` decimal(10,2) NOT NULL DEFAULT 0.00,
      `duration_days` int(11) NOT NULL DEFAULT 7,
      `is_active` tinyint(1) NOT NULL DEFAULT 1,
      `sort_order` int(11) NOT NULL DEFAULT 0,
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "featured_contest_plans table ensured.\n";

    // 21. Add featured columns to contests
    addColumnIfNotExists($db, 'contests', 'is_featured', 'TINYINT(1) NOT NULL DEFAULT 0');
    addColumnIfNotExists($db, 'contests', 'featured_until', 'DATETIME DEFAULT NULL');
    addColumnIfNotExists($db, 'contests', 'featured_plan_id', 'INT(11) UNSIGNED DEFAULT NULL');
    addColumnIfNotExists($db, 'contests', 'featured_payment_ref', 'VARCHAR(255) DEFAULT NULL');

    // Insert default feature plans
    $existingPlans = $db->query("SELECT COUNT(*) FROM featured_contest_plans")->fetchColumn();
    if ((int)$existingPlans === 0) {
        $db->exec("INSERT INTO featured_contest_plans (name, description, price, duration_days, sort_order) VALUES
          ('Starter', 'Feature your contest for 3 days', 2000.00, 3, 1),
          ('Standard', 'Feature your contest for 7 days', 5000.00, 7, 2),
          ('Premium', 'Feature your contest for 14 days', 9000.00, 14, 3)");
    }
    echo "Feature plans ensured.\n";

    echo "Database migrations completed successfully.\n";

} catch (Throwable $e) {
    echo "FATAL ERROR during migration: " . $e->getMessage() . "\n";
    exit(1);
}
