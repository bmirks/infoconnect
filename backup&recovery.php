<header class="settings-panel-heading">
	<h3>Backup &amp; Recovery</h3>
	<p>Manage database backup and restore operations.</p>
</header>
<section class="settings-section" aria-labelledby="backup-tools-heading">
	<header class="settings-section-heading"><h4 id="backup-tools-heading">Backup &amp; Recovery</h4><p>Manage database backup and restore.</p></header>
	<div class="backup-tool-grid">
		<form class="backup-tool backup-automatic" method="post" action="?tab=backup">
			<input type="hidden" name="settings_action" value="save_backup">
			<input type="hidden" name="settings_csrf_token" value="<?php echo escapeSettingsValue($_SESSION['system_settings_csrf_token']); ?>">
			<h5>Automatic Backup</h5>
			<p>Enable or disable automatic database backup.</p>
			<div class="backup-selects">
				<label for="automatic_backup_enabled" class="visually-hidden">Automatic backup status</label>
				<select id="automatic_backup_enabled" name="automatic_backup_enabled">
					<option value="1" <?php echo $settingsValues['automatic_backup_enabled'] === '1' ? 'selected' : ''; ?>>Enabled</option>
					<option value="0" <?php echo $settingsValues['automatic_backup_enabled'] === '0' ? 'selected' : ''; ?>>Disabled</option>
				</select>
				<label for="automatic_backup_frequency" class="visually-hidden">Automatic backup frequency</label>
				<select id="automatic_backup_frequency" name="automatic_backup_frequency">
					<option value="daily" <?php echo $settingsValues['automatic_backup_frequency'] === 'daily' ? 'selected' : ''; ?>>Daily</option>
					<option value="weekly" <?php echo $settingsValues['automatic_backup_frequency'] === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
					<option value="monthly" <?php echo $settingsValues['automatic_backup_frequency'] === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
				</select>
			</div>
			<small>Scheduled backups run daily at 6:00 PM; frequency is controlled by this setting.</small>
			<button type="submit">Save Schedule</button>
		</form>
		<form class="backup-tool backup-create" method="post" action="?tab=backup">
			<input type="hidden" name="settings_action" value="create_backup">
			<input type="hidden" name="settings_csrf_token" value="<?php echo escapeSettingsValue($_SESSION['system_settings_csrf_token']); ?>">
			<h5>Create Backup</h5>
			<p>Create a backup of the entire system database.</p>
			<button type="submit">Create Backup</button>
			<small>A .sql file will be generated and saved for download.</small>
		</form>
		<form class="backup-tool backup-restore" method="post" action="?tab=backup" enctype="multipart/form-data">
			<input type="hidden" name="settings_action" value="restore_backup">
			<input type="hidden" name="settings_csrf_token" value="<?php echo escapeSettingsValue($_SESSION['system_settings_csrf_token']); ?>">
			<h5>Restore Database</h5>
			<p>Restore the system database from a backup file.</p>
			<label class="backup-restore-file" for="backup_file"><span class="visually-hidden">SQL backup file</span><input id="backup_file" name="backup_file" type="file" accept=".sql" required></label>
			<label class="backup-confirm" for="restore_confirmation">Type RESTORE to confirm<input id="restore_confirmation" name="restore_confirmation" type="text" autocomplete="off" required></label>
			<button type="submit" onclick="return confirm('This replaces the current database. A safety backup will be created first. Continue?')">Restore</button>
		</form>
	</div>
</section>
<section class="settings-section" aria-labelledby="backup-history-heading">
	<header class="settings-section-heading"><h4 id="backup-history-heading">Backup History</h4></header>
	<div class="settings-table-scroll">
		<table class="settings-data-table">
			<thead><tr><th>#</th><th>File Name</th><th>Date &amp; Time</th><th>Size</th><th>Action</th></tr></thead>
			<tbody>
				<?php if (!$backupFiles): ?>
					<tr><td colspan="5" class="settings-empty-row">No database backups have been created yet.</td></tr>
				<?php else: ?>
					<?php foreach ($backupFiles as $index => $backupPath): ?>
						<?php $backupName = basename($backupPath); ?>
						<tr>
							<td><?php echo $index + 1; ?></td>
							<td><?php echo escapeSettingsValue($backupName); ?></td>
							<td><?php echo escapeSettingsValue(date('M j, Y h:i A', filemtime($backupPath))); ?></td>
							<td><?php echo escapeSettingsValue(number_format(filesize($backupPath) / (1024 * 1024), 1) . ' MB'); ?></td>
							<td><a href="?tab=backup&amp;download=<?php echo rawurlencode($backupName); ?>">Download</a></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</section>