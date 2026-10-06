<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/activity_log.php';
require_once __DIR__ . '/../config/system_settings.php';
requireLogin();

$userId = (int) $_SESSION['user_id'];
$errors = [];
if (empty($_SESSION['forced_password_token'])) {
	$_SESSION['forced_password_token'] = bin2hex(random_bytes(32));
}
$settings = loadSystemSettings($databaseConnection);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	$currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
	$newPassword = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
	$confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
	$token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
	if (!hash_equals($_SESSION['forced_password_token'], $token)) {
		$errors[] = 'Your session token expired. Refresh the page and try again.';
	}
	if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
		$errors[] = 'Complete all password fields.';
	}
	$errors = array_merge($errors, systemPasswordPolicyErrors($newPassword, $settings));
	if ($confirmPassword !== '' && $newPassword !== $confirmPassword) {
		$errors[] = 'The new password and confirmation do not match.';
	}

	$user = null;
	if (!$errors) {
		$statement = $databaseConnection->prepare('SELECT password, role FROM users WHERE id = ? AND status = \'active\' LIMIT 1');
		$statement->bind_param('i', $userId);
		$statement->execute();
		$user = $statement->get_result()->fetch_assoc() ?: null;
		$statement->close();
		if (!$user || !password_verify($currentPassword, $user['password'])) {
			$errors[] = 'The current password is incorrect.';
		} elseif (password_verify($newPassword, $user['password'])) {
			$errors[] = 'Choose a new password that is different from your current password.';
		}
	}

	if (!$errors && $user) {
		$historyStatement = $databaseConnection->prepare('SELECT password_hash FROM password_history WHERE user_id = ?');
		$historyStatement->bind_param('i', $userId);
		$historyStatement->execute();
		$historyResult = $historyStatement->get_result();
		while ($historyRow = $historyResult->fetch_assoc()) {
			if (password_verify($newPassword, $historyRow['password_hash'])) {
				$errors[] = 'This password has been used before. Choose a different password.';
				break;
			}
		}
		$historyStatement->close();
	}

	if (!$errors && $user) {
		$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
		if ($newHash === false) {
			$errors[] = 'Unable to secure the new password. Please try again.';
		} else {
			try {
				$databaseConnection->begin_transaction();
				$historyInsert = $databaseConnection->prepare('INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)');
				$update = $databaseConnection->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ? AND status = \'active\'');
				if (!$historyInsert || !$update) {
					throw new RuntimeException('Unable to prepare password update.');
				}
				$historyInsert->bind_param('is', $userId, $user['password']);
				if (!$historyInsert->execute()) {
					throw new RuntimeException('Unable to save password history.');
				}
				$update->bind_param('si', $newHash, $userId);
				if (!$update->execute() || $update->affected_rows !== 1) {
					throw new RuntimeException('Unable to update password.');
				}
				writeActivityLog($databaseConnection, $userId, 'update', 'Authentication', 'Completed a required password change.');
				$databaseConnection->commit();
				unset($_SESSION['password_change_required'], $_SESSION['forced_password_token']);
				if ($user['role'] === 'admin') {
					header('Location: /BMirk/admin/home.php', true, 303);
				} elseif ($user['role'] === 'staff') {
					header('Location: /BMirk/staff/home.php', true, 303);
				} else {
					header('Location: /BMirk/senior/home.php', true, 303);
				}
				exit;
			} catch (Throwable $exception) {
				$databaseConnection->rollback();
				error_log('Unable to complete required password change: ' . $exception->getMessage());
				$errors[] = 'Unable to update your password. Please try again.';
			}
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Change Password | InfoConnect</title>
	<link rel="stylesheet" href="assets/login.css">
	<link rel="stylesheet" href="assets/logo.css">
</head>
<body>
	<main class="page-shell">
		<section class="form-panel">
			<form class="login-card" method="post" action="">
				<h3>Change your password</h3>
				<p class="subtitle">For your account's security, set a new password before continuing.</p>
				<?php if ($errors): ?>
					<div class="error-box" role="alert"><?php echo htmlspecialchars(implode(' ', $errors), ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['forced_password_token'], ENT_QUOTES, 'UTF-8'); ?>">
				<div class="form-group"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
				<div class="form-group"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" minlength="<?php echo max(8, (int) $settings['password_minimum_length']); ?>" maxlength="72" autocomplete="new-password" required></div>
				<div class="form-group"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" minlength="<?php echo max(8, (int) $settings['password_minimum_length']); ?>" maxlength="72" autocomplete="new-password" required></div>
				<p class="subtitle">Minimum <?php echo max(8, (int) $settings['password_minimum_length']); ?> characters<?php echo $settings['password_require_special_characters'] === '1' ? ', including a special character' : ''; ?>.</p>
				<button type="submit">Update Password</button>
			</form>
		</section>
	</main>
</body>
</html>
