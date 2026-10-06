<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'admin') {
	header('Location: /BMirk/auth/login.php');
	exit;
}

$page_title = 'Request History';
$historyItems = [];
$historyError = '';

$historyQuery = $databaseConnection->query(
	"SELECT ar.id, ar.request_type, ar.reference_id, ar.status, ar.created_at, ar.reviewed_at, ar.remarks,
	        COALESCE(
	            NULLIF(TRIM(CONCAT_WS(' ', requester_staff.first_name, requester_staff.middle_name, requester_staff.last_name, requester_staff.suffix)), ''),
	            NULLIF(TRIM(CONCAT_WS(' ', requester_admin.first_name, requester_admin.middle_name, requester_admin.last_name, requester_admin.suffix)), ''),
	            requester.account_id,
	            'Unknown'
	        ) AS requester_name,
	        COALESCE(
	            NULLIF(TRIM(CONCAT_WS(' ', reviewer_staff.first_name, reviewer_staff.middle_name, reviewer_staff.last_name, reviewer_staff.suffix)), ''),
	            NULLIF(TRIM(CONCAT_WS(' ', reviewer_admin.first_name, reviewer_admin.middle_name, reviewer_admin.last_name, reviewer_admin.suffix)), ''),
	            reviewer.account_id,
	            'Unknown'
	        ) AS reviewer_name
	 FROM approval_requests ar
	 LEFT JOIN users requester ON requester.id = ar.requested_by
	 LEFT JOIN staff_profiles requester_staff ON requester_staff.user_id = requester.id
	 LEFT JOIN admin_profiles requester_admin ON requester_admin.user_id = requester.id
	 LEFT JOIN users reviewer ON reviewer.id = ar.reviewed_by
	 LEFT JOIN staff_profiles reviewer_staff ON reviewer_staff.user_id = reviewer.id
	 LEFT JOIN admin_profiles reviewer_admin ON reviewer_admin.user_id = reviewer.id
	 ORDER BY ar.created_at DESC, ar.id DESC"
);

if (!$historyQuery) {
	error_log('Unable to load request history: ' . $databaseConnection->error);
	$historyError = 'Unable to load request history. Please try again later.';
} else {
	while ($row = $historyQuery->fetch_assoc()) {
		$historyItems[] = [
			'id' => (int) $row['id'],
			'type' => ucwords(str_replace('_', ' ', $row['request_type'] ?? 'Request')),
			'reference_id' => (int) $row['reference_id'],
			'status' => ucfirst($row['status'] ?? ''),
			'raw_status' => strtolower($row['status'] ?? ''),
			'created_at' => $row['created_at'] ? date('M d, Y h:i A', strtotime($row['created_at'])) : 'N/A',
			'created_date' => $row['created_at'] ? date('Y-m-d', strtotime($row['created_at'])) : '',
			'reviewed_at' => $row['reviewed_at'] ? date('M d, Y h:i A', strtotime($row['reviewed_at'])) : 'N/A',
			'requester' => $row['requester_name'] ?: 'Unknown',
			'reviewer' => strtolower($row['status'] ?? '') === 'pending' ? '—' : ($row['reviewer_name'] ?: 'Unknown'),
			'remarks' => $row['remarks'] ?: '—',
		];
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
	<link rel="stylesheet" href="/BMirk/admin/home.css">
	<link rel="stylesheet" href="/BMirk/admin/requests/assets/history_form.css?v=1">
</head>
<body>
	<div class="admin-layout">
		<?php include __DIR__ . '/../includes/sidebar.php'; ?>
		<main class="admin-main">
			<?php include __DIR__ . '/../includes/header.php'; ?>
			<section class="admin-content history-requests-page">
				<header class="requests-heading">
					<h1><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
					<p>All submitted requests and their current review details.</p>
				</header>

				<?php if ($historyError !== ''): ?>
					<div class="requests-error" role="alert"><?php echo htmlspecialchars($historyError, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>

				<div class="page-card pending-requests-card">
					<div class="requests-filter-grid">
						<div class="search-group requests-search">
							<input id="historySearch" type="search" placeholder="Search by request ID, requester, reviewer, or remarks..." aria-label="Search request history">
							<button type="button" id="historySearchButton">Search</button>
						</div>
						<label class="filter-field">
							<span>Type</span>
							<select id="historyTypeFilter">
								<option value="All">All</option>
								<option value="Senior">Senior</option>
								<option value="Event">Event</option>
								<option value="Announcement">Announcement</option>
								<option value="Password Reset">Password Reset</option>
							</select>
						</label>
						<label class="filter-field">
							<span>Date</span>
							<select id="historyDateFilter">
								<option value="All">All</option>
								<option value="Today">Today</option>
								<option value="Last 7 Days">Last 7 Days</option>
								<option value="This Month">This Month</option>
							</select>
						</label>
						<label class="filter-field">
							<span>Status</span>
							<select id="historyStatusFilter">
								<option value="All">All</option>
								<option value="Pending">Pending</option>
								<option value="Approved">Approved</option>
								<option value="Rejected">Rejected</option>
							</select>
						</label>
					</div>

					<div class="table-wrap">
						<table class="data-table requests-data-table requests-history-table" id="historyTable">
							<thead>
								<tr>
									<th>#</th>
									<th>Request ID</th>
									<th>Type</th>
									<th>Requested By</th>
									<th>Date Submitted</th>
									<th>Status</th>
									<th>Reviewed By</th>
									<th>Reviewed At</th>
									<th>Remarks</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($historyItems as $index => $item): ?>
									<tr data-search="<?php echo htmlspecialchars(strtolower('REQ-' . str_pad((string) $item['id'], 3, '0', STR_PAD_LEFT) . ' ' . $item['type'] . ' ' . $item['reference_id'] . ' ' . $item['requester'] . ' ' . $item['reviewer'] . ' ' . $item['remarks'] . ' ' . $item['status']), ENT_QUOTES, 'UTF-8'); ?>" data-type="<?php echo htmlspecialchars($item['type'], ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo htmlspecialchars($item['status'], ENT_QUOTES, 'UTF-8'); ?>" data-date="<?php echo htmlspecialchars($item['created_date'], ENT_QUOTES, 'UTF-8'); ?>">
										<td class="request-row-number"><?php echo $index + 1; ?></td>
										<td>REQ-<?php echo str_pad((string) $item['id'], 3, '0', STR_PAD_LEFT); ?></td>
										<td><?php echo htmlspecialchars($item['type'], ENT_QUOTES, 'UTF-8'); ?></td>
										<td><?php echo htmlspecialchars($item['requester'], ENT_QUOTES, 'UTF-8'); ?></td>
										<td><?php echo htmlspecialchars($item['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
										<td><span class="status-badge <?php echo htmlspecialchars($item['raw_status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($item['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
										<td><?php echo htmlspecialchars($item['reviewer'], ENT_QUOTES, 'UTF-8'); ?></td>
										<td><?php echo htmlspecialchars($item['reviewed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
										<td><?php echo htmlspecialchars($item['remarks'], ENT_QUOTES, 'UTF-8'); ?></td>
										<td><a class="section-action" href="/BMirk/admin/requests/requests_review.php?id=<?php echo (int) $item['id']; ?>&amp;from=history">View</a></td>
									</tr>
								<?php endforeach; ?>
								<tr class="requests-empty-row" id="historyEmptyRow" <?php echo $historyItems ? 'hidden' : ''; ?>>
									<td colspan="10"><?php echo $historyError !== '' ? 'Request history could not be loaded.' : 'No requests found.'; ?></td>
								</tr>
							</tbody>
						</table>
					</div>
					<footer class="requests-table-footer">
						<p class="requests-result-count" id="historyResultCount" aria-live="polite"></p>
						<div class="pagination" id="historyPagination" aria-label="Request history result pages"></div>
					</footer>
				</div>
			</section>
		</main>
	</div>
	<script>
		const historySearch = document.getElementById('historySearch');
		const historySearchButton = document.getElementById('historySearchButton');
		const historyTypeFilter = document.getElementById('historyTypeFilter');
		const historyDateFilter = document.getElementById('historyDateFilter');
		const historyStatusFilter = document.getElementById('historyStatusFilter');
		const historyTableBody = document.querySelector('#historyTable tbody');
		const historyEmptyRow = document.getElementById('historyEmptyRow');
		const historyResultCount = document.getElementById('historyResultCount');
		const historyPagination = document.getElementById('historyPagination');
		const historyPageSize = 10;
		let historyCurrentPage = 1;

		function renderHistoryPagination(totalPages) {
			if (!historyPagination) return;
			if (totalPages <= 1) {
				historyPagination.replaceChildren();
				return;
			}

			const visiblePages = Array.from(new Set([1, totalPages, historyCurrentPage - 1, historyCurrentPage, historyCurrentPage + 1]))
				.filter(function (page) { return page >= 1 && page <= totalPages; })
				.sort(function (a, b) { return a - b; });
			const controls = [
				'<button class="page-arrow" type="button" data-page="' + (historyCurrentPage - 1) + '" aria-label="Previous page"' + (historyCurrentPage === 1 ? ' disabled' : '') + '>&lsaquo;</button>'
			];
			let previousPage = 0;
			visiblePages.forEach(function (page) {
				if (page - previousPage > 1) controls.push('<span class="page-ellipsis" aria-hidden="true">...</span>');
				controls.push('<button class="page-btn' + (page === historyCurrentPage ? ' active' : '') + '" type="button" data-page="' + page + '" aria-current="' + (page === historyCurrentPage ? 'page' : 'false') + '">' + page + '</button>');
				previousPage = page;
			});
			controls.push(
				'<button class="page-arrow" type="button" data-page="' + (historyCurrentPage + 1) + '" aria-label="Next page"' + (historyCurrentPage === totalPages ? ' disabled' : '') + '>&rsaquo;</button>'
			);
			historyPagination.innerHTML = controls.join('');
			historyPagination.querySelectorAll('button[data-page]').forEach(function (button) {
				button.addEventListener('click', function () {
					const targetPage = Number(button.dataset.page);
					if (targetPage >= 1 && targetPage <= totalPages) {
						historyCurrentPage = targetPage;
						applyHistoryFilters();
					}
				});
			});
		}

		function applyHistoryFilters() {
			if (!historyTableBody) return;

			const rows = Array.from(historyTableBody.querySelectorAll('tr[data-search]'));
			const term = historySearch ? historySearch.value.trim().toLowerCase() : '';
			const type = historyTypeFilter ? historyTypeFilter.value : 'All';
			const status = historyStatusFilter ? historyStatusFilter.value : 'All';
			const date = historyDateFilter ? historyDateFilter.value : 'All';
			const today = new Date();
			today.setHours(0, 0, 0, 0);
			const startOfWeek = new Date(today);
			startOfWeek.setDate(today.getDate() - 6);
			const filteredRows = rows.filter(function (row) {
				const rowDate = row.dataset.date ? new Date(row.dataset.date + 'T00:00:00') : null;
				const matchesDate = date === 'All'
					|| (rowDate && date === 'Today' && rowDate.getTime() === today.getTime())
					|| (rowDate && date === 'Last 7 Days' && rowDate >= startOfWeek && rowDate <= today)
					|| (rowDate && date === 'This Month' && rowDate.getFullYear() === today.getFullYear() && rowDate.getMonth() === today.getMonth());
				return (row.dataset.search || '').toLowerCase().includes(term)
					&& (type === 'All' || (row.dataset.type || '').toLowerCase() === type.toLowerCase())
					&& (status === 'All' || (row.dataset.status || '').toLowerCase() === status.toLowerCase())
					&& matchesDate;
			});
			const totalPages = Math.max(1, Math.ceil(filteredRows.length / historyPageSize));
			historyCurrentPage = Math.min(historyCurrentPage, totalPages);
			const startIndex = (historyCurrentPage - 1) * historyPageSize;
			const visibleRows = filteredRows.slice(startIndex, historyCurrentPage * historyPageSize);
			rows.forEach(function (row) {
				row.hidden = !visibleRows.includes(row);
				if (!row.hidden) row.querySelector('.request-row-number').textContent = String(filteredRows.indexOf(row) + 1);
			});
			if (historyEmptyRow) historyEmptyRow.hidden = filteredRows.length > 0;
			historyResultCount.textContent = filteredRows.length
				? 'Showing ' + (startIndex + 1) + ' to ' + (startIndex + visibleRows.length) + ' of ' + filteredRows.length + ' records'
				: 'No records found';
			renderHistoryPagination(totalPages);
		}

		if (historyTableBody) {
			[historySearch, historyTypeFilter, historyDateFilter, historyStatusFilter].forEach(function (control) {
				if (!control) return;
				control.addEventListener(control.tagName === 'INPUT' ? 'input' : 'change', function () {
					historyCurrentPage = 1;
					applyHistoryFilters();
				});
			});
			if (historySearchButton) historySearchButton.addEventListener('click', function () {
				historyCurrentPage = 1;
				applyHistoryFilters();
			});
			applyHistoryFilters();
		}
	</script>
</body>
</html>
