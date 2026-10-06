(function () {
	'use strict';

	const passwordInput = document.getElementById('new_password');
	const strengthIndicator = document.getElementById('passwordStrength');
	const strengthText = document.getElementById('passwordStrengthText');
	if (!passwordInput || !strengthIndicator || !strengthText) return;

	passwordInput.addEventListener('input', function () {
		const password = passwordInput.value;
		if (!password) {
			strengthIndicator.dataset.strength = 'empty';
			strengthText.textContent = 'Enter a password to check its strength.';
			return;
		}

		const characterTypes = [
			/[a-z]/.test(password),
			/[A-Z]/.test(password),
			/[0-9]/.test(password),
			/[^A-Za-z0-9]/.test(password)
		].filter(Boolean).length;
		const isStrong = password.length >= 12 && characterTypes >= 3;
		strengthIndicator.dataset.strength = isStrong ? 'strong' : 'weak';
		strengthText.textContent = isStrong
			? 'Strong password.'
			: 'Weak password. Use at least 12 characters and a mix of character types.';
	});
})();
