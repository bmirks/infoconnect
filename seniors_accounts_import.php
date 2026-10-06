<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/session.php';
require_once __DIR__ . '/../../../config/activity_log.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'staff') {
	header('Location: /BMirk/auth/login.php');
	exit;
}

$page_title = 'Import Seniors';
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

$errors = [];
$importSummary = null;
$rowResults = [];
$csvColumns = [
	'senior_citizen_id',
	'first_name',
	'middle_name',
	'last_name',
	'suffix',
	'sex',
	'birth_date',
	'civil_status',
	'place_of_birth',
	'address',
	'barangay',
	'contact_number',
	'residence_since',
];
$requiredColumns = ['senior_citizen_id', 'first_name', 'last_name', 'sex', 'birth_date', 'civil_status', 'address', 'barangay'];

if (empty($_SESSION['staff_senior_import_token'])) {
	$_SESSION['staff_senior_import_token'] = bin2hex(random_bytes(32));
}

if (($_GET['template'] ?? '') === '1') {
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="senior_import_template.csv"');
	$output = fopen('php://output', 'w');
	fputcsv($output, $csvColumns);
	fputcsv($output, ['SC-0001', 'Juan', '', 'Dela Cruz', '', 'male', '1950-01-15', 'Widowed', 'Manila', '123 Main Street', 'Central', '09171234567', '1990']);
	fclose($output);
	exit;
}

function escapeStaffSeniorImport($value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function staffSeniorImportRowError(array $row): ?string
{
	$lengthLimits = [
		'senior_citizen_id' => 50,
		'first_name' => 100,
		'middle_name' => 100,
		'last_name' => 100,
		'suffix' => 20,
		'place_of_birth' => 255,
		'barangay' => 150,
		'contact_number' => 30,
	];
	foreach ($lengthLimits as $field => $maxLength) {
		if (strlen($row[$field]) > $maxLength) {
			return ucfirst(str_replace('_', ' ', $field)) . ' exceeds ' . $maxLength . ' characters.';
		}
	}

	foreach (['senior_citizen_id', 'first_name', 'last_name', 'sex', 'birth_date', 'civil_status', 'address', 'barangay'] as $field) {
		if ($row[$field] === '') {
			return ucfirst(str_replace('_', ' ', $field)) . ' is required.';
		}
	}

	if (!in_array(strtolower($row['sex']), ['male', 'female'], true)) {
		return 'Sex must be male or female.';
	}

	$birthDate = DateTime::createFromFormat('!Y-m-d', $row['birth_date']);
	if (!$birthDate || $birthDate->format('Y-m-d') !== $row['birth_date'] || $row['birth_date'] > date('Y-m-d')) {
		return 'Birth date must use YYYY-MM-DD and cannot be in the future.';
	}

	$today = new DateTimeImmutable('today');
	$age = (int) $birthDate->diff($today)->format('%y');
	if ($age < 60) {
		return 'Senior applicants must be at least 60 years old.';
	}

	if ($row['residence_since'] !== '' && (!preg_match('/^\d{4}$/', $row['residence_since']) || (int) $row['residence_since'] < 1901 || (int) $row['residence_since'] > (int) date('Y'))) {
		return 'Residence since must be a valid year.';
	}

	return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$submittedToken = $_POST['csrf_token'] ?? '';
	if (!is_string($submittedToken) || !hash_equals($_SESSION['staff_senior_import_token'], $submittedToken)) {
		$errors[] = 'This form has expired. Refresh the page and try again.';
	} elseif (!isset($_FILES['senior_csv']) || !is_array($_FILES['senior_csv'])) {
		$errors[] = 'Select a CSV file to import.';
	} elseif ($_FILES['senior_csv']['error'] !== UPLOAD_ERR_OK) {
		$errors[] = 'The file upload failed. Select a CSV file smaller than 5 MB and try again.';
	} elseif ($_FILES['senior_csv']['size'] > 5 * 1024 * 1024) {
		$errors[] = 'The CSV file must be 5 MB or smaller.';
	} elseif (strtolower(pathinfo((string) $_FILES['senior_csv']['name'], PATHINFO_EXTENSION)) !== 'csv') {
		$errors[] = 'Upload a file with the .csv extension.';
	}

	if (!$errors && !is_uploaded_file($_FILES['senior_csv']['tmp_name'])) {
		$errors[] = 'The uploaded CSV file could not be verified.';
	}

	$csvRows = [];
	$headerMap = [];
	if (!$errors) {
		$handle = fopen($_FILES['senior_csv']['tmp_name'], 'r');
		if ($handle === false) {
			$errors[] = 'The CSV file could not be opened.';
		} else {
			$headers = fgetcsv($handle, 0, ',', '"', '\\');
			if (!is_array($headers) || !$headers) {
				$errors[] = 'The CSV file is empty or has no header row.';
			} else {
				foreach ($headers as $index => $header) {
					$header = trim((string) $header);
					if ($index === 0) {
						$header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
					}
					$headerMap[strtolower($header)] = $index;
				}
				$missingColumns = array_values(array_filter(
					$requiredColumns,
					static fn ($column) => !array_key_exists($column, $headerMap)
				));
				if ($missingColumns) {
					$errors[] = 'Missing required CSV columns: ' . implode(', ', $missingColumns) . '.';
				}
			}

			if (!$errors) {
				$rowNumber = 1;
				while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
					$rowNumber++;
					if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
						continue;
					}
					if (count($csvRows) >= 500) {
						$errors[] = 'The CSV can contain no more than 500 data rows per import.';
						break;
					}
					$row = array_fill_keys($csvColumns, '');
					foreach ($csvColumns as $column) {
						if (isset($headerMap[$column])) {
							$row[$column] = trim((string) ($cells[$headerMap[$column]] ?? ''));
						}
					}
					$row['_row_number'] = $rowNumber;
					$csvRows[] = $row;
				}
			}
			fclose($handle);
		}
	}

	if (!$errors && !$csvRows) {
		$errors[] = 'The CSV has no data rows to import.';
	}

	if (!$errors) {
		$civilStatuses = [];
		$civilStatusResult = $databaseConnection->query('SELECT id, name FROM civil_statuses WHERE is_active = 1');
		if (!$civilStatusResult) {
			$errors[] = 'Civil status records could not be loaded for the import.';
		} else {
			while ($civilStatus = $civilStatusResult->fetch_assoc()) {
				$civilStatuses[strtolower(trim($civilStatus['name']))] = (int) $civilStatus['id'];
			}
		}
	}

	if (!$errors) {
		$transactionStarted = false;
		try {
			$databaseConnection->begin_transaction();
			$transactionStarted = true;

			$fileName = substr(basename((string) $_FILES['senior_csv']['name']), 0, 255);
			$totalRecords = count($csvRows);
			$batchStatement = $databaseConnection->prepare(
				"INSERT INTO import_batches (imported_by, file_name, total_records, status)
				 VALUES (?, ?, ?, 'reviewing')"
			);
			if (!$batchStatement) {
				throw new RuntimeException('Unable to start the import batch.');
			}
			$batchStatement->bind_param('isi', $staffId, $fileName, $totalRecords);
			$batchStatement->execute();
			$batchId = (int) $databaseConnection->insert_id;
			$batchStatement->close();

			$duplicateStatement = $databaseConnection->prepare('SELECT id FROM senior_profiles WHERE senior_citizen_id = ? LIMIT 1');
			$seniorInsert = $databaseConnection->prepare(
				"INSERT INTO senior_profiles
				 (senior_citizen_id, last_name, first_name, middle_name, suffix, sex, birth_date, civil_status_id,
				  place_of_birth, address, barangay, contact_number, residence_since, status, approval_status, submitted_by)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?)"
			);
			$approvalInsert = $databaseConnection->prepare(
				"INSERT INTO approval_requests (request_type, reference_id, requested_by, status)
				 VALUES ('senior', ?, ?, 'pending')"
			);
			$recordInsert = $databaseConnection->prepare(
				'INSERT INTO import_records (import_batch_id, row_number, senior_citizen_id, status, error_message, senior_id)
				 VALUES (?, ?, ?, ?, ?, ?)'
			);
			if (!$duplicateStatement || !$seniorInsert || !$approvalInsert || !$recordInsert) {
				throw new RuntimeException('Unable to prepare the import operations.');
			}

			$counts = ['imported' => 0, 'duplicate' => 0, 'failed' => 0];
			$requesterId = $staffId;
			foreach ($csvRows as $row) {
				$rowNumber = (int) $row['_row_number'];
				$recordId = $row['senior_citizen_id'] !== '' ? $row['senior_citizen_id'] : null;
				$logRecordId = $recordId !== null ? substr($recordId, 0, 50) : null;
				$seniorDatabaseId = null;
				$errorMessage = staffSeniorImportRowError($row);
				$civilStatusId = $civilStatuses[strtolower($row['civil_status'])] ?? null;
				if ($errorMessage === null && $civilStatusId === null) {
					$errorMessage = 'Civil status does not match an active civil status option.';
				}

				$status = 'imported';
				if ($errorMessage !== null) {
					$status = 'failed';
					$counts['failed']++;
				} else {
					$duplicateStatement->bind_param('s', $recordId);
					$duplicateStatement->execute();
					if ($duplicateStatement->get_result()->num_rows > 0) {
						$status = 'duplicate';
						$errorMessage = 'Senior Citizen ID already exists.';
						$counts['duplicate']++;
					} else {
						$databaseConnection->query('SAVEPOINT senior_import_row');
						try {
							$middleName = $row['middle_name'] !== '' ? $row['middle_name'] : null;
							$suffix = $row['suffix'] !== '' ? $row['suffix'] : null;
							$placeOfBirth = $row['place_of_birth'] !== '' ? $row['place_of_birth'] : null;
							$contactNumber = $row['contact_number'] !== '' ? $row['contact_number'] : null;
							$residenceSince = $row['residence_since'] !== '' ? $row['residence_since'] : null;
							$sex = strtolower($row['sex']);
							$seniorInsert->bind_param(
								'sssssssisssssi',
								$recordId,
								$row['last_name'],
								$row['first_name'],
								$middleName,
								$suffix,
								$sex,
								$row['birth_date'],
								$civilStatusId,
								$placeOfBirth,
								$row['address'],
								$row['barangay'],
								$contactNumber,
								$residenceSince,
								$requesterId
							);
							$seniorInsert->execute();
							$seniorDatabaseId = (int) $databaseConnection->insert_id;

							$approvalInsert->bind_param('ii', $seniorDatabaseId, $requesterId);
							$approvalInsert->execute();
							$databaseConnection->query('RELEASE SAVEPOINT senior_import_row');
							$counts['imported']++;
						} catch (Throwable $exception) {
							$databaseConnection->query('ROLLBACK TO SAVEPOINT senior_import_row');
							$databaseConnection->query('RELEASE SAVEPOINT senior_import_row');
							error_log('Staff senior CSV row import failed: ' . $exception->getMessage());
							$status = 'failed';
							$errorMessage = 'The record could not be saved.';
							$seniorDatabaseId = null;
							$counts['failed']++;
						}
					}
				}

				$recordInsert->bind_param('iisssi', $batchId, $rowNumber, $logRecordId, $status, $errorMessage, $seniorDatabaseId);
				$recordInsert->execute();
				$rowResults[] = [
					'row' => $rowNumber,
					'id' => $recordId ?: 'Missing ID',
					'status' => ucfirst($status),
					'message' => $errorMessage ?? 'Submitted for approval.'
				];
			}

			$duplicateStatement->close();
			$seniorInsert->close();
			$approvalInsert->close();
			$recordInsert->close();

			$batchUpdate = $databaseConnection->prepare(
				"UPDATE import_batches
				 SET total_records = ?, successful_records = ?, failed_records = ?, duplicate_records = ?,
				     status = 'completed', completed_at = NOW()
				 WHERE id = ?"
			);
			if (!$batchUpdate) {
				throw new RuntimeException('Unable to finish the import batch.');
			}
			$batchUpdate->bind_param('iiiii', $totalRecords, $counts['imported'], $counts['failed'], $counts['duplicate'], $batchId);
			$batchUpdate->execute();
			$batchUpdate->close();

			writeActivityLog(
				$databaseConnection,
				$staffId,
				'import',
				'Seniors',
				'Imported senior records from ' . $fileName . ': ' . $counts['imported'] . ' submitted, ' . $counts['duplicate'] . ' duplicates, ' . $counts['failed'] . ' failed.',
				$batchId
			);
			$databaseConnection->commit();
			$transactionStarted = false;
			$importSummary = $counts;
			$_SESSION['staff_senior_import_token'] = bin2hex(random_bytes(32));
		} catch (Throwable $exception) {
			if ($transactionStarted) {
				$databaseConnection->rollback();
			}
			error_log('Staff senior CSV import failed: ' . $exception->getMessage());
			$errors[] = 'The import could not be completed. No rows from this batch were saved.';
			$rowResults = [];
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo escapeStaffSeniorImport($page_title); ?></title>
	<link rel="stylesheet" href="../../home.css">
	<link rel="stylesheet" href="assets/seniors_accounts_forms.css?v=28">
	<link rel="stylesheet" href="/BMirk/staff/includes/assets/sidebar.css?v=5">
</head>
<body>
	<div class="staff-layout">
		<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
		<main class="staff-main">
			<?php include __DIR__ . '/../../includes/header.php'; ?>
			<section class="staff-content staff-senior-form-page staff-senior-import-page">
				<header class="staff-senior-form-heading">
					<div>
						<h1>Import Seniors</h1>
						<p>Submit senior citizen records from a CSV file for admin review.</p>
					</div>
					<a class="back-link" href="/BMirk/staff/seniors/accounts/seniors_accounts.php">Back to List</a>
				</header>

				<?php if ($errors): ?>
					<div class="form-error" role="alert">
						<ul>
							<?php foreach ($errors as $error): ?>
								<li><?php echo escapeStaffSeniorImport($error); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ($importSummary): ?>
					<div class="senior-import-summary" role="status">
						<p><strong>Import complete.</strong> <?php echo (int) $importSummary['imported']; ?> submitted for approval, <?php echo (int) $importSummary['duplicate']; ?> duplicates skipped, <?php echo (int) $importSummary['failed']; ?> failed.</p>
					</div>
				<?php endif; ?>

				<div class="senior-detail-columns staff-senior-import-columns">
					<section class="senior-info-card" aria-labelledby="import-guidelines-heading">
						<h2 id="import-guidelines-heading">Import Guidelines</h2>
						<dl class="senior-info-table">
							<div><dt>Maximum Records</dt><dd>500 records per CSV file</dd></div>
							<div><dt>Required Columns</dt><dd><?php echo escapeStaffSeniorImport(implode(', ', $requiredColumns)); ?></dd></div>
							<div><dt>Optional Columns</dt><dd>middle_name, suffix, place_of_birth, contact_number, residence_since</dd></div>
							<div><dt>Accepted Values</dt><dd>Use male/female for sex and YYYY-MM-DD for birth date. Civil status must match an active option.</dd></div>
						</dl>
						<a class="senior-import-template" href="/BMirk/staff/seniors/accounts/seniors_accounts_import.php?template=1">Download CSV Template</a>
					</section>

					<form method="post" enctype="multipart/form-data" class="senior-info-card staff-senior-import-form">
						<input type="hidden" name="csrf_token" value="<?php echo escapeStaffSeniorImport($_SESSION['staff_senior_import_token']); ?>">
						<h2 id="upload-csv-heading">Upload CSV File</h2>
						<dl class="senior-info-table">
							<div><dt><label for="seniorCsv">CSV File <b>*</b></label></dt><dd><input id="seniorCsv" type="file" name="senior_csv" accept=".csv,text/csv" required></dd></div>
						</dl>
						<div class="form-actions staff-senior-form-actions">
							<a class="secondary-form-action" href="/BMirk/staff/seniors/accounts/seniors_accounts.php">Cancel</a>
							<button class="primary-add-btn" type="submit">Import CSV</button>
						</div>
					</form>
				</div>

				<?php if ($rowResults): ?>
					<section class="senior-info-card senior-import-results" aria-labelledby="import-results-heading">
						<h2 id="import-results-heading">Import Results</h2>
						<div class="table-wrap">
							<table class="data-table requests-data-table">
								<thead><tr><th>CSV Row</th><th>Senior ID</th><th>Result</th><th>Details</th></tr></thead>
								<tbody>
									<?php foreach ($rowResults as $result): ?>
										<tr>
											<td><?php echo (int) $result['row']; ?></td>
											<td><?php echo escapeStaffSeniorImport($result['id']); ?></td>
											<td><?php echo escapeStaffSeniorImport($result['status']); ?></td>
											<td><?php echo escapeStaffSeniorImport($result['message']); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</section>
				<?php endif; ?>
			</section>
		</main>
	</div>
</body>
</html>
