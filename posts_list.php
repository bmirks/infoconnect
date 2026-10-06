<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/session.php';
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
	$staffProfileStatement->close();
}

$posts = [];
$postStatement = $databaseConnection->prepare(
	"SELECT 'event' AS post_type, e.id, e.title, e.description AS body, e.event_date AS schedule_date,
	        e.start_time, e.end_time, e.location, e.status AS post_status, e.created_at,
	        (SELECT ar.status FROM approval_requests ar
	         WHERE ar.request_type = 'event' AND ar.reference_id = e.id AND ar.requested_by = e.created_by
	         ORDER BY ar.created_at DESC, ar.id DESC LIMIT 1) AS approval_status
	 FROM events e
	 WHERE e.created_by = ?
	 UNION ALL
	 SELECT 'announcement' AS post_type, a.id, a.title, a.content AS body, a.expires_at AS schedule_date,
	        NULL AS start_time, NULL AS end_time, NULL AS location, a.status AS post_status, a.created_at,
	        (SELECT ar.status FROM approval_requests ar
	         WHERE ar.request_type = 'announcement' AND ar.reference_id = a.id AND ar.requested_by = a.created_by
	         ORDER BY ar.created_at DESC, ar.id DESC LIMIT 1) AS approval_status
	 FROM announcements a
	 WHERE a.created_by = ?
	 ORDER BY created_at DESC, id DESC"
);
if (!$postStatement) {
	error_log('Unable to prepare Staff posts list query: ' . $databaseConnection->error);
	throw new RuntimeException('Unable to load your posts.');
}
$postStatement->bind_param('ii', $staffId, $staffId);
if (!$postStatement->execute()) {
	error_log('Unable to execute Staff posts list query: ' . $postStatement->error);
	throw new RuntimeException('Unable to load your posts.');
}
$postResult = $postStatement->get_result();
while ($row = $postResult->fetch_assoc()) {
	$body = trim((string) $row['body']);
	$schedule = '';
	if ($row['schedule_date'] !== null && $row['schedule_date'] !== '') {
		$schedule = date('M j, Y', strtotime((string) $row['schedule_date']));
	}
	if ($row['post_type'] === 'event') {
		$time = date('g:i A', strtotime((string) $row['start_time']));
		if ($row['end_time'] !== null) {
			$time .= ' - ' . date('g:i A', strtotime((string) $row['end_time']));
		}
		$schedule .= ($schedule !== '' ? ' · ' : '') . $time;
		$schedule .= $row['location'] !== null && $row['location'] !== '' ? ' · ' . $row['location'] : '';
	} elseif ($schedule === '') {
		$schedule = 'No expiry';
	}

	$posts[] = [
		'type' => $row['post_type'],
		'id' => (int) $row['id'],
		'title' => (string) $row['title'],
		'body' => $body,
		'schedule' => $schedule ?: '—',
		'status' => strtolower((string) ($row['post_status'] ?? 'draft')),
		'approval_status' => strtolower((string) ($row['approval_status'] ?? '')),
		'created_at' => (string) $row['created_at'],
	];
}
$postStatement->close();

function escapeStaffPostList($value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo escapeStaffPostList($page_title); ?></title>
	<link rel="stylesheet" href="../../home.css">
	<link rel="stylesheet" href="assets/posts_list.css?v=10">
	<link rel="stylesheet" href="/BMirk/staff/includes/assets/sidebar.css?v=5">
	<link rel="stylesheet" href="../../assets/table.css?v=12">
</head>
<body>
	<div class="staff-layout">
		<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
		<main class="staff-main">
			<?php include __DIR__ . '/../../includes/header.php'; ?>
			<section class="staff-content staff-posts-page">
				<header class="staff-posts-heading">
					<div>
						<h1>Posts</h1>
						<p>Manage events and announcements for senior citizens.</p>
					</div>
					<div class="staff-post-create-menu">
						<a class="staff-post-create-button" href="/BMirk/staff/posts/create/posts_create.php">
							<span aria-hidden="true">+</span> Create
						</a>
					</div>
				</header>

				<section class="staff-posts-panel" aria-label="Post list">
					<form class="staff-post-list-filters" id="postFilters">
						<div class="staff-post-search">
							<label class="visually-hidden" for="postSearch">Search title or description</label>
							<div class="staff-post-search-group">
								<input id="postSearch" type="search" placeholder="Search title or description...">
								<button type="submit" class="staff-post-search-button">Search</button>
							</div>
						</div>
						<label class="staff-post-filter">
							<span>Post Type</span>
							<select id="postTypeFilter">
								<option value="all">All</option>
								<option value="event">Event</option>
								<option value="announcement">Announcement</option>
							</select>
						</label>
						<label class="staff-post-filter">
							<span>Status</span>
							<select id="postStatusFilter">
								<option value="all">All</option>
								<option value="draft">Draft</option>
								<option value="pending">Pending</option>
								<option value="approved">Approved</option>
								<option value="published">Published</option>
								<option value="rejected">Rejected</option>
								<option value="cancelled">Cancelled</option>
								<option value="completed">Completed</option>
								<option value="archived">Archived</option>
							</select>
						</label>
						<label class="staff-post-filter">
							<span>Date From</span>
							<input id="postDateFrom" type="date">
						</label>
						<label class="staff-post-filter">
							<span>Date To</span>
							<input id="postDateTo" type="date">
						</label>
					</form>

					<div class="staff-post-table-wrap staff-consistent-table-wrap">
						<table class="data-table staff-consistent-table staff-post-table" id="staffPostTable">
							<thead>
								<tr>
									<th>#</th>
									<th>Title</th>
									<th>Type</th>
									<th>Schedule / Date</th>
									<th>Status</th>
									<th>Date Posted</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($posts as $index => $post): ?>
									<?php
									$displayStatus = in_array($post['approval_status'], ['pending', 'rejected'], true)
										? $post['approval_status']
										: $post['status'];
									?>
									<tr data-post-type="<?php echo escapeStaffPostList($post['type']); ?>"
										data-title="<?php echo escapeStaffPostList(mb_strtolower($post['title'], 'UTF-8')); ?>"
										data-description="<?php echo escapeStaffPostList(mb_strtolower($post['body'], 'UTF-8')); ?>"
										data-status="<?php echo escapeStaffPostList($displayStatus); ?>"
										data-date="<?php echo escapeStaffPostList(substr($post['created_at'], 0, 10)); ?>">
										<td class="staff-post-row-number"><?php echo $index + 1; ?></td>
										<td class="staff-post-title">
											<strong><?php echo escapeStaffPostList($post['title']); ?></strong>
										</td>
										<td><span class="staff-post-type-badge <?php echo escapeStaffPostList($post['type']); ?>"><?php echo escapeStaffPostList(ucfirst($post['type'])); ?></span></td>
										<td class="staff-post-schedule"><?php echo escapeStaffPostList($post['schedule']); ?></td>
										<td><span class="staff-post-status <?php echo escapeStaffPostList($displayStatus); ?>"><?php echo escapeStaffPostList(ucfirst($displayStatus)); ?></span></td>
										<td><?php echo escapeStaffPostList(date('M j, Y g:i A', strtotime($post['created_at']))); ?></td>
										<td>
											<button class="staff-post-view-button" type="button"
												data-view-title="<?php echo escapeStaffPostList($post['title']); ?>"
												data-view-type="<?php echo escapeStaffPostList(ucfirst($post['type'])); ?>"
												data-view-status="<?php echo escapeStaffPostList(ucfirst($displayStatus)); ?>"
												data-view-schedule="<?php echo escapeStaffPostList($post['schedule']); ?>"
												data-view-created="<?php echo escapeStaffPostList(date('M j, Y g:i A', strtotime($post['created_at']))); ?>"
												data-view-body="<?php echo escapeStaffPostList($post['body']); ?>">View</button>
										</td>
									</tr>
								<?php endforeach; ?>
								<tr id="staffPostEmpty" hidden><td colspan="7">No matching posts found.</td></tr>
							</tbody>
						</table>
					</div>

					<footer class="staff-post-table-footer staff-consistent-table-footer">
						<p class="staff-consistent-result-count" id="staffPostResultCount" aria-live="polite"></p>
						<div class="staff-post-pagination staff-consistent-pagination" id="staffPostPagination" aria-label="Post result pages"></div>
					</footer>
				</section>
			</section>
		</main>
	</div>

	<dialog class="staff-post-dialog" id="staffPostDialog" aria-labelledby="staffPostDialogTitle">
		<div class="staff-post-dialog-heading">
			<h2 id="staffPostDialogTitle"></h2>
			<button type="button" id="closePostDialog" aria-label="Close details">×</button>
		</div>
		<dl class="staff-post-dialog-info">
			<div><dt>Type</dt><dd id="dialogPostType"></dd></div>
			<div><dt>Schedule / Date</dt><dd id="dialogPostSchedule"></dd></div>
			<div><dt>Status</dt><dd id="dialogPostStatus"></dd></div>
			<div><dt>Date Posted</dt><dd id="dialogPostCreated"></dd></div>
		</dl>
		<p class="staff-post-dialog-body" id="dialogPostBody"></p>
	</dialog>

	<script>
		const postRows = Array.from(document.querySelectorAll('#staffPostTable tbody tr[data-post-type]'));
		const postSearch = document.getElementById('postSearch');
		const postTypeFilter = document.getElementById('postTypeFilter');
		const postStatusFilter = document.getElementById('postStatusFilter');
		const postDateFrom = document.getElementById('postDateFrom');
		const postDateTo = document.getElementById('postDateTo');
		const postEmpty = document.getElementById('staffPostEmpty');
		const postResultCount = document.getElementById('staffPostResultCount');
		const postPagination = document.getElementById('staffPostPagination');
		const postDialog = document.getElementById('staffPostDialog');
		const postPageSize = 8;
		let currentPostPage = 1;

		function applyPostFilters() {
			const term = postSearch.value.trim().toLocaleLowerCase();
			const type = postTypeFilter.value;
			const status = postStatusFilter.value;
			const dateFrom = postDateFrom.value;
			const dateTo = postDateTo.value;
			const filteredRows = postRows.filter(function (row) {
				return (!term || row.dataset.title.includes(term) || row.dataset.description.includes(term))
					&& (type === 'all' || row.dataset.postType === type)
					&& (status === 'all' || row.dataset.status === status)
					&& (!dateFrom || row.dataset.date >= dateFrom)
					&& (!dateTo || row.dataset.date <= dateTo);
			});
			const totalPages = Math.max(1, Math.ceil(filteredRows.length / postPageSize));
			currentPostPage = Math.min(currentPostPage, totalPages);
			const start = (currentPostPage - 1) * postPageSize;
			const visibleRows = filteredRows.slice(start, start + postPageSize);
			postRows.forEach(function (row) {
				row.hidden = !visibleRows.includes(row);
				if (!row.hidden) row.querySelector('.staff-post-row-number').textContent = String(filteredRows.indexOf(row) + 1);
			});
			postEmpty.hidden = filteredRows.length !== 0;
			postResultCount.textContent = filteredRows.length
				? 'Showing ' + (start + 1) + ' to ' + (start + visibleRows.length) + ' of ' + filteredRows.length + ' entries'
				: 'Showing 0 entries';
			renderPostPagination(totalPages);
		}

		function renderPostPagination(totalPages) {
			if (totalPages <= 1) {
				postPagination.innerHTML = '<span>Page 1 of 1</span>';
				return;
			}
			const buttons = [];
			for (let page = 1; page <= totalPages; page++) {
				buttons.push('<button type="button" data-page="' + page + '"' + (page === currentPostPage ? ' aria-current="page"' : '') + '>' + page + '</button>');
			}
			postPagination.innerHTML =
				'<button type="button" data-page="' + (currentPostPage - 1) + '" aria-label="Previous page"' + (currentPostPage === 1 ? ' disabled' : '') + '>Previous</button>' +
				buttons.join('') +
				'<button type="button" data-page="' + (currentPostPage + 1) + '" aria-label="Next page"' + (currentPostPage === totalPages ? ' disabled' : '') + '>Next</button>';
			postPagination.querySelectorAll('button[data-page]').forEach(function (button) {
				button.addEventListener('click', function () {
					const targetPage = Number(button.dataset.page);
					if (targetPage >= 1 && targetPage <= totalPages) {
						currentPostPage = targetPage;
						applyPostFilters();
					}
				});
			});
		}

		[postSearch, postTypeFilter, postStatusFilter, postDateFrom, postDateTo].forEach(function (control) {
			control.addEventListener(control === postSearch ? 'input' : 'change', function () {
				currentPostPage = 1;
				applyPostFilters();
			});
		});
		document.getElementById('postFilters').addEventListener('submit', function (event) {
			event.preventDefault();
			currentPostPage = 1;
			applyPostFilters();
		});
		document.querySelectorAll('.staff-post-view-button').forEach(function (button) {
			button.addEventListener('click', function () {
				document.getElementById('staffPostDialogTitle').textContent = button.dataset.viewTitle;
				document.getElementById('dialogPostType').textContent = button.dataset.viewType;
				document.getElementById('dialogPostSchedule').textContent = button.dataset.viewSchedule;
				document.getElementById('dialogPostStatus').textContent = button.dataset.viewStatus;
				document.getElementById('dialogPostCreated').textContent = button.dataset.viewCreated;
				document.getElementById('dialogPostBody').textContent = button.dataset.viewBody || 'No additional details.';
				postDialog.showModal();
			});
		});
		document.getElementById('closePostDialog').addEventListener('click', function () {
			postDialog.close();
		});
		postDialog.addEventListener('click', function (event) {
			if (event.target === postDialog) postDialog.close();
		});
		applyPostFilters();
	</script>
</body>
</html>
