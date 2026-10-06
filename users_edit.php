<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/activity_log.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'admin') {
	header('Location: /BMirk/auth/login.php');
	exit;
}

$page_title = 'Edit User Account';
$userId = filter_var($_GET['id'] ?? $_POST['id'] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId < 1) {
	header('Location: /BMirk/admin/management/users.php');
	exit;
}

$userQuery = $databaseConnection->prepare(
	"SELECT u.id, u.account_id, u.role, u.status, u.role_changed_at,
	        COALESCE(GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(u.role_changed_at, INTERVAL 7 DAY))), 0) AS role_cooldown_seconds,
	        ap.employee_id AS admin_employee_id, ap.first_name AS admin_first_name, ap.middle_name AS admin_middle_name, ap.last_name AS admin_last_name, ap.suffix AS admin_suffix, ap.email AS admin_email, ap.contact_number AS admin_contact,
	        sp.employee_id AS staff_employee_id, sp.first_name AS staff_first_name, sp.middle_name AS staff_middle_name, sp.last_name AS staff_last_name, sp.suffix AS staff_suffix, sp.email AS staff_email, sp.contact_number AS staff_contact
	 FROM users u
	 LEFT JOIN admin_profiles ap ON ap.user_id = u.id
	 LEFT JOIN staff_profiles sp ON sp.user_id = u.id
	 WHERE u.id = ? AND u.role IN ('admin', 'staff')
	 LIMIT 1"
);
$userQuery->bind_param('i', $userId);
$userQuery->execute();
$user = $userQuery->get_result()->fetch_assoc();
$userQuery->close();

if (!$user) {
	header('Location: /BMirk/admin/management/users.php');
	exit;
}

$profilePrefix = $user['role'] === 'admin' ? 'admin' : 'staff';
$formValues = [
	'employee_id' => $user[$profilePrefix . '_employee_id'] ?? '',
	'first_name' => $user[$profilePrefix . '_first_name'] ?? '',
	'middle_name' => $user[$profilePrefix . '_middle_name'] ?? '',
	'last_name' => $user[$profilePrefix . '_last_name'] ?? '',
	'email' => $user[$profilePrefix . '_email'] ?? '',
	'contact_number' => $user[$profilePrefix . '_contact'] ?? '',
	'role' => $user['role'],
	'status' => $user['status'],
];
$roleCooldownSeconds = (int) $user['role_cooldown_seconds'];
$formError = '';

if (empty($_SESSION['users_edit_token'])) {
	$_SESSION['users_edit_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	foreach ($formValues as $field => $value) {
		$postedValue = $_POST[$field] ?? $value;
		$formValues[$field] = is_string($postedValue) ? trim($postedValue) : $value;
	}

	$submittedToken = $_POST['csrf_token'] ?? '';
	if (!is_string($submittedToken) || !hash_equals($_SESSION['users_edit_token'], $submittedToken)) {
		$formError = 'This form has expired. Refresh the page and try again.';
	} elseif ($formValues['first_name'] === '' || $formValues['last_name'] === '' || $formValues['employee_id'] === '') {
		$formError = 'First name, last name, and employee ID are required.';
	} elseif (!in_array($formValues['role'], ['admin', 'staff'], true)) {
		$formError = 'Select either Admin or Staff as the account role.';
	} elseif ($formValues['role'] !== $user['role'] && (int) ($_SESSION['user_id'] ?? 0) === (int) $user['id']) {
		$formError = 'You cannot change the role of the account you are currently using.';
	} elseif (!in_array($formValues['status'], ['active', 'inactive'], true)) {
		$formError = 'Select a valid account status.';
	} elseif ($formValues['role'] !== $user['role'] && $roleCooldownSeconds > 0) {
		$formError = 'This account role is on cooldown. Try again after the seven-day cooldown ends.';
	} elseif ($formValues['email'] !== '' && !filter_var($formValues['email'], FILTER_VALIDATE_EMAIL)) {
		$formError = 'Enter a valid email address.';
	} elseif (strlen($formValues['first_name']) > 100 || strlen($formValues['middle_name']) > 100 || strlen($formValues['last_name']) > 100 || strlen($formValues['employee_id']) > 50 || strlen($formValues['email']) > 150 || strlen($formValues['contact_number']) > 30) {
		$formError = 'One or more fields exceed the allowed length.';
	} elseif ((int) ($_SESSION['user_id'] ?? 0) === (int) $user['id'] && $formValues['status'] === 'inactive') {
		$formError = 'You cannot deactivate the account you are currently using.';
	} else {
		$duplicateCheck = $databaseConnection->prepare(
			'SELECT user_id FROM admin_profiles WHERE employee_id = ? AND user_id <> ?
			 UNION ALL
			 SELECT user_id FROM staff_profiles WHERE employee_id = ? AND user_id <> ?
			 LIMIT 1'
		);
		$duplicateCheck->bind_param('sisi', $formValues['employee_id'], $userId, $formValues['employee_id'], $userId);
		$duplicateCheck->execute();
		if ($duplicateCheck->get_result()->num_rows > 0) {
			$formError = 'Employee ID is already assigned.';
		}
		$duplicateCheck->close();
	}

	if ($formError === '') {
		$transactionStarted = false;
		try {
			$databaseConnection->begin_transaction();
			$transactionStarted = true;

			$accountLock = $databaseConnection->prepare(
				'SELECT role, COALESCE(GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(role_changed_at, INTERVAL 7 DAY))), 0) AS role_cooldown_seconds
				 FROM users WHERE id = ? FOR UPDATE'
			);
			$accountLock->bind_param('i', $userId);
			$accountLock->execute();
			$lockedAccount = $accountLock->get_result()->fetch_assoc();
			$accountLock->close();

			if (!$lockedAccount || $lockedAccount['role'] !== $user['role']) {
				throw new RuntimeException('This account changed while you were editing it. Reload the page and try again.');
			}

			$roleChanged = $formValues['role'] !== $lockedAccount['role'];
			$roleCooldownSeconds = (int) $lockedAccount['role_cooldown_seconds'];
			if ($roleChanged && $roleCooldownSeconds > 0) {
				throw new RuntimeException('This account role is on cooldown. Try again after the seven-day cooldown ends.');
			}

			$middleName = $formValues['middle_name'] !== '' ? $formValues['middle_name'] : null;
			$email = $formValues['email'] !== '' ? $formValues['email'] : null;
			$contactNumber = $formValues['contact_number'] !== '' ? $formValues['contact_number'] : null;

			if ($roleChanged) {
				$sourceTable = $lockedAccount['role'] === 'admin' ? 'admin_profiles' : 'staff_profiles';
				$targetTable = $formValues['role'] === 'admin' ? 'admin_profiles' : 'staff_profiles';
				$suffix = $user[$profilePrefix . '_suffix'] ?? null;

				$profileInsert = $databaseConnection->prepare(
					"INSERT INTO {$targetTable} (user_id, employee_id, first_name, middle_name, last_name, suffix, email, contact_number)
					 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
				);
				$profileInsert->bind_param('isssssss', $userId, $formValues['employee_id'], $formValues['first_name'], $middleName, $formValues['last_name'], $suffix, $email, $contactNumber);
				$profileInsert->execute();

				$profileDelete = $databaseConnection->prepare("DELETE FROM {$sourceTable} WHERE user_id = ?");
				$profileDelete->bind_param('i', $userId);
				$profileDelete->execute();
				if ($profileDelete->affected_rows !== 1) {
					throw new RuntimeException('The existing account profile could not be moved.');
				}
				$profileDelete->close();

				$roleUpdate = $databaseConnection->prepare('UPDATE users SET role = ?, status = ?, role_changed_at = NOW() WHERE id = ?');
				$roleUpdate->bind_param('ssi', $formValues['role'], $formValues['status'], $userId);
				$roleUpdate->execute();
			} else {
				$profileTable = $lockedAccount['role'] === 'admin' ? 'admin_profiles' : 'staff_profiles';
				$profileExists = $databaseConnection->prepare("SELECT id FROM {$profileTable} WHERE user_id = ? LIMIT 1 FOR UPDATE");
				$profileExists->bind_param('i', $userId);
				$profileExists->execute();
				$hasProfile = (bool) $profileExists->get_result()->fetch_assoc();
				$profileExists->close();

				if ($hasProfile) {
					$profileUpdate = $databaseConnection->prepare(
						"UPDATE {$profileTable}
						 SET employee_id = ?, first_name = ?, middle_name = ?, last_name = ?, email = ?, contact_number = ?
						 WHERE user_id = ?"
					);
					$profileUpdate->bind_param('ssssssi', $formValues['employee_id'], $formValues['first_name'], $middleName, $formValues['last_name'], $email, $contactNumber, $userId);
					$profileUpdate->execute();
					$profileUpdate->close();
				} else {
					$suffix = $user[$profilePrefix . '_suffix'] ?? null;
					$profileInsert = $databaseConnection->prepare(
						"INSERT INTO {$profileTable} (user_id, employee_id, first_name, middle_name, last_name, suffix, email, contact_number)
						 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
					);
					$profileInsert->bind_param('isssssss', $userId, $formValues['employee_id'], $formValues['first_name'], $middleName, $formValues['last_name'], $suffix, $email, $contactNumber);
					$profileInsert->execute();
					$profileInsert->close();
				}

				$statusUpdate = $databaseConnection->prepare('UPDATE users SET status = ? WHERE id = ?');
				$statusUpdate->bind_param('si', $formValues['status'], $userId);
				$statusUpdate->execute();
			}

			$fullName = trim($formValues['first_name'] . ' ' . $formValues['last_name']);
			$changes = [];
			if ($roleChanged) {
				$changes[] = 'role changed from ' . $lockedAccount['role'] . ' to ' . $formValues['role'];
			}
			if ($formValues['status'] !== $user['status']) {
				$changes[] = 'status changed to ' . $formValues['status'];
			}
			writeActivityLog(
				$databaseConnection,
				(int) ($_SESSION['user_id'] ?? 0),
				'update',
				'Accounts',
				'Updated account ' . $user['account_id'] . ' (' . $fullName . ')' . ($changes ? ': ' . implode('; ', $changes) : ' profile details.'),
				(int) $userId
			);
			$databaseConnection->commit();
			$transactionStarted = false;
			$_SESSION['users_flash'] = $roleChanged ? 'User account and role updated. The role can be changed again in seven days.' : 'User account updated.';
			$_SESSION['users_edit_token'] = bin2hex(random_bytes(32));
			header('Location: /BMirk/admin/management/users.php');
			exit;
		} catch (Throwable $exception) {
			if ($transactionStarted) {
				$databaseConnection->rollback();
			}
			$formError = $exception instanceof RuntimeException
				? $exception->getMessage()
				: 'The account could not be updated. Check the employee ID and try again.';
		}
	}
}

function escapeUserEditValue($value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo escapeUserEditValue($page_title); ?></title>
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
							<h1>Edit Personnel</h1>
							<p>Update the information of the selected personnel.</p>
						</div>
						<a class="back-link" id="backToUsersLink" href="users.php">Back to List</a>
					</header>
					<section class="edit-personnel-summary" aria-label="Personnel summary">
						<div class="edit-personnel-identity">
							<h2><?php echo escapeUserEditValue(trim($formValues['last_name'] . ', ' . $formValues['first_name'] . ' ' . $formValues['middle_name'])); ?></h2>
							<p><?php echo escapeUserEditValue($formValues['employee_id']); ?></p>
						</div>
						<div class="summary-meta">
							<span>Account Status</span>
							<strong class="status-pill <?php echo escapeUserEditValue($formValues['status']); ?>"><?php echo escapeUserEditValue(ucfirst($formValues['status'])); ?></strong>
						</div>
						<div class="summary-meta">
							<span>Role</span>
							<strong><?php echo escapeUserEditValue(ucfirst($formValues['role'])); ?></strong>
						</div>
						<div class="summary-meta">
							<span>Email</span>
							<strong><?php echo escapeUserEditValue($formValues['email'] !== '' ? $formValues['email'] : '—'); ?></strong>
						</div>
						<div class="summary-meta">
							<span>Contact No.</span>
							<strong><?php echo escapeUserEditValue($formValues['contact_number'] !== '' ? $formValues['contact_number'] : '—'); ?></strong>
						</div>
					</section>

					<?php if ($formError !== ''): ?>
						<div class="form-error" role="alert"><?php echo escapeUserEditValue($formError); ?></div>
					<?php endif; ?>

					<form class="account-form" method="post" action="">
						<input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
						<input type="hidden" name="csrf_token" value="<?php echo escapeUserEditValue($_SESSION['users_edit_token']); ?>">
						<input type="hidden" name="employee_id" value="<?php echo escapeUserEditValue($formValues['employee_id']); ?>">

						<div class="senior-detail-columns">
							<div class="senior-detail-column">
								<section class="senior-info-card" aria-labelledby="name-information-heading">
									<h2 id="name-information-heading">Name Information</h2>
									<dl class="senior-info-table">
										<div>
											<dt>Last Name <b>*</b></dt>
											<dd><input id="last_name" name="last_name" type="text" maxlength="100" autocomplete="family-name" value="<?php echo escapeUserEditValue($formValues['last_name']); ?>" required></dd>
										</div>
										<div>
											<dt>First Name <b>*</b></dt>
											<dd><input id="first_name" name="first_name" type="text" maxlength="100" autocomplete="given-name" value="<?php echo escapeUserEditValue($formValues['first_name']); ?>" required></dd>
										</div>
										<div>
											<dt>Middle Name</dt>
											<dd><input id="middle_name" name="middle_name" type="text" maxlength="100" autocomplete="additional-name" value="<?php echo escapeUserEditValue($formValues['middle_name']); ?>"></dd>
										</div>
									</dl>
								</section>

								<section class="senior-info-card" aria-labelledby="account-settings-heading">
									<h2 id="account-settings-heading">Account Settings</h2>
									<dl class="senior-info-table">
										<div>
											<dt>Account Status <b>*</b></dt>
											<dd>
												<select id="status" name="status" required>
													<option value="active" <?php echo $formValues['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
													<option value="inactive" <?php echo $formValues['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
												</select>
											</dd>
										</div>
										<div>
											<dt>Role <b>*</b></dt>
											<dd>
												<select id="role" name="role" required <?php echo $roleCooldownSeconds > 0 ? 'disabled' : ''; ?>>
													<option value="admin" <?php echo $formValues['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
													<option value="staff" <?php echo $formValues['role'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
												</select>
												<?php if ($roleCooldownSeconds > 0): ?>
													<input type="hidden" name="role" value="<?php echo escapeUserEditValue($user['role']); ?>">
													<small class="role-cooldown-notice">Role changes are locked for <?php
														$cooldownDays = intdiv($roleCooldownSeconds, 86400);
														$cooldownHours = intdiv($roleCooldownSeconds % 86400, 3600);
														$cooldownMinutes = max(1, intdiv($roleCooldownSeconds % 3600, 60));
														if ($cooldownDays > 0) {
															echo $cooldownDays . ' day' . ($cooldownDays === 1 ? '' : 's');
															if ($cooldownHours > 0) echo ' and ' . $cooldownHours . ' hour' . ($cooldownHours === 1 ? '' : 's');
														} elseif ($cooldownHours > 0) {
															echo $cooldownHours . ' hour' . ($cooldownHours === 1 ? '' : 's');
															if ($cooldownMinutes > 0) echo ' and ' . $cooldownMinutes . ' minute' . ($cooldownMinutes === 1 ? '' : 's');
														} else {
															echo $cooldownMinutes . ' minute' . ($cooldownMinutes === 1 ? '' : 's');
														}
													?>.</small>
												<?php else: ?>
													<small class="role-cooldown-notice">After changing the role, it cannot be changed again for 7 days.</small>
												<?php endif; ?>
											</dd>
										</div>
									</dl>
								</section>
							</div>

							<div class="senior-detail-column">
								<section class="senior-info-card" aria-labelledby="contact-information-heading">
									<h2 id="contact-information-heading">Contact Information</h2>
									<dl class="senior-info-table">
										<div>
											<dt>Email</dt>
											<dd><input id="email" name="email" type="email" maxlength="150" autocomplete="email" value="<?php echo escapeUserEditValue($formValues['email']); ?>"></dd>
										</div>
										<div>
											<dt>Contact Number</dt>
											<dd><input id="contact_number" name="contact_number" type="tel" maxlength="30" autocomplete="tel" value="<?php echo escapeUserEditValue($formValues['contact_number']); ?>"></dd>
										</div>
									</dl>
								</section>
							</div>
						</div>

						<div class="form-actions">
							<a class="secondary-form-action" href="users.php">Cancel</a>
							<button class="primary-add-btn" type="submit">Save Changes</button>
						</div>
					</form>
			</section>
		</main>
	</div>
	<script>
		(function () {
			const form = document.querySelector('.account-form');
			let hasUnsavedChanges = false;

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
