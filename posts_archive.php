<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/session.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'staff') {
	header('Location: /BMirk/auth/login.php');
	exit;
}

$page_title = 'Post History';
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

$historyItems = [];
$historyStatement = $databaseConnection->prepare(
	"SELECT ar.request_type, ar.status AS approval_status, ar.created_at AS submitted_at,
	        e.title, e.status AS post_status,
	        DATE_FORMAT(e.event_date, '%b %e, %Y') AS schedule,
	        CONCAT_WS(' | ',
	            CONCAT(TIME_FORMAT(e.start_time, '%h:%i %p'),
	                CASE WHEN e.end_time IS NOT NULL THEN CONCAT(' - ', TIME_FORMAT(e.end_time, '%h:%i %p')) ELSE '' END),
	            e.location,
	            NULLIF(LEFT(e.description, 240), '')
	        ) AS details
	 FROM approval_requests ar
	 INNER JOIN events e ON ar.request_type = 'event' AND e.id = ar.reference_id
	 WHERE ar.requested_by = ? AND ar.request_type = 'event'
	 UNION ALL
	 SELECT ar.request_type, ar.status AS approval_status, ar.created_at AS submitted_at,
	        a.title, a.status AS post_status,
	        CASE WHEN a.expires_at IS NOT NULL THEN DATE_FORMAT(a.expires_at, '%b %e, %Y') ELSE 'No expiry' END AS schedule,
	        LEFT(a.content, 240) AS details
	 FROM approval_requests ar
	 INNER JOIN announcements a ON ar.request_type = 'announcement' AND a.id = ar.reference_id
	 WHERE ar.requested_by = ? AND ar.request_type = 'announcement'
	 ORDER BY submitted_at DESC"
);
if (!$historyStatement) {
	error_log('Unable to prepare Staff posts archive query: ' . $databaseConnection->error);
	throw new RuntimeException('Unable to load your post history.');
}
$historyStatement->bind_param('ii', $staffId, $staffId);
if (!$historyStatement->execute()) {
	error_log('Unable to execute Staff posts archive query: ' . $historyStatement->error);
	throw new RuntimeException('Unable to load your post history.');
}
$historyResult = $historyStatement->get_result();
while ($row = $historyResult->fetch_assoc()) {
	$historyItems[] = [
		'title' => $row['title'],
		'type' => ucfirst($row['request_type']),
		'submitted_at' => $row['submitted_at'],
		'schedule' => $row['schedule'],
		'details' => $row['details'],
		'post_status' => ucfirst($row['post_status']),
		'approval_status' => ucfirst($row['approval_status'])
	];
}
$historyStatement->close();

$statuses = array_values(array_unique(array_column($historyItems, 'approval_status')));
sort($statuses, SORT_NATURAL | SORT_FLAG_CASE);

function escapeArchiveValue($value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo escapeArchiveValue($page_title); ?></title>
	<link rel="stylesheet" href="../../home.css">
	<link rel="stylesheet" href="../../seniors/accounts/assets/seniors_accounts_forms.css?v=28">
	<link rel="stylesheet" href="assets/posts_archive.css?v=10">
	<link rel="stylesheet" href="/BMirk/staff/includes/assets/sidebar.css?v=5">
	<link rel="stylesheet" href="../../assets/table.css?v=11">
</head>
<body>
	<div class="staff-layout">
		<?php include __DIR__ . '/../../includes/sidebar.php'; ?>

		<main class="staff-main">
			<?php include __DIR__ . '/../../includes/header.php'; ?>

			<section class="staff-content staff-archive-page">
				<header class="staff-archive-heading">
					<div>
						<h1>Archive</h1>
						<p>Review your submitted events and announcements.</p>
					</div>
				</header>

				<div class="page-card staff-archive-card">
					<div class="archive-filter-toolbar">
						<div class="search-group">
								<input id="archiveSearch" type="search" placeholder="Search submitted posts..." aria-label="Search submitted posts">
								<button id="searchButton" type="button">Search</button>
						</div>
						<label class="filter-field">
							<span>Post type</span>
							<select id="typeFilter">
								<option value="All">All</option>
								<option value="Event">Event</option>
								<option value="Announcement">Announcement</option>
							</select>
						</label>
						<label class="filter-field">
							<span>Status</span>
							<select id="statusFilter">
								<option value="All">All</option>
								<?php foreach ($statuses as $status): ?>
									<option value="<?php echo escapeArchiveValue($status); ?>"><?php echo escapeArchiveValue($status); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>

					<div class="table-wrap staff-consistent-table-wrap">
						<table class="data-table staff-consistent-table archive-table">
							<thead>
								<tr>
									<th>#</th>
									<th>Title</th>
									<th>Type</th>
									<th>Submitted on</th>
									<th>Schedule / expiry</th>
									<th>Details</th>
									<th>Post status</th>
									<th>Approval status</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($historyItems as $index => $item): ?>
									<tr data-type="<?php echo escapeArchiveValue($item['type']); ?>" data-status="<?php echo escapeArchiveValue($item['approval_status']); ?>">
										<td class="archive-row-number"><?php echo $index + 1; ?></td>
										<td class="archive-title"><?php echo escapeArchiveValue($item['title']); ?></td>
										<td><?php echo escapeArchiveValue($item['type']); ?></td>
										<td><?php echo escapeArchiveValue(date('M j, Y g:i A', strtotime($item['submitted_at']))); ?></td>
										<td><?php echo escapeArchiveValue($item['schedule']); ?></td>
										<td class="archive-details"><?php echo escapeArchiveValue($item['details']); ?></td>
										<td><?php echo escapeArchiveValue($item['post_status']); ?></td>
										<td><span class="status-badge <?php echo escapeArchiveValue(strtolower($item['approval_status'])); ?>"><?php echo escapeArchiveValue($item['approval_status']); ?></span></td>
									</tr>
								<?php endforeach; ?>
								<tr id="emptyState" hidden>
									<td colspan="8">No submitted posts found.</td>
								</tr>
							</tbody>
						</table>
					</div>

					<footer class="archive-table-footer staff-consistent-table-footer">
						<p class="archive-result-count staff-consistent-result-count" id="resultCount" aria-live="polite"></p>
						<div class="pagination staff-consistent-pagination" id="pagination" aria-label="Result pages"></div>
					</footer>
				</div>
			</section>
		</main>
	</div>

	<script>
		const archiveRows = Array.from(document.querySelectorAll('.archive-table tbody tr[data-type]'));
		const searchInput = document.getElementById('archiveSearch');
		const typeFilter = document.getElementById('typeFilter');
		const statusFilter = document.getElementById('statusFilter');
		const pagination = document.getElementById('pagination');
		const emptyState = document.getElementById('emptyState');
		const resultCount = document.getElementById('resultCount');
		const pageSize = 10;
		let currentPage = 1;

		function applyArchiveFilters() {
			const term = searchInput.value.trim().toLocaleLowerCase();
			const selectedType = typeFilter.value.toLocaleLowerCase();
			const selectedStatus = statusFilter.value.toLocaleLowerCase();
			const filteredRows = archiveRows.filter(function (row) {
				return (!term || row.textContent.toLocaleLowerCase().includes(term))
					&& (selectedType === 'all' || row.dataset.type.toLocaleLowerCase() === selectedType)
					&& (selectedStatus === 'all' || row.dataset.status.toLocaleLowerCase() === selectedStatus);
			});

			const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
			currentPage = Math.min(currentPage, totalPages);
			const start = (currentPage - 1) * pageSize;
			const visibleRows = filteredRows.slice(start, start + pageSize);
			archiveRows.forEach(function (row) {
				row.hidden = !visibleRows.includes(row);
				if (!row.hidden) {
					row.querySelector('.archive-row-number').textContent = String(filteredRows.indexOf(row) + 1);
				}
			});
			emptyState.hidden = filteredRows.length !== 0;
			resultCount.textContent = filteredRows.length
				? 'Showing ' + (start + 1) + ' to ' + (start + visibleRows.length) + ' of ' + filteredRows.length + ' entries'
				: 'Showing 0 entries';
			renderArchivePagination(totalPages);
		}

		function renderArchivePagination(totalPages) {
			const firstPage = Math.max(1, Math.min(currentPage - 2, totalPages - 4));
			const lastPage = Math.min(totalPages, firstPage + 4);
			const buttons = [];
			for (let page = firstPage; page <= lastPage; page++) {
				buttons.push('<button class="page-btn' + (page === currentPage ? ' active' : '') + '" type="button" data-page="' + page + '"' + (page === currentPage ? ' aria-current="page"' : '') + '>' + page + '</button>');
			}
			pagination.innerHTML =
				'<button type="button" class="page-arrow" data-page="' + (currentPage - 1) + '" aria-label="Previous page"' + (currentPage === 1 ? ' disabled' : '') + '>Previous</button>' +
				buttons.join('') +
				'<button type="button" class="page-arrow" data-page="' + (currentPage + 1) + '" aria-label="Next page"' + (currentPage === totalPages ? ' disabled' : '') + '>Next</button>';

			pagination.querySelectorAll('button[data-page]').forEach(function (button) {
				button.addEventListener('click', function () {
					const targetPage = Number(button.dataset.page);
					if (targetPage >= 1 && targetPage <= totalPages) {
						currentPage = targetPage;
						applyArchiveFilters();
					}
				});
			});
		}

		[searchInput, typeFilter, statusFilter].forEach(function (control) {
			control.addEventListener('input', function () {
				currentPage = 1;
				applyArchiveFilters();
			});
			control.addEventListener('change', function () {
				currentPage = 1;
				applyArchiveFilters();
			});
		});
		document.getElementById('searchButton').addEventListener('click', applyArchiveFilters);
		searchInput.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				currentPage = 1;
				applyArchiveFilters();
			}
		});
		applyArchiveFilters();
	</script>
</body>
</html>
