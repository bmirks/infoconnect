<header class="settings-panel-heading">
	<h3>Account Settings</h3>
	<p>Manage account-related configurations.</p>
</header>
<section class="settings-section" aria-labelledby="account-options-heading">
	<header class="settings-section-heading"><h4 id="account-options-heading">Account Settings</h4><p>Manage account-related configurations.</p></header>
	<form class="settings-policy-form" method="post" action="?tab=account">
		<input type="hidden" name="settings_action" value="save_account">
		<input type="hidden" name="settings_csrf_token" value="<?php echo escapeSettingsValue($_SESSION['system_settings_csrf_token']); ?>">
		<div class="settings-control-grid">
			<label class="settings-control-row" for="auto_deactivate_days">
				<strong>Auto-deactivate inactive accounts</strong>
				<span class="settings-control-input"><input id="auto_deactivate_days" name="auto_deactivate_days" type="number" min="1" max="3650" value="<?php echo escapeSettingsValue($settingsValues['auto_deactivate_days']); ?>" required><span>days</span></span>
				<small>Accounts are deactivated after this many days without a login.</small>
			</label>
			<label class="settings-control-row" for="default_password_length">
				<strong>Default Password (New Accounts)</strong>
				<select id="default_password_length" name="default_password_length">
					<option value="16" <?php echo $settingsValues['default_password_length'] === '16' ? 'selected' : ''; ?>>Auto-generated (16 characters)</option>
					<option value="24" <?php echo $settingsValues['default_password_length'] === '24' ? 'selected' : ''; ?>>Auto-generated (24 characters)</option>
				</select>
				<small>Secure temporary passwords are generated for new accounts.</small>
			</label>
			<label class="settings-control-row" for="require_password_change_first_login">
				<strong>Require Password Change<br>on First Login</strong>
				<select id="require_password_change_first_login" name="require_password_change_first_login">
					<option value="1" <?php echo $settingsValues['require_password_change_first_login'] === '1' ? 'selected' : ''; ?>>Yes</option>
					<option value="0" <?php echo $settingsValues['require_password_change_first_login'] === '0' ? 'selected' : ''; ?>>No</option>
				</select>
				<small>Ask new users to change their temporary password after sign-in.</small>
			</label>
		</div>
		<div class="settings-form-actions"><button type="submit">Save Changes</button></div>
	</form>
</section>