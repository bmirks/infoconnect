<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'admin') {
	header('Location: /BMirk/auth/login.php');
	exit;
}

$page_title = 'Personnel Management';
$usersFlash = $_SESSION['users_flash'] ?? '';
unset($_SESSION['users_flash']);
$userAccounts = [];
$personnelCounts = [
	'total' => 0,
	'active' => 0,
	'inactive' => 0,
	'admins' => 0,
	'staff' => 0,
];

$query = $databaseConnection->query(
	"SELECT u.id, u.account_id, u.status, u.last_login, ap.employee_id, ap.first_name, ap.middle_name, ap.last_name, ap.suffix, ap.contact_number, 'Admin' AS account_role
	 FROM users u
	 LEFT JOIN admin_profiles ap ON ap.user_id = u.id
	 WHERE u.role = 'admin'

	 UNION ALL

	 SELECT u.id, u.account_id, u.status, u.last_login, sp.employee_id, sp.first_name, sp.middle_name, sp.last_name, sp.suffix, sp.contact_number, 'Staff' AS account_role
	 FROM users u
	 LEFT JOIN staff_profiles sp ON sp.user_id = u.id
	 WHERE u.role = 'staff'
	 ORDER BY last_name, first_name, account_id"
);

if ($query) {
	while ($row = $query->fetch_assoc()) {
		$status = strtolower($row['status'] ?? 'inactive');
		$role = $row['account_role'] ?? 'Admin';
		$personnelCounts['total']++;
		$personnelCounts[$status === 'active' ? 'active' : 'inactive']++;
		$personnelCounts[$role === 'Admin' ? 'admins' : 'staff']++;

		$fullName = trim(
			($row['first_name'] ?? '') . ' ' .
			($row['middle_name'] ? $row['middle_name'] . ' ' : '') .
			($row['last_name'] ?? '') . ' ' .
			($row['suffix'] ?? '')
		);

		$userAccounts[] = [
			'database_id' => (int) $row['id'],
			'account_id' => $row['account_id'] ?? 'N/A',
			'employee_id' => $row['employee_id'] ?? 'N/A',
			'contact_number' => $row['contact_number'] ?? '',
			'full_name' => $fullName ?: 'Unassigned',
			'status' => ucfirst($status),
			'raw_status' => $status,
			'last_login' => $row['last_login'] ? date('M d, Y h:i A', strtotime($row['last_login'])) : 'Never',
			'account_role' => $role
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
	<link rel="stylesheet" href="../home.css">
	<link rel="stylesheet" href="../assets/table.css?v=2">
	<link rel="stylesheet" href="assets/users_form.css">
	<link rel="stylesheet" href="assets/seniors_form.css">
</head>
<body>
	<div class="admin-layout">
		<?php include __DIR__ . '/../includes/sidebar.php'; ?>
		<main class="admin-main">
			<?php include __DIR__ . '/../includes/header.php'; ?>
			<section class="admin-content personnel-page">
				<?php if ($usersFlash !== ''): ?>
					<div class="user-success" role="status"><?php echo htmlspecialchars($usersFlash, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>
				<div class="personnel-heading">
					<div>
						<h1>Personnel Management</h1>
						<p>View and manage admin and staff accounts.</p>
					</div>
					<a class="primary-add-btn" href="users_add.php">Add Account</a>
				</div>

				<div class="personnel-summary-grid" aria-label="Personnel account summary">
					<article class="personnel-summary-card personnel-summary-total"><span>Total Personnel</span><strong><?php echo number_format($personnelCounts['total']); ?></strong></article>
					<article class="personnel-summary-card personnel-summary-active"><span>Active Accounts</span><strong><?php echo number_format($personnelCounts['active']); ?></strong></article>
					<article class="personnel-summary-card personnel-summary-inactive"><span>Inactive Accounts</span><strong><?php echo number_format($personnelCounts['inactive']); ?></strong></article>
					<article class="personnel-summary-card personnel-summary-admin"><span>Administrators</span><strong><?php echo number_format($personnelCounts['admins']); ?></strong></article>
					<article class="personnel-summary-card personnel-summary-staff"><span>Staff</span><strong><?php echo number_format($personnelCounts['staff']); ?></strong></article>
				</div>

				<div class="page-card personnel-table-card">
					<div class="filter-grid">
						<div class="search-group">
							<input id="accountSearch" type="search" placeholder="Search by name, employee ID, or account ID..." aria-label="Search personnel">
							<button type="button" id="searchButton">Search</button>
						</div>
						<label class="filter-field">
							<span>Role</span>
							<select id="roleFilter">
								<option value="All">All</option>
								<option value="Admin">Admin</option>
								<option value="Staff">Staff</option>
							</select>
						</label>
						<label class="filter-field">
							<span>Account Status</span>
							<select id="statusFilter">
								<option value="All">All</option>
								<option value="Active">Active</option>
								<option value="Inactive">Inactive</option>
							</select>
						</label>
					</div>

					<div class="table-wrap">
						<table class="data-table personnel-data-table" id="userAccountsTable">
							<thead>
								<tr>
									<th>#</th>
									<th>Employee ID</th>
									<th>Account ID</th>
									<th>Full Name</th>
									<th>Role</th>
									<th>Contact</th>
									<th>Account Status</th>
									<th>Last Login</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php if (empty($userAccounts)): ?>
									<tr><td colspan="9" class="empty-personnel">No personnel accounts found.</td></tr>
								<?php else: ?>
									<?php foreach ($userAccounts as $index => $account): ?>
										<tr data-index="<?php echo $index + 1; ?>" data-search="<?php echo htmlspecialchars(strtolower($account['account_id'] . ' ' . $account['employee_id'] . ' ' . $account['full_name'] . ' ' . $account['account_role'] . ' ' . $account['status']), ENT_QUOTES, 'UTF-8'); ?>" data-role="<?php echo htmlspecialchars($account['account_role'], ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo htmlspecialchars($account['status'], ENT_QUOTES, 'UTF-8'); ?>">
											<td class="personnel-row-number"><?php echo $index + 1; ?></td>
											<td><?php echo htmlspecialchars($account['employee_id'], ENT_QUOTES, 'UTF-8'); ?></td>
											<td><?php echo htmlspecialchars($account['account_id'], ENT_QUOTES, 'UTF-8'); ?></td>
											<td><?php echo htmlspecialchars($account['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
											<td><?php echo htmlspecialchars($account['account_role'], ENT_QUOTES, 'UTF-8'); ?></td>
											<td><?php echo htmlspecialchars($account['contact_number'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
											<td><span class="status-badge <?php echo htmlspecialchars($account['raw_status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($account['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
											<td><?php echo htmlspecialchars($account['last_login'], ENT_QUOTES, 'UTF-8'); ?></td>
											<td><a class="section-action" href="users_edit.php?id=<?php echo (int) $account['database_id']; ?>">Edit</a></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
					<div class="personnel-table-footer">
						<p class="personnel-result-count" id="personnelResultCount" aria-live="polite"></p>
						<div class="pagination" id="userPagination" aria-label="Personnel result pages"></div>
					</div>
				</div>
			</section>
		</main>
	</div>

	<script>
		const searchInput = document.getElementById('accountSearch');
		const searchButton = document.getElementById('searchButton');
		const statusFilter = document.getElementById('statusFilter');
		const roleFilter = document.getElementById('roleFilter');
		const tableBody = document.querySelector('#userAccountsTable tbody');
		const userPagination = document.getElementById('userPagination');
		const resultCount = document.getElementById('personnelResultCount');
		const pageSize = 10;
		let currentPage = 1;

		function renderUserPagination(totalPages) {
			if (!userPagination) return;
			if (totalPages <= 1) {
				userPagination.replaceChildren();
				return;
			}

			const pages = new Set([1, totalPages, currentPage - 1, currentPage, currentPage + 1]);
			const orderedPages = Array.from(pages).filter(function (page) {
				return page >= 1 && page <= totalPages;
			}).sort(function (a, b) {
				return a - b;
			});
			const controls = [
				'<button class="page-arrow" type="button" data-page="' + (currentPage - 1) + '" aria-label="Previous page"' + (currentPage === 1 ? ' disabled' : '') + '>&lsaquo;</button>'
			];

			let previousPage = 0;
			orderedPages.forEach(function (page) {
				if (page - previousPage > 1) controls.push('<span class="page-ellipsis" aria-hidden="true">...</span>');
				controls.push('<button class="page-btn' + (page === currentPage ? ' active' : '') + '" type="button" data-page="' + page + '" aria-current="' + (page === currentPage ? 'page' : 'false') + '">' + page + '</button>');
				previousPage = page;
			});
			controls.push(
				'<button class="page-arrow" type="button" data-page="' + (currentPage + 1) + '" aria-label="Next page"' + (currentPage === totalPages ? ' disabled' : '') + '>&rsaquo;</button>'
			);
			userPagination.innerHTML = controls.join('');

			userPagination.querySelectorAll('button[data-page]').forEach(function (button) {
				button.addEventListener('click', function () {
					const targetPage = Number(button.dataset.page);
					if (targetPage >= 1 && targetPage <= totalPages) {
						currentPage = targetPage;
						applyUserFilters();
					}
				});
			});
		}

		function applyUserFilters() {
			if (!tableBody) return;

			const rows = Array.from(tableBody.querySelectorAll('tr[data-search]'));
			const term = searchInput ? searchInput.value.trim().toLowerCase() : '';
			const statusValue = statusFilter ? statusFilter.value : 'All';
			const roleValue = roleFilter ? roleFilter.value : 'All';
			const filteredRows = rows.filter(function (row) {
				const haystack = (row.dataset.search || '').toLowerCase();
				const rowRole = (row.dataset.role || '').toLowerCase();
				const rowStatus = (row.dataset.status || '').toLowerCase();
				const matchesTerm = haystack.includes(term);
				const matchesStatus = statusValue === 'All' || rowStatus === statusValue.toLowerCase();
				const matchesRole = roleValue === 'All' || rowRole === roleValue.toLowerCase();
				return matchesTerm && matchesStatus && matchesRole;
			});

			const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
			currentPage = Math.min(currentPage, totalPages);
			const startIndex = (currentPage - 1) * pageSize;
			const visibleRows = filteredRows.slice(startIndex, currentPage * pageSize);
			rows.forEach(function (row) {
				row.hidden = !visibleRows.includes(row);
				if (!row.hidden) {
					row.querySelector('.personnel-row-number').textContent = String(filteredRows.indexOf(row) + 1);
				}
			});

			resultCount.textContent = filteredRows.length
				? 'Showing ' + (startIndex + 1) + ' to ' + (startIndex + visibleRows.length) + ' of ' + filteredRows.length + ' records'
				: 'No records found';
			renderUserPagination(totalPages);
		}

		if (searchInput && tableBody) {
			searchInput.addEventListener('input', function () {
				currentPage = 1;
				applyUserFilters();
			});
			if (searchButton) searchButton.addEventListener('click', function () {
				currentPage = 1;
				applyUserFilters();
			});
			if (statusFilter) statusFilter.addEventListener('change', function () {
				currentPage = 1;
				applyUserFilters();
			});
			if (roleFilter) roleFilter.addEventListener('change', function () {
				currentPage = 1;
				applyUserFilters();
			});
			applyUserFilters();
		}
	</script>
</body>
</html>
