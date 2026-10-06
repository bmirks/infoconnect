<header class="settings-panel-heading">
	<h3>Personal Information</h3>
	<p>View your personal information.</p>
</header>
<section class="settings-section" id="personal-information" aria-labelledby="basic-information-heading">
	<header class="settings-section-heading"><h4 id="basic-information-heading">Basic Information</h4></header>
	<div class="settings-fields">
		<div class="settings-field"><span>First Name</span><div><?php echo escapeSettingsValue($adminProfile['first_name'] ?? 'N/A'); ?></div></div>
		<div class="settings-field"><span>Last Name</span><div><?php echo escapeSettingsValue($adminProfile['last_name'] ?? 'N/A'); ?></div></div>
		<div class="settings-field"><span>Middle Name</span><div><?php echo escapeSettingsValue($adminProfile['middle_name'] ?? 'N/A'); ?></div></div>
		<div class="settings-field"><span>Employee ID</span><div><?php echo escapeSettingsValue($adminProfile['employee_id'] ?? 'N/A'); ?></div></div>
		<div class="settings-field"><span>Email Address</span><div><?php echo escapeSettingsValue($adminProfile['email'] ?? 'N/A'); ?></div></div>
		<div class="settings-field"><span>Account ID</span><div><?php echo escapeSettingsValue($adminProfile['account_id'] ?? 'N/A'); ?></div></div>
		<div class="settings-field"><span>Contact Number</span><div><?php echo escapeSettingsValue($adminProfile['contact_number'] ?? 'N/A'); ?></div></div>
		<div class="settings-field"><span>Role</span><div><?php echo escapeSettingsValue(ucfirst($adminProfile['role'] ?? 'Admin')); ?></div></div>
	</div>
</section>
<section class="settings-section" id="change-password" aria-labelledby="change-password-heading">
	<header class="settings-section-heading settings-password-heading">
		<div><h4 id="change-password-heading">Change Password</h4><p>Update your account password.</p></div>
	</header>
	<form class="settings-password-form" method="post" action="?tab=personal">
		<input type="hidden" name="csrf_token" value="<?php echo escapeSettingsValue($_SESSION['password_csrf_token']); ?>">
		<label for="current_password"><span>Current Password</span><input id="current_password" name="current_password" type="password" autocomplete="current-password" placeholder="Enter current password" required></label>
		<label for="new_password">
			<span>New Password</span>
			<input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" placeholder="Enter new password" required>
			<small>Use at least 8 characters. Strong passwords use 12 or more characters and a mix of character types.</small>
			<div class="password-strength" id="passwordStrength" data-strength="empty" aria-live="polite">
				<div class="strength-meter"><span id="passwordStrengthBar"></span></div>
				<p id="passwordStrengthText">Enter a password to check its strength.</p>
			</div>
		</label>
		<label for="confirm_password"><span>Confirm New Password</span><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" placeholder="Confirm new password" required></label>
		<div class="settings-password-actions"><button type="submit">Update Password</button></div>
	</form>
</section>