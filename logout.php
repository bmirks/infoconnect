<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/activity_log.php';

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
if ($userId !== null) {
    try {
        require_once __DIR__ . '/../config/database.php';
        writeActivityLog($databaseConnection, $userId, 'logout', 'Authentication', 'Signed out.');
    } catch (mysqli_sql_exception $exception) {
        error_log('Unable to record logout activity: ' . $exception->getMessage());
    }
}
$_SESSION = [];
setcookie('infoconnect_remember', '', [
    'expires' => time() - 3600,
    'path' => '/BMirk',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();
header('Location: /BMirk/auth/login.php');
exit;
