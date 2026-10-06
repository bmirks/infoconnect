<?php

function getSystemSettingDefaults(): array
{
	return [
		'auto_deactivate_days' => '7',
		'default_password_length' => '16',
		'require_password_change_first_login' => '1',
		'password_minimum_length' => '8',
		'password_require_special_characters' => '1',
		'login_attempt_limit' => '5',
		'password_expiration_days' => '0',
		'automatic_backup_enabled' => '1',
		'automatic_backup_frequency' => 'daily',
	];
}

function loadSystemSettings(mysqli $connection): array
{
	$settings = getSystemSettingDefaults();
	$result = $connection->query('SELECT setting_key, setting_value FROM system_settings');
	while ($row = $result->fetch_assoc()) {
		if (array_key_exists($row['setting_key'], $settings)) {
			$settings[$row['setting_key']] = $row['setting_value'];
		}
	}
	return $settings;
}

function saveSystemSettings(mysqli $connection, array $settings): void
{
	$statement = $connection->prepare(
		'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
		 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
	);
	if (!$statement) {
		throw new RuntimeException('Unable to prepare system settings update.');
	}
	foreach ($settings as $key => $value) {
		$key = (string) $key;
		$value = (string) $value;
		$statement->bind_param('ss', $key, $value);
		if (!$statement->execute()) {
			$statement->close();
			throw new RuntimeException('Unable to save system settings.');
		}
	}
	$statement->close();
}

function systemPasswordPolicyErrors(string $password, array $settings): array
{
	$errors = [];
	$minimumLength = max(8, (int) ($settings['password_minimum_length'] ?? 8));
	if (strlen($password) < $minimumLength) {
		$errors[] = 'The password must be at least ' . $minimumLength . ' characters.';
	}
	if (strlen($password) > 72) {
		$errors[] = 'The password must be no more than 72 bytes.';
	}
	if (($settings['password_require_special_characters'] ?? '1') === '1' && !preg_match('/[^a-zA-Z0-9]/', $password)) {
		$errors[] = 'The password must include at least one special character.';
	}
	return $errors;
}

function generateSystemTemporaryPassword(array $settings): string
{
	$length = max(
		(int) ($settings['default_password_length'] ?? 16),
		(int) ($settings['password_minimum_length'] ?? 8),
		12
	);
	$length = min($length, 64);
	$groups = [
		'abcdefghijklmnopqrstuvwxyz',
		'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
		'0123456789',
	];
	if (($settings['password_require_special_characters'] ?? '1') === '1') {
		$groups[] = '!@#$%^&*()-_=+[]{}';
	}
	$password = '';
	foreach ($groups as $characters) {
		$password .= $characters[random_int(0, strlen($characters) - 1)];
	}
	$allCharacters = implode('', $groups);
	while (strlen($password) < $length) {
		$password .= $allCharacters[random_int(0, strlen($allCharacters) - 1)];
	}
	for ($index = strlen($password) - 1; $index > 0; $index--) {
		$swapIndex = random_int(0, $index);
		$temporary = $password[$index];
		$password[$index] = $password[$swapIndex];
		$password[$swapIndex] = $temporary;
	}
	return $password;
}
