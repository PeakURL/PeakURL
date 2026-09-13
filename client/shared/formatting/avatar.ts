// Use a local vendored MD5 implementation to avoid module resolution issues.
import md5 from "./md5.js";

/**
 * Build avatar initials from a user's name with a stable one-letter fallback.
 *
 * @param firstName    - The user's first name.
 * @param lastName     - The user's last name.
 * @param fallbackName - A fallback name to use if first/last are missing.
 * @return The avatar initials.
 */
export function getAvatarInitials(
	firstName: string = "",
	lastName: string = "",
	fallbackName: string = ""
): string {
	const first = firstName.trim().charAt(0).toUpperCase();
	const last = lastName.trim().charAt(0).toUpperCase();
	const combined = `${first}${last}`.trim();

	/*
	 * If we have at least one initial from the first or last name,
	 * return the combined result.
	 */
	if (combined) {
		return combined;
	}

	/*
	 * Fall back to the first letter of the fallback name,
	 * or "U" (Unknown) if all else fails.
	 */
	const fallback = fallbackName.trim().charAt(0).toUpperCase();
	return fallback || "U";
}

/**
 * Build a Gravatar URL for an email address.
 *
 * @param email - The email address to hash.
 * @param size  - The requested image size in pixels.
 * @return The Gravatar URL, or an empty string if no email is provided.
 */
export function getGravatarUrl(email: string = "", size: number = 96): string {
	const normalizedEmail = email.trim().toLowerCase();

	if (!normalizedEmail) {
		return "";
	}

	/*
	 * Return a Gravatar URL with a 404 default and "g" rating.
	 * The email is MD5 hashed per Gravatar's requirements.
	 */
	return `https://www.gravatar.com/avatar/${md5(normalizedEmail)}?d=404&s=${size}&r=g`;
}
