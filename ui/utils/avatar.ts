// Use a local vendored MD5 implementation to avoid module resolution issues.
import md5 from './md5.js';

/**
 * Builds avatar initials from a user's name with a stable one-letter fallback.
 */
export function getAvatarInitials(
	firstName: string = '',
	lastName: string = '',
	fallbackName: string = ''
): string {
	const first = firstName.trim().charAt(0).toUpperCase();
	const last = lastName.trim().charAt(0).toUpperCase();
	const combined = `${first}${last}`.trim();

	if (combined) {
		return combined;
	}

	const fallback = fallbackName.trim().charAt(0).toUpperCase();
	return fallback || 'U';
}

/**
 * Builds a Gravatar URL for an email address.
 *
 * Returns an empty string when no email address is available.
 */
export function getGravatarUrl(
	email: string = '',
	size: number = 96
): string {
	const normalizedEmail = email.trim().toLowerCase();

	if (!normalizedEmail) {
		return '';
	}

	return `https://www.gravatar.com/avatar/${md5(normalizedEmail)}?d=404&s=${size}&r=g`;
}
