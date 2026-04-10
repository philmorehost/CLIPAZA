<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/contest_manager.php';

echo "Starting Functional Verification...\n";

try {
    $db = db();

    // 1. Create a dummy creator and contestant
    $db->exec("INSERT IGNORE INTO users (id, username, email, password, role, status) VALUES (999, 'test_creator', 'creator@test.com', 'hash', 'user', 'active')");
    $db->exec("INSERT IGNORE INTO user_profiles (user_id, display_name, active_mode) VALUES (999, 'Test Creator', 'creator')");

    $db->exec("INSERT IGNORE INTO users (id, username, email, password, role, status) VALUES (1000, 'test_clipper', 'clipper@test.com', 'hash', 'user', 'active')");
    $db->exec("INSERT IGNORE INTO user_profiles (user_id, display_name, active_mode) VALUES (1000, 'Test Clipper', 'clipper')");

    echo "Dummy users created.\n";

    // 2. Create a contest that expires in the past
    $db->exec("INSERT INTO contests (id, creator_id, title, prize_pool, status, escrow_status, end_date, youtube_video_id)
               VALUES (999, 999, 'Test Contest', 10000, 'active', 'funded', '2020-01-01 00:00:00', 'vID123')");
    $db->exec("INSERT INTO contest_platforms (contest_id, platform, prize_amount, winner_count) VALUES (999, 'tiktok', 10000, 1)");

    echo "Expired contest created.\n";

    // 3. Create a submission for the contest
    $db->exec("INSERT INTO contest_entries (contest_id, user_id, clip_url, platform, view_count, like_count, status, verified_like, verified_subscribe, verified_comment)
               VALUES (999, 1000, 'https://tiktok.com/clip', 'tiktok', 5000, 100, 'approved', 1, 1, 1)");

    echo "Submission created.\n";

    // 4. Run processExpiredContests
    echo "Processing expired contests...\n";
    processExpiredContests();

    // 5. Verify contest status and payout creation
    $contestStatus = $db->query("SELECT status FROM contests WHERE id = 999")->fetchColumn();
    $payout = $db->query("SELECT * FROM payouts WHERE contest_id = 999 AND user_id = 1000")->fetch();

    if ($contestStatus === 'ended' && $payout && (float)$payout['amount'] === 10000.0) {
        echo "SUCCESS: Contest ended and payout created correctly.\n";
    } else {
        echo "FAILURE: Contest status: $contestStatus, Payout found: " . ($payout ? 'Yes' : 'No') . "\n";
    }

    // Clean up
    $db->exec("DELETE FROM payouts WHERE contest_id = 999");
    $db->exec("DELETE FROM contest_entries WHERE contest_id = 999");
    $db->exec("DELETE FROM contest_platforms WHERE contest_id = 999");
    $db->exec("DELETE FROM contests WHERE id = 999");
    $db->exec("DELETE FROM user_profiles WHERE user_id IN (999, 1000)");
    $db->exec("DELETE FROM users WHERE id IN (999, 1000)");

    echo "Cleanup complete.\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
