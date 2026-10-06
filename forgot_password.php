<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/activity_log.php';
require_once __DIR__ . '/../config/system_settings.php';

$systemSettings = loadSystemSettings($databaseConnection);

$pageTitle = 'InfoConnect | Reset Password';
$error = '';
$success = $_SESSION['password_reset_success'] ?? '';
unset($_SESSION['password_reset_success']);
$resetUserId = (int) ($_SESSION['password_reset_user_id'] ?? 0);

if (empty($_SESSION['password_recovery_token'])) {
    $_SESSION['password_recovery_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['password_recovery_token'], $submittedToken)) {
        $error = 'This form has expired. Refresh the page and try again.';
    } elseif (($_POST['step'] ?? '') === 'verify') {
        $accountId = trim(is_string($_POST['account_id'] ?? null) ? $_POST['account_id'] : '');
        $registeredContact = trim(is_string($_POST['registered_contact'] ?? null) ? $_POST['registered_contact'] : '');

        if ($accountId === '' || $registeredContact === '') {
            $error = 'Account ID and registered email or contact number are required.';
        } else {
            $statement = $databaseConnection->prepare(
                'SELECT u.id, u.role, ap.email AS admin_email, ap.contact_number AS admin_contact,
                        st.email AS staff_email, st.contact_number AS staff_contact,
                        sp.contact_number AS senior_contact
                 FROM users u
                 LEFT JOIN admin_profiles ap ON ap.user_id = u.id
                 LEFT JOIN staff_profiles st ON st.user_id = u.id
                 LEFT JOIN senior_profiles sp ON sp.user_id = u.id
                 WHERE u.account_id = ? AND u.status = \'active\'
                 LIMIT 1'
            );

            if (!$statement) {
                error_log('Password recovery lookup could not be prepared: ' . $databaseConnection->error);
                $error = 'Password recovery is temporarily unavailable. Please try again.';
            } else {
                $statement->bind_param('s', $accountId);
                $statement->execute();
                $user = $statement->get_result()->fetch_assoc();
                $statement->close();

                $savedContacts = [];
                if ($user) {
                    if ($user['role'] === 'senior') {
                        $savedContacts[] = trim((string) $user['senior_contact']);
                    } elseif ($user['role'] === 'staff') {
                        $savedContacts[] = trim((string) $user['staff_email']);
                        $savedContacts[] = trim((string) $user['staff_contact']);
                    } elseif ($user['role'] === 'admin') {
                        $savedContacts[] = trim((string) $user['admin_email']);
                        $savedContacts[] = trim((string) $user['admin_contact']);
                    }
                }

                $matches = false;
                foreach ($savedContacts as $savedContact) {
                    if ($savedContact !== '' && hash_equals(strtolower($savedContact), strtolower($registeredContact))) {
                        $matches = true;
                        break;
                    }
                }

                if ($matches) {
                    $_SESSION['password_reset_user_id'] = (int) $user['id'];
                    $_SESSION['password_reset_token'] = bin2hex(random_bytes(32));
                    $resetUserId = (int) $user['id'];
                } else {
                    unset($_SESSION['password_reset_user_id'], $_SESSION['password_reset_token']);
                    $resetUserId = 0;
                    $error = 'The account details could not be verified. Check the information and try again.';
                }
            }
        }
    } elseif (($_POST['step'] ?? '') === 'reset' && $resetUserId > 0) {
        $resetToken = $_POST['reset_token'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!is_string($resetToken) || !isset($_SESSION['password_reset_token'])
            || !hash_equals($_SESSION['password_reset_token'], $resetToken)) {
            $error = 'This password reset form has expired. Verify your details again.';
            unset($_SESSION['password_reset_user_id'], $_SESSION['password_reset_token']);
            $resetUserId = 0;
        } elseif (!is_string($newPassword)) {
            $error = 'Enter a valid new password.';
        } elseif (($policyErrors = systemPasswordPolicyErrors($newPassword, $systemSettings)) !== []) {
            $error = implode(' ', $policyErrors);
        } elseif (!is_string($confirmPassword) || !hash_equals($newPassword, $confirmPassword)) {
            $error = 'The new password and confirmation do not match.';
        } else {
            $transactionStarted = false;
            try {
                $databaseConnection->begin_transaction();
                $transactionStarted = true;

                $currentStatement = $databaseConnection->prepare(
                    'SELECT password FROM users WHERE id = ? AND status = \'active\' LIMIT 1 FOR UPDATE'
                );
                if (!$currentStatement) {
                    throw new RuntimeException('Password reset account lookup could not be prepared: ' . $databaseConnection->error);
                }
                $currentStatement->bind_param('i', $resetUserId);
                if (!$currentStatement->execute()) {
                    throw new RuntimeException('Password reset account lookup failed: ' . $currentStatement->error);
                }
                $currentUser = $currentStatement->get_result()->fetch_assoc();
                $currentStatement->close();

                if (!$currentUser) {
                    throw new RuntimeException('Password reset account is unavailable.');
                }
                if (password_verify($newPassword, $currentUser['password'])) {
                    $error = 'Choose a password that is different from your current password.';
                }

                if ($error === '') {
                    $historyStatement = $databaseConnection->prepare(
                        'SELECT password_hash FROM password_history WHERE user_id = ?'
                    );
                    if (!$historyStatement) {
                        throw new RuntimeException('Password history lookup could not be prepared: ' . $databaseConnection->error);
                    }
                    $historyStatement->bind_param('i', $resetUserId);
                    if (!$historyStatement->execute()) {
                        throw new RuntimeException('Password history lookup failed: ' . $historyStatement->error);
                    }
                    $historyResult = $historyStatement->get_result();
                    while ($historyRow = $historyResult->fetch_assoc()) {
                        if (password_verify($newPassword, $historyRow['password_hash'])) {
                            $error = 'This password has been used before. Choose a different password.';
                            break;
                        }
                    }
                    $historyStatement->close();
                }

                if ($error !== '') {
                    $databaseConnection->rollback();
                    $transactionStarted = false;
                } else {
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    if ($passwordHash === false) {
                        throw new RuntimeException('Password reset hash generation failed.');
                    }

                    $historyInsert = $databaseConnection->prepare(
                        'INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)'
                    );
                    $updateStatement = $databaseConnection->prepare(
                        'UPDATE users SET password = ?, must_change_password = 0 WHERE id = ? AND status = \'active\''
                    );
                    if (!$historyInsert || !$updateStatement) {
                        throw new RuntimeException('Password reset statements could not be prepared: ' . $databaseConnection->error);
                    }

                    $historyInsert->bind_param('is', $resetUserId, $currentUser['password']);
                    if (!$historyInsert->execute()) {
                        throw new RuntimeException('Password history could not be saved: ' . $historyInsert->error);
                    }
                    $historyInsert->close();

                    $updateStatement->bind_param('si', $passwordHash, $resetUserId);
                    if (!$updateStatement->execute() || $updateStatement->affected_rows !== 1) {
                        throw new RuntimeException('Password reset update failed: ' . $updateStatement->error);
                    }
                    $updateStatement->close();

                    writeActivityLog(
                        $databaseConnection,
                        (int) $resetUserId,
                        'reset',
                        'Authentication',
                        'Password reset completed.'
                    );
                    $databaseConnection->commit();
                    $transactionStarted = false;
                    unset($_SESSION['password_reset_user_id'], $_SESSION['password_reset_token']);
                    $_SESSION['password_reset_success'] = 'Your password has been changed. You can now log in.';
                    header('Location: /BMirk/auth/forgot_password.php', true, 303);
                    exit;
                }
            } catch (Throwable $exception) {
                if ($transactionStarted) {
                    $databaseConnection->rollback();
                }
                error_log('Password reset failed: ' . $exception->getMessage());
                $error = 'The password could not be updated. Verify your details and try again.';
                unset($_SESSION['password_reset_user_id'], $_SESSION['password_reset_token']);
                $resetUserId = 0;
            }
        }
    } else {
        $error = 'Submit the requested form to continue.';
    }
}

function escapePasswordRecovery($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapePasswordRecovery($pageTitle); ?></title>
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
                <section class="form-panel" aria-label="Password recovery">
                    <div class="login-card">
                        <h3>Forgot Password?</h3>
                        <p class="subtitle">Verify your account details to choose a new password.</p>
                        <?php if ($success !== ''): ?>
                            <div class="success-box" role="status"><?php echo escapePasswordRecovery($success); ?></div>
                        <?php endif; ?>
                        <?php if ($error !== ''): ?>
                            <div class="error-box" role="alert"><?php echo escapePasswordRecovery($error); ?></div>
                        <?php endif; ?>

                        <?php if ($resetUserId > 0): ?>
                            <form method="post" action="">
                                <input type="hidden" name="step" value="reset">
                                <input type="hidden" name="csrf_token" value="<?php echo escapePasswordRecovery($_SESSION['password_recovery_token']); ?>">
                                <input type="hidden" name="reset_token" value="<?php echo escapePasswordRecovery($_SESSION['password_reset_token'] ?? ''); ?>">
                                <div class="form-group">
                                    <label for="new_password">New password</label>
                                    <input id="new_password" name="new_password" type="password" minlength="8" autocomplete="new-password" required>
                                </div>
                                <div class="form-group">
                                    <label for="confirm_password">Confirm new password</label>
                                    <input id="confirm_password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required>
                                </div>
                                <button class="btn-login" type="submit">Save new password</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="">
                                <input type="hidden" name="step" value="verify">
                                <input type="hidden" name="csrf_token" value="<?php echo escapePasswordRecovery($_SESSION['password_recovery_token']); ?>">
                                <div class="form-group">
                                    <label for="account_id">Account ID</label>
                                    <input id="account_id" name="account_id" type="text" maxlength="50" autocomplete="username" required>
                                </div>
                                <div class="form-group">
                                    <label for="registered_contact">Registered email or contact number</label>
                                    <input id="registered_contact" name="registered_contact" type="text" maxlength="150" autocomplete="email" required>
                                </div>
                                <button class="btn-login" type="submit">Verify account</button>
                            </form>
                        <?php endif; ?>
                        <div class="signup-text"><a href="login.php">Back to log in</a></div>
                    </div>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
