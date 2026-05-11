import { API_ORIGIN, PEAKURL_URL } from "@/constants";

/**
 * Normalize URL input by trimming whitespace.
 *
 * @param {string|null|undefined} value The URL to normalize.
 * @return {string} The normalized URL.
 */
function normalizeUrlInput(value: string | null | undefined): string {
	return typeof value === "string" ? value.trim() : "";
}

declare const imageSourceBrand: unique symbol;

/**
 * Image source brand type.
 */
export type ImageSource = string & {
	readonly [imageSourceBrand]: true;
};

/**
 * Cast a string to an ImageSource.
 *
 * @param {string} value The value to cast.
 * @return {ImageSource} The casted value.
 */
function toImageSource(value: string): ImageSource {
	return value as ImageSource;
}

/**
 * Add a trusted origin to a set of origins.
 *
 * @param {Set<string>} origins The set of origins.
 * @param {string|null|undefined} value The origin to add.
 */
function addTrustedOrigin(
	origins: Set<string>,
	value: string | null | undefined
): void {
	const normalizedValue = normalizeUrlInput(value);

	if (!normalizedValue) {
		return;
	}

	try {
		origins.add(new URL(normalizedValue).origin);
	} catch {
		// Fail closed for invalid runtime values.
	}
}

/**
 * Retrieve the set of trusted image origins.
 *
 * @param {string|undefined} currentOrigin The current runtime origin.
 * @return {Set<string>} The set of trusted origins.
 */
function getTrustedImageOrigins(currentOrigin?: string): Set<string> {
	const origins = new Set<string>();

	addTrustedOrigin(origins, currentOrigin);
	addTrustedOrigin(origins, PEAKURL_URL);
	addTrustedOrigin(origins, API_ORIGIN);

	return origins;
}

/**
 * Resolve the current browser origin.
 *
 * @return {string|undefined} The current origin, if available.
 */
function getCurrentOrigin(): string | undefined {
	if (typeof window !== "undefined" && window.location?.origin) {
		return window.location.origin;
	}

	return undefined;
}

/**
 * Check if an origin is a trusted image origin.
 *
 * @param {ReadonlySet<string>} trustedOrigins The allowed image origins.
 * @param {string}              origin         The origin to check.
 * @return {boolean} Whether the origin is trusted.
 */
function isTrustedImageOrigin(
	trustedOrigins: ReadonlySet<string>,
	origin: string
): boolean {
	return trustedOrigins.has(origin);
}

/**
 * Sanitize a dashboard URL for internal navigation or external linking.
 *
 * @param {string|null|undefined} value The URL to sanitize.
 * @return {string} The sanitized URL.
 */
export function sanitizeUrl(value: string | null | undefined): string {
	const normalizedValue = normalizeUrlInput(value);

	if (!normalizedValue) {
		return "";
	}

	if (isRelativeUrl(normalizedValue)) {
		return normalizedValue;
	}

	if (normalizedValue.startsWith("//")) {
		return "";
	}

	try {
		const url = new URL(normalizedValue);

		if (url.protocol !== "http:" && url.protocol !== "https:") {
			return "";
		}

		return url.toString();
	} catch {
		return "";
	}
}

/**
 * Sanitize an image preview URL before rendering it as an image source.
 *
 * @param {string|null|undefined} value         The URL to sanitize.
 * @param {string|undefined}      currentOrigin The current runtime origin.
 * @return {ImageSource|string} The sanitized image source.
 */
export function sanitizeImageUrl(
	value: string | null | undefined,
	currentOrigin: string | undefined = getCurrentOrigin()
): ImageSource | "" {
	const normalizedValue = normalizeUrlInput(value);

	if (!normalizedValue) {
		return "";
	}

	if (isRelativeUrl(normalizedValue)) {
		return toImageSource(normalizedValue);
	}

	if (normalizedValue.startsWith("//")) {
		return "";
	}

	try {
		const url = new URL(normalizedValue);
		const trustedOrigins = getTrustedImageOrigins(currentOrigin);

		if (
			(url.protocol === "http:" || url.protocol === "https:") &&
			isTrustedImageOrigin(trustedOrigins, url.origin)
		) {
			return toImageSource(url.toString());
		}

		if (
			url.protocol === "blob:" &&
			isTrustedImageOrigin(trustedOrigins, url.origin)
		) {
			return toImageSource(url.toString());
		}

		return "";
	} catch {
		return "";
	}
}

/**
 * Returns whether a URL points to a root-relative dashboard path.
 *
 * @param {string} value The URL to check.
 * @return {boolean} Whether the URL is relative.
 */
export function isRelativeUrl(value: string): boolean {
	return value.startsWith("/") && !value.startsWith("//");
}
