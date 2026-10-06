<?php

function systemBackupDirectory(): string
{
	return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'BMirkBackups';
}

function ensureSystemBackupDirectory(): string
{
	$directory = systemBackupDirectory();
	if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
		throw new RuntimeException('The backup directory could not be created.');
	}
	if (!is_writable($directory)) {
		throw new RuntimeException('The backup directory is not writable.');
	}
	return $directory;
}

function systemBackupFiles(): array
{
	$directory = systemBackupDirectory();
	if (!is_dir($directory)) {
		return [];
	}
	$files = glob($directory . DIRECTORY_SEPARATOR . 'infoconnect_*.sql');
	if ($files === false) {
		throw new RuntimeException('Backup history could not be read.');
	}
	usort($files, static function (string $left, string $right): int {
		return filemtime($right) <=> filemtime($left);
	});
	return array_slice($files, 0, 5);
}

function runSystemDatabaseBackup(array $databaseConfig, string $kind = 'manual'): string
{
	$directory = ensureSystemBackupDirectory();
	$dumpExecutable = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
	if (!is_file($dumpExecutable)) {
		throw new RuntimeException('The mysqldump utility is not installed.');
	}
	$prefix = $kind === 'scheduled' ? 'infoconnect_scheduled_' : 'infoconnect_';
	$filename = $prefix . date('Y-m-d_His') . '.sql';
	$targetPath = $directory . DIRECTORY_SEPARATOR . $filename;
	$temporaryPath = $targetPath . '.tmp';
	$output = fopen($temporaryPath, 'wb');
	if ($output === false) {
		throw new RuntimeException('The backup file could not be created.');
	}
	$command = [
		$dumpExecutable,
		'--host=' . $databaseConfig['host'],
		'--user=' . $databaseConfig['user'],
		'--single-transaction',
		'--quick',
		'--routines',
		'--triggers',
		'--skip-comments',
		$databaseConfig['name'],
	];
	$environment = getenv();
	if (!is_array($environment)) {
		$environment = [];
	}
	if ($databaseConfig['password'] !== '') {
		$environment['MYSQL_PWD'] = $databaseConfig['password'];
	}
	$process = proc_open($command, [0 => ['pipe', 'r'], 1 => $output, 2 => ['pipe', 'w']], $pipes, null, $environment);
	if (!is_resource($process)) {
		fclose($output);
		@unlink($temporaryPath);
		throw new RuntimeException('The database backup process could not start.');
	}
	fclose($pipes[0]);
	$errorOutput = stream_get_contents($pipes[2]);
	fclose($pipes[2]);
	$exitCode = proc_close($process);
	fclose($output);
	if ($exitCode !== 0 || !is_file($temporaryPath) || filesize($temporaryPath) === 0) {
		@unlink($temporaryPath);
		throw new RuntimeException('The database backup failed: ' . trim((string) $errorOutput));
	}
	if (!rename($temporaryPath, $targetPath)) {
		@unlink($temporaryPath);
		throw new RuntimeException('The completed backup could not be saved.');
	}
	return $filename;
}

function restoreSystemDatabaseBackup(array $databaseConfig, string $uploadedPath): void
{
	$mysqlExecutable = 'C:\\xampp\\mysql\\bin\\mysql.exe';
	if (!is_file($mysqlExecutable)) {
		throw new RuntimeException('The mysql utility is not installed.');
	}
	$input = fopen($uploadedPath, 'rb');
	if ($input === false) {
		throw new RuntimeException('The uploaded SQL backup could not be read.');
	}
	$command = [
		$mysqlExecutable,
		'--host=' . $databaseConfig['host'],
		'--user=' . $databaseConfig['user'],
		'--database=' . $databaseConfig['name'],
	];
	$environment = getenv();
	if (!is_array($environment)) {
		$environment = [];
	}
	if ($databaseConfig['password'] !== '') {
		$environment['MYSQL_PWD'] = $databaseConfig['password'];
	}
	$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
	if (!is_resource($process)) {
		fclose($input);
		throw new RuntimeException('The database restore process could not start.');
	}
	stream_copy_to_stream($input, $pipes[0]);
	fclose($input);
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	$errorOutput = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exitCode = proc_close($process);
	if ($exitCode !== 0) {
		throw new RuntimeException('The database restore failed: ' . trim((string) ($errorOutput ?: $output)));
	}
}
