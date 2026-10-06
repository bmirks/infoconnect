<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/activity_log.php';
require_once __DIR__ . '/../../config/system_settings.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'staff') {
	header('Location: /BMirk/auth/login.php');
	exit;
}

$page_title = 'Settings';
$staffId = (int) ($_SESSION['user_id'] ?? 0);
$staffFirstName = $_SESSION['account_id'] ?? 'Staff';
$activeTab = is_string($_GET['tab'] ?? null) && in_array($_GET['tab'], ['personal', 'security', 'support', 'about'], true)
	? $_GET['tab']
	: 'personal';
$tabs = [
	'personal' => ['label' => 'Personal & Account', 'description' => 'View and update your personal and account details.'],
	'security' => ['label' => 'Security', 'description' => 'Change your password.'],
	'support' => ['label' => 'Help & Support', 'description' => 'Report a problem or send a message to an administrator.'],
	'about' => ['label' => 'About System', 'description' => 'View system information.'],
];
$errors = [];
$successMessage = $_SESSION['staff_settings_flash'] ?? '';
unset($_SESSION['staff_settings_flash']);
$formValues = [
	'first_name' => '',
	'middle_name' => '',
	'last_name' => '',
	'suffix' => '',
	'email' => '',
	'contact_number' => '',
];
$account = [];

if (empty($_SESSION['staff_settings_csrf_token'])) {
	$_SESSION['staff_settings_csrf_token'] = bin2hex(random_bytes(32));
}

try {
	$profileStatement = $databaseConnection->prepare(
		"SELECT u.account_id, u.role, u.status AS account_status, u.last_login,
		        sp.employee_id, sp.first_name, sp.middle_name, sp.last_name, sp.suffix,
		        sp.email, sp.contact_number
		 FROM users u
		 LEFT JOIN staff_profiles sp ON sp.user_id = u.id
		 WHERE u.id = ? AND u.role = 'staff'
		 LIMIT 1"
	);
	$profileStatement->bind_param('i', $staffId);
	$profileStatement->execute();
	$account = $profileStatement->get_result()->fetch_assoc() ?: [];
	$profileStatement->close();
	if (!$account) {
		throw new RuntimeException('Staff profile not found.');
	}
	$staffFirstName = $account['first_name'] ?: $staffFirstName;
	foreach ($formValues as $field => $value) {
		$formValues[$field] = (string) ($account[$field] ?? '');
	}
} catch (Throwable $exception) {
	error_log('Unable to load staff settings profile: ' . $exception->getMessage());
	$errors[] = 'Your account information could not be loaded.';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	$postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
	if (!hash_equals($_SESSION['staff_settings_csrf_token'], $postedToken)) {
		$errors[] = 'Your session token expired. Refresh the page and try again.';
	}
	$action = is_string($_POST['settings_action'] ?? null) ? $_POST['settings_action'] : '';

	if ($action === 'save_profile') {
		foreach ($formValues as $field => $value) {
			$postedValue = $_POST[$field] ?? '';
			$formValues[$field] = is_string($postedValue) ? trim($postedValue) : '';
		}
		$limits = ['first_name' => 100, 'middle_name' => 100, 'last_name' => 100, 'suffix' => 20, 'email' => 150, 'contact_number' => 30];
		foreach ($limits as $field => $maxLength) {
			if (mb_strlen($formValues[$field], 'UTF-8') > $maxLength) {
				$errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be ' . $maxLength . ' characters or fewer.';
			}
		}
		if ($formValues['first_name'] === '' || $formValues['last_name'] === '') {
			$errors[] = 'First name and last name are required.';
		}
		if ($formValues['email'] !== '' && !filter_var($formValues['email'], FILTER_VALIDATE_EMAIL)) {
			$errors[] = 'Enter a valid email address.';
		}
		if (!$errors) {
			$passwordTransactionStarted = false;
			try {
				$middleName = $formValues['middle_name'] !== '' ? $formValues['middle_name'] : null;
				$suffix = $formValues['suffix'] !== '' ? $formValues['suffix'] : null;
				$email = $formValues['email'] !== '' ? $formValues['email'] : null;
				$contactNumber = $formValues['contact_number'] !== '' ? $formValues['contact_number'] : null;
				$updateProfile = $databaseConnection->prepare(
					'UPDATE staff_profiles SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, email = ?, contact_number = ? WHERE user_id = ?'
				);
				$updateProfile->bind_param('ssssssi', $formValues['first_name'], $middleName, $formValues['last_name'], $suffix, $email, $contactNumber, $staffId);
				$updateProfile->execute();
				$updateProfile->close();
				writeActivityLog($databaseConnection, $staffId, 'update', 'Settings', 'Updated staff profile details.');
				$_SESSION['staff_settings_flash'] = 'Your personal information was updated.';
				$_SESSION['staff_settings_csrf_token'] = bin2hex(random_bytes(32));
				header('Location: /BMirk/staff/settings/settings.php?tab=personal', true, 303);
				exit;
			} catch (Throwable $exception) {
				error_log('Unable to update staff settings profile: ' . $exception->getMessage());
				$errors[] = 'Your personal information could not be saved. Please try again.';
			}
		}
	} elseif ($action === 'change_password') {
		$currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
		$newPassword = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
		$confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
		if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
			$errors[] = 'Complete all password fields.';
		}
		try {
			$passwordSettings = loadSystemSettings($databaseConnection);
			$errors = array_merge($errors, systemPasswordPolicyErrors($newPassword, $passwordSettings));
		} catch (Throwable $exception) {
			$errors[] = 'Password requirements could not be loaded. Please try again.';
		}
		if ($newPassword !== '' && $confirmPassword !== '' && $newPassword !== $confirmPassword) {
			$errors[] = 'The new password and confirmation do not match.';
		}
		if (!$errors) {
			try {
				$passwordQuery = $databaseConnection->prepare("SELECT password FROM users WHERE id = ? AND role = 'staff' LIMIT 1");
				$passwordQuery->bind_param('i', $staffId);
				$passwordQuery->execute();
				$storedAccount = $passwordQuery->get_result()->fetch_assoc();
				$passwordQuery->close();
				if (!$storedAccount || !password_verify($currentPassword, $storedAccount['password'])) {
					$errors[] = 'The current password is incorrect.';
				} elseif (password_verify($newPassword, $storedAccount['password'])) {
					$errors[] = 'Choose a new password that is different from your current password.';
				} else {
					$historyQuery = $databaseConnection->prepare('SELECT password_hash FROM password_history WHERE user_id = ?');
					$historyQuery->bind_param('i', $staffId);
					$historyQuery->execute();
					$historyResult = $historyQuery->get_result();
					while ($historyRow = $historyResult->fetch_assoc()) {
						if (password_verify($newPassword, $historyRow['password_hash'])) {
							$errors[] = 'This password has been used before. Choose a different password.';
						break;
						}
					}
					$historyQuery->close();
				}
				if (!$errors) {
					$newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
					if ($newPasswordHash === false) {
						throw new RuntimeException('Password hash could not be created.');
					}
					$databaseConnection->begin_transaction();
					$passwordTransactionStarted = true;
					$historyInsert = $databaseConnection->prepare('INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)');
					$historyInsert->bind_param('is', $staffId, $storedAccount['password']);
					$historyInsert->execute();
					$historyInsert->close();
					$updatePassword = $databaseConnection->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ? AND role = 'staff'");
					$updatePassword->bind_param('si', $newPasswordHash, $staffId);
					$updatePassword->execute();
					if ($updatePassword->affected_rows !== 1) {
						throw new RuntimeException('Password update did not affect a staff account.');
					}
					$updatePassword->close();
					writeActivityLog($databaseConnection, $staffId, 'update', 'Settings', 'Changed staff password.');
					$databaseConnection->commit();
					$passwordTransactionStarted = false;
					$_SESSION['staff_settings_flash'] = 'Your password was changed successfully.';
					$_SESSION['staff_settings_csrf_token'] = bin2hex(random_bytes(32));
					header('Location: /BMirk/staff/settings/settings.php?tab=security', true, 303);
					exit;
				}
			} catch (Throwable $exception) {
				if ($passwordTransactionStarted) {
					$databaseConnection->rollback();
				}
				error_log('Unable to change staff password: ' . $exception->getMessage());
				$errors[] = 'Unable to update your password. Please try again.';
			}
		}
	} else {
		$errors[] = 'Choose a valid settings action.';
	}
}

function escapeStaffSettings($value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$lastLogin = !empty($account['last_login']) ? date('M j, Y g:i A', strtotime($account['last_login'])) : 'Never';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo escapeStaffSettings($page_title); ?></title>
	<link rel="stylesheet" href="../home.css">
	<link rel="stylesheet" href="../assets/table.css?v=12">
	<link rel="stylesheet" href="assets/settings.css?v=6">
	<link rel="stylesheet" href="/BMirk/staff/includes/assets/sidebar.css?v=5">
</head>
<body>
	<div class="staff-layout">
		<?php include __DIR__ . '/../includes/sidebar.php'; ?>
		<main class="staff-main">
			<?php include __DIR__ . '/../includes/header.php'; ?>
			<section class="staff-content staff-settings-page">
				<header class="settings-page-heading">
					<h1>Settings</h1>
					<p>Manage your account, security, and support options.</p>
				</header>
				<?php if ($successMessage !== ''): ?><div class="settings-alert success" role="status"><?php echo escapeStaffSettings($successMessage); ?></div><?php endif; ?>
				<?php if ($errors): ?><div class="settings-alert error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?php echo escapeStaffSettings($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
				<div class="settings-layout">
					<nav class="settings-navigation" aria-label="Settings sections">
						<?php foreach ($tabs as $tabKey => $tab): ?>
							<a href="?tab=<?php echo escapeStaffSettings($tabKey); ?>" class="settings-nav-item<?php echo $activeTab === $tabKey ? ' active' : ''; ?>"<?php echo $activeTab === $tabKey ? ' aria-current="page"' : ''; ?>>
								<span><strong><?php echo escapeStaffSettings($tab['label']); ?></strong><small><?php echo escapeStaffSettings($tab['description']); ?></small></span>
							</a>
						<?php endforeach; ?>
					</nav>

					<div class="settings-panels">
						<?php if ($activeTab === 'personal'): ?>
							<section class="settings-panel" aria-labelledby="personal-panel-heading">
								<header class="panel-heading"><h2 id="personal-panel-heading">Personal &amp; Account</h2><p>View and update your personal information. Some fields are read-only and can only be changed by the administrator.</p></header>
								<form method="post" class="settings-profile-form">
									<input type="hidden" name="csrf_token" value="<?php echo escapeStaffSettings($_SESSION['staff_settings_csrf_token']); ?>">
									<input type="hidden" name="settings_action" value="save_profile">
									<div class="settings-profile-grid">
										<label>First Name <b>*</b><input name="first_name" maxlength="100" required value="<?php echo escapeStaffSettings($formValues['first_name']); ?>"></label>
										<label>Middle Name<input name="middle_name" maxlength="100" value="<?php echo escapeStaffSettings($formValues['middle_name']); ?>"></label>
										<label>Last Name <b>*</b><input name="last_name" maxlength="100" required value="<?php echo escapeStaffSettings($formValues['last_name']); ?>"></label>
										<label>Suffix<input name="suffix" maxlength="20" placeholder="e.g. Jr., Sr., III" value="<?php echo escapeStaffSettings($formValues['suffix']); ?>"></label>
										<label>Employee ID<input readonly value="<?php echo escapeStaffSettings($account['employee_id'] ?? 'N/A'); ?>"></label>
										<label>Account ID<input readonly value="<?php echo escapeStaffSettings($account['account_id'] ?? 'N/A'); ?>"></label>
										<label>Role<input readonly value="Staff"></label>
										<label>Account Status<input readonly value="<?php echo escapeStaffSettings(ucfirst($account['account_status'] ?? 'Unknown')); ?>"></label>
										<label>Contact Number<input name="contact_number" type="tel" maxlength="30" autocomplete="tel" value="<?php echo escapeStaffSettings($formValues['contact_number']); ?>"></label>
										<label>Last Login<input readonly value="<?php echo escapeStaffSettings($lastLogin); ?>"></label>
										<label class="profile-email">Email Address<input name="email" type="email" maxlength="150" autocomplete="email" value="<?php echo escapeStaffSettings($formValues['email']); ?>"></label>
									</div>
									<footer class="panel-actions"><button type="submit">Save Changes</button></footer>
								</form>
							</section>
						<?php elseif ($activeTab === 'security'): ?>
							<section class="settings-panel" aria-labelledby="security-panel-heading">
								<header class="panel-heading"><h2 id="security-panel-heading">Change Password</h2><p>Update your password to keep your account secure.</p></header>
								<form method="post" class="settings-password-form">
									<input type="hidden" name="csrf_token" value="<?php echo escapeStaffSettings($_SESSION['staff_settings_csrf_token']); ?>">
									<input type="hidden" name="settings_action" value="change_password">
									<label>Current Password <b>*</b><input name="current_password" type="password" autocomplete="current-password" placeholder="Enter current password" required></label>
									<label>New Password <b>*</b><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" placeholder="Enter new password" required>
										<small>Password must contain at least 8 characters.</small>
										<div class="password-strength" id="passwordStrength" data-strength="empty" aria-live="polite"><div class="strength-meter"><span id="passwordStrengthBar"></span></div><p id="passwordStrengthText">Enter a password to check its strength.</p></div>
									</label>
									<label>Confirm New Password <b>*</b><input name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" placeholder="Confirm new password" required></label>
									<footer class="panel-actions"><button type="submit">Update Password</button></footer>
								</form>
							</section>
						<?php elseif ($activeTab === 'support'): ?>
							<section class="settings-panel support-panel" aria-labelledby="support-panel-heading">
								<header class="panel-heading"><h2 id="support-panel-heading">Help &amp; Support</h2><p>If you encounter an issue, need a data update, or have a request, contact the system administrator.</p></header>
								<div class="support-contact"><strong>Need help with InfoConnect?</strong><span>Contact the system administrator at <a href="mailto:osca@mandaluyong.gov.ph">osca@mandaluyong.gov.ph</a>.</span></div>
								<form class="support-form" id="supportForm">
									<label>Subject <b>*</b><input id="supportSubject" maxlength="150" required placeholder="Enter subject"></label>
									<label>Concern Type <b>*</b><select id="supportType" required><option>System Problem</option><option>Data Correction</option><option>Account Assistance</option><option>Feature Request</option><option>Other</option></select></label>
									<label class="support-message-field">Message <b>*</b><textarea id="supportMessage" maxlength="1000" required placeholder="Describe your concern in detail..."></textarea><small><span id="supportCharacterCount">0</span> / 1000 characters</small></label>
									<footer class="panel-actions"><button type="submit">Send Message</button></footer>
								</form>
								<h3>My Requests / Messages</h3>
								<p class="support-empty">Messages are sent to the system administrator by email and aren’t tracked in InfoConnect.</p>
							</section>
						<?php else: ?>
							<section class="settings-panel" aria-labelledby="about-panel-heading">
								<header class="panel-heading"><h2 id="about-panel-heading">About InfoConnect</h2><p>System information and details.</p></header>
								<div class="about-banner"><strong>InfoConnect</strong><span>Mandaluyong City Office for Senior Citizens Affairs</span><p>A system for managing senior citizen records, events, announcements, and communications.</p></div>
								<div class="table-wrap settings-about-table-wrap">
									<table class="data-table settings-about-table">
										<tbody>
											<tr><th scope="row">System Name</th><td>InfoConnect</td></tr>
											<tr><th scope="row">Organization</th><td>Mandaluyong City Office for Senior Citizens Affairs (OSCA)</td></tr>
											<tr><th scope="row">Version</th><td>1.0.0</td></tr>
											<tr><th scope="row">Environment</th><td>Production</td></tr>
											<tr><th scope="row">Server Time</th><td><?php echo escapeStaffSettings(date('F j, Y g:i:s A')); ?></td></tr>
											<tr><th scope="row">Developed For</th><td>Office for Senior Citizens Affairs &mdash; Mandaluyong City</td></tr>
										</tbody>
									</table>
								</div>
							</section>
						<?php endif; ?>
					</div>
				</div>
			</section>
		</main>
	</div>
	<script src="/BMirk/admin/assets/password-strength.js" defer></script>
	<script>
		const supportMessage = document.getElementById('supportMessage');
		const supportCounter = document.getElementById('supportCharacterCount');
		if (supportMessage && supportCounter) {
			supportMessage.addEventListener('input', function () {
				supportCounter.textContent = String(supportMessage.value.length);
			});
		}
		const supportForm = document.getElementById('supportForm');
		if (supportForm) {
			supportForm.addEventListener('submit', function (event) {
				event.preventDefault();
				const subject = '[' + document.getElementById('supportType').value + '] ' + document.getElementById('supportSubject').value.trim();
				const body = document.getElementById('supportMessage').value.trim() + '\n\nStaff account: <?php echo htmlspecialchars((string) ($account['account_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>';
				window.location.href = 'mailto:osca@mandaluyong.gov.ph?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
			});
		}
	</script>
</body>
</html>
