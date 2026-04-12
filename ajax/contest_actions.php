<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$action = sanitizeInput($_REQUEST['action'] ?? '');

switch ($action) {
    case 'fetch_youtube_info':
        handleFetchYoutubeInfo();
        break;
    case 'submit_clip':
        handleSubmitClip();
        break;
    case 'update_views':
        handleUpdateViews();
        break;
    case 'get_leaderboard':
        handleGetLeaderboard();
        break;
    case 'disqualify_entry':
        handleDisqualifyEntry();
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
}

function handleFetchYoutubeInfo(): never {
    $youtubeUrl = sanitizeInput($_REQUEST['youtube_url'] ?? '');
    if (empty($youtubeUrl)) {
        jsonResponse(['success' => false, 'message' => 'YouTube URL is required.']);
    }

    $videoId = '';
    if (preg_match('/[?&]v=([a-zA-Z0-9_-]{11})/', $youtubeUrl, $m)) {
        $videoId = $m[1];
    } elseif (preg_match('/youtu\.be\/([a-zA-Z0-9_-]{11})/', $youtubeUrl, $m)) {
        $videoId = $m[1];
    } elseif (preg_match('/embed\/([a-zA-Z0-9_-]{11})/', $youtubeUrl, $m)) {
        $videoId = $m[1];
    }

    if (empty($videoId)) {
        jsonResponse(['success' => false, 'message' => 'Could not extract YouTube video ID from URL.']);
    }

    $apiKey = getSetting('youtube_api_key', '');
    if ($apiKey) {
        $apiUrl  = 'https://www.googleapis.com/youtube/v3/videos?id=' . urlencode($videoId)
                 . '&key=' . urlencode($apiKey) . '&part=snippet&fields=items(snippet(title,thumbnails))';
        $apiData = @file_get_contents($apiUrl);
        if ($apiData) {
            $decoded = json_decode($apiData, true);
            if (!empty($decoded['items'][0]['snippet'])) {
                $snippet = $decoded['items'][0]['snippet'];
                jsonResponse([
                    'success'       => true,
                    'video_id'      => $videoId,
                    'title'         => $snippet['title'] ?? '',
                    'thumbnail_url' => $snippet['thumbnails']['high']['url'] ?? $snippet['thumbnails']['default']['url'] ?? '',
                ]);
            }
        }
    }

    $oembedUrl = 'https://www.youtube.com/oembed?url=' . urlencode('https://www.youtube.com/watch?v=' . $videoId) . '&format=json';
    $ch = curl_init($oembedUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'Clipaza/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response && $httpCode === 200) {
        $data     = json_decode($response, true);
        $thumbUrl = $data['thumbnail_url'] ?? 'https://img.youtube.com/vi/' . $videoId . '/hqdefault.jpg';
        jsonResponse([
            'success'       => true,
            'video_id'      => $videoId,
            'title'         => $data['title'] ?? '',
            'thumbnail_url' => $thumbUrl,
        ]);
    }

    jsonResponse([
        'success'       => true,
        'video_id'      => $videoId,
        'title'         => 'YouTube Video (' . $videoId . ')',
        'thumbnail_url' => 'https://img.youtube.com/vi/' . $videoId . '/hqdefault.jpg',
    ]);
}

/**
 * Detect bot/spam activity for a clip submission.
 * Returns ['score' => int, 'flags' => string[], 'auto_reject' => bool]
 */
function detectBotActivity(int $userId, int $contestId, string $clipUrl, string $platform, string $ip, string $ua): array {
    $score = 0;
    $flags = [];

    // 1. Missing/empty User-Agent
    if (empty(trim($ua))) {
        $score += 40;
        $flags[] = 'no_ua';
    }

    // 2. Headless browser detected
    if (!empty($ua) && preg_match('/HeadlessChrome|PhantomJS|Selenium|webdriver|puppeteer/i', $ua)) {
        $score += 50;
        $flags[] = 'headless_ua';
    }

    // 3. Suspicious URL format
    $urlOk = false;
    switch ($platform) {
        case 'tiktok':
            $urlOk = (bool)preg_match('/\/video\/\d+/i', $clipUrl);
            break;
        case 'instagram':
            $urlOk = (bool)preg_match('\/(reel|p)\//', $clipUrl);
            break;
        case 'facebook':
            $urlOk = (bool)preg_match('\/(video|reel|watch)\//i', $clipUrl) || (bool)preg_match('/watch\?v=/i', $clipUrl);
            break;
    }
    if (!$urlOk) {
        $score += 30;
        $flags[] = 'suspicious_url_format';
    }

    try {
        $db = db();

        // 4. IP rate limit — same IP submitted more than 3 clips to this contest today
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM contest_entries
             WHERE contest_id = ? AND submission_ip = ?
             AND DATE(submitted_at) = CURDATE()"
        );
        $stmt->execute([$contestId, $ip]);
        if ((int)$stmt->fetchColumn() >= 3) {
            $score += 35;
            $flags[] = 'ip_rate_limit';
        }

        // 5. User rate limit — same user submitted more than 2 clips today across all contests
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM contest_entries
             WHERE user_id = ? AND DATE(submitted_at) = CURDATE()"
        );
        $stmt->execute([$userId]);
        if ((int)$stmt->fetchColumn() >= 2) {
            $score += 25;
            $flags[] = 'user_rate_limit';
        }

        // 6. Duplicate URL anywhere in contest_entries
        $stmt = $db->prepare("SELECT COUNT(*) FROM contest_entries WHERE clip_url = ?");
        $stmt->execute([$clipUrl]);
        if ((int)$stmt->fetchColumn() > 0) {
            $score += 60;
            $flags[] = 'duplicate_url';
        }
    } catch (Throwable) {}

    return [
        'score'       => $score,
        'flags'       => $flags,
        'auto_reject' => $score >= 60,
    ];
}

/**
 * Save a proof file upload and return the relative path, or null on failure.
 */
function saveProofFile(array $file, int $contestId, int $userId, string $platform, string $type): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return null;
    }

    // Validate MIME type using finfo
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!str_starts_with((string)$mimeType, 'image/')) {
        return null;
    }

    $ext     = match($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        default      => 'jpg',
    };

    $root    = dirname(__DIR__);
    $dir     = $root . '/uploads/proofs/' . $contestId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = $userId . '_' . $platform . '_' . $type . '.' . $ext;
    $destPath = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return null;
    }

    return 'uploads/proofs/' . $contestId . '/' . $filename;
}

function saveProofVideo(array $file, int $contestId, int $userId, string $platform): ?string
{
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 50 * 1024 * 1024) return null;
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $allowed  = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov', 'video/x-msvideo' => 'avi'];
    if (!isset($allowed[$mimeType])) return null;
    $ext  = $allowed[$mimeType];
    $root = dirname(__DIR__);
    $dir  = $root . '/uploads/proofs/' . $contestId;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = $userId . '_' . $platform . '_analytics.' . $ext;
    $dest     = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
    return 'uploads/proofs/' . $contestId . '/' . $filename;
}

function handleSubmitClip(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'You must be logged in to submit a clip.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }

    $userId    = (int)$_SESSION['user_id'];
    $contestId = (int)($_POST['contest_id'] ?? 0);
    $platform  = sanitizeInput($_POST['platform'] ?? '');
    $clipUrl   = sanitizeInput($_POST['clip_url'] ?? '');
    $ytHandle  = sanitizeInput($_POST['youtube_handle'] ?? '');

    // Bank details (optional)
    $bankCode     = sanitizeInput($_POST['bank_code'] ?? '');
    $bankName     = sanitizeInput($_POST['bank_name'] ?? '');
    $accountNum   = sanitizeInput($_POST['account_number'] ?? '');
    $accountName  = sanitizeInput($_POST['account_name'] ?? '');

    if (!in_array($platform, ['tiktok', 'instagram', 'facebook'], true)) {
        jsonResponse(['success' => false, 'message' => 'Invalid platform selected.']);
    }

    $urlPatterns = [
        'tiktok'    => '/^https?:\/\/(www\.|vm\.)?tiktok\.com\//i',
        'instagram' => '/^https?:\/\/(www\.)?instagram\.com\//i',
        'facebook'  => '/^https?:\/\/(www\.)?facebook\.com\//i',
    ];
    if (!preg_match($urlPatterns[$platform], $clipUrl)) {
        jsonResponse(['success' => false, 'message' => "Clip URL must be from {$platform}.com."]);
    }

    if (!filter_var($clipUrl, FILTER_VALIDATE_URL)) {
        jsonResponse(['success' => false, 'message' => 'Please enter a valid clip URL.']);
    }

    // Capture submission metadata
    $submissionIp = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $submissionUa = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    try {
        $db   = db();
        $stmt = $db->prepare("SELECT * FROM contests WHERE id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$contestId]);
        $contest = $stmt->fetch();
        if (!$contest) {
            jsonResponse(['success' => false, 'message' => 'Contest is not active.']);
        }
        if (!empty($contest['end_date']) && strtotime($contest['end_date']) < time()) {
            jsonResponse(['success' => false, 'message' => 'Contest has expired.']);
        }

        // Check not already submitted for this platform
        $stmt = $db->prepare('SELECT id FROM contest_entries WHERE contest_id = ? AND user_id = ? AND platform = ? LIMIT 1');
        $stmt->execute([$contestId, $userId, $platform]);
        if ($stmt->fetch()) {
            jsonResponse(['success' => false, 'message' => "You have already submitted a clip for {$platform} in this contest."]);
        }

        // Check platform is enabled for this contest
        $stmt = $db->prepare('SELECT id FROM contest_platforms WHERE contest_id = ? AND platform = ? LIMIT 1');
        $stmt->execute([$contestId, $platform]);
        if (!$stmt->fetch()) {
            jsonResponse(['success' => false, 'message' => 'This platform is not enabled for this contest.']);
        }

        // Bot detection
        $botResult = detectBotActivity($userId, $contestId, $clipUrl, $platform, $submissionIp, $submissionUa);
        $botScore  = $botResult['score'];
        $botFlags  = empty($botResult['flags']) ? null : json_encode($botResult['flags']);

        // Handle proof file uploads
        $proofSubscribePath = null;
        $proofLikePath      = null;
        $proofCommentPath   = null;
        $hasRequiredProofs  = true;

        if (!empty($contest['must_subscribe'])) {
            if (!empty($_FILES['proof_subscribe']['name'])) {
                $proofSubscribePath = saveProofFile($_FILES['proof_subscribe'], $contestId, $userId, $platform, 'subscribe');
            }
            if ($proofSubscribePath === null) {
                $hasRequiredProofs = false;
            }
        }
        if (!empty($contest['must_like'])) {
            if (!empty($_FILES['proof_like']['name'])) {
                $proofLikePath = saveProofFile($_FILES['proof_like'], $contestId, $userId, $platform, 'like');
            }
            if ($proofLikePath === null) {
                $hasRequiredProofs = false;
            }
        }
        if (!empty($contest['must_comment'])) {
            if (!empty($_FILES['proof_comment']['name'])) {
                $proofCommentPath = saveProofFile($_FILES['proof_comment'], $contestId, $userId, $platform, 'comment');
            }
            if ($proofCommentPath === null) {
                $hasRequiredProofs = false;
            }
        }

        // Analytics video proof upload (optional at submission; required to claim prize)
        $proofVideoPath = null;
        if (!empty($_FILES['proof_video']['name'])) {
            $proofVideoPath = saveProofVideo($_FILES['proof_video'], $contestId, $userId, $platform);
        }

        // Determine entry status
        if ($botResult['auto_reject']) {
            $entryStatus  = 'rejected';
            $disqualified = 1;
            $disqualReason = 'Automated/bot activity detected';

            // Auto-ban user
            $db->prepare("UPDATE users SET status = 'banned' WHERE id = ?")->execute([$userId]);

            // Block IP permanently
            try {
                require_once dirname(__DIR__) . '/includes/security.php';
                BruteForceProtection::blockIp($submissionIp, 'permanent', null, 'Bot/artificial activity detected');
            } catch (Throwable) {}

            // Notify user
            sendNotification($userId, 'bot_ban', '🚫 Account Suspended', 'Your account has been permanently suspended for bot/artificial activity in a contest submission. Please contact support if you believe this is an error.', '/dashboard');

            // Notify all admins
            try {
                $adminStmt = $db->query("SELECT id FROM users WHERE role = 'admin'");
                foreach ($adminStmt->fetchAll() as $adm) {
                    sendNotification((int)$adm['id'], 'bot_alert', '⚠️ Bot Activity Detected', "User ID {$userId} was auto-banned for bot activity submitting to contest #{$contestId}.", '/admin/contests.php');
                }
            } catch (Throwable) {}

            // Send ban email to user + admin alert
            try {
                $userRow = $db->prepare("SELECT email, username FROM users WHERE id = ? LIMIT 1");
                $userRow->execute([$userId]);
                $userInfo = $userRow->fetch();
                if ($userInfo) {
                    require_once dirname(__DIR__) . '/includes/email_templates.php';
                    sendEmail($userInfo['email'], 'Your Clipaza account has been suspended', emailBotBanned($userInfo['username']));
                    $adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : getSetting('admin_email', '');
                    if ($adminEmail) {
                        $contestRow = $db->prepare("SELECT title FROM contests WHERE id = ? LIMIT 1");
                        $contestRow->execute([$contestId]);
                        $contestInfo = $contestRow->fetch();
                        sendEmail(
                            $adminEmail,
                            '⚠️ Bot Activity Detected — ' . ($contestInfo['title'] ?? 'Contest'),
                            emailAdminBotAlert($userInfo['username'], $userInfo['email'], $contestInfo['title'] ?? "Contest #{$contestId}", $clipUrl, $botResult['flags'])
                        );
                    }
                }
            } catch (Throwable) {}
        } elseif ($botScore > 0 || !$hasRequiredProofs) {
            $entryStatus  = 'pending';
            $disqualified = 0;
            $disqualReason = null;
        } else {
            $entryStatus  = 'approved';
            $disqualified = 0;
            $disqualReason = null;
        }

        $db->prepare(
            "INSERT INTO contest_entries
                (contest_id, user_id, clip_url, platform, status, disqualified, disqualify_reason,
                 bot_score, bot_flags, submission_ip, submission_ua,
                 proof_subscribe_path, proof_like_path, proof_comment_path, proof_video_path)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $contestId, $userId, $clipUrl, $platform,
            $entryStatus, $disqualified, $disqualReason,
            $botScore, $botFlags, $submissionIp, $submissionUa,
            $proofSubscribePath, $proofLikePath, $proofCommentPath, $proofVideoPath,
        ]);

        $entryId = (int)$db->lastInsertId();

        // Save bank details if provided
        if (!empty($bankCode) && preg_match('/^\d{10}$/', $accountNum) && !empty($accountName)) {
            $db->prepare(
                "UPDATE user_profiles
                 SET bank_name = ?, bank_code = ?, account_number = ?, account_name = ?
                 WHERE user_id = ?"
            )->execute([$bankName ?: null, $bankCode, $accountNum, $accountName, $userId]);
        }

        $message = $botResult['auto_reject']
            ? 'Clip submitted but flagged for review.'
            : 'Clip submitted successfully! Good luck!';

        // Send submission confirmation email (only for non-rejected entries)
        if (!$botResult['auto_reject']) {
            try {
                $userEmailRow = $db->prepare("SELECT email, username FROM users WHERE id = ? LIMIT 1");
                $userEmailRow->execute([$userId]);
                $uInfo = $userEmailRow->fetch();
                if ($uInfo) {
                    require_once dirname(__DIR__) . '/includes/email_templates.php';
                    sendEmail($uInfo['email'], "Clip Submitted — {$contest['title']}", emailContestSubmitted($uInfo['username'], $contest['title'], $platform, $clipUrl));
                }
            } catch (Throwable) {}
        }

        jsonResponse(['success' => true, 'message' => $message, 'entry_id' => $entryId]);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to submit clip. Please try again.']);
    }
}

function handleUpdateViews(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }

    $entryId    = (int)($_POST['entry_id'] ?? 0);
    $viewCount  = max(0, (int)($_POST['view_count'] ?? 0));
    $likeCount  = max(0, (int)($_POST['like_count'] ?? 0));
    $commentCnt = max(0, (int)($_POST['comment_count'] ?? 0));

    try {
        $db = db();
        $db->prepare(
            'UPDATE contest_entries SET view_count = ?, like_count = ?, comment_count = ? WHERE id = ?'
        )->execute([$viewCount, $likeCount, $commentCnt, $entryId]);
        jsonResponse(['success' => true]);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Update failed.']);
    }
}

function handleGetLeaderboard(): never {
    $contestId = (int)($_GET['contest_id'] ?? 0);
    $platform  = sanitizeInput($_GET['platform'] ?? '');

    if (!in_array($platform, ['tiktok', 'instagram', 'facebook'], true)) {
        jsonResponse(['success' => false, 'message' => 'Invalid platform.']);
    }

    try {
        $db   = db();
        $stmt = $db->prepare(
            "SELECT ce.id, u.username, ce.view_count, ce.like_count, ce.rank_position
             FROM contest_entries ce
             INNER JOIN users u ON u.id = ce.user_id
             WHERE ce.contest_id = ? AND ce.platform = ? AND ce.disqualified = 0
             ORDER BY ce.view_count DESC, ce.like_count DESC
             LIMIT 10"
        );
        $stmt->execute([$contestId, $platform]);
        jsonResponse(['success' => true, 'entries' => $stmt->fetchAll()]);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Failed to load leaderboard.']);
    }
}


function handleDisqualifyEntry(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }

    $entryId = (int)($_POST['entry_id'] ?? 0);
    $reason  = sanitizeInput($_POST['reason'] ?? '');
    $userId  = (int)$_SESSION['user_id'];

    if (!$entryId) {
        jsonResponse(['success' => false, 'message' => 'Invalid entry ID.']);
    }

    try {
        $db   = db();
        // Verify caller owns the contest (or is admin)
        $stmt = $db->prepare(
            "SELECT ce.id, c.creator_id FROM contest_entries ce
             INNER JOIN contests c ON c.id = ce.contest_id
             WHERE ce.id = ? LIMIT 1"
        );
        $stmt->execute([$entryId]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonResponse(['success' => false, 'message' => 'Entry not found.']);
        }
        $isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
        if (!$isAdmin && (int)$row['creator_id'] !== $userId) {
            jsonResponse(['success' => false, 'message' => 'You do not have permission to disqualify this entry.'], 403);
        }

        $db->prepare(
            "UPDATE contest_entries
             SET disqualified = 1, disqualify_reason = ?, status = 'rejected'
             WHERE id = ?"
        )->execute([$reason ?: 'Disqualified by creator', $entryId]);

        jsonResponse(['success' => true, 'message' => 'Entry disqualified.']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Action failed.']);
    }
}
