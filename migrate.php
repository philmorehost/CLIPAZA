<?php
declare(strict_types=1);
require_once 'includes/db.php';

try {
    $db = db();

    // 1. Update user_profiles
    $db->exec("ALTER TABLE user_profiles
        ADD COLUMN IF NOT EXISTS kyc_status ENUM('none', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'none',
        ADD COLUMN IF NOT EXISTS kyc_id_type VARCHAR(50) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS kyc_id_path VARCHAR(255) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS kyc_snapshot_path VARCHAR(255) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS kyc_id_expiry DATE DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS kyc_rejection_reason TEXT DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS bank_name VARCHAR(100) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS bank_code VARCHAR(20) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS account_number VARCHAR(20) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS account_name VARCHAR(200) DEFAULT NULL
    ");
    echo "user_profiles updated.\n";

    // 2. Update payouts
    $db->exec("ALTER TABLE payouts
        ADD COLUMN IF NOT EXISTS rejection_reason TEXT DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS appeal_message TEXT DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS paystack_transfer_code VARCHAR(255) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS paystack_reference VARCHAR(255) DEFAULT NULL
    ");

    // Update ENUM for status in payouts (if possible, else just assume it handles it)
    // MySQL might need a specific syntax to change an ENUM if it already exists
    try {
        $db->exec("ALTER TABLE payouts MODIFY COLUMN status ENUM('pending', 'claimed', 'processing', 'completed', 'failed', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending'");
        echo "payouts status enum updated.\n";
    } catch (Exception $e) {
        echo "Note: Could not update payouts status enum (might already be correct or unsupported): " . $e->getMessage() . "\n";
    }

    echo "Database migrations completed successfully.\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
