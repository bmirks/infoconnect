<header class="settings-panel-heading">
	<h3>Security Settings</h3>
	<p>Configure security-related settings.</p>
</header>
<section class="settings-section" aria-labelledby="security-options-heading">
	<header class="settings-section-heading"><h4 id="security-options-heading">Security Settings</h4><p>Configure security-related settings.</p></header>
	<form class="settings-policy-form" method="post" action="?tab=security">
		<input type="hidden" name="settings_action" value="save_security">
		<input type="hidden" name="settings_csrf_token" value="<?php echo escapeSettingsValue($_SESSION['system_settings_csrf_token']); ?>">
		<div class="settings-control-grid security-control-grid">
			<label class="settings-control-row" for="password_minimum_length">
				<strong>Minimum Password Length</strong>
				<span class="settings-control-input"><input id="password_minimum_length" name="password_minimum_length" type="number" min="8" max="72" value="<?php echo escapeSettingsValue($settingsValues['password_minimum_length']); ?>" required><span>characters</span></span>
			</label>
			<label class="settings-control-row" for="password_require_special_characters">
				<strong>Require Special Characters</strong>
				<select id="password_require_special_characters" name="password_require_special_characters">
					<option value="1" <?php echo $settingsValues['password_require_special_characters'] === '1' ? 'selected' : ''; ?>>Yes</option>
					<option value="0" <?php echo $settingsValues['password_require_special_characters'] === '0' ? 'selected' : ''; ?>>No</option>
				</select>
			</label>
			<label class="settings-control-row" for="login_attempt_limit">
				<strong>Login Attempt Limit</strong>
				<span class="settings-control-input"><input id="login_attempt_limit" name="login_attempt_limit" type="number" min="1" max="20" value="<?php echo escapeSettingsValue($settingsValues['login_attempt_limit']); ?>" required><span>attempts</span></span>
			</label>
			<label class="settings-control-row" for="password_expiration_days">
				<strong>Password Expiration (Optional)</strong>
				<span class="settings-control-input"><input id="password_expiration_days" name="password_expiration_days" type="number" min="0" max="3650" value="<?php echo escapeSettingsValue($settingsValues['password_expiration_days']); ?>" required><span>days</span></span>
				<small>Set to 0 if passwords should not expire.</small>
			</label>
		</div>
		<div class="settings-form-actions"><button type="submit">Save Changes</button></div>
	</form>
</section>