<?php
declare(strict_types=1);
session_start();

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';

if (!empty($_SESSION['admin_impersonating']) && !empty($_SESSION['original_admin_id'])) {
    $adminId   = (int)$_SESSION['original_admin_id'];
    $adminName = $_SESSION['original_admin_name'] ?? 'Admin';

    // Load admin data
    try {
        require_once $root . '/includes/db.php';
        $db   = db();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND role = ? LIMIT 1');
        $stmt->execute([$adminId, 'admin']);
        $admin = $stmt->fetch();

        if ($admin) {
            $_SESSION['user_id']    = $admin['id'];
            $_SESSION['username']   = $admin['username'];
            $_SESSION['user_role']  = $admin['role'];
            $_SESSION['user_email'] = $admin['email'];
            $_SESSION['user_mode']  = 'creator';
            unset($_SESSION['admin_impersonating'], $_SESSION['original_admin_id'], $_SESSION['original_admin_name']);
            redirect('/admin/');
        }
    } catch (Throwable) {}
}

// If not impersonating, redirect
redirect(isLoggedIn() ? '/admin/' : '/admin/login.php');
