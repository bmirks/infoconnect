<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'admin') {
	header('Location: /BMirk/auth/login.php');
	exit;
}

$page_title = 'Senior Details';
$senior = null;
$requirements = [];
$detailError = '';
$profileId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if ($profileId !== false && $profileId !== null && $profileId > 0) {
	$statement = $databaseConnection->prepare(
		'SELECT sp.senior_citizen_id, sp.first_name, sp.middle_name, sp.last_name, sp.suffix, sp.sex, sp.birth_date,
		        sp.place_of_birth, sp.address, sp.barangay, sp.contact_number, sp.residence_since, sp.status,
		        sp.company_name, sp.position, sp.is_pwd, sp.is_bedridden, sp.remarks, sp.created_at AS profile_created_at,
		        sp.updated_at AS profile_updated_at, cs.name AS civil_status, ea.name AS educational_attainment,
		        o.name AS former_occupation, pt.name AS pension_type,
		        u.account_id, u.status AS account_status, u.last_login, u.must_change_password,
		        u.created_at AS account_created_at, u.updated_at AS account_updated_at
		 FROM senior_profiles sp
		 LEFT JOIN civil_statuses cs ON cs.id = sp.civil_status_id
		 LEFT JOIN educational_attainments ea ON ea.id = sp.educational_attainment_id
		 LEFT JOIN occupations o ON o.id = sp.former_occupation_id
		 LEFT JOIN pension_types pt ON pt.id = sp.pension_type_id
		 LEFT JOIN users u ON u.id = sp.user_id AND u.role = \'senior\'
		 WHERE sp.id = ?
		 LIMIT 1'
	);

	if ($statement) {
		$statement->bind_param('i', $profileId);
		if ($statement->execute()) {
			$senior = $statement->get_result()->fetch_assoc() ?: null;
		} else {
			$detailError = 'Senior details could not be loaded.';
		}
		$statement->close();
	} else {
		$detailError = 'Senior details could not be loaded.';
	}

	if ($senior) {
		$requirementStatement = $databaseConnection->prepare(
			'SELECT requirement_type, status FROM senior_requirements WHERE senior_id = ?'
		);
		if ($requirementStatement) {
			$requirementStatement->bind_param('i', $profileId);
			if ($requirementStatement->execute()) {
				$result = $requirementStatement->get_result();
				while ($requirement = $result->fetch_assoc()) {
					$requirements[$requirement['requirement_type']] = $requirement['status'];
				}
			} else {
				$detailError = 'Senior requirements could not be loaded.';
			}
			$requirementStatement->close();
		} else {
			$detailError = 'Senior requirements could not be loaded.';
		}
	}
}

function seniorViewValue($value): string
{
	$value = trim((string) $value);
	return $value !== '' ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : 'N/A';
}

function seniorViewDate($value, string $format): string
{
	if (!$value) {
		return 'N/A';
	}

	$timestamp = strtotime((string) $value);
	return $timestamp === false ? 'N/A' : date($format, $timestamp);
}

function seniorRequirementStatus(array $requirements, string $type): string
{
	return ucfirst($requirements[$type] ?? 'missing');
}

$fullName = '';
$age = null;
$seniorStatus = 'pending';
if ($senior) {
	$fullName = trim($senior['first_name'] . ' ' . ($senior['middle_name'] ? $senior['middle_name'] . ' ' : '') . $senior['last_name'] . ' ' . ($senior['suffix'] ?? ''));
	$age = (int) date_diff(date_create($senior['birth_date']), date_create('today'))->format('%y');
	$completedRequirements = 0;
	foreach (['birth_certificate', 'valid_id'] as $requirementType) {
		if (isset($requirements[$requirementType]) && strtolower($requirements[$requirementType]) !== 'missing') {
			$completedRequirements++;
		}
	}
	$seniorStatus = $completedRequirements < 2 ? 'pending' : strtolower($senior['status']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
	<link rel="stylesheet" href="../home.css">
	<link rel="stylesheet" href="assets/seniors_form.css?v=2">
</head>
<body>
	<div class="admin-layout">
		<?php include __DIR__ . '/../includes/sidebar.php'; ?>

		<main class="admin-main">
			<?php include __DIR__ . '/../includes/header.php'; ?>

			<section class="admin-content senior-detail-page">
				<?php if ($detailError !== ''): ?>
					<div class="senior-detail-error" role="alert"><?php echo htmlspecialchars($detailError, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>

				<?php if (!$senior): ?>
					<div class="card senior-detail-not-found">
						<h2><?php echo $detailError !== '' ? 'Unable to load senior details' : 'Senior not found'; ?></h2>
						<p><?php echo $detailError !== '' ? 'Please try again later.' : 'The requested senior profile could not be found.'; ?></p>
						<a class="back-link" href="/BMirk/admin/management/senior.php">Back to List</a>
					</div>
				<?php else: ?>
					<header class="senior-detail-heading">
						<div>
							<h1>Senior Details</h1>
							<p>View complete information for this senior citizen.</p>
						</div>
						<a class="senior-detail-back" href="/BMirk/admin/management/senior.php">Back to List</a>
					</header>

					<section class="senior-detail-overview" aria-label="Senior summary">
						<div class="senior-detail-name">
							<h2><?php echo seniorViewValue($fullName); ?></h2>
							<p><?php echo seniorViewValue($senior['senior_citizen_id']); ?></p>
						</div>
						<div class="senior-overview-item">
							<span>Status</span>
							<strong><span class="detail-status <?php echo seniorViewValue($seniorStatus); ?>"><?php echo seniorViewValue(ucfirst($seniorStatus)); ?></span></strong>
						</div>
						<div class="senior-overview-item"><span>Age</span><strong><?php echo (int) $age; ?></strong></div>
						<div class="senior-overview-item"><span>Sex</span><strong><?php echo seniorViewValue(ucfirst($senior['sex'])); ?></strong></div>
						<div class="senior-overview-item"><span>Barangay</span><strong><?php echo seniorViewValue($senior['barangay']); ?></strong></div>
					</section>

					<div class="senior-detail-columns">
						<div class="senior-detail-column">
							<section class="senior-info-card" aria-labelledby="personal-information-heading">
								<h2 id="personal-information-heading">Personal Information</h2>
								<dl class="senior-info-table">
									<div><dt>Last Name</dt><dd><?php echo seniorViewValue($senior['last_name']); ?></dd></div>
									<div><dt>First Name</dt><dd><?php echo seniorViewValue($senior['first_name']); ?></dd></div>
									<div><dt>Middle Name</dt><dd><?php echo seniorViewValue($senior['middle_name']); ?></dd></div>
									<div><dt>Suffix</dt><dd><?php echo seniorViewValue($senior['suffix']); ?></dd></div>
									<div><dt>Senior Citizen ID (OSCA ID)</dt><dd><?php echo seniorViewValue($senior['senior_citizen_id']); ?></dd></div>
									<div><dt>Date of Birth</dt><dd><?php echo seniorViewDate($senior['birth_date'], 'M j, Y'); ?></dd></div>
									<div><dt>Age</dt><dd><?php echo (int) $age; ?></dd></div>
									<div><dt>Sex</dt><dd><?php echo seniorViewValue(ucfirst($senior['sex'])); ?></dd></div>
									<div><dt>Civil Status</dt><dd><?php echo seniorViewValue($senior['civil_status']); ?></dd></div>
								</dl>
							</section>

							<section class="senior-info-card" aria-labelledby="other-details-heading">
								<h2 id="other-details-heading">Other Details</h2>
								<dl class="senior-info-table">
									<div><dt>Place of Birth</dt><dd><?php echo seniorViewValue($senior['place_of_birth']); ?></dd></div>
									<div><dt>Highest Educational Attainment</dt><dd><?php echo seniorViewValue($senior['educational_attainment']); ?></dd></div>
									<div><dt>Former Occupation</dt><dd><?php echo seniorViewValue($senior['former_occupation']); ?></dd></div>
									<div><dt>Company (Name)</dt><dd><?php echo seniorViewValue($senior['company_name']); ?></dd></div>
									<div><dt>Position</dt><dd><?php echo seniorViewValue($senior['position']); ?></dd></div>
									<div><dt>Pension</dt><dd><?php echo seniorViewValue($senior['pension_type']); ?></dd></div>
									<div><dt>PWD</dt><dd><?php echo !empty($senior['is_pwd']) ? 'Yes' : 'No'; ?></dd></div>
									<div><dt>Bedridden</dt><dd><?php echo !empty($senior['is_bedridden']) ? 'Yes' : 'No'; ?></dd></div>
									<div><dt>Remarks</dt><dd><?php echo seniorViewValue($senior['remarks']); ?></dd></div>
								</dl>
							</section>
						</div>

						<div class="senior-detail-column">
							<section class="senior-info-card" aria-labelledby="address-information-heading">
								<h2 id="address-information-heading">Address Information</h2>
								<dl class="senior-info-table">
									<div><dt>Address</dt><dd><?php echo seniorViewValue($senior['address']); ?></dd></div>
									<div><dt>Barangay</dt><dd><?php echo seniorViewValue($senior['barangay']); ?></dd></div>
									<div><dt>Residence Since (Year)</dt><dd><?php echo seniorViewValue($senior['residence_since']); ?></dd></div>
								</dl>
							</section>

							<section class="senior-info-card" aria-labelledby="contact-information-heading">
								<h2 id="contact-information-heading">Contact Information</h2>
								<dl class="senior-info-table">
									<div><dt>Contact No.</dt><dd><?php echo seniorViewValue($senior['contact_number']); ?></dd></div>
								</dl>
							</section>

							<section class="senior-info-card" aria-labelledby="requirements-heading">
								<h2 id="requirements-heading">Requirements</h2>
								<dl class="senior-info-table senior-requirements-table">
									<div>
										<dt>Birth Certificate (Hardcopy)</dt>
										<dd><span class="requirement-status <?php echo seniorViewValue($requirements['birth_certificate'] ?? 'missing'); ?>"><?php echo seniorViewValue(seniorRequirementStatus($requirements, 'birth_certificate')); ?></span></dd>
									</div>
									<div>
										<dt>Valid ID (Hardcopy)</dt>
										<dd><span class="requirement-status <?php echo seniorViewValue($requirements['valid_id'] ?? 'missing'); ?>"><?php echo seniorViewValue(seniorRequirementStatus($requirements, 'valid_id')); ?></span></dd>
									</div>
								</dl>
							</section>

							<section class="senior-info-card" aria-labelledby="account-information-heading">
								<h2 id="account-information-heading">Account Information</h2>
								<dl class="senior-info-table">
									<div><dt>Account ID</dt><dd><?php echo seniorViewValue($senior['account_id']); ?></dd></div>
									<div><dt>Password</dt><dd><?php echo $senior['account_id'] ? '(Hidden)' : 'N/A'; ?></dd></div>
									<div><dt>Account Status</dt><dd><?php if ($senior['account_status'] !== null): ?><span class="detail-status <?php echo seniorViewValue($senior['account_status']); ?>"><?php echo seniorViewValue(ucfirst($senior['account_status'])); ?></span><?php else: ?>N/A<?php endif; ?></dd></div>
									<div><dt>Last Login</dt><dd><?php echo seniorViewDate($senior['last_login'], 'M j, Y h:i A'); ?></dd></div>
									<div><dt>Must Change Password</dt><dd><?php echo $senior['account_id'] ? (!empty($senior['must_change_password']) ? 'Yes' : 'No') : 'N/A'; ?></dd></div>
									<div><dt>Date Created</dt><dd><?php echo seniorViewDate($senior['account_created_at'], 'M j, Y h:i A'); ?></dd></div>
									<div><dt>Date Updated</dt><dd><?php echo seniorViewDate($senior['account_updated_at'], 'M j, Y h:i A'); ?></dd></div>
								</dl>
							</section>
						</div>
					</div>
				<?php endif; ?>
			</section>
		</main>
	</div>
</body>
</html>
