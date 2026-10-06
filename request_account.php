<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/activity_log.php';
require_once __DIR__ . '/../config/system_settings.php';

$systemSettings = loadSystemSettings($databaseConnection);

$pageTitle = 'InfoConnect | Request Senior Account';
$error = '';
$issuedCredentials = $_SESSION['issued_senior_credentials'] ?? null;
unset($_SESSION['issued_senior_credentials']);

if (empty($_SESSION['senior_account_request_token'])) {
    $_SESSION['senior_account_request_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['senior_account_request_token'], $submittedToken)) {
        $error = 'This form has expired. Refresh the page and try again.';
    } else {
        $seniorCitizenId = trim(is_string($_POST['senior_citizen_id'] ?? null) ? $_POST['senior_citizen_id'] : '');
        $firstName = trim(is_string($_POST['first_name'] ?? null) ? $_POST['first_name'] : '');
        $lastName = trim(is_string($_POST['last_name'] ?? null) ? $_POST['last_name'] : '');
        $birthDate = trim(is_string($_POST['birth_date'] ?? null) ? $_POST['birth_date'] : '');

        $validBirthDate = DateTime::createFromFormat('!Y-m-d', $birthDate);
        if ($seniorCitizenId === '' || $firstName === '' || $lastName === '' || !$validBirthDate || $validBirthDate->format('Y-m-d') !== $birthDate) {
            $error = 'Enter your senior citizen ID, first name, last name, and a valid birth date.';
        } else {
            $transactionStarted = false;
            try {
                $databaseConnection->begin_transaction();
                $transactionStarted = true;

                $seniorStatement = $databaseConnection->prepare(
                    "SELECT id, user_id FROM senior_profiles
                     WHERE senior_citizen_id = ? AND first_name = ? AND last_name = ? AND birth_date = ?
                       AND status = 'active' AND approval_status = 'approved'
                     LIMIT 1 FOR UPDATE"
                );
                if (!$seniorStatement) {
                    throw new RuntimeException('Unable to verify the senior profile.');
                }
                $seniorStatement->bind_param('ssss', $seniorCitizenId, $firstName, $lastName, $birthDate);
                $seniorStatement->execute();
                $senior = $seniorStatement->get_result()->fetch_assoc();
                $seniorStatement->close();

                if (!$senior) {
                    throw new DomainException('The details could not be verified. Only an approved senior profile without an account can request one.');
                }
                if (!empty($senior['user_id'])) {
                    throw new DomainException('An account has already been created for this senior profile. Please use the Forgot Password option if needed.');
                }

                $accountPrefix = 'SEN-';
                $accountPattern = $accountPrefix . '%';
                $accountStatement = $databaseConnection->prepare('SELECT account_id FROM users WHERE account_id LIKE ? FOR UPDATE');
                if (!$accountStatement) {
                    throw new RuntimeException('Unable to generate a senior account ID.');
                }
                $accountStatement->bind_param('s', $accountPattern);
                $accountStatement->execute();
                $accountResult = $accountStatement->get_result();
                $highestAccountNumber = 0;
                while ($accountRow = $accountResult->fetch_assoc()) {
                    if (preg_match('/^' . preg_quote($accountPrefix, '/') . '(\d+)$/i', $accountRow['account_id'], $matches)) {
                        $highestAccountNumber = max($highestAccountNumber, (int) $matches[1]);
                    }
                }
                $accountStatement->close();

                $accountId = sprintf('%s%02d', $accountPrefix, $highestAccountNumber + 1);
                $temporaryPassword = generateSystemTemporaryPassword($systemSettings);
                $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
                $role = 'senior';
                $mustChangePassword = (int) $systemSettings['require_password_change_first_login'];
                $userStatement = $databaseConnection->prepare(
                    'INSERT INTO users (account_id, password, role, status, must_change_password) VALUES (?, ?, ?, \'active\', ?)'
                );
                if (!$userStatement) {
                    throw new RuntimeException('Unable to create the senior account.');
                }
                $userStatement->bind_param('sssi', $accountId, $passwordHash, $role, $mustChangePassword);
                $userStatement->execute();
                $userId = (int) $databaseConnection->insert_id;
                $userStatement->close();

                $linkStatement = $databaseConnection->prepare('UPDATE senior_profiles SET user_id = ? WHERE id = ? AND user_id IS NULL');
                if (!$linkStatement) {
                    throw new RuntimeException('Unable to link the account to the senior profile.');
                }
                $seniorProfileId = (int) $senior['id'];
                $linkStatement->bind_param('ii', $userId, $seniorProfileId);
                $linkStatement->execute();
                if ($linkStatement->affected_rows !== 1) {
                    throw new RuntimeException('The senior account could not be linked to the profile.');
                }
                $linkStatement->close();

                writeActivityLog(
                    $databaseConnection,
                    $userId,
                    'create',
                    'Accounts',
                    'Senior account ' . $accountId . ' was created for an approved senior profile.',
                    $userId
                );
                $databaseConnection->commit();
                $transactionStarted = false;
                $_SESSION['issued_senior_credentials'] = [
                    'account_id' => $accountId,
                    'temporary_password' => $temporaryPassword,
                ];
                $_SESSION['senior_account_request_token'] = bin2hex(random_bytes(32));
                header('Location: /BMirk/auth/request_account.php', true, 303);
                exit;
            } catch (DomainException $exception) {
                if ($transactionStarted) {
                    $databaseConnection->rollback();
                }
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                if ($transactionStarted) {
                    $databaseConnection->rollback();
                }
                error_log('Senior account request failed: ' . $exception->getMessage());
                $error = 'The account could not be created right now. Check your details and try again.';
            }
        }
    }
}

function escapeSeniorAccountRequest($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeSeniorAccountRequest($pageTitle); ?></title>
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
                <section class="form-panel" aria-label="Senior account request">
                    <div class="login-card">
                        <h3>Request Account</h3>
                        <p class="subtitle">This request is for registered senior citizens.</p>
                        <?php if ($error !== ''): ?>
                            <div class="error-box" role="alert"><?php echo escapeSeniorAccountRequest($error); ?></div>
                        <?php endif; ?>
                        <?php if (is_array($issuedCredentials)): ?>
                            <div class="success-box" role="status">
                                <h4>Your senior account is ready</h4>
                                <p>Save these credentials. The temporary password is shown only once.</p>
                                <p><strong>Account ID:</strong> <code><?php echo escapeSeniorAccountRequest($issuedCredentials['account_id']); ?></code></p>
                                <p><strong>Temporary password:</strong> <code><?php echo escapeSeniorAccountRequest($issuedCredentials['temporary_password']); ?></code></p>
                            </div>
                        <?php else: ?>
                            <form method="post" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo escapeSeniorAccountRequest($_SESSION['senior_account_request_token']); ?>">
                                <div class="form-group">
                                    <label for="senior_citizen_id">Senior citizen ID</label>
                                    <input id="senior_citizen_id" name="senior_citizen_id" type="text" maxlength="50" required>
                                </div>
                                <div class="form-group">
                                    <label for="first_name">First name</label>
                                    <input id="first_name" name="first_name" type="text" maxlength="100" autocomplete="given-name" required>
                                </div>
                                <div class="form-group">
                                    <label for="last_name">Last name</label>
                                    <input id="last_name" name="last_name" type="text" maxlength="100" autocomplete="family-name" required>
                                </div>
                                <div class="form-group">
                                    <label for="birth_date">Date of birth</label>
                                    <input id="birth_date" name="birth_date" type="date" autocomplete="bday" required>
                                </div>
                                <button class="btn-login" type="submit">Verify and request account</button>
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
