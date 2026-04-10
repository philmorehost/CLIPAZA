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
    case 'verify_engagement':
        handleVerifyEngagement();
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
}

function handleFetchYoutubeInfo(): never {
    $youtubeUrl = sanitizeInput($_REQUEST['youtube_url'] ?? '');
    if (empty($youtubeUrl)) {
        jsonResponse(['success' => false, 'message' => 'YouTube URL is required.']);
    }

    // Extract video ID from URL
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

    // Try YouTube Data API first if key configured
    $apiKey = getSetting('youtube_api_key', '');
    if ($apiKey) {
        $apiUrl = 'https://www.googleapis.com/youtube/v3/videos?id=' . urlencode($videoId)
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

    // Fallback: YouTube oEmbed
    $oembedUrl  = 'https://www.youtube.com/oembed?url=' . urlencode('https://www.youtube.com/watch?v=' . $videoId) . '&format=json';
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
        $data = json_decode($response, true);
        $thumbUrl = $data['thumbnail_url'] ?? 'https://img.youtube.com/vi/' . $videoId . '/hqdefault.jpg';
        jsonResponse([
            'success'       => true,
            'video_id'      => $videoId,
            'title'         => $data['title'] ?? '',
            'thumbnail_url' => $thumbUrl,
        ]);
    }

    // Final fallback: use standard thumbnail URL
    jsonResponse([
        'success'       => true,
        'video_id'      => $videoId,
        'title'         => 'YouTube Video (' . $videoId . ')',
        'thumbnail_url' => 'https://img.youtube.com/vi/' . $videoId . '/hqdefault.jpg',
    ]);
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

    try {
        $db = db();
        $stmt = $db->prepare("SELECT id, status, end_date FROM contests WHERE id = ? AND status = 'active' LIMIT 1");
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

        $db->prepare(
            "INSERT INTO contest_entries (contest_id, user_id, clip_url, platform, status)
             VALUES (?, ?, ?, ?, 'approved')"
        )->execute([$contestId, $userId, $clipUrl, $platform]);

        $entryId = (int)$db->lastInsertId();
        jsonResponse(['success' => true, 'message' => 'Clip submitted successfully! Good luck!', 'entry_id' => $entryId]);
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

function handleVerifyEngagement(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }

    $contestId = (int)($_POST['contest_id'] ?? 0);
    $accessToken = $_SESSION['google_access_token'] ?? ''; // We need to store this in session during login

    if (empty($accessToken)) {
        jsonResponse(['success' => false, 'message' => 'YouTube access token not found. Please log in with Google again.']);
    }

    require_once dirname(__DIR__) . '/includes/youtube.php';

    try {
        $db = db();
        $stmt = $db->prepare('SELECT * FROM contests WHERE id = ? LIMIT 1');
        $stmt->execute([$contestId]);
        $contest = $stmt->fetch();

        if (!$contest) {
            jsonResponse(['success' => false, 'message' => 'Contest not found.']);
        }

        $requirements = [
            'subscribe' => (bool)$contest['must_subscribe'],
            'like' => (bool)$contest['must_like'],
            'comment' => (bool)$contest['must_comment']
        ];

        // We need the creator's channel ID if subscription is required.
        // It's probably stored in user_profiles of the creator.
        $creatorChannelId = '';
        if ($requirements['subscribe']) {
            $stmt = $db->prepare('SELECT youtube_channel_id FROM user_profiles WHERE user_id = ? LIMIT 1');
            $stmt->execute([$contest['creator_id']]);
            $creatorChannelId = $stmt->fetchColumn() ?: '';
        }

        $result = verifyYoutubeEngagement($accessToken, $contest['youtube_video_id'], $creatorChannelId, $requirements);

        // Update entry verification status if it exists
        $stmt = $db->prepare('UPDATE contest_entries SET verified_subscribe = ?, verified_like = ?, verified_comment = ? WHERE contest_id = ? AND user_id = ?');
        $stmt->execute([
            (int)$result['verified']['subscribe'],
            (int)$result['verified']['like'],
            (int)$result['verified']['comment'],
            $contestId,
            $_SESSION['user_id']
        ]);

        if ($result['success']) {
            jsonResponse(['success' => true, 'message' => 'Engagement verified successfully!']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Verification failed: ' . implode(' ', $result['errors']), 'details' => $result['verified']]);
        }
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'An error occurred during verification.']);
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
