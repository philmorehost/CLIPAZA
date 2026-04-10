<?php
declare(strict_types=1);

/**
 * Processes contests that have reached their expiration date.
 * transitions 'active' contests to 'ended' and identifies winners.
 */
function processExpiredContests(): void {
    try {
        $db = db();

        // Find active contests that have expired
        $stmt = $db->query("SELECT id FROM contests WHERE status = 'active' AND end_date <= NOW()");
        $expiredContestIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($expiredContestIds as $contestId) {
            $db->beginTransaction();

            try {
                // Update contest status to 'ended'
                $db->prepare("UPDATE contests SET status = 'ended' WHERE id = ?")->execute([$contestId]);

                // For each platform enabled in this contest, find the winners
                $stmt = $db->prepare("SELECT platform, prize_amount, winner_count FROM contest_platforms WHERE contest_id = ?");
                $stmt->execute([$contestId]);
                $platforms = $stmt->fetchAll();

                foreach ($platforms as $p) {
                    $platform = $p['platform'];
                    $winnerCount = (int)$p['winner_count'];
                    $prizePool = (float)$p['prize_amount'];

                    if ($winnerCount <= 0) continue;

                    $prizePerWinner = round($prizePool / $winnerCount, 2);

                    // Get top N entries for this platform
                    // Criteria: verified engagement (all required) + highest view_count, then like_count
                    $stmt = $db->prepare("
                        SELECT ce.id, ce.user_id
                        FROM contest_entries ce
                        INNER JOIN contests c ON c.id = ce.contest_id
                        WHERE ce.contest_id = ?
                          AND ce.platform = ?
                          AND ce.disqualified = 0
                          AND (c.must_subscribe = 0 OR ce.verified_subscribe = 1)
                          AND (c.must_like = 0 OR ce.verified_like = 1)
                          AND (c.must_comment = 0 OR ce.verified_comment = 1)
                        ORDER BY ce.view_count DESC, ce.like_count DESC
                        LIMIT ?
                    ");
                    $stmt->execute([$contestId, $platform, $winnerCount]);
                    $winners = $stmt->fetchAll();

                    foreach ($winners as $index => $winner) {
                        $rank = $index + 1;

                        // Insert into payouts
                        $db->prepare("
                            INSERT INTO payouts (contest_id, user_id, entry_id, amount, platform, rank_position, status)
                            VALUES (?, ?, ?, ?, ?, ?, 'pending')
                        ")->execute([
                            $contestId,
                            $winner['user_id'],
                            $winner['id'],
                            $prizePerWinner,
                            $platform,
                            $rank
                        ]);

                        // Update entry with rank position
                        $db->prepare("UPDATE contest_entries SET rank_position = ? WHERE id = ?")
                           ->execute([$rank, $winner['id']]);
                    }
                }

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                // Log error
                error_log("Failed to process expired contest {$contestId}: " . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log("processExpiredContests failed: " . $e->getMessage());
    }
}
