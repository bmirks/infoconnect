<?php
if (session_status() === PHP_SESSION_NONE) {
    $rememberSession = isset($_COOKIE['infoconnect_remember']) && $_COOKIE['infoconnect_remember'] === '1';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'login.php') {
        $rememberSession = isset($_POST['remember_me']);
    }

    if ($rememberSession) {
        ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
    }

    session_set_cookie_params([
        'lifetime' => $rememberSession ? 60 * 60 * 24 * 30 : 0,
        'path' => '/BMirk',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /BMirk/auth/login.php');
        exit;
    }
    if (!empty($_SESSION['password_change_required']) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'change_password.php') {
        header('Location: /BMirk/auth/change_password.php');
        exit;
    }
}

function flashMessage($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}
