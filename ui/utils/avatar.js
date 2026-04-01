// Use a local vendored MD5 implementation to avoid module resolution issues
import md5 from './md5.js';

export function getAvatarInitials(
	firstName = '',
	lastName = '',
	fallbackName = ''
) {
	const first = firstName.trim().charAt(0).toUpperCase();
	const last = lastName.trim().charAt(0).toUpperCase();
	const combined = `${first}${last}`.trim();

	if (combined) {
		return combined;
	}

	const fallback = fallbackName.trim().charAt(0).toUpperCase();
	return fallback || 'U';
}

export function getGravatarUrl(email = '', size = 96) {
	const normalizedEmail = email.trim().toLowerCase();

	if (!normalizedEmail) {
		return '';
	}

	return `https://www.gravatar.com/avatar/${md5(normalizedEmail)}?d=404&s=${size}&r=g`;
}
