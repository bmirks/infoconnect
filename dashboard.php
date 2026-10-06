<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'staff') {
	header('Location: /BMirk/auth/login.php');
	exit;
}

$page_title = 'Staff Dashboard';
$staffId = (int) ($_SESSION['user_id'] ?? 0);
$staffFirstName = $_SESSION['account_id'] ?? 'Staff';
$dashboardError = '';
$metrics = ['seniors' => 0, 'active_seniors' => 0, 'my_posts' => 0, 'pending_posts' => 0];
$recentPosts = [];
$upcomingEvents = [];
$recentActivities = [];

function escapeStaffDashboard($value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function staffDashboardTimeAgo(?string $datetime): string
{
	if (!$datetime) {
		return 'Just now';
	}
	$timestamp = strtotime($datetime);
	$seconds = max(0, time() - ($timestamp ?: time()));
	if ($seconds < 60) {
		return 'Just now';
	}
	foreach (['day' => 86400, 'hour' => 3600, 'minute' => 60] as $unit => $size) {
		$count = (int) floor($seconds / $size);
		if ($count > 0) {
			return $count . ' ' . $unit . ($count === 1 ? '' : 's') . ' ago';
		}
	}
	return 'Just now';
}

try {
	$profileStatement = $databaseConnection->prepare('SELECT first_name FROM staff_profiles WHERE user_id = ? LIMIT 1');
	$profileStatement->bind_param('i', $staffId);
	$profileStatement->execute();
	$profile = $profileStatement->get_result()->fetch_assoc();
	$profileStatement->close();
	if (!empty($profile['first_name'])) {
		$staffFirstName = $profile['first_name'];
	}

	$seniorSummary = $databaseConnection->query(
		"SELECT COUNT(*) AS total,
		        COALESCE(SUM(status = 'active'), 0) AS active
		 FROM senior_profiles
		 WHERE status <> 'deceased'"
	);
	$seniorSummaryRow = $seniorSummary->fetch_assoc() ?: [];
	$metrics['seniors'] = (int) ($seniorSummaryRow['total'] ?? 0);
	$metrics['active_seniors'] = (int) ($seniorSummaryRow['active'] ?? 0);

	$postSummary = $databaseConnection->prepare(
		"SELECT
		    (SELECT COUNT(*) FROM events WHERE created_by = ?) +
		    (SELECT COUNT(*) FROM announcements WHERE created_by = ?) AS total_posts,
		    (SELECT COUNT(*) FROM approval_requests
		     WHERE requested_by = ? AND status = 'pending'
		       AND request_type IN ('event', 'announcement')) AS pending_posts"
	);
	$postSummary->bind_param('iii', $staffId, $staffId, $staffId);
	$postSummary->execute();
	$postSummaryRow = $postSummary->get_result()->fetch_assoc() ?: [];
	$postSummary->close();
	$metrics['my_posts'] = (int) ($postSummaryRow['total_posts'] ?? 0);
	$metrics['pending_posts'] = (int) ($postSummaryRow['pending_posts'] ?? 0);

	$recentPostStatement = $databaseConnection->prepare(
		"SELECT ar.request_type, ar.status AS approval_status, ar.created_at,
		        COALESCE(e.title, a.title, 'Untitled post') AS title,
		        CASE WHEN ar.request_type = 'event' THEN e.event_date ELSE a.expires_at END AS post_date
		 FROM approval_requests ar
		 LEFT JOIN events e ON ar.request_type = 'event' AND e.id = ar.reference_id
		 LEFT JOIN announcements a ON ar.request_type = 'announcement' AND a.id = ar.reference_id
		 WHERE ar.requested_by = ? AND ar.request_type IN ('event', 'announcement')
		 ORDER BY ar.created_at DESC, ar.id DESC
		 LIMIT 6"
	);
	$recentPostStatement->bind_param('i', $staffId);
	$recentPostStatement->execute();
	$recentPostResult = $recentPostStatement->get_result();
	while ($row = $recentPostResult->fetch_assoc()) {
		$recentPosts[] = $row;
	}
	$recentPostStatement->close();

	$eventResult = $databaseConnection->query(
		"SELECT title, event_date, start_time, location
		 FROM events
		 WHERE status IN ('approved', 'published') AND event_date >= CURDATE()
		 ORDER BY event_date ASC, start_time ASC
		 LIMIT 5"
	);
	while ($row = $eventResult->fetch_assoc()) {
		$upcomingEvents[] = $row;
	}

	$activityStatement = $databaseConnection->prepare(
		'SELECT action, module, description, created_at FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 6'
	);
	$activityStatement->bind_param('i', $staffId);
	$activityStatement->execute();
	$activityResult = $activityStatement->get_result();
	while ($row = $activityResult->fetch_assoc()) {
		$recentActivities[] = $row;
	}
	$activityStatement->close();
} catch (Throwable $exception) {
	error_log('Unable to load staff dashboard: ' . $exception->getMessage());
	$dashboardError = 'Some dashboard information could not be loaded. Refresh the page or contact an administrator.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo escapeStaffDashboard($page_title); ?></title>
	<link rel="stylesheet" href="home.css">
	<link rel="stylesheet" href="dashboard.css?v=1">
	<link rel="stylesheet" href="/BMirk/staff/includes/assets/sidebar.css?v=5">
	<link rel="stylesheet" href="assets/table.css?v=12">
</head>
<body>
	<div class="staff-layout">
		<?php include __DIR__ . '/includes/sidebar.php'; ?>
		<main class="staff-main">
			<?php include __DIR__ . '/includes/header.php'; ?>
			<section class="staff-content staff-dashboard-page">
				<header class="staff-dashboard-heading">
					<div><h1>Dashboard</h1><p>Welcome back, <?php echo escapeStaffDashboard($staffFirstName); ?>. Here is your staff workspace at a glance.</p></div>
					<a class="dashboard-primary-link" href="/BMirk/staff/posts/create/posts_create.php">Create Post</a>
				</header>
				<?php if ($dashboardError !== ''): ?><div class="dashboard-alert" role="alert"><?php echo escapeStaffDashboard($dashboardError); ?></div><?php endif; ?>

				<div class="staff-dashboard-stats">
					<a class="dashboard-stat" href="/BMirk/staff/seniors/accounts/seniors_accounts.php"><span>Total Seniors</span><strong><?php echo number_format($metrics['seniors']); ?></strong><small>Registered records</small></a>
					<a class="dashboard-stat stat-green" href="/BMirk/staff/seniors/accounts/seniors_accounts.php"><span>Active Seniors</span><strong><?php echo number_format($metrics['active_seniors']); ?></strong><small>Currently active</small></a>
					<a class="dashboard-stat stat-purple" href="/BMirk/staff/posts/create/posts_list.php"><span>My Posts</span><strong><?php echo number_format($metrics['my_posts']); ?></strong><small>Events and announcements</small></a>
					<a class="dashboard-stat stat-amber" href="/BMirk/staff/posts/create/posts_list.php"><span>Awaiting Review</span><strong><?php echo number_format($metrics['pending_posts']); ?></strong><small>Submitted for approval</small></a>
				</div>

				<div class="staff-dashboard-columns">
					<section class="dashboard-card">
						<header class="dashboard-card-heading"><div><h2>Recent Post Submissions</h2><p>Track the latest events and announcements you submitted.</p></div><a href="/BMirk/staff/posts/create/posts_list.php">View posts</a></header>
						<div class="table-wrap">
							<table class="data-table staff-consistent-table dashboard-post-table">
								<thead><tr><th>Title</th><th>Type</th><th>Submitted</th><th>Status</th></tr></thead>
								<tbody>
									<?php foreach ($recentPosts as $post): ?>
										<tr>
											<td class="dashboard-post-title"><?php echo escapeStaffDashboard($post['title']); ?></td>
											<td><?php echo escapeStaffDashboard(ucfirst($post['request_type'])); ?></td>
											<td><?php echo escapeStaffDashboard(date('M j, Y', strtotime($post['created_at']))); ?></td>
											<td><span class="dashboard-status <?php echo escapeStaffDashboard(strtolower($post['approval_status'])); ?>"><?php echo escapeStaffDashboard(ucfirst($post['approval_status'])); ?></span></td>
										</tr>
									<?php endforeach; ?>
									<?php if (!$recentPosts): ?><tr><td colspan="4" class="dashboard-empty">You have not submitted any posts yet.</td></tr><?php endif; ?>
								</tbody>
							</table>
						</div>
					</section>

					<section class="dashboard-card upcoming-card">
						<header class="dashboard-card-heading"><div><h2>Upcoming Events</h2><p>Approved events on the community calendar.</p></div><a href="/BMirk/staff/posts/create/posts_list.php">All posts</a></header>
						<?php if ($upcomingEvents): ?>
							<ul class="upcoming-event-list">
								<?php foreach ($upcomingEvents as $event): ?>
									<li><time datetime="<?php echo escapeStaffDashboard($event['event_date']); ?>"><strong><?php echo escapeStaffDashboard(date('M j', strtotime($event['event_date']))); ?></strong><span><?php echo escapeStaffDashboard(date('Y', strtotime($event['event_date']))); ?></span></time><div><strong><?php echo escapeStaffDashboard($event['title']); ?></strong><small><?php echo escapeStaffDashboard($event['start_time'] ? date('g:i A', strtotime($event['start_time'])) : 'All day'); ?> · <?php echo escapeStaffDashboard($event['location'] ?: 'Location to be announced'); ?></small></div></li>
				<?php endforeach; ?>
			</ul>
						<?php else: ?>
							<p class="dashboard-empty">There are no upcoming approved events.</p>
						<?php endif; ?>
					</section>

					<section class="dashboard-card activity-card">
						<header class="dashboard-card-heading"><div><h2>Recent Activity</h2><p>Your latest actions in InfoConnect.</p></div><a href="/BMirk/staff/monitoring/activity_logs.php">Activity logs</a></header>
						<?php if ($recentActivities): ?>
							<ul class="dashboard-activity-list">
								<?php foreach ($recentActivities as $activity): ?>
									<li><span class="activity-dot" aria-hidden="true"></span><div><strong><?php echo escapeStaffDashboard(ucfirst($activity['action']) . ' · ' . $activity['module']); ?></strong><small><?php echo escapeStaffDashboard($activity['description'] ?: 'Activity recorded'); ?></small></div><time><?php echo escapeStaffDashboard(staffDashboardTimeAgo($activity['created_at'])); ?></time></li>
								<?php endforeach; ?>
							</ul>
						<?php else: ?>
							<p class="dashboard-empty">Your recent activity will appear here.</p>
						<?php endif; ?>
					</section>

					<section class="dashboard-card quick-links-card">
						<header class="dashboard-card-heading"><div><h2>Quick Links</h2><p>Go to common staff tasks.</p></div></header>
						<div class="dashboard-quick-links">
							<a href="/BMirk/staff/seniors/accounts/seniors_accounts.php">Manage Seniors</a>
							<a href="/BMirk/staff/posts/create/posts_list.php">Manage Posts</a>
							<a href="/BMirk/staff/posts/archive/posts_archive.php">Post Archive</a>
							<a href="/BMirk/staff/settings/settings.php">Settings</a>
						</div>
					</section>
				</div>
			</section>
		</main>
	</div>
</body>
</html>
