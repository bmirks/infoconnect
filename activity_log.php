<?php
function writeActivityLog(
	mysqli $connection,
	?int $userId,
	string $action,
	string $module,
	string $description,
	?int $referenceId = null
): void {
	$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
	$statement = $connection->prepare(
		'INSERT INTO activity_logs (user_id, action, module, reference_id, description, ip_address)
		 VALUES (?, ?, ?, ?, ?, ?)'
	);
	$statement->bind_param('ississ', $userId, $action, $module, $referenceId, $description, $ipAddress);
	$statement->execute();
	$statement->close();
}
