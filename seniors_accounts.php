<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/session.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'staff') {
	header('Location: /BMirk/auth/login.php');
	exit;
}

$page_title = 'Senior Management';
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

$seniorAccounts = [];
$query = $databaseConnection->query(
	"SELECT sp.id, sp.senior_citizen_id, sp.first_name, sp.middle_name, sp.last_name, sp.suffix, sp.sex, sp.birth_date, sp.barangay, sp.address, sp.status, sp.created_at,
	        u.account_id, u.status AS account_status,
	        (SELECT COUNT(DISTINCT sr.requirement_type)
	         FROM senior_requirements sr
	         WHERE sr.senior_id = sp.id
	           AND sr.requirement_type IN ('birth_certificate', 'valid_id')
	           AND sr.status <> 'missing') AS completed_requirements
	 FROM senior_profiles sp
	 LEFT JOIN users u ON u.id = sp.user_id AND u.role = 'senior'
	 ORDER BY sp.last_name, sp.first_name"
);

if ($query) {
	while ($row = $query->fetch_assoc()) {
		$rawStatus = (int) $row['completed_requirements'] < 2 ? 'pending' : strtolower($row['status'] ?? 'N/A');
		$nameParts = [
			$row['first_name'] ?? '',
			$row['middle_name'] ?? '',
			$row['last_name'] ?? '',
			$row['suffix'] ?? ''
		];
		$fullName = trim(implode(' ', array_filter($nameParts, static fn ($part) => $part !== '')));
		$age = null;
		if (!empty($row['birth_date']) && $row['birth_date'] !== '0000-00-00') {
			try {
				$age = (int) date_diff(new DateTime($row['birth_date']), new DateTime('today'))->format('%y');
			} catch (Exception $exception) {
				$age = null;
			}
		}

		$seniorAccounts[] = [
			'database_id' => (int) $row['id'],
			'id' => $row['senior_citizen_id'] ?? '',
			'name' => $fullName ?: 'Unnamed senior',
			'sex' => ucfirst($row['sex'] ?? 'N/A'),
			'age' => $age,
			'barangay' => $row['barangay'] ?: 'N/A',
			'address' => $row['address'] ?? '',
			'status' => ucfirst($rawStatus),
			'raw_status' => $rawStatus,
			'account_id' => $row['account_id'] ?: 'N/A',
			'account_status' => $row['account_status'] ? ucfirst($row['account_status']) : 'Inactive',
			'raw_account_status' => $row['account_status'] ?: 'inactive',
			'date_added' => $row['created_at']
		];
	}
}

$barangays = array_values(array_unique(array_column($seniorAccounts, 'barangay')));
sort($barangays, SORT_NATURAL | SORT_FLAG_CASE);
$flashMessage = $_SESSION['staff_senior_flash'] ?? '';
unset($_SESSION['staff_senior_flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
	<link rel="stylesheet" href="../../home.css">
	<link rel="stylesheet" href="assets/seniors_accounts_forms.css?v=28">
	<link rel="stylesheet" href="/BMirk/staff/includes/assets/sidebar.css?v=5">
	<link rel="stylesheet" href="../../assets/table.css?v=11">
</head>
<body>
	<div class="staff-layout">
		<?php include __DIR__ . '/../../includes/sidebar.php'; ?>

		<main class="staff-main">
			<?php include __DIR__ . '/../../includes/header.php'; ?>

			<section class="staff-content seniors-page staff-seniors-page">
				<header class="seniors-heading">
					<div>
						<h1>Senior Management</h1>
						<p>View, search, and manage senior citizen records.</p>
					</div>
					<div class="senior-account-actions">
						<a class="senior-account-action senior-account-action-primary" href="/BMirk/staff/seniors/accounts/seniors_accounts_add.php">
							<span aria-hidden="true">+</span> Add Senior
						</a>
					</div>
				</header>

				<?php if ($flashMessage !== ''): ?>
					<div class="form-success" role="status"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>

				<div class="page-card seniors-table-card">
					<div class="senior-filter-toolbar">
						<div class="search-group">
							<input id="accountSearch" type="search" placeholder="Search name, senior ID, or address..." aria-label="Search senior records">
							<button id="searchButton" type="button">Search</button>
						</div>
						<label class="filter-field">
							<span>Barangay</span>
							<select id="barangayFilter">
								<option value="All">All</option>
								<?php foreach ($barangays as $barangay): ?>
									<option value="<?php echo htmlspecialchars($barangay, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($barangay, ENT_QUOTES, 'UTF-8'); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="filter-field">
							<span>Sex</span>
							<select id="sexFilter">
								<option value="All">All</option>
								<option value="Male">Male</option>
								<option value="Female">Female</option>
							</select>
						</label>
						<label class="filter-field">
							<span>Status</span>
							<select id="statusFilter">
								<option value="All">All</option>
								<option value="active">Active</option>
								<option value="pending">Pending</option>
								<option value="unlocated">Unlocated</option>
								<option value="transferred">Transferred</option>
								<option value="unclaimed">Unclaimed</option>
								<option value="deceased">Deceased</option>
							</select>
						</label>
						<label class="filter-field senior-age-filter">
							<span>Age Range</span>
							<select id="ageRangeFilter" aria-label="Filter by age range">
								<option value="All">All</option>
								<option value="60-69">60-69</option>
								<option value="70-79">70-79</option>
								<option value="80-89">80-89</option>
								<option value="90-99">90-99</option>
								<option value="100+">100+</option>
							</select>
						</label>
					</div>

					<div class="table-wrap staff-consistent-table-wrap">
						<table class="data-table staff-consistent-table senior-data-table" id="seniorAccountsTable">
							<thead>
								<tr>
									<th>#</th>
									<th>Senior ID</th>
									<th>Full Name</th>
									<th>Sex</th>
									<th>Age</th>
									<th>Barangay</th>
									<th>Status</th>
									<th>Account ID</th>
									<th>Account Status</th>
									<th>Date Added</th>
									<th>Action</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($seniorAccounts as $index => $account): ?>
									<tr data-index="<?php echo $index + 1; ?>" data-name="<?php echo htmlspecialchars(strtolower($account['name']), ENT_QUOTES, 'UTF-8'); ?>" data-id="<?php echo htmlspecialchars(strtolower($account['id']), ENT_QUOTES, 'UTF-8'); ?>" data-account-id="<?php echo htmlspecialchars(strtolower($account['account_id']), ENT_QUOTES, 'UTF-8'); ?>" data-address="<?php echo htmlspecialchars(strtolower($account['address']), ENT_QUOTES, 'UTF-8'); ?>" data-age="<?php echo $account['age'] === null ? '' : (int) $account['age']; ?>" data-barangay="<?php echo htmlspecialchars($account['barangay'], ENT_QUOTES, 'UTF-8'); ?>" data-sex="<?php echo htmlspecialchars($account['sex'], ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo htmlspecialchars($account['raw_status'], ENT_QUOTES, 'UTF-8'); ?>">
										<td class="row-number"><?php echo $index + 1; ?></td>
										<td><?php echo htmlspecialchars((string) $account['id'], ENT_QUOTES, 'UTF-8'); ?></td>
										<td><?php echo htmlspecialchars($account['name'], ENT_QUOTES, 'UTF-8'); ?></td>
										<td><?php echo htmlspecialchars($account['sex'], ENT_QUOTES, 'UTF-8'); ?></td>
										<td><?php echo $account['age'] === null ? 'N/A' : (int) $account['age']; ?></td>
										<td><?php echo htmlspecialchars($account['barangay'], ENT_QUOTES, 'UTF-8'); ?></td>
										<td><span class="status-badge <?php echo htmlspecialchars(strtolower($account['status']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($account['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
										<td><?php echo htmlspecialchars($account['account_id'], ENT_QUOTES, 'UTF-8'); ?></td>
										<td><span class="status-badge <?php echo htmlspecialchars($account['raw_account_status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($account['account_status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
										<td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($account['date_added'])), ENT_QUOTES, 'UTF-8'); ?></td>
										<td><a class="section-action" href="/BMirk/staff/seniors/accounts/seniors_accounts_edit.php?id=<?php echo $account['database_id']; ?>">Edit</a></td>
									</tr>
								<?php endforeach; ?>
								<tr id="emptyState" hidden>
									<td colspan="11">No matching senior accounts found.</td>
								</tr>
							</tbody>
						</table>
					</div>
					<footer class="senior-table-footer staff-consistent-table-footer">
						<p class="senior-result-count staff-consistent-result-count" id="resultCount" aria-live="polite"></p>
						<div class="pagination staff-consistent-pagination" id="pagination" aria-label="Result pages"></div>
					</footer>
				</div>
			</section>
		</main>
	</div>

	<script>
		const rows = Array.from(document.querySelectorAll('#seniorAccountsTable tbody tr[data-name]'));
		const searchInput = document.getElementById('accountSearch');
		const ageRangeFilter = document.getElementById('ageRangeFilter');
		const barangayFilter = document.getElementById('barangayFilter');
		const sexFilter = document.getElementById('sexFilter');
		const statusFilter = document.getElementById('statusFilter');
		const searchButton = document.getElementById('searchButton');
		const pagination = document.getElementById('pagination');
		const emptyState = document.getElementById('emptyState');
		const resultCount = document.getElementById('resultCount');
		const pageSize = 10;
		let currentPage = 1;

		function applyFilters() {
			const term = searchInput.value.trim().toLocaleLowerCase();
			const selectedAgeRange = ageRangeFilter.value;
			const selectedBarangay = barangayFilter.value;
			const selectedSex = sexFilter.value;
			const selectedStatus = statusFilter.value;
			const filteredRows = rows.filter(function (row) {
				const age = Number(row.dataset.age);
				const ageBounds = selectedAgeRange === 'All' ? null : selectedAgeRange.split('-').map(Number);
				const matchesAge = selectedAgeRange === 'All'
					|| (row.dataset.age !== '' && (selectedAgeRange === '100+'
						? age >= 100
						: age >= ageBounds[0] && age <= ageBounds[1]));
				return (!term || row.dataset.name.includes(term) || row.dataset.id.includes(term) || row.dataset.accountId.includes(term) || row.dataset.address.includes(term))
					&& matchesAge
					&& (selectedBarangay === 'All' || row.dataset.barangay === selectedBarangay)
					&& (selectedSex === 'All' || row.dataset.sex === selectedSex)
					&& (selectedStatus === 'All' || row.dataset.status === selectedStatus);
			});

			const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
			currentPage = Math.min(currentPage, totalPages);
			const visibleRows = filteredRows.slice((currentPage - 1) * pageSize, currentPage * pageSize);
			rows.forEach(function (row) {
				row.hidden = !visibleRows.includes(row);
				if (!row.hidden) row.querySelector('.row-number').textContent = String(filteredRows.indexOf(row) + 1);
			});
			emptyState.hidden = filteredRows.length !== 0;
			resultCount.textContent = filteredRows.length
				? 'Showing ' + ((currentPage - 1) * pageSize + 1) + ' to ' + ((currentPage - 1) * pageSize + visibleRows.length) + ' of ' + filteredRows.length + ' records'
				: 'No records found';
			renderPagination(totalPages);
		}

		function renderPagination(totalPages) {
			const firstPage = Math.max(1, Math.min(currentPage - 2, totalPages - 4));
			const lastPage = Math.min(totalPages, firstPage + 4);
			const buttons = [];
			for (let page = firstPage; page <= lastPage; page++) {
				buttons.push('<button class="page-btn' + (page === currentPage ? ' active' : '') + '" type="button" data-page="' + page + '"' + (page === currentPage ? ' aria-current="page"' : '') + '>' + page + '</button>');
			}
			pagination.innerHTML =
				'<button class="page-arrow" type="button" data-page="' + (currentPage - 1) + '" aria-label="Previous page"' + (currentPage === 1 ? ' disabled' : '') + '>Previous</button>' +
				buttons.join('') +
				'<button class="page-arrow" type="button" data-page="' + (currentPage + 1) + '" aria-label="Next page"' + (currentPage === totalPages ? ' disabled' : '') + '>Next</button>';

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

		[barangayFilter, sexFilter, statusFilter].forEach(function (filter) {
			filter.addEventListener('change', function () {
				currentPage = 1;
				applyFilters();
			});
		});
		ageRangeFilter.addEventListener('change', function () {
			currentPage = 1;
			applyFilters();
		});
		searchButton.addEventListener('click', function () {
			currentPage = 1;
			applyFilters();
		});
		searchInput.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				searchButton.click();
			}
		});
		applyFilters();
	</script>
</body>
</html>
