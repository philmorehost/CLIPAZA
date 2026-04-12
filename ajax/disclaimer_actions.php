<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$action = sanitizeInput($_POST['action'] ?? '');
switch ($action) {
    case 'accept_disclaimer':
        handleAcceptDisclaimer();
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
}

function handleAcceptDisclaimer(): never
{
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(['success' => false, 'message' => 'Invalid request.'], 403);
    }
    try {
        $db = db();
        $db->prepare(
            "UPDATE user_profiles SET disclaimer_accepted = 1, disclaimer_accepted_at = NOW() WHERE user_id = ?"
        )->execute([(int)$_SESSION['user_id']]);
        $_SESSION['disclaimer_accepted'] = true;
        jsonResponse(['success' => true]);
    } catch (Throwable) {
        jsonResponse(['success' => false, 'message' => 'Failed to save.']);
    }
}
