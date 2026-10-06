<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/activity_log.php';
require_once __DIR__ . '/../config/system_settings.php';

$page_title = 'InfoConnect | Log In';
$formError = '';

if (isset($_SESSION['user_id'])) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        header('Location: /BMirk/admin/home.php');
        exit;
    }
    if (($_SESSION['role'] ?? '') === 'staff') {
        header('Location: /BMirk/staff/home.php');
        exit;
    }
    if (($_SESSION['role'] ?? '') === 'senior') {
        header('Location: /BMirk/senior/home.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountId = trim($_POST['account_id'] ?? '');
    $password = $_POST['password'] ?? '';
    $systemSettings = loadSystemSettings($databaseConnection);
    $autoDeactivateDays = max(1, (int) $systemSettings['auto_deactivate_days']);
    $deactivateBefore = (new DateTimeImmutable())->modify('-' . $autoDeactivateDays . ' days')->format('Y-m-d H:i:s');
    $deactivateStatement = $databaseConnection->prepare(
        "UPDATE users SET status = 'inactive'
         WHERE role IN ('staff', 'senior') AND status = 'active'
           AND COALESCE(last_login, created_at) < ?"
    );
    $deactivateStatement->bind_param('s', $deactivateBefore);
    $deactivateStatement->execute();
    $deactivateStatement->close();

    if ($accountId === '' || $password === '') {
        $formError = 'Account ID and password are required.';
    } else {
        $stmt = $databaseConnection->prepare(
            'SELECT id, account_id, password, role, status, must_change_password, created_at,
                    COALESCE((SELECT MAX(ph.created_at) FROM password_history ph WHERE ph.user_id = users.id), created_at) AS password_set_at
             FROM users WHERE account_id = ? LIMIT 1'
        );
        $stmt->bind_param('s', $accountId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        $attemptStatement = $databaseConnection->prepare('SELECT failed_attempts, locked_until, updated_at FROM system_login_attempts WHERE account_id = ? LIMIT 1');
        $attemptStatement->bind_param('s', $accountId);
        $attemptStatement->execute();
        $attempt = $attemptStatement->get_result()->fetch_assoc();
        $attemptStatement->close();
        $attemptLimit = max(1, (int) $systemSettings['login_attempt_limit']);

        if (!$user) {
            $formError = 'Invalid account ID or password.';
        } elseif ($attempt && $attempt['locked_until'] && strtotime($attempt['locked_until']) > time()) {
            $formError = 'Too many failed sign-in attempts. Try again after ' . date('h:i A', strtotime($attempt['locked_until'])) . '.';
        } elseif ($user['status'] !== 'active') {
            $formError = 'This account is currently inactive.';
        } elseif (!password_verify($password, $user['password'])) {
            $attemptWindowActive = $attempt && strtotime($attempt['updated_at']) > time() - 15 * 60;
            $failedAttempts = $attemptWindowActive ? (int) $attempt['failed_attempts'] + 1 : 1;
            $lockedUntil = $failedAttempts >= $attemptLimit ? date('Y-m-d H:i:s', time() + 15 * 60) : null;
            $failedAttemptStatement = $databaseConnection->prepare(
                'INSERT INTO system_login_attempts (account_id, failed_attempts, locked_until)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE failed_attempts = VALUES(failed_attempts), locked_until = VALUES(locked_until)'
            );
            $failedAttemptStatement->bind_param('sis', $accountId, $failedAttempts, $lockedUntil);
            $failedAttemptStatement->execute();
            $failedAttemptStatement->close();
            $formError = $lockedUntil
                ? 'Too many failed sign-in attempts. Try again after ' . date('h:i A', strtotime($lockedUntil)) . '.'
                : 'Invalid account ID or password.';
        } else {
            $clearAttemptsStatement = $databaseConnection->prepare('DELETE FROM system_login_attempts WHERE account_id = ?');
            $clearAttemptsStatement->bind_param('s', $accountId);
            $clearAttemptsStatement->execute();
            $clearAttemptsStatement->close();
            $lastLoginUpdate = $databaseConnection->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
            $lastLoginUpdate->bind_param('i', $user['id']);
            $lastLoginUpdate->execute();
            $lastLoginUpdate->close();

            $passwordExpirationDays = max(0, (int) $systemSettings['password_expiration_days']);
            $passwordExpired = $passwordExpirationDays > 0
                && strtotime($user['password_set_at']) <= strtotime('-' . $passwordExpirationDays . ' days');
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['account_id'] = $user['account_id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['password_change_required'] = (int) $user['must_change_password'] === 1 || $passwordExpired;
            writeActivityLog(
                $databaseConnection,
                (int) $user['id'],
                'login',
                'Authentication',
                'Successful sign-in.'
            );

            $rememberCookieOptions = [
                'expires' => isset($_POST['remember_me']) ? time() + 60 * 60 * 24 * 30 : time() - 3600,
                'path' => '/BMirk',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ];
            setcookie('infoconnect_remember', isset($_POST['remember_me']) ? '1' : '', $rememberCookieOptions);

            if ($_SESSION['password_change_required']) {
                header('Location: /BMirk/auth/change_password.php');
                exit;
            }
            if ($user['role'] === 'admin') {
                header('Location: /BMirk/admin/home.php');
                exit;
            }
            if ($user['role'] === 'staff') {
                header('Location: /BMirk/staff/home.php');
                exit;
            }
            header('Location: /BMirk/senior/home.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="assets/login.css">
    <link rel="stylesheet" href="assets/logo.css">
</head>
<body>
    <div class="page-shell">
        <main class="login-layout">
            <div class="login-container">
                <section class="brand-panel" aria-label="Department branding">
                    <?php include __DIR__ . '/logo.php'; ?>
                </section>

                <section class="form-panel" aria-label="Login form">
                    <form class="login-card" method="POST" action="">
                        <h3>Welcome!</h3>
                        <p class="subtitle">Enter your details below to continue to InfoConnect.</p>

                        <?php if ($formError !== ''): ?>
                            <div class="error-box" style="margin-bottom: 1rem; padding: 0.75rem 1rem; border-radius: 8px; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; font-size: 0.9rem;">
                                <?php echo htmlspecialchars($formError); ?>
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="account_id">Account ID</label>
                            <input id="account_id" name="account_id" type="text" placeholder="Enter your account ID" value="<?php echo htmlspecialchars($_POST['account_id'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <input id="password" name="password" type="password" placeholder="Enter your password" required>
                        </div>

                        <div class="meta-row">
                            <label class="remember">
                                <input type="checkbox" name="remember_me" value="1" <?php echo isset($_POST['remember_me']) ? 'checked' : ''; ?>>
                                <span>Remember me</span>
                            </label>

                            <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
                        </div>

                        <button class="btn-login" type="submit">Log in</button>

                        <div class="signup-text">
                            No account yet? <a href="request_account.php">Request Account</a>
                        </div>
                    </form>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
