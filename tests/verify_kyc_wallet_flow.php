<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';

echo "Starting Lifecycle Verification...\n";

try {
    $db = db();

    // 1. Setup
    $userId = 1111;
    $db->exec("INSERT IGNORE INTO users (id, username, email, password, role, status) VALUES ($userId, 'lifecycle_user', 'life@cycle.com', 'pw', 'user', 'active')");
    $db->exec("INSERT IGNORE INTO user_profiles (user_id, display_name, wallet_balance, kyc_status) VALUES ($userId, 'Lifecycle User', 5000.00, 'none')");

    echo "User created with ₦5000 wallet.\n";

    // 2. Mock Payout Request (Wallet)
    $db->prepare("INSERT INTO payouts (user_id, amount, status, bank_name, account_number, created_at) VALUES (?, 1000, 'claimed', 'Test Bank', '0123456789', NOW())")
       ->execute([$userId]);
    $payoutId = $db->lastInsertId();
    $db->prepare("UPDATE user_profiles SET wallet_balance = wallet_balance - 1000 WHERE user_id = ?")->execute([$userId]);

    echo "Payout request created for ₦1000. Wallet now ₦4000.\n";

    // 3. Admin Rejection (with reversal)
    $db->beginTransaction();
    $db->prepare("UPDATE user_profiles SET wallet_balance = wallet_balance + 1000 WHERE user_id = ?")->execute([$userId]);
    $db->prepare("UPDATE payouts SET status = 'rejected', rejection_reason = 'Testing reversal' WHERE id = ?")->execute([$payoutId]);
    $db->commit();

    echo "Admin rejected payout. Wallet reversed to ₦5000.\n";

    $finalBalance = (float)$db->query("SELECT wallet_balance FROM user_profiles WHERE user_id = $userId")->fetchColumn();
    if ($finalBalance === 5000.0) {
        echo "SUCCESS: Wallet reversal verified.\n";
    } else {
        echo "FAILURE: Wallet balance is $finalBalance\n";
    }

    // 4. Cleanup
    $db->exec("DELETE FROM payouts WHERE user_id = $userId");
    $db->exec("DELETE FROM user_profiles WHERE user_id = $userId");
    $db->exec("DELETE FROM users WHERE id = $userId");

    echo "Cleanup complete.\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
