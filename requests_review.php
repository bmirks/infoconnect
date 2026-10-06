<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/activity_log.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'admin') {
	header('Location: /BMirk/auth/login.php');
	exit;
}

$page_title = 'Review Request';
$requestId = filter_var($_GET['id'] ?? $_POST['id'] ?? null, FILTER_VALIDATE_INT);
$returnSource = $_GET['from'] ?? $_POST['from'] ?? '';
if (!in_array($returnSource, ['archive', 'history'], true)) {
	$returnSource = '';
}
$historyQuerySuffix = $returnSource !== '' ? '&from=' . $returnSource : '';
$request = null;
$requestDetails = [];
$pageError = '';
$formError = '';
$successMessage = $_SESSION['requests_review_flash'] ?? '';
unset($_SESSION['requests_review_flash']);

if (empty($_SESSION['requests_review_token'])) {
	$_SESSION['requests_review_token'] = bin2hex(random_bytes(32));
}

function escapeRequestReviewValue($value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function requestReviewValue($value): string
{
	$value = trim((string) $value);
	return $value !== '' ? $value : 'N/A';
}

if ($requestId === false || $requestId === null || $requestId < 1) {
	$pageError = 'Choose a valid request to review.';
} else {
	try {
		$requestStatement = $databaseConnection->prepare(
			"SELECT ar.id, ar.request_type, ar.reference_id, ar.status, ar.remarks, ar.created_at, ar.reviewed_at,
			        COALESCE(
			            NULLIF(TRIM(CONCAT_WS(' ', sp.first_name, sp.middle_name, sp.last_name, sp.suffix)), ''),
			            NULLIF(TRIM(CONCAT_WS(' ', ap.first_name, ap.middle_name, ap.last_name, ap.suffix)), ''),
			            u.account_id
			        ) AS requester_name
			 FROM approval_requests ar
			 LEFT JOIN users u ON u.id = ar.requested_by
			 LEFT JOIN staff_profiles sp ON sp.user_id = u.id
			 LEFT JOIN admin_profiles ap ON ap.user_id = u.id
			 WHERE ar.id = ?
			 LIMIT 1"
		);
		if (!$requestStatement) {
			throw new RuntimeException('Could not prepare the request lookup.');
		}
		$requestStatement->bind_param('i', $requestId);
		$requestStatement->execute();
		$request = $requestStatement->get_result()->fetch_assoc() ?: null;
		$requestStatement->close();

		if (!$request) {
			$pageError = 'The requested approval record could not be found.';
		} else {
			$referenceId = (int) $request['reference_id'];
			$detailsStatement = null;
			switch ($request['request_type']) {
				case 'event':
					$detailsStatement = $databaseConnection->prepare(
						'SELECT title, description, event_date, start_time, end_time, location, status
						 FROM events WHERE id = ? LIMIT 1'
					);
					break;
				case 'announcement':
					$detailsStatement = $databaseConnection->prepare(
						'SELECT title, content, status, expires_at
						 FROM announcements WHERE id = ? LIMIT 1'
					);
					break;
				case 'senior':
					$detailsStatement = $databaseConnection->prepare(
						'SELECT sp.senior_citizen_id, sp.first_name, sp.middle_name, sp.last_name, sp.suffix,
						       sp.sex, sp.birth_date, sp.place_of_birth, sp.address, sp.barangay, sp.contact_number,
						       sp.residence_since, sp.status, sp.approval_status, cs.name AS civil_status,
						       (SELECT COUNT(DISTINCT sr.requirement_type)
						        FROM senior_requirements sr
						        WHERE sr.senior_id = sp.id
						          AND sr.requirement_type IN (\'birth_certificate\', \'valid_id\')
						          AND sr.status <> \'missing\') AS completed_requirements
						 FROM senior_profiles sp
						 LEFT JOIN civil_statuses cs ON cs.id = sp.civil_status_id
						 WHERE sp.id = ? LIMIT 1'
					);
					break;
				case 'password_reset':
					$detailsStatement = $databaseConnection->prepare(
						'SELECT account_id, role, status FROM users WHERE id = ? LIMIT 1'
					);
					break;
			}

			if (!$detailsStatement) {
				throw new RuntimeException('This request type cannot be reviewed.');
			}
			$detailsStatement->bind_param('i', $referenceId);
			$detailsStatement->execute();
			$record = $detailsStatement->get_result()->fetch_assoc() ?: null;
			$detailsStatement->close();

			if (!$record) {
				throw new RuntimeException('The record linked to this request could not be found.');
			}

			switch ($request['request_type']) {
				case 'event':
					$requestDetails = [
						'Event title' => requestReviewValue($record['title']),
						'Event date' => $record['event_date'] ? date('F j, Y', strtotime($record['event_date'])) : 'N/A',
						'Start time' => $record['start_time'] ? date('g:i A', strtotime($record['start_time'])) : 'N/A',
						'End time' => $record['end_time'] ? date('g:i A', strtotime($record['end_time'])) : 'N/A',
						'Location' => requestReviewValue($record['location']),
						'Description' => requestReviewValue($record['description']),
						'Record status' => ucfirst($record['status']),
					];
					break;
				case 'announcement':
					$requestDetails = [
						'Announcement title' => requestReviewValue($record['title']),
						'Expires on' => $record['expires_at'] ? date('F j, Y g:i A', strtotime($record['expires_at'])) : 'No expiry date',
						'Content' => requestReviewValue($record['content']),
						'Record status' => ucfirst($record['status']),
					];
					break;
				case 'senior':
					$fullName = trim(implode(' ', array_filter([
						$record['first_name'],
						$record['middle_name'],
						$record['last_name'],
						$record['suffix'],
					])));
					$recordStatus = (int) $record['completed_requirements'] < 2
						? 'pending'
						: strtolower($record['status']);
					$requestDetails = [
						'Full name' => requestReviewValue($fullName),
						'Senior citizen ID' => requestReviewValue($record['senior_citizen_id']),
						'Birth date' => $record['birth_date'] ? date('F j, Y', strtotime($record['birth_date'])) : 'N/A',
						'Sex' => ucfirst($record['sex']),
						'Civil status' => requestReviewValue($record['civil_status']),
						'Contact number' => requestReviewValue($record['contact_number']),
						'Address' => requestReviewValue($record['address']),
						'Barangay' => requestReviewValue($record['barangay']),
						'Place of birth' => requestReviewValue($record['place_of_birth']),
						'Residence since' => requestReviewValue($record['residence_since']),
						'Record status' => ucfirst($recordStatus),
						'Approval status' => ucfirst($record['approval_status']),
					];
					break;
				case 'password_reset':
					$requestDetails = [
						'Account ID' => requestReviewValue($record['account_id']),
						'Account role' => ucfirst($record['role']),
						'Account status' => ucfirst($record['status']),
					];
					break;
			}
		}
	} catch (Throwable $exception) {
		$pageError = $exception->getMessage();
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $request && $pageError === '') {
	$decision = $_POST['decision'] ?? '';
	$remarks = $_POST['remarks'] ?? '';
	$submittedToken = $_POST['csrf_token'] ?? '';

	if ($returnSource === 'history') {
		$formError = 'Requests opened from History are view-only.';
	} elseif (!is_string($submittedToken) || !hash_equals($_SESSION['requests_review_token'], $submittedToken)) {
		$formError = 'This form has expired. Refresh the page and try again.';
	} elseif (!is_string($decision) || !in_array($decision, ['approved', 'rejected', 'reopen'], true)) {
		$formError = 'Choose a valid request action.';
	} elseif (!is_string($remarks) || strlen($remarks) > 10000) {
		$formError = 'Remarks must be 10,000 characters or fewer.';
	} elseif ($decision === 'reopen' && !in_array($request['status'], ['approved', 'rejected'], true)) {
		$formError = 'Only archived requests can be returned to pending.';
	} elseif ($decision !== 'reopen' && $request['status'] !== 'pending') {
		$formError = 'This request has already been reviewed.';
	} else {
		$transactionStarted = false;
		try {
			$databaseConnection->begin_transaction();
			$transactionStarted = true;

			$lockStatement = $databaseConnection->prepare('SELECT request_type, reference_id, status FROM approval_requests WHERE id = ? FOR UPDATE');
			$lockStatement->bind_param('i', $requestId);
			$lockStatement->execute();
			$lockedRequest = $lockStatement->get_result()->fetch_assoc();
			$lockStatement->close();
			$allowedStatuses = $decision === 'reopen' ? ['approved', 'rejected'] : ['pending'];
			if (!$lockedRequest || !in_array($lockedRequest['status'], $allowedStatuses, true)) {
				throw new RuntimeException('This request has changed or no longer exists.');
			}

			$referenceId = (int) $lockedRequest['reference_id'];
			$targetStatement = null;
			if ($decision === 'reopen') {
				switch ($lockedRequest['request_type']) {
					case 'event':
						$targetStatement = $databaseConnection->prepare("UPDATE events SET status = 'pending' WHERE id = ? AND status IN ('approved', 'rejected')");
						break;
					case 'announcement':
						$targetStatement = $databaseConnection->prepare("UPDATE announcements SET status = 'pending' WHERE id = ? AND status IN ('approved', 'rejected')");
						break;
					case 'senior':
						$targetStatement = $databaseConnection->prepare(
							"UPDATE senior_profiles
							 SET approval_status = 'pending', status = 'pending', reviewed_by = NULL, reviewed_at = NULL, rejection_reason = NULL
							 WHERE id = ? AND approval_status IN ('approved', 'rejected')"
						);
						break;
					case 'password_reset':
						break;
					default:
						throw new RuntimeException('This request type cannot be reopened.');
				}
				if ($targetStatement) {
					$targetStatement->bind_param('i', $referenceId);
				}
			} else {
				switch ($lockedRequest['request_type']) {
					case 'event':
						$targetStatement = $databaseConnection->prepare("UPDATE events SET status = ? WHERE id = ? AND status = 'pending'");
						break;
					case 'announcement':
						$targetStatement = $databaseConnection->prepare("UPDATE announcements SET status = ? WHERE id = ? AND status = 'pending'");
						break;
					case 'senior':
						$seniorStatus = $decision === 'approved' ? 'active' : 'pending';
						$targetStatement = $databaseConnection->prepare(
							'UPDATE senior_profiles
							 SET approval_status = ?, status = ?, reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ?
							 WHERE id = ? AND approval_status = \'pending\''
						);
						$adminId = (int) ($_SESSION['user_id'] ?? 0);
						$rejectionReason = $decision === 'rejected' && trim($remarks) !== '' ? trim($remarks) : null;
						$targetStatement->bind_param('ssisi', $decision, $seniorStatus, $adminId, $rejectionReason, $referenceId);
						break;
					case 'password_reset':
						break;
					default:
						throw new RuntimeException('This request type cannot be reviewed.');
				}
			}

			if ($targetStatement) {
				if ($decision !== 'reopen' && $lockedRequest['request_type'] !== 'senior') {
					$targetStatement->bind_param('si', $decision, $referenceId);
				}
				$targetStatement->execute();
				if ($targetStatement->affected_rows !== 1) {
					$targetStatement->close();
					throw new RuntimeException('The linked record is no longer pending and was not changed.');
				}
				$targetStatement->close();
			}

			if ($decision === 'reopen') {
				$updateStatement = $databaseConnection->prepare(
					"UPDATE approval_requests
					 SET status = 'pending', reviewed_by = NULL, reviewed_at = NULL, remarks = NULL
					 WHERE id = ? AND status IN ('approved', 'rejected')"
				);
				$updateStatement->bind_param('i', $requestId);
			} else {
				$updateStatement = $databaseConnection->prepare(
					'UPDATE approval_requests
					 SET status = ?, reviewed_by = ?, reviewed_at = NOW(), remarks = ?
					 WHERE id = ? AND status = \'pending\''
				);
				$adminId = (int) ($_SESSION['user_id'] ?? 0);
				$remarks = trim($remarks);
				$updateStatement->bind_param('sisi', $decision, $adminId, $remarks, $requestId);
			}
			$updateStatement->execute();
			if ($updateStatement->affected_rows !== 1) {
				$updateStatement->close();
				throw new RuntimeException('The request could not be updated. Please try again.');
			}
			$updateStatement->close();

			$logAction = $decision === 'reopen' ? 'reopen' : $decision;
			writeActivityLog(
				$databaseConnection,
				(int) ($_SESSION['user_id'] ?? 0),
				$logAction,
				'Requests',
				ucfirst($decision) . ' ' . str_replace('_', ' ', $lockedRequest['request_type']) . ' request REQ-' . str_pad((string) $requestId, 3, '0', STR_PAD_LEFT) . '.',
				(int) $requestId
			);
			$databaseConnection->commit();
			$transactionStarted = false;
			$_SESSION['requests_review_flash'] = $decision === 'reopen'
				? 'Decision cancelled. Request returned to pending.'
				: 'Request ' . $decision . '.';
			$_SESSION['requests_review_token'] = bin2hex(random_bytes(32));
			header('Location: /BMirk/admin/requests/requests_review.php?id=' . (int) $requestId . $historyQuerySuffix, true, 303);
			exit;
		} catch (Throwable $exception) {
			if ($transactionStarted) {
				$databaseConnection->rollback();
			}
			$formError = $exception->getMessage();
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo escapeRequestReviewValue($page_title); ?></title>
	<link rel="stylesheet" href="/BMirk/admin/home.css">
	<link rel="stylesheet" href="/BMirk/admin/management/assets/seniors_form.css?v=2">
	<link rel="stylesheet" href="/BMirk/admin/requests/assets/requests_review.css?v=2">
</head>
<body>
	<div class="admin-layout">
		<?php include __DIR__ . '/../includes/sidebar.php'; ?>
		<main class="admin-main">
			<?php include __DIR__ . '/../includes/header.php'; ?>
			<section class="admin-content senior-detail-page request-review-page">
					<header class="senior-detail-heading">
						<div>
							<h1><?php echo escapeRequestReviewValue($page_title); ?></h1>
							<p>Review the submitted request details below.</p>
						</div>
						<a class="senior-detail-back" href="<?php echo $returnSource === 'history' ? '/BMirk/admin/requests/history.php' : ($returnSource === 'archive' || ($request && $request['status'] !== 'pending') ? '/BMirk/admin/requests/archive.php' : '/BMirk/admin/requests/requests.php'); ?>">
							Back to <?php echo $returnSource === 'history' ? 'History' : ($returnSource === 'archive' || ($request && $request['status'] !== 'pending') ? 'Archive' : 'Pending Requests'); ?>
						</a>
					</header>

					<?php if ($successMessage !== ''): ?>
						<div class="review-alert success" role="status"><?php echo escapeRequestReviewValue($successMessage); ?></div>
					<?php endif; ?>
					<?php if ($pageError !== ''): ?>
						<div class="review-alert error" role="alert"><?php echo escapeRequestReviewValue($pageError); ?></div>
					<?php elseif ($formError !== ''): ?>
						<div class="review-alert error" role="alert"><?php echo escapeRequestReviewValue($formError); ?></div>
					<?php endif; ?>

					<?php if ($request && $pageError === ''): ?>
						<?php
							$requestTypeLabel = ucwords(str_replace('_', ' ', $request['request_type']));
							$submittedAt = $request['created_at'] ? date('M d, Y g:i A', strtotime($request['created_at'])) : 'N/A';
						?>
						<section class="senior-detail-overview" aria-label="Request summary">
							<div class="senior-detail-name">
								<h2>Request #<?php echo (int) $request['id']; ?></h2>
								<p><?php echo escapeRequestReviewValue($requestTypeLabel); ?> request</p>
							</div>
							<div class="senior-overview-item">
								<span>Status</span>
								<strong><span class="detail-status <?php echo escapeRequestReviewValue(strtolower($request['status'])); ?>"><?php echo escapeRequestReviewValue(ucfirst($request['status'])); ?></span></strong>
							</div>
							<div class="senior-overview-item"><span>Requested by</span><strong><?php echo escapeRequestReviewValue(requestReviewValue($request['requester_name'])); ?></strong></div>
							<div class="senior-overview-item"><span>Submitted on</span><strong><?php echo escapeRequestReviewValue($submittedAt); ?></strong></div>
							<div class="senior-overview-item"><span>Reference ID</span><strong><?php echo (int) $request['reference_id']; ?></strong></div>
						</section>

						<div class="senior-detail-columns">
							<div class="senior-detail-column">
								<section class="senior-info-card" aria-labelledby="request-details-heading">
									<h2 id="request-details-heading">Submitted Details</h2>
									<dl class="senior-info-table review-info-list">
										<?php foreach ($requestDetails as $label => $value): ?>
											<div><dt><?php echo escapeRequestReviewValue($label); ?></dt><dd><?php echo escapeRequestReviewValue($value); ?></dd></div>
										<?php endforeach; ?>
									</dl>
								</section>
							</div>

							<div class="senior-detail-column">
								<section class="senior-info-card" aria-labelledby="request-information-heading">
									<h2 id="request-information-heading">Request Information</h2>
									<dl class="senior-info-table">
										<div><dt>Request status</dt><dd><?php echo escapeRequestReviewValue(ucfirst($request['status'])); ?></dd></div>
										<div><dt>Requested by</dt><dd><?php echo escapeRequestReviewValue(requestReviewValue($request['requester_name'])); ?></dd></div>
										<div><dt>Submitted on</dt><dd><?php echo escapeRequestReviewValue($submittedAt); ?></dd></div>
										<div><dt>Request ID</dt><dd><?php echo (int) $request['id']; ?></dd></div>
										<div><dt>Reference ID</dt><dd><?php echo (int) $request['reference_id']; ?></dd></div>
									</dl>
								</section>

								<?php if ($request['status'] === 'pending' && $returnSource !== 'history'): ?>
									<form class="senior-info-card review-form" method="post" action="/BMirk/admin/requests/requests_review.php?id=<?php echo (int) $requestId . $historyQuerySuffix; ?>">
										<input type="hidden" name="id" value="<?php echo (int) $requestId; ?>">
										<input type="hidden" name="csrf_token" value="<?php echo escapeRequestReviewValue($_SESSION['requests_review_token']); ?>">
										<h2 id="review-decision-heading">Review Decision</h2>
										<label for="remarks">Remarks <span>(optional)</span></label>
										<textarea id="remarks" name="remarks" rows="4" maxlength="10000" placeholder="Add a note for this decision..."><?php echo escapeRequestReviewValue(is_string($_POST['remarks'] ?? null) ? $_POST['remarks'] : ''); ?></textarea>
										<div class="review-actions">
											<button class="review-decision reject" type="submit" name="decision" value="rejected">Reject Request</button>
											<button class="review-decision approve" type="submit" name="decision" value="approved">Approve Request</button>
										</div>
									</form>
								<?php elseif ($request['status'] === 'pending'): ?>
									<section class="senior-info-card review-closed" aria-labelledby="review-decision-heading">
										<h2 id="review-decision-heading">Review Decision</h2>
										<p>This request is pending review. Requests opened from History are view-only.</p>
									</section>
								<?php else: ?>
									<section class="senior-info-card review-closed" aria-labelledby="review-decision-heading">
										<h2 id="review-decision-heading">Review Decision</h2>
										<p>This request has already been reviewed<?php echo $request['reviewed_at'] ? ' on ' . escapeRequestReviewValue(date('M d, Y g:i A', strtotime($request['reviewed_at']))) : ''; ?>.</p>
										<?php if (trim((string) $request['remarks']) !== ''): ?>
											<p><strong>Review remarks:</strong> <?php echo escapeRequestReviewValue($request['remarks']); ?></p>
										<?php endif; ?>
										<?php if ($returnSource !== 'history'): ?>
											<form class="review-reopen-form" method="post" action="/BMirk/admin/requests/requests_review.php?id=<?php echo (int) $requestId . $historyQuerySuffix; ?>">
												<input type="hidden" name="id" value="<?php echo (int) $requestId; ?>">
												<input type="hidden" name="csrf_token" value="<?php echo escapeRequestReviewValue($_SESSION['requests_review_token']); ?>">
												<button class="review-reopen-btn" type="submit" name="decision" value="reopen">Cancel Decision</button>
											</form>
										<?php endif; ?>
									</section>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>
			</section>
		</main>
	</div>
</body>
</html>
