<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/activity_log.php';
require_once __DIR__ . '/../../config/system_settings.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'admin') {
	header('Location: /BMirk/auth/login.php');
	exit;
}

$page_title = 'Add User Account';
$systemSettings = loadSystemSettings($databaseConnection);
$formError = '';
$formValues = [
	'last_name' => '',
	'first_name' => '',
	'middle_name' => '',
	'employee_id' => '',
	'email' => '',
	'contact_number' => '',
	'role' => 'staff',
];

if (empty($_SESSION['users_add_token'])) {
	$_SESSION['users_add_token'] = bin2hex(random_bytes(32));
}

$createdAccount = null;
if (($_GET['created'] ?? '') === '1') {
	$createdAccount = $_SESSION['created_user_account'] ?? null;
	unset($_SESSION['created_user_account']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	foreach ($formValues as $field => $default) {
		$postedValue = $_POST[$field] ?? $default;
		$formValues[$field] = is_string($postedValue) ? trim($postedValue) : $default;
	}

	$submittedToken = $_POST['csrf_token'] ?? '';
	if (!is_string($submittedToken) || !hash_equals($_SESSION['users_add_token'], $submittedToken)) {
		$formError = 'This form has expired. Refresh the page and try again.';
	} elseif ($formValues['last_name'] === '' || $formValues['first_name'] === '' || $formValues['employee_id'] === '') {
		$formError = 'Last name, first name, and employee ID are required.';
	} elseif (!in_array($formValues['role'], ['admin', 'staff'], true)) {
		$formError = 'Select either Admin or Staff as the account role.';
	} elseif ($formValues['email'] !== '' && !filter_var($formValues['email'], FILTER_VALIDATE_EMAIL)) {
		$formError = 'Enter a valid email address.';
	} elseif (strlen($formValues['last_name']) > 100 || strlen($formValues['first_name']) > 100 || strlen($formValues['middle_name']) > 100 || strlen($formValues['employee_id']) > 50 || strlen($formValues['email']) > 150 || strlen($formValues['contact_number']) > 30) {
		$formError = 'One or more fields exceed the allowed length.';
	} else {
		$transactionStarted = false;
		try {
			foreach (['admin_profiles', 'staff_profiles'] as $profileTable) {
				$employeeCheck = $databaseConnection->prepare("SELECT id FROM {$profileTable} WHERE employee_id = ? LIMIT 1");
				$employeeCheck->bind_param('s', $formValues['employee_id']);
				$employeeCheck->execute();
				if ($employeeCheck->get_result()->num_rows > 0) {
					throw new RuntimeException('Employee ID is already assigned.');
				}
			}

			$databaseConnection->begin_transaction();
			$transactionStarted = true;
			$accountPrefix = $formValues['role'] === 'admin' ? 'ADM-' : 'STF-';
			$accountPattern = $accountPrefix . '%';
			$accountCheck = $databaseConnection->prepare('SELECT account_id FROM users WHERE account_id LIKE ? FOR UPDATE');
			if (!$accountCheck) {
				throw new RuntimeException('Unable to generate a login ID. Try again.');
			}
			$accountCheck->bind_param('s', $accountPattern);
			$accountCheck->execute();
			$accountResult = $accountCheck->get_result();

			$highestAccountNumber = 0;
			while ($accountRow = $accountResult->fetch_assoc()) {
				if (preg_match('/^' . preg_quote($accountPrefix, '/') . '(\d+)$/i', $accountRow['account_id'], $matches)) {
					$highestAccountNumber = max($highestAccountNumber, (int) $matches[1]);
				}
			}
			$accountId = sprintf('%s%02d', $accountPrefix, $highestAccountNumber + 1);

			$temporaryPassword = generateSystemTemporaryPassword($systemSettings);
			$passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
			$profileTable = $formValues['role'] === 'admin' ? 'admin_profiles' : 'staff_profiles';
			$mustChangePassword = (int) $systemSettings['require_password_change_first_login'];

			$userInsert = $databaseConnection->prepare('INSERT INTO users (account_id, password, role, must_change_password) VALUES (?, ?, ?, ?)');
			$userInsert->bind_param('sssi', $accountId, $passwordHash, $formValues['role'], $mustChangePassword);
			$userInsert->execute();
			$userId = (int) $databaseConnection->insert_id;

			$profileInsert = $databaseConnection->prepare("INSERT INTO {$profileTable} (user_id, employee_id, first_name, middle_name, last_name, email, contact_number) VALUES (?, ?, ?, ?, ?, ?, ?)");
			$middleName = $formValues['middle_name'] !== '' ? $formValues['middle_name'] : null;
			$email = $formValues['email'] !== '' ? $formValues['email'] : null;
			$contactNumber = $formValues['contact_number'] !== '' ? $formValues['contact_number'] : null;
			$profileInsert->bind_param('issssss', $userId, $formValues['employee_id'], $formValues['first_name'], $middleName, $formValues['last_name'], $email, $contactNumber);
			$profileInsert->execute();

			$fullName = trim($formValues['first_name'] . ' ' . $formValues['last_name']);
			writeActivityLog(
				$databaseConnection,
				(int) ($_SESSION['user_id'] ?? 0),
				'create',
				'Accounts',
				'Created ' . $formValues['role'] . ' account ' . $accountId . ' for ' . $fullName . '.',
				$userId
			);
			$databaseConnection->commit();
			$transactionStarted = false;
			$_SESSION['created_user_account'] = [
				'account_id' => $accountId,
				'temporary_password' => $temporaryPassword,
				'role' => ucfirst($formValues['role']),
				'name' => trim($formValues['first_name'] . ' ' . $formValues['last_name']),
			];
			$_SESSION['users_add_token'] = bin2hex(random_bytes(32));
			header('Location: /BMirk/admin/management/users_add.php?created=1');
			exit;
		} catch (Throwable $exception) {
			if ($transactionStarted) {
				$databaseConnection->rollback();
			}
			$formError = $exception instanceof RuntimeException
				? $exception->getMessage()
				: 'The account could not be created. Check that the employee ID is unique and try again.';
		}
	}
}

function escapeUserAddValue($value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo escapeUserAddValue($page_title); ?></title>
	<link rel="stylesheet" href="../home.css">
	<link rel="stylesheet" href="assets/users_form.css?v=7">
</head>
<body>
	<div class="admin-layout">
		<?php include __DIR__ . '/../includes/sidebar.php'; ?>
		<main class="admin-main">
			<?php include __DIR__ . '/../includes/header.php'; ?>
			<section class="admin-content edit-personnel-page">
					<header class="personnel-heading edit-personnel-heading">
						<div>
							<h1>Add Personnel</h1>
							<p>Create a new admin or staff account.</p>
						</div>
						<a class="back-link" href="users.php">Back to List</a>
					</header>

					<?php if ($createdAccount): ?>
						<section class="credential-notice" aria-labelledby="credential-title">
							<h3 id="credential-title">Account created</h3>
							<p><?php echo escapeUserAddValue($createdAccount['name']); ?> now has an active <?php echo escapeUserAddValue($createdAccount['role']); ?> account. Share these credentials securely; the temporary password will not be shown again.</p>
							<dl class="credential-list">
								<div><dt>Account ID</dt><dd><code><?php echo escapeUserAddValue($createdAccount['account_id']); ?></code></dd></div>
								<div><dt>Temporary password</dt><dd><code><?php echo escapeUserAddValue($createdAccount['temporary_password']); ?></code></dd></div>
							</dl>
						</section>
					<?php endif; ?>

					<?php if ($formError !== ''): ?>
						<div class="form-error" role="alert"><?php echo escapeUserAddValue($formError); ?></div>
					<?php endif; ?>

					<form class="account-form" id="accountForm" method="post" action="">
						<input type="hidden" name="csrf_token" value="<?php echo escapeUserAddValue($_SESSION['users_add_token']); ?>">

						<section class="senior-info-card" aria-labelledby="name-information-heading">
							<h2 id="name-information-heading">Name Information</h2>
							<dl class="senior-info-table">
								<div>
									<dt>Last Name <b>*</b></dt>
									<dd><input id="last_name" name="last_name" type="text" maxlength="100" autocomplete="family-name" value="<?php echo escapeUserAddValue($formValues['last_name']); ?>" required></dd>
								</div>
								<div>
									<dt>First Name <b>*</b></dt>
									<dd><input id="first_name" name="first_name" type="text" maxlength="100" autocomplete="given-name" value="<?php echo escapeUserAddValue($formValues['first_name']); ?>" required></dd>
								</div>
								<div>
									<dt>Middle Name</dt>
									<dd><input id="middle_name" name="middle_name" type="text" maxlength="100" autocomplete="additional-name" value="<?php echo escapeUserAddValue($formValues['middle_name']); ?>"></dd>
								</div>
								<div>
									<dt>Employee ID <b>*</b></dt>
									<dd><input id="employee_id" name="employee_id" type="text" maxlength="50" value="<?php echo escapeUserAddValue($formValues['employee_id']); ?>" required></dd>
								</div>
							</dl>
						</section>

						<section class="senior-info-card" aria-labelledby="contact-information-heading">
							<h2 id="contact-information-heading">Contact Information</h2>
							<dl class="senior-info-table">
								<div>
									<dt>Email</dt>
									<dd><input id="email" name="email" type="email" maxlength="150" autocomplete="email" value="<?php echo escapeUserAddValue($formValues['email']); ?>"></dd>
								</div>
								<div>
									<dt>Contact Number</dt>
									<dd><input id="contact_number" name="contact_number" type="tel" maxlength="30" autocomplete="tel" value="<?php echo escapeUserAddValue($formValues['contact_number']); ?>"></dd>
								</div>
							</dl>
						</section>

						<section class="senior-info-card" aria-labelledby="account-settings-heading">
							<h2 id="account-settings-heading">Account Settings</h2>
							<dl class="senior-info-table">
								<div>
									<dt>Role <b>*</b></dt>
									<dd>
										<select id="role" name="role" required>
											<option value="admin" <?php echo $formValues['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
											<option value="staff" <?php echo $formValues['role'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
										</select>
									</dd>
								</div>
							</dl>
						</section>

						<div class="form-actions">
							<a class="secondary-form-action" href="users.php">Cancel</a>
							<button class="primary-add-btn" type="submit">Create Account</button>
						</div>
					</form>
			</section>
		</main>
	</div>
	<script>
		(function () {
			const form = document.getElementById('accountForm');
			const dataFields = form.querySelectorAll('input:not([type="hidden"]), select');
			let hasUnsavedChanges = Array.from(dataFields).some(function (field) {
				return field.name !== 'role' && field.value.trim() !== '';
			});

			form.addEventListener('input', function (event) {
				if (event.target.matches('input:not([type="hidden"]), select')) {
					hasUnsavedChanges = true;
				}
			});

			form.addEventListener('change', function (event) {
				if (event.target.matches('input:not([type="hidden"]), select')) {
					hasUnsavedChanges = true;
				}
			});

			document.querySelectorAll('.edit-personnel-page a[href="users.php"]').forEach(function (link) {
				link.addEventListener('click', function (event) {
					if (hasUnsavedChanges && !window.confirm('You have unsaved changes. Continue back to the users list and discard them?')) {
						event.preventDefault();
					}
				});
			});
		})();
	</script>
</body>
</html>
