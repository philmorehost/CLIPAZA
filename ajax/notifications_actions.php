<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$action = sanitizeInput($_REQUEST['action'] ?? '');

switch ($action) {
    case 'get_count':
        handleGetCount();
        break;
    case 'clear_all':
        handleClearAll();
        break;
    case 'mark_read':
        handleMarkRead();
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
}

function handleGetCount(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['count' => 0]);
    }
    $count = getUnreadNotificationCount((int)$_SESSION['user_id']);
    jsonResponse(['success' => true, 'count' => $count]);
}

function handleClearAll(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }
    try {
        $db = db();
        $db->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([(int)$_SESSION['user_id']]);
        jsonResponse(['success' => true, 'message' => 'Cleared.']);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Failed.']);
    }
}

function handleMarkRead(): never {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }
    try {
        $db = db();
        $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([(int)$_SESSION['user_id']]);
        jsonResponse(['success' => true]);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Failed.']);
    }
}
