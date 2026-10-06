<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/session.php';
require_once __DIR__ . '/../../../config/activity_log.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'staff') {
	header('Location: /BMirk/auth/login.php');
	exit;
}

$page_title = 'Posts';
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
}

if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$formValues = [
	'type' => in_array($_GET['type'] ?? '', ['event', 'announcement'], true) ? $_GET['type'] : 'event',
	'title' => '',
	'description' => '',
	'event_date' => '',
	'start_time' => '',
	'end_time' => '',
	'location' => '',
	'content' => '',
	'expires_at' => ''
];
$errors = [];
$successMessage = $_SESSION['post_flash'] ?? '';
unset($_SESSION['post_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	foreach ($formValues as $key => $value) {
		if (isset($_POST[$key]) && is_string($_POST[$key])) {
			$formValues[$key] = trim($_POST[$key]);
		}
	}
	if (!isset($_POST['type'])) {
		$formValues['type'] = '';
	}

	if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
		$errors[] = 'Your session token expired. Refresh the page and try again.';
	}

	$type = $formValues['type'];
	$title = $formValues['title'];
	if (!in_array($type, ['event', 'announcement'], true)) {
		$errors[] = 'Choose an event or announcement.';
	}
	if ($title === '' || mb_strlen($title, 'UTF-8') > 200) {
		$errors[] = 'Enter a title of 1 to 200 characters.';
	}

	$eventDate = $formValues['event_date'];
	$startTime = $formValues['start_time'];
	$endTime = $formValues['end_time'];
	$location = $formValues['location'];
	$description = $formValues['description'];
	$content = $formValues['content'];
	$expiresAt = null;

	if ($type === 'event') {
		$parsedEventDate = DateTime::createFromFormat('!Y-m-d', $eventDate);
		if (!$parsedEventDate || $parsedEventDate->format('Y-m-d') !== $eventDate || $eventDate < date('Y-m-d')) {
			$errors[] = 'Choose a valid event date that is today or later.';
		}
		if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
			$errors[] = 'Choose a valid event start time.';
		}
		if ($endTime !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime)) {
			$errors[] = 'Choose a valid event end time.';
		} elseif ($endTime !== '' && $endTime <= $startTime) {
			$errors[] = 'The event end time must be after its start time.';
		}
		if ($location === '' || mb_strlen($location, 'UTF-8') > 255) {
			$errors[] = 'Enter a location of 1 to 255 characters.';
		}
		if (mb_strlen($description, 'UTF-8') > 10000) {
			$errors[] = 'The event description must be 10,000 characters or fewer.';
		}
	} else {
		if ($content === '') {
			$errors[] = 'Enter the announcement content.';
		} elseif (mb_strlen($content, 'UTF-8') > 20000) {
			$errors[] = 'The announcement content must be 20,000 characters or fewer.';
		}

		if ($formValues['expires_at'] !== '') {
			$parsedExpiry = DateTime::createFromFormat('!Y-m-d', $formValues['expires_at']);
			if (!$parsedExpiry || $parsedExpiry->format('Y-m-d') !== $formValues['expires_at'] || $formValues['expires_at'] < date('Y-m-d')) {
				$errors[] = 'Choose a valid expiry date that is today or later.';
			} else {
				$expiresAt = $parsedExpiry->format('Y-m-d') . ' 23:59:59';
			}
		}
	}

	if (!$errors) {
		try {
			$databaseConnection->begin_transaction();

			if ($type === 'event') {
				$eventStatement = $databaseConnection->prepare(
					"INSERT INTO events (created_by, title, description, event_date, start_time, end_time, location, status)
					 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
				);
				$endTimeValue = $endTime !== '' ? $endTime : null;
				$eventStatement->bind_param('issssss', $staffId, $title, $description, $eventDate, $startTime, $endTimeValue, $location);
				$eventStatement->execute();
				$referenceId = (int) $databaseConnection->insert_id;
			} else {
				$announcementStatement = $databaseConnection->prepare(
					"INSERT INTO announcements (created_by, title, content, status, expires_at)
					 VALUES (?, ?, ?, 'pending', ?)"
				);
				$announcementStatement->bind_param('isss', $staffId, $title, $content, $expiresAt);
				$announcementStatement->execute();
				$referenceId = (int) $databaseConnection->insert_id;
			}

			$requestType = $type;
			$approvalStatement = $databaseConnection->prepare(
				"INSERT INTO approval_requests (request_type, reference_id, requested_by, status)
				 VALUES (?, ?, ?, 'pending')"
			);
			$approvalStatement->bind_param('sii', $requestType, $referenceId, $staffId);
			$approvalStatement->execute();
			writeActivityLog(
				$databaseConnection,
				$staffId,
				'create',
				ucfirst($type),
				'Submitted "' . $title . '" for approval.',
				$referenceId
			);
			$databaseConnection->commit();

			$_SESSION['post_flash'] = ucfirst($type) . ' submitted for approval.';
			$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
			header('Location: /BMirk/staff/posts/create/posts_list.php', true, 303);
			exit;
		} catch (Throwable $exception) {
			$databaseConnection->rollback();
			$errors[] = 'The post could not be submitted. Please try again.';
		}
	}
}

function escapePostValue($value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo escapePostValue($page_title); ?></title>
	<link rel="stylesheet" href="../../home.css">
	<link rel="stylesheet" href="assets/posts_create.css?v=2">
	<link rel="stylesheet" href="/BMirk/staff/includes/assets/sidebar.css?v=5">
</head>
<body>
	<div class="staff-layout">
		<?php include __DIR__ . '/../../includes/sidebar.php'; ?>

		<main class="staff-main">
			<?php include __DIR__ . '/../../includes/header.php'; ?>

			<section class="staff-content staff-post-page">
				<header class="staff-post-heading">
					<div>
						<h1>Posts</h1>
						<p>Create an event or announcement for admin review.</p>
					</div>
					<a class="staff-post-back" href="/BMirk/staff/posts/create/posts_list.php">Back</a>
				</header>

					<?php if ($successMessage !== ''): ?>
						<div class="staff-post-alert success" role="status"><?php echo escapePostValue($successMessage); ?></div>
					<?php endif; ?>

					<?php if ($errors): ?>
						<div class="staff-post-alert error" role="alert">
							<ul>
								<?php foreach ($errors as $error): ?>
									<li><?php echo escapePostValue($error); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>

					<form class="staff-post-form" method="post" action="" id="postForm">
						<input type="hidden" name="csrf_token" value="<?php echo escapePostValue($_SESSION['csrf_token']); ?>">
						<section class="staff-post-card" aria-labelledby="post-information-heading">
							<h2 id="post-information-heading">Post Information</h2>
							<fieldset class="staff-post-type-fieldset">
								<legend>Choose whether this post is an event or announcement <b>*</b></legend>
								<div class="staff-post-type-switch">
									<label>
										<input type="radio" name="type" value="event" required <?php echo $formValues['type'] === 'event' ? 'checked' : ''; ?>>
										<span>Event</span>
									</label>
									<label>
										<input type="radio" name="type" value="announcement" required <?php echo $formValues['type'] === 'announcement' ? 'checked' : ''; ?>>
										<span>Announcement</span>
									</label>
								</div>
							</fieldset>
							<dl class="staff-post-fields-table">
								<div>
									<dt><label id="postTitleLabel" for="postTitle">Event title <b>*</b></label></dt>
									<dd><input id="postTitle" type="text" name="title" maxlength="200" required value="<?php echo escapePostValue($formValues['title']); ?>" placeholder="Give this event a clear title"></dd>
								</div>
							</dl>
						</section>

						<section class="staff-post-card post-fields" id="eventFields" aria-labelledby="event-details-heading" hidden>
							<h2 id="event-details-heading">Event Details</h2>
							<dl class="staff-post-fields-table">
								<div>
									<dt><label for="eventDate">Event date <b>*</b></label></dt>
									<dd><input id="eventDate" type="date" name="event_date" value="<?php echo escapePostValue($formValues['event_date']); ?>"></dd>
								</div>
								<div>
									<dt><label for="eventLocation">Location <b>*</b></label></dt>
									<dd><input id="eventLocation" type="text" name="location" maxlength="255" value="<?php echo escapePostValue($formValues['location']); ?>" placeholder="Venue or address"></dd>
								</div>
								<div>
									<dt><label for="eventStartTime">Start time <b>*</b></label></dt>
									<dd><input id="eventStartTime" type="time" name="start_time" value="<?php echo escapePostValue($formValues['start_time']); ?>"></dd>
								</div>
								<div>
									<dt><label for="eventEndTime">End time <small>Optional</small></label></dt>
									<dd><input id="eventEndTime" type="time" name="end_time" value="<?php echo escapePostValue($formValues['end_time']); ?>"></dd>
								</div>
								<div>
									<dt><label for="eventDescription">Description <small>Optional</small></label></dt>
									<dd><textarea id="eventDescription" name="description" rows="5" maxlength="10000" placeholder="Add event details"><?php echo escapePostValue($formValues['description']); ?></textarea></dd>
								</div>
							</dl>
						</section>

						<section class="staff-post-card post-fields" id="announcementFields" aria-labelledby="announcement-details-heading" hidden>
							<h2 id="announcement-details-heading">Announcement Details</h2>
							<dl class="staff-post-fields-table">
								<div>
									<dt><label for="announcementContent">Announcement content <b>*</b></label></dt>
									<dd><textarea id="announcementContent" name="content" rows="7" maxlength="20000" placeholder="Write the announcement"><?php echo escapePostValue($formValues['content']); ?></textarea></dd>
								</div>
								<div>
									<dt><label for="announcementExpiry">Expires on <small>Optional</small></label></dt>
									<dd><input id="announcementExpiry" type="date" name="expires_at" value="<?php echo escapePostValue($formValues['expires_at']); ?>"></dd>
								</div>
							</dl>
						</section>

						<footer class="staff-post-footer">
							<span>Submissions are sent for admin approval.</span>
							<button class="staff-post-submit" type="submit">Submit for approval</button>
						</footer>
					</form>
			</section>
		</main>
	</div>

	<script>
		const postTypeInputs = document.querySelectorAll('input[name="type"]');
		const eventFields = document.getElementById('eventFields');
		const announcementFields = document.getElementById('announcementFields');
		const postTitleLabel = document.getElementById('postTitleLabel');
		const postTitleInput = document.getElementById('postTitle');

		function updatePostType() {
			const selectedInput = document.querySelector('input[name="type"]:checked');
			const selectedType = selectedInput ? selectedInput.value : '';
			const isEvent = selectedType === 'event';
			const isAnnouncement = selectedType === 'announcement';
			postTitleLabel.firstChild.textContent = isAnnouncement ? 'Announcement title ' : 'Event title ';
			postTitleInput.placeholder = isAnnouncement
				? 'Give this announcement a clear title'
				: 'Give this event a clear title';
			eventFields.hidden = !isEvent;
			announcementFields.hidden = !isAnnouncement;

			eventFields.querySelectorAll('input, textarea').forEach(function (field) {
				field.disabled = !isEvent;
				field.required = isEvent && ['event_date', 'start_time', 'location'].includes(field.name);
			});
			announcementFields.querySelectorAll('input, textarea').forEach(function (field) {
				field.disabled = !isAnnouncement;
				field.required = isAnnouncement && field.name === 'content';
			});
		}

		postTypeInputs.forEach(function (input) {
			input.addEventListener('change', updatePostType);
		});
		updatePostType();
	</script>
</body>
</html>
