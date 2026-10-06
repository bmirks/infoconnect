<?php
require_once __DIR__ . '/../../config/session.php';
requireLogin();

if (($_SESSION['role'] ?? '') !== 'admin') {
	header('Location: /BMirk/auth/login.php');
	exit;
}

$requestId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($requestId !== false && $requestId !== null && $requestId > 0) {
	header('Location: /BMirk/admin/requests/requests_review.php?id=' . $requestId . '&from=archive');
	exit;
}

header('Location: /BMirk/admin/requests/archive.php');
exit;