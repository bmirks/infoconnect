<?php
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/system_settings.php';
require_once __DIR__ . '/../../config/system_backup.php';

try {
	$settings = loadSystemSettings($databaseConnection);
	if (($settings['automatic_backup_enabled'] ?? '0') !== '1') {
		exit(0);
	}

	$latestScheduledBackup = glob(systemBackupDirectory() . DIRECTORY_SEPARATOR . 'infoconnect_scheduled_*.sql') ?: [];
	usort($latestScheduledBackup, static function (string $left, string $right): int {
		return filemtime($right) <=> filemtime($left);
	});
	$lastBackupTime = $latestScheduledBackup ? filemtime($latestScheduledBackup[0]) : 0;
	$frequency = $settings['automatic_backup_frequency'] ?? 'daily';
	$due = $lastBackupTime === 0
		|| ($frequency === 'daily' && date('Y-m-d', $lastBackupTime) !== date('Y-m-d'))
		|| ($frequency === 'weekly' && $lastBackupTime < strtotime('-7 days'))
		|| ($frequency === 'monthly' && date('Y-m', $lastBackupTime) !== date('Y-m'));
	if (!$due) {
		exit(0);
	}

	runSystemDatabaseBackup(
		['host' => $dbHost, 'user' => $dbUser, 'password' => $dbPass, 'name' => $dbName],
		'scheduled'
	);
} catch (Throwable $exception) {
	error_log('Scheduled InfoConnect database backup failed: ' . $exception->getMessage());
	exit(1);
}
