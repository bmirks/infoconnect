<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'admin') {
	header('Location: /BMirk/auth/login.php');
	exit;
}

$page_title = 'Senior Management';
$seniorRecords = [];
$seniorCounts = [
	'total' => 0,
	'active' => 0,
	'pending' => 0,
	'unlocated_transferred' => 0,
];
$query = $databaseConnection->query(
	"SELECT sp.id, sp.senior_citizen_id, sp.first_name, sp.middle_name, sp.last_name, sp.suffix, sp.sex, sp.birth_date, sp.address, sp.barangay, sp.status,
	        (SELECT COUNT(DISTINCT sr.requirement_type)
	         FROM senior_requirements sr
	         WHERE sr.senior_id = sp.id
	           AND sr.requirement_type IN ('birth_certificate', 'valid_id')
	           AND sr.status <> 'missing') AS completed_requirements,
	        u.account_id, u.status AS account_status
	 FROM senior_profiles sp
	 LEFT JOIN users u ON u.id = sp.user_id AND u.role = 'senior'
	 ORDER BY sp.last_name, sp.first_name"
);

if ($query) {
	while ($row = $query->fetch_assoc()) {
		$status = (int) $row['completed_requirements'] < 2 ? 'pending' : strtolower($row['status']);
		$seniorCounts['total']++;
		if ($status === 'active') {
			$seniorCounts['active']++;
		} elseif ($status === 'pending') {
			$seniorCounts['pending']++;
		} elseif (in_array($status, ['unlocated', 'transferred'], true)) {
			$seniorCounts['unlocated_transferred']++;
		}

		$fullName = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name'] . ' ' . ($row['suffix'] ?? ''));
		$birthDate = $row['birth_date'] ?: '2000-01-01';
		$age = (int) date_diff(date_create($birthDate), date_create('today'))->format('%y');

		$seniorRecords[] = [
			'database_id' => (int) $row['id'],
			'id' => $row['senior_citizen_id'],
			'name' => $fullName,
			'sex' => ucfirst($row['sex']),
			'age' => $age,
			'address' => $row['address'],
			'barangay' => $row['barangay'] ?: 'N/A',
			'status' => ucfirst($status),
			'raw_status' => $status,
			'account_id' => $row['account_id'] ?: 'N/A',
			'account_status' => $row['account_status'] ? ucfirst($row['account_status']) : 'Inactive',
			'raw_account_status' => $row['account_status'] ?: 'inactive'
		];
	}
}

$barangays = array_values(array_unique(array_column($seniorRecords, 'barangay')));
sort($barangays, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
	<link rel="stylesheet" href="../home.css">
	<link rel="stylesheet" href="../assets/table.css?v=2">
	<link rel="stylesheet" href="assets/seniors_form.css">
</head>
<body>
	<div class="admin-layout">
		<?php include __DIR__ . '/../includes/sidebar.php'; ?>

		<main class="admin-main">
			<?php include __DIR__ . '/../includes/header.php'; ?>

			<section class="admin-content seniors-page">
				<div class="seniors-heading">
					<h1>Senior Management</h1>
					<p>View and manage senior citizen records.</p>
				</div>

				<div class="senior-summary-grid" aria-label="Senior record summary">
					<article class="senior-summary-card summary-total">
						<span>Total Seniors</span>
						<strong><?php echo number_format($seniorCounts['total']); ?></strong>
					</article>
					<article class="senior-summary-card summary-active">
						<span>Active</span>
						<strong><?php echo number_format($seniorCounts['active']); ?></strong>
					</article>
					<article class="senior-summary-card summary-pending">
						<span>Pending (No Requirements)</span>
						<strong><?php echo number_format($seniorCounts['pending']); ?></strong>
					</article>
					<article class="senior-summary-card summary-unlocated">
						<span>Unlocated / Transferred</span>
						<strong><?php echo number_format($seniorCounts['unlocated_transferred']); ?></strong>
					</article>
				</div>

				<div class="page-card seniors-table-card">
					<div class="filter-grid">
						<div class="search-group">
							<input id="searchInput" type="search" placeholder="Search by name, senior ID, or address..." aria-label="Search seniors">
							<button id="searchButton" type="button">Search</button>
						</div>
						<label class="filter-field">
							<span>Age Range</span>
							<select id="filterAge">
								<option value="All">All</option>
								<option value="60-69">60-69</option>
								<option value="70-79">70-79</option>
								<option value="80+">80+</option>
							</select>
						</label>
						<label class="filter-field">
							<span>Status</span>
							<select id="filterSeniorStatus">
								<option value="All">All</option>
								<option value="Active">Active</option>
								<option value="Pending">Pending</option>
								<option value="Unlocated">Unlocated</option>
								<option value="Transferred">Transferred</option>
								<option value="Unclaimed">Unclaimed</option>
								<option value="Deceased">Deceased</option>
							</select>
						</label>
						<label class="filter-field">
							<span>Barangay</span>
							<select id="filterBarangay">
								<option value="All">All</option>
								<?php foreach ($barangays as $barangay): ?>
									<option value="<?php echo htmlspecialchars($barangay, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($barangay, ENT_QUOTES, 'UTF-8'); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="filter-field">
							<span>Sex</span>
							<select id="filterSex">
								<option value="All">All</option>
								<option value="Male">Male</option>
								<option value="Female">Female</option>
							</select>
						</label>
						<label class="filter-field">
							<span>Account Status</span>
							<select id="filterAccountStatus">
								<option value="All">All</option>
								<option value="Active">Active</option>
								<option value="Inactive">Inactive</option>
							</select>
						</label>
					</div>

					<div class="table-wrap">
						<table class="data-table senior-data-table">
							<thead>
								<tr>
										<th>#</th>
										<th>Senior ID</th>
										<th>Full Name</th>
									<th>Age</th>
										<th>Sex</th>
										<th>Barangay</th>
										<th>Status</th>
									<th>Account ID</th>
										<th>Account Status</th>
										<th>Actions</th>
									</tr>
								</thead>
								<tbody id="seniorTableBody">
									<?php if (empty($seniorRecords)): ?>
										<tr><td colspan="10" class="empty-seniors">No senior records found.</td></tr>
									<?php else: ?>
										<?php foreach ($seniorRecords as $index => $record): ?>
											<tr data-index="<?php echo $index + 1; ?>" data-name="<?php echo htmlspecialchars(strtolower($record['name']), ENT_QUOTES, 'UTF-8'); ?>" data-id="<?php echo htmlspecialchars(strtolower($record['id']), ENT_QUOTES, 'UTF-8'); ?>" data-address="<?php echo htmlspecialchars(strtolower($record['address']), ENT_QUOTES, 'UTF-8'); ?>" data-sex="<?php echo htmlspecialchars($record['sex'], ENT_QUOTES, 'UTF-8'); ?>" data-age="<?php echo (int) $record['age']; ?>" data-barangay="<?php echo htmlspecialchars($record['barangay'], ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo htmlspecialchars($record['status'], ENT_QUOTES, 'UTF-8'); ?>" data-account-status="<?php echo htmlspecialchars($record['account_status'], ENT_QUOTES, 'UTF-8'); ?>">
												<td class="row-number"><?php echo $index + 1; ?></td>
												<td><?php echo htmlspecialchars($record['id'], ENT_QUOTES, 'UTF-8'); ?></td>
												<td><?php echo htmlspecialchars($record['name'], ENT_QUOTES, 'UTF-8'); ?></td>
												<td><?php echo (int) $record['age']; ?></td>
												<td><?php echo htmlspecialchars($record['sex'], ENT_QUOTES, 'UTF-8'); ?></td>
												<td><?php echo htmlspecialchars($record['barangay'], ENT_QUOTES, 'UTF-8'); ?></td>
												<td><span class="status-badge <?php echo htmlspecialchars($record['raw_status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($record['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
												<td><?php echo htmlspecialchars($record['account_id'], ENT_QUOTES, 'UTF-8'); ?></td>
												<td><span class="status-badge <?php echo htmlspecialchars($record['raw_account_status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($record['account_status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
												<td><a class="section-action" href="senior_view.php?id=<?php echo (int) $record['database_id']; ?>">View</a></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
					<div class="senior-table-footer">
						<p class="senior-result-count" id="seniorResultCount" aria-live="polite"></p>
						<div class="pagination" id="pagination" aria-label="Senior result pages"></div>
					</div>
				</div>
			</section>
		</main>
	</div>

	<script>
		const seniorRows = Array.from(document.querySelectorAll('#seniorTableBody tr[data-name]'));
		const searchInput = document.getElementById('searchInput');
		const searchButton = document.getElementById('searchButton');
		const filterSex = document.getElementById('filterSex');
		const filterAge = document.getElementById('filterAge');
		const filterBarangay = document.getElementById('filterBarangay');
		const filterSeniorStatus = document.getElementById('filterSeniorStatus');
		const filterAccountStatus = document.getElementById('filterAccountStatus');
		const pagination = document.getElementById('pagination');
		const resultCount = document.getElementById('seniorResultCount');
		const pageSize = 10;
		let currentPage = 1;

		function matchesAgeRange(age, range) {
			if (range === '60-69') return age >= 60 && age <= 69;
			if (range === '70-79') return age >= 70 && age <= 79;
			if (range === '80+') return age >= 80;
			return true;
		}

		function applyFilters() {
			const term = searchInput.value.trim().toLowerCase();
			const sex = filterSex.value;
			const age = filterAge.value;
			const barangay = filterBarangay.value;
			const status = filterSeniorStatus.value;
			const accountStatus = filterAccountStatus.value;
			const filteredRows = seniorRows.filter(function (row) {
				return (!term || row.dataset.name.includes(term) || row.dataset.id.includes(term) || row.dataset.address.includes(term))
					&& (sex === 'All' || row.dataset.sex === sex)
					&& matchesAgeRange(Number(row.dataset.age), age)
					&& (barangay === 'All' || row.dataset.barangay === barangay)
					&& (status === 'All' || row.dataset.status === status)
					&& (accountStatus === 'All' || row.dataset.accountStatus === accountStatus);
			});

			const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
			currentPage = Math.min(currentPage, totalPages);
			const startIndex = (currentPage - 1) * pageSize;
			const visibleRows = filteredRows.slice(startIndex, currentPage * pageSize);
			seniorRows.forEach(function (row) {
				row.hidden = !visibleRows.includes(row);
				if (!row.hidden) row.querySelector('.row-number').textContent = String(filteredRows.indexOf(row) + 1);
			});

			resultCount.textContent = filteredRows.length
				? 'Showing ' + (startIndex + 1) + ' to ' + (startIndex + visibleRows.length) + ' of ' + filteredRows.length + ' records'
				: 'No records found';
			renderPagination(totalPages);
		}

		function renderPagination(totalPages) {
			if (totalPages <= 1) {
				pagination.replaceChildren();
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

			pagination.innerHTML = controls.join('');

			pagination.querySelectorAll('button[data-page]').forEach(function (button) {
				button.addEventListener('click', function () {
					const targetPage = Number(button.dataset.page);
					if (targetPage >= 1 && targetPage <= totalPages) {
						currentPage = targetPage;
						applyFilters();
					}
				});
			});
		}

		[searchInput, filterSex, filterAge, filterBarangay, filterSeniorStatus, filterAccountStatus].forEach(function (control) {
			control.addEventListener('input', function () {
				currentPage = 1;
				applyFilters();
			});
			control.addEventListener('change', function () {
				currentPage = 1;
				applyFilters();
			});
		});

		if (searchButton) searchButton.addEventListener('click', applyFilters);
		if (seniorRows.length) {
			applyFilters();
		} else {
			resultCount.textContent = 'No records found';
		}
	</script>
</body>
</html>
