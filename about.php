<header class="settings-panel-heading">
	<h3>About System</h3>
	<p>System information, organization details, and version information.</p>
</header>
<section class="settings-section" aria-labelledby="about-infoconnect-heading">
	<header class="settings-section-heading"><h4 id="about-infoconnect-heading">System Information</h4></header>
	<dl class="about-system-table">
		<div><dt>System Name</dt><dd>InfoConnect</dd></div>
		<div><dt>System Version</dt><dd>1.0.0</dd></div>
		<div><dt>Organization</dt><dd>Mandaluyong City Office for Senior Citizens Affairs</dd></div>
		<div><dt>Database</dt><dd>MySQL (<?php echo escapeSettingsValue($dbName); ?><?php echo $databaseVersion !== '' ? ', ' . escapeSettingsValue($databaseVersion) : ''; ?>)</dd></div>
		<div><dt>Address</dt><dd>Mandaluyong City, Metro Manila</dd></div>
		<div><dt>Developed By</dt><dd>BS Information Technology Capstone Project</dd></div>
		<div><dt>Contact Number</dt><dd>(02) 8530-1234</dd></div>
		<div><dt>Last Updated</dt><dd><?php echo escapeSettingsValue(date('F j, Y h:i A')); ?></dd></div>
		<div><dt>Email Address</dt><dd>osca@mandaluyong.gov.ph</dd></div>
	</dl>
</section>