<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/session.php';
require_once __DIR__ . '/../../../config/activity_log.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'staff') {
	header('Location: /BMirk/auth/login.php');
	exit;
}
$errors = [];
$editProfileId = null;
$isEditing = array_key_exists('id', $_GET);
$page_title = 'Add Senior';
if ($isEditing) {
	$editProfileId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
	if ($editProfileId === false || $editProfileId === null || $editProfileId < 1) {
		$errors[] = 'The requested senior profile could not be found.';
		$editProfileId = null;
	} else {
		$page_title = 'Edit Senior';
	}
}
$staffId = (int) ($_SESSION['user_id'] ?? 0);
$staffFirstName = $_SESSION['account_id'] ?? 'Staff';
$staffProfileStatement = $databaseConnection->prepare('SELECT first_name FROM staff_profiles WHERE user_id = ? LIMIT 1');
if ($staffProfileStatement) {
	$staffProfileStatement->bind_param('i', $staffId);
	$staffProfileStatement->execute();
	$staffProfile = $staffProfileStatement->get_result()->fetch_assoc();
	if (!empty($staffProfile['first_name'])) {
		$staffFirstName = $staffProfile['first_name'];
	}
	$staffProfileStatement->close();
}
$values = [
	'senior_citizen_id' => '',
	'first_name' => '',
	'middle_name' => '',
	'last_name' => '',
	'suffix' => '',
	'sex' => '',
	'birth_date' => '',
	'civil_status_id' => '',
	'place_of_birth' => '',
	'address' => '',
	'barangay' => '',
	'contact_number' => '',
	'residence_since' => '',
	'educational_attainment_id' => '',
	'former_occupation_id' => '',
	'company_name' => '',
	'position' => '',
	'pension_type_id' => '',
	'is_pwd' => '0',
	'is_bedridden' => '0',
];
$requirements = [
	'birth_certificate' => false,
	'valid_id' => false,
];
$existingRequirementStatuses = [
	'birth_certificate' => 'missing',
	'valid_id' => 'missing',
];

if (empty($_SESSION['staff_senior_create_token'])) {
	$_SESSION['staff_senior_create_token'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['staff_senior_import_token'])) {
	$_SESSION['staff_senior_import_token'] = bin2hex(random_bytes(32));
}

$civilStatuses = [];
$civilStatusResult = $databaseConnection->query('SELECT id, name FROM civil_statuses WHERE is_active = 1 ORDER BY name');
if ($civilStatusResult) {
	while ($row = $civilStatusResult->fetch_assoc()) {
		$civilStatuses[] = $row;
	}
}

$barangays = [];
$barangayResult = $databaseConnection->query('SELECT name FROM barangays WHERE is_active = 1 ORDER BY name');
if ($barangayResult) {
	while ($row = $barangayResult->fetch_assoc()) {
		$barangays[] = $row['name'];
	}
} else {
	error_log('Unable to load barangays for the Add Senior form: ' . $databaseConnection->error);
	$errors[] = 'Unable to load barangays. Please refresh the page or contact an administrator.';
}

$educationLevels = [];
$educationLevelResult = $databaseConnection->query('SELECT id, name FROM educational_attainments WHERE is_active = 1 ORDER BY name');
if ($educationLevelResult) {
	while ($row = $educationLevelResult->fetch_assoc()) {
		$educationLevels[] = $row;
	}
}

$occupations = [];
$occupationResult = $databaseConnection->query('SELECT id, name FROM occupations WHERE is_active = 1 ORDER BY name');
if ($occupationResult) {
	while ($row = $occupationResult->fetch_assoc()) {
		$occupations[] = $row;
	}
}

$pensionTypes = [];
$pensionTypeResult = $databaseConnection->query('SELECT id, name FROM pension_types WHERE is_active = 1 ORDER BY name');
if ($pensionTypeResult) {
	while ($row = $pensionTypeResult->fetch_assoc()) {
		$pensionTypes[] = $row;
	}
}

if ($isEditing && $editProfileId !== null) {
	$seniorStatement = $databaseConnection->prepare(
		'SELECT senior_citizen_id, first_name, middle_name, last_name, suffix, sex, birth_date,
		        civil_status_id, place_of_birth, address, barangay, contact_number, residence_since,
		        educational_attainment_id, former_occupation_id, company_name, position, pension_type_id,
		        is_pwd, is_bedridden
		 FROM senior_profiles
		 WHERE id = ?
		 LIMIT 1'
	);
	if (!$seniorStatement) {
		error_log('Unable to prepare Staff senior edit query: ' . $databaseConnection->error);
		$errors[] = 'Senior details could not be loaded.';
	} else {
		$seniorStatement->bind_param('i', $editProfileId);
		if (!$seniorStatement->execute()) {
			error_log('Unable to load Staff senior for editing: ' . $seniorStatement->error);
			$errors[] = 'Senior details could not be loaded.';
		} else {
			$seniorRecord = $seniorStatement->get_result()->fetch_assoc();
			if (!$seniorRecord) {
				$errors[] = 'The requested senior profile could not be found.';
			} else {
				foreach ($values as $field => $value) {
					$values[$field] = (string) ($seniorRecord[$field] ?? '');
				}
			}
		}
		$seniorStatement->close();
	}

	if (!$errors) {
		$requirementStatement = $databaseConnection->prepare(
			'SELECT requirement_type, status FROM senior_requirements WHERE senior_id = ?'
		);
		if (!$requirementStatement) {
			error_log('Unable to prepare Staff senior edit requirements query: ' . $databaseConnection->error);
			$errors[] = 'Senior requirements could not be loaded.';
		} else {
			$requirementStatement->bind_param('i', $editProfileId);
			if (!$requirementStatement->execute()) {
				error_log('Unable to load Staff senior edit requirements: ' . $requirementStatement->error);
				$errors[] = 'Senior requirements could not be loaded.';
			} else {
				$result = $requirementStatement->get_result();
				while ($requirement = $result->fetch_assoc()) {
					if (array_key_exists($requirement['requirement_type'], $requirements)) {
						$existingRequirementStatuses[$requirement['requirement_type']] = $requirement['status'];
						$requirements[$requirement['requirement_type']] = $requirement['status'] !== 'missing';
					}
				}
			}
			$requirementStatement->close();
		}
	}
}

function escapeStaffSeniorCreate($value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!$isEditing || $editProfileId !== null)) {
	foreach ($values as $field => $value) {
		$postedValue = $_POST[$field] ?? '';
		$values[$field] = is_string($postedValue) ? trim($postedValue) : '';
	}

	$postedRequirements = $_POST['requirements'] ?? [];
	if (!is_array($postedRequirements)) {
		$errors[] = 'Choose valid senior requirements.';
	} else {
		foreach ($postedRequirements as $requirementType => $requirementValue) {
			if (!array_key_exists($requirementType, $requirements) || $requirementValue !== '1') {
				$errors[] = 'Choose valid senior requirements.';
				break;
			}
		}
		foreach ($requirements as $requirementType => $isChecked) {
			$requirements[$requirementType] = ($postedRequirements[$requirementType] ?? null) === '1';
		}
	}

	$submittedToken = $_POST['csrf_token'] ?? '';
	if (!is_string($submittedToken) || !hash_equals($_SESSION['staff_senior_create_token'], $submittedToken)) {
		$errors[] = 'This form has expired. Refresh the page and try again.';
	}

	foreach ([
		'senior_citizen_id' => 50,
		'first_name' => 100,
		'middle_name' => 100,
		'last_name' => 100,
		'suffix' => 20,
		'place_of_birth' => 255,
		'barangay' => 150,
		'contact_number' => 30,
	] as $field => $maxLength) {
		if (strlen($values[$field]) > $maxLength) {
			$errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be ' . $maxLength . ' characters or fewer.';
		}
	}

	foreach (['senior_citizen_id', 'first_name', 'last_name', 'address', 'barangay'] as $requiredField) {
		if ($values[$requiredField] === '') {
			$errors[] = ucfirst(str_replace('_', ' ', $requiredField)) . ' is required.';
		}
	}

	if ($values['barangay'] !== '' && !in_array($values['barangay'], $barangays, true)) {
		$errors[] = 'Choose a valid barangay.';
	}

	if (!in_array($values['sex'], ['male', 'female'], true)) {
		$errors[] = 'Choose a valid sex.';
	}

	$birthDate = DateTime::createFromFormat('!Y-m-d', $values['birth_date']);
	if (!$birthDate || $birthDate->format('Y-m-d') !== $values['birth_date'] || $values['birth_date'] > date('Y-m-d')) {
		$errors[] = 'Enter a valid birth date that is not in the future.';
	} else {
		$today = new DateTimeImmutable('today');
		$age = (int) $birthDate->diff($today)->format('%y');
		if ($age < 60) {
			$errors[] = 'Senior applicants must be at least 60 years old.';
		}
	}

	$civilStatusId = filter_var($values['civil_status_id'], FILTER_VALIDATE_INT);
	$civilStatusIds = array_map(static fn ($status) => (int) $status['id'], $civilStatuses);
	if ($civilStatusId === false || !in_array($civilStatusId, $civilStatusIds, true)) {
		$errors[] = 'Choose a valid civil status.';
	}

	$educationLevelId = filter_var($values['educational_attainment_id'], FILTER_VALIDATE_INT);
	$educationLevelIds = array_map(static fn ($level) => (int) $level['id'], $educationLevels);
	if ($values['educational_attainment_id'] !== '' && ($educationLevelId === false || !in_array($educationLevelId, $educationLevelIds, true))) {
		$errors[] = 'Choose a valid highest educational attainment.';
	}

	$formerOccupationId = filter_var($values['former_occupation_id'], FILTER_VALIDATE_INT);
	$formerOccupationIds = array_map(static fn ($occupation) => (int) $occupation['id'], $occupations);
	if ($values['former_occupation_id'] !== '' && ($formerOccupationId === false || !in_array($formerOccupationId, $formerOccupationIds, true))) {
		$errors[] = 'Choose a valid former occupation.';
	}

	$pensionTypeId = filter_var($values['pension_type_id'], FILTER_VALIDATE_INT);
	$pensionTypeIds = array_map(static fn ($type) => (int) $type['id'], $pensionTypes);
	if ($values['pension_type_id'] !== '' && ($pensionTypeId === false || !in_array($pensionTypeId, $pensionTypeIds, true))) {
		$errors[] = 'Choose a valid pension type.';
	}

	$residenceSince = $values['residence_since'];
	if ($residenceSince !== '' && (!preg_match('/^\d{4}$/', $residenceSince) || (int) $residenceSince < 1901 || (int) $residenceSince > (int) date('Y'))) {
		$errors[] = 'Residence since must be a valid year.';
	}

	$companyName = $values['company_name'];
	if ($companyName !== '' && mb_strlen($companyName) > 150) {
		$errors[] = 'Company must be 150 characters or fewer.';
	}

	$position = $values['position'];
	if ($position !== '' && mb_strlen($position) > 150) {
		$errors[] = 'Position must be 150 characters or fewer.';
	}

	$isPwd = in_array($values['is_pwd'] ?? '', ['1', '0'], true) ? (int) $values['is_pwd'] : 0;
	$isBedridden = in_array($values['is_bedridden'] ?? '', ['1', '0'], true) ? (int) $values['is_bedridden'] : 0;
	$seniorStatus = $requirements['birth_certificate'] && $requirements['valid_id'] ? 'active' : 'pending';

	if (!$errors) {
		$duplicateQuery = 'SELECT id FROM senior_profiles WHERE senior_citizen_id = ?';
		if ($isEditing) {
			$duplicateQuery .= ' AND id <> ?';
		}
		$duplicateQuery .= ' LIMIT 1';
		$duplicateStatement = $databaseConnection->prepare($duplicateQuery);
		if (!$duplicateStatement) {
			$errors[] = 'Unable to validate the senior ID. Please try again.';
		} else {
			if ($isEditing) {
				$duplicateStatement->bind_param('si', $values['senior_citizen_id'], $editProfileId);
			} else {
				$duplicateStatement->bind_param('s', $values['senior_citizen_id']);
			}
			$duplicateStatement->execute();
			$isDuplicate = $duplicateStatement->get_result()->num_rows > 0;
			$duplicateStatement->close();
			if ($isDuplicate) {
				$errors[] = 'That Senior Citizen ID is already in use.';
			}
		}
	}

	if (!$errors) {
		$transactionStarted = false;
		try {
			$databaseConnection->begin_transaction();
			$transactionStarted = true;

			$middleName = $values['middle_name'] !== '' ? $values['middle_name'] : null;
			$suffix = $values['suffix'] !== '' ? $values['suffix'] : null;
			$placeOfBirth = $values['place_of_birth'] !== '' ? $values['place_of_birth'] : null;
			$contactNumber = $values['contact_number'] !== '' ? $values['contact_number'] : null;
			$residenceSinceValue = $residenceSince !== '' ? $residenceSince : null;
			$companyNameValue = $companyName !== '' ? $companyName : null;
			$positionValue = $position !== '' ? $position : null;
			$educationalAttainmentValue = $values['educational_attainment_id'] !== '' ? $educationLevelId : null;
			$formerOccupationValue = $values['former_occupation_id'] !== '' ? $formerOccupationId : null;
			$pensionTypeValue = $values['pension_type_id'] !== '' ? $pensionTypeId : null;
			$profileValues = [
				$values['senior_citizen_id'],
				$values['last_name'],
				$values['first_name'],
				$middleName,
				$suffix,
				$values['sex'],
				$values['birth_date'],
				$civilStatusId,
				$placeOfBirth,
				$values['address'],
				$values['barangay'],
				$contactNumber,
				$residenceSinceValue,
				$educationalAttainmentValue,
				$formerOccupationValue,
				$companyNameValue,
				$positionValue,
				$pensionTypeValue,
				$isPwd,
				$isBedridden,
			];
			if ($isEditing) {
				$updateStatement = $databaseConnection->prepare(
					'UPDATE senior_profiles
					 SET senior_citizen_id = ?, last_name = ?, first_name = ?, middle_name = ?, suffix = ?,
					     sex = ?, birth_date = ?, civil_status_id = ?, place_of_birth = ?, address = ?,
					     barangay = ?, contact_number = ?, residence_since = ?, educational_attainment_id = ?,
					     former_occupation_id = ?, company_name = ?, position = ?, pension_type_id = ?,
					     is_pwd = ?, is_bedridden = ?
					 WHERE id = ?'
				);
				if (!$updateStatement) {
					throw new RuntimeException('Unable to prepare the senior record update.');
				}
				$updateValues = $profileValues;
				$updateValues[] = $editProfileId;
				$bindTypes = '';
				foreach ($updateValues as $updateValue) {
					$bindTypes .= is_int($updateValue) ? 'i' : 's';
				}
				$updateStatement->bind_param($bindTypes, ...$updateValues);
				$updateStatement->execute();
				$updateStatement->close();
				$seniorId = $editProfileId;

				$requirementStatement = $databaseConnection->prepare(
					"UPDATE senior_requirements
					 SET submitted_at = CASE WHEN ? = 'missing' THEN NULL WHEN ? = 'submitted' THEN COALESCE(submitted_at, CURRENT_TIMESTAMP) ELSE submitted_at END,
					     reviewed_by = CASE WHEN ? = 'missing' OR (? = 'submitted' AND status = 'rejected') THEN NULL ELSE reviewed_by END,
					     reviewed_at = CASE WHEN ? = 'missing' OR (? = 'submitted' AND status = 'rejected') THEN NULL ELSE reviewed_at END,
					     status = ?
					 WHERE senior_id = ? AND requirement_type = ?"
				);
				if (!$requirementStatement) {
					throw new RuntimeException('Unable to prepare senior requirement updates.');
				}
				$requirementStatus = '';
				$requirementType = '';
				$requirementStatement->bind_param(
					'sssssssis',
					$requirementStatus,
					$requirementStatus,
					$requirementStatus,
					$requirementStatus,
					$requirementStatus,
					$requirementStatus,
					$requirementStatus,
					$seniorId,
					$requirementType
				);
				foreach ($requirements as $type => $isChecked) {
					$requirementType = $type;
					$previousStatus = $existingRequirementStatuses[$type];
					$requirementStatus = !$isChecked
						? 'missing'
						: (in_array($previousStatus, ['approved', 'submitted'], true) ? $previousStatus : 'submitted');
					$requirementStatement->execute();
				}
				$requirementStatement->close();
				writeActivityLog(
					$databaseConnection,
					$staffId,
					'update',
					'Seniors',
					'Updated senior record ' . $values['senior_citizen_id'] . '.',
					$seniorId
				);
				$successMessage = 'Senior details updated.';
			} else {
				$insertStatement = $databaseConnection->prepare(
					"INSERT INTO senior_profiles
					 (senior_citizen_id, last_name, first_name, middle_name, suffix, sex, birth_date, civil_status_id,
					  place_of_birth, address, barangay, contact_number, residence_since, educational_attainment_id,
					  former_occupation_id, company_name, position, pension_type_id, is_pwd, is_bedridden, status, approval_status, submitted_by)
					 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)"
				);
				if (!$insertStatement) {
					throw new RuntimeException('Unable to prepare the senior record.');
				}
				$insertValues = $profileValues;
				$insertValues[] = $seniorStatus;
				$insertValues[] = $staffId;
				$bindTypes = '';
				foreach ($insertValues as $insertValue) {
					$bindTypes .= is_int($insertValue) ? 'i' : 's';
				}
				$insertStatement->bind_param($bindTypes, ...$insertValues);
				$insertStatement->execute();
				$seniorId = (int) $databaseConnection->insert_id;
				$insertStatement->close();

				$requirementStatement = $databaseConnection->prepare(
					'INSERT INTO senior_requirements (senior_id, requirement_type, status)
					 VALUES (?, ?, ?)'
				);
				if (!$requirementStatement) {
					throw new RuntimeException('Unable to prepare senior requirement records.');
				}
				$requirementStatus = '';
				$requirementType = '';
				$requirementStatement->bind_param('iss', $seniorId, $requirementType, $requirementStatus);
				foreach ($requirements as $type => $isChecked) {
					$requirementType = $type;
					$requirementStatus = $isChecked ? 'submitted' : 'missing';
					$requirementStatement->execute();
				}
				$requirementStatement->close();

				$requestType = 'senior';
				$approvalStatement = $databaseConnection->prepare(
					"INSERT INTO approval_requests (request_type, reference_id, requested_by, status)
					 VALUES (?, ?, ?, 'pending')"
				);
				if (!$approvalStatement) {
					throw new RuntimeException('Unable to create the senior review request.');
				}
				$approvalStatement->bind_param('sii', $requestType, $seniorId, $staffId);
				$approvalStatement->execute();
				$approvalStatement->close();

				writeActivityLog(
					$databaseConnection,
					$staffId,
					'create',
					'Seniors',
					'Submitted senior record ' . $values['senior_citizen_id'] . ' for approval.',
					$seniorId
				);
				$successMessage = 'Senior record submitted for approval.';
			}
			$databaseConnection->commit();
			$transactionStarted = false;
			$_SESSION['staff_senior_flash'] = $successMessage;
			$_SESSION['staff_senior_create_token'] = bin2hex(random_bytes(32));
			header('Location: /BMirk/staff/seniors/accounts/seniors_accounts.php', true, 303);
			exit;
		} catch (Throwable $exception) {
			if ($transactionStarted) {
				$databaseConnection->rollback();
			}
			error_log(($isEditing ? 'Staff senior update failed: ' : 'Staff senior create failed: ') . $exception->getMessage());
			$errors[] = $isEditing
				? 'The senior record could not be updated. Please review the details and try again.'
				: 'The senior record could not be submitted. Please review the details and try again.';
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo escapeStaffSeniorCreate($page_title); ?></title>
	<link rel="stylesheet" href="../../home.css">
	<link rel="stylesheet" href="assets/seniors_accounts_forms.css?v=28">
	<link rel="stylesheet" href="/BMirk/staff/includes/assets/sidebar.css?v=5">
</head>
<body>
	<div class="staff-layout">
		<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
		<main class="staff-main">
			<?php include __DIR__ . '/../../includes/header.php'; ?>
			<section class="staff-content staff-senior-form-page staff-senior-entry-page">
				<header class="staff-senior-form-heading">
					<div>
						<h1><?php echo $isEditing ? 'Edit Senior' : 'Add Senior'; ?></h1>
						<p><?php echo $isEditing ? 'Update senior citizen information.' : 'Create a senior citizen record for admin review.'; ?></p>
					</div>
					<a class="back-link" href="/BMirk/staff/seniors/accounts/seniors_accounts.php">Back to List</a>
				</header>

				<?php if ($errors): ?>
					<div class="form-error" role="alert">
						<ul>
							<?php foreach ($errors as $error): ?>
								<li><?php echo escapeStaffSeniorCreate($error); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if (!$isEditing || $editProfileId !== null): ?>
				<form method="post" class="account-form staff-senior-entry-form">
					<input type="hidden" name="csrf_token" value="<?php echo escapeStaffSeniorCreate($_SESSION['staff_senior_create_token']); ?>">
					<div class="senior-detail-columns">
						<div class="senior-detail-column">
							<section class="senior-info-card" aria-labelledby="senior-personal-heading">
								<h2 id="senior-personal-heading">Personal Information</h2>
								<dl class="senior-info-table">
									<div><dt>Senior Citizen ID <b>*</b></dt><dd><input name="senior_citizen_id" maxlength="50" required value="<?php echo escapeStaffSeniorCreate($values['senior_citizen_id']); ?>"></dd></div>
									<div><dt>First Name <b>*</b></dt><dd><input name="first_name" maxlength="100" required value="<?php echo escapeStaffSeniorCreate($values['first_name']); ?>"></dd></div>
									<div><dt>Middle Name</dt><dd><input name="middle_name" maxlength="100" value="<?php echo escapeStaffSeniorCreate($values['middle_name']); ?>"></dd></div>
									<div><dt>Last Name <b>*</b></dt><dd><input name="last_name" maxlength="100" required value="<?php echo escapeStaffSeniorCreate($values['last_name']); ?>"></dd></div>
									<div><dt>Suffix</dt><dd><input name="suffix" maxlength="20" value="<?php echo escapeStaffSeniorCreate($values['suffix']); ?>"></dd></div>
									<div><dt>Sex <b>*</b></dt><dd><select name="sex" required><option value="">Choose sex</option><option value="male"<?php echo $values['sex'] === 'male' ? ' selected' : ''; ?>>Male</option><option value="female"<?php echo $values['sex'] === 'female' ? ' selected' : ''; ?>>Female</option></select></dd></div>
									<div><dt>Birth Date <b>*</b></dt><dd><input type="date" name="birth_date" required value="<?php echo escapeStaffSeniorCreate($values['birth_date']); ?>"></dd></div>
									<div>
										<dt>Civil Status <b>*</b></dt>
										<dd><select name="civil_status_id" required><option value="">Choose civil status</option><?php foreach ($civilStatuses as $civilStatus): ?><option value="<?php echo (int) $civilStatus['id']; ?>"<?php echo (string) $civilStatus['id'] === $values['civil_status_id'] ? ' selected' : ''; ?>><?php echo escapeStaffSeniorCreate($civilStatus['name']); ?></option><?php endforeach; ?></select></dd>
									</div>
								</dl>
							</section>
							<section class="senior-info-card" aria-labelledby="senior-address-heading">
								<h2 id="senior-address-heading">Address Information</h2>
								<dl class="senior-info-table">
									<div><dt>Address <b>*</b></dt><dd><textarea name="address" rows="3" required><?php echo escapeStaffSeniorCreate($values['address']); ?></textarea></dd></div>
									<div>
										<dt>Barangay <b>*</b></dt>
										<dd><select name="barangay" required><option value="">Choose barangay</option><?php foreach ($barangays as $barangay): ?><option value="<?php echo escapeStaffSeniorCreate($barangay); ?>"<?php echo $barangay === $values['barangay'] ? ' selected' : ''; ?>><?php echo escapeStaffSeniorCreate($barangay); ?></option><?php endforeach; ?></select></dd>
									</div>
									<div><dt>Residence Since</dt><dd><input type="number" name="residence_since" min="1901" max="<?php echo date('Y'); ?>" value="<?php echo escapeStaffSeniorCreate($values['residence_since']); ?>"></dd></div>
									<div><dt>Contact Number</dt><dd><input name="contact_number" maxlength="30" value="<?php echo escapeStaffSeniorCreate($values['contact_number']); ?>"></dd></div>
									<div><dt>Place of Birth</dt><dd><input name="place_of_birth" maxlength="255" value="<?php echo escapeStaffSeniorCreate($values['place_of_birth']); ?>"></dd></div>
								</dl>
							</section>
						</div>
						<div class="senior-detail-column">
							<section class="senior-info-card" aria-labelledby="senior-other-heading">
								<h2 id="senior-other-heading">Other Details</h2>
								<dl class="senior-info-table">
									<div><dt>Education</dt><dd><select name="educational_attainment_id"><option value="">Choose education</option><?php foreach ($educationLevels as $educationLevel): ?><option value="<?php echo (int) $educationLevel['id']; ?>"<?php echo (string) $educationLevel['id'] === $values['educational_attainment_id'] ? ' selected' : ''; ?>><?php echo escapeStaffSeniorCreate($educationLevel['name']); ?></option><?php endforeach; ?></select></dd></div>
									<div><dt>Former Occupation</dt><dd><select name="former_occupation_id"><option value="">Choose occupation</option><?php foreach ($occupations as $occupation): ?><option value="<?php echo (int) $occupation['id']; ?>"<?php echo (string) $occupation['id'] === $values['former_occupation_id'] ? ' selected' : ''; ?>><?php echo escapeStaffSeniorCreate($occupation['name']); ?></option><?php endforeach; ?></select></dd></div>
									<div><dt>Company</dt><dd><input name="company_name" maxlength="150" value="<?php echo escapeStaffSeniorCreate($values['company_name']); ?>"></dd></div>
									<div><dt>Position</dt><dd><input name="position" maxlength="150" value="<?php echo escapeStaffSeniorCreate($values['position']); ?>"></dd></div>
									<div><dt>Pension</dt><dd><select name="pension_type_id"><option value="">Choose pension</option><?php foreach ($pensionTypes as $pensionType): ?><option value="<?php echo (int) $pensionType['id']; ?>"<?php echo (string) $pensionType['id'] === $values['pension_type_id'] ? ' selected' : ''; ?>><?php echo escapeStaffSeniorCreate($pensionType['name']); ?></option><?php endforeach; ?></select></dd></div>
									<div><dt>PWD</dt><dd><select name="is_pwd"><option value="0"<?php echo $values['is_pwd'] === '0' ? ' selected' : ''; ?>>No</option><option value="1"<?php echo $values['is_pwd'] === '1' ? ' selected' : ''; ?>>Yes</option></select></dd></div>
									<div><dt>Bedridden</dt><dd><select name="is_bedridden"><option value="0"<?php echo $values['is_bedridden'] === '0' ? ' selected' : ''; ?>>No</option><option value="1"<?php echo $values['is_bedridden'] === '1' ? ' selected' : ''; ?>>Yes</option></select></dd></div>
								</dl>
							</section>
							<section class="senior-info-card" aria-labelledby="senior-requirements-heading">
								<h2 id="senior-requirements-heading">Requirements</h2>
								<dl class="senior-info-table staff-senior-requirements-table">
									<div>
										<dt>Birth Certificate</dt>
										<dd><input type="checkbox" name="requirements[birth_certificate]" value="1" aria-label="Birth Certificate requirement submitted"<?php echo $requirements['birth_certificate'] ? ' checked' : ''; ?>></dd>
									</div>
									<div>
										<dt>Valid ID</dt>
										<dd><input type="checkbox" name="requirements[valid_id]" value="1" aria-label="Valid ID requirement submitted"<?php echo $requirements['valid_id'] ? ' checked' : ''; ?>></dd>
									</div>
								</dl>
							</section>
						</div>
					</div>
					<div class="form-actions staff-senior-form-actions">
						<a class="secondary-form-action" href="/BMirk/staff/seniors/accounts/seniors_accounts.php">Cancel</a>
						<button class="primary-add-btn" type="submit"><?php echo $isEditing ? 'Save Changes' : 'Submit for Approval'; ?></button>
					</div>
				</form>

				<?php if (!$isEditing): ?>
				<section class="staff-senior-import-section" aria-labelledby="staff-senior-import-heading">
					<h2 id="staff-senior-import-heading">Import Seniors</h2>
					<div class="senior-detail-columns staff-senior-import-columns">
						<section class="senior-info-card" aria-labelledby="import-guidelines-heading">
							<h3 id="import-guidelines-heading">Import Guidelines</h3>
							<dl class="senior-info-table">
								<div><dt>Maximum Records</dt><dd>500 records per CSV file</dd></div>
								<div><dt>Required Columns</dt><dd>senior_citizen_id, first_name, last_name, sex, birth_date, civil_status, address, barangay</dd></div>
								<div><dt>Optional Columns</dt><dd>middle_name, suffix, place_of_birth, contact_number, residence_since</dd></div>
								<div><dt>Accepted Values</dt><dd>Use male/female for sex and YYYY-MM-DD for birth date. Civil status must match an active option.</dd></div>
							</dl>
							<a class="senior-import-template" href="/BMirk/staff/seniors/accounts/seniors_accounts_import.php?template=1">Download CSV Template</a>
						</section>

						<form method="post" action="/BMirk/staff/seniors/accounts/seniors_accounts_import.php" enctype="multipart/form-data" class="senior-info-card staff-senior-import-form">
							<input type="hidden" name="csrf_token" value="<?php echo escapeStaffSeniorCreate($_SESSION['staff_senior_import_token']); ?>">
							<h3 id="upload-csv-heading">Upload CSV File</h3>
							<dl class="senior-info-table">
								<div><dt><label for="seniorCsv">CSV File <b>*</b></label></dt><dd><input id="seniorCsv" type="file" name="senior_csv" accept=".csv,text/csv" required></dd></div>
							</dl>
							<div class="form-actions staff-senior-form-actions">
								<button class="primary-add-btn" type="submit">Import CSV</button>
							</div>
						</form>
					</div>
				</section>
				<?php endif; ?>
				<?php endif; ?>
			</section>
		</main>
	</div>
</body>
</html>
