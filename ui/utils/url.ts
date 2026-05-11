import { API_ORIGIN, PEAKURL_URL } from "@/constants";

/**
 * Normalize URL input by trimming whitespace.
 *
 * @param value - The URL to normalize.
 * @return The normalized URL.
 */
function normalizeUrlInput(value: string | null | undefined): string {
	return typeof value === "string" ? value.trim() : "";
}

declare const imageSourceBrand: unique symbol;

/**
 * Branded type for trusted image sources.
 */
export type ImageSource = string & {
	readonly [imageSourceBrand]: true;
};

/**
 * Cast a string to an ImageSource type.
 *
 * @param value - The value to cast.
 * @return The branded ImageSource.
 */
function toImageSource(value: string): ImageSource {
	return value as ImageSource;
}

/**
 * Add a trusted origin to a set of origins.
 *
 * @param origins - The set of trusted origins.
 * @param value   - The origin to add.
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
		/* Fail closed for invalid runtime values. */
	}
}

/**
 * Retrieve the set of trusted image origins.
 *
 * @param currentOrigin - The current runtime origin.
 * @return The set of trusted origins.
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
 * @return The current origin if available, otherwise undefined.
 */
function getCurrentOrigin(): string | undefined {
	if (typeof window !== "undefined" && window.location?.origin) {
		return window.location.origin;
	}

	return undefined;
}

/**
 * Check if an origin is in the trusted origins set.
 *
 * @param trustedOrigins - The set of allowed origins.
 * @param origin         - The origin to verify.
 * @return Whether the origin is trusted.
 */
function isTrustedImageOrigin(
	trustedOrigins: ReadonlySet<string>,
	origin: string
): boolean {
	return trustedOrigins.has(origin);
}

/**
 * Sanitize a URL for internal navigation or external linking.
 *
 * @param value - The URL to sanitize.
 * @return The sanitized URL.
 */
export function sanitizeUrl(value: string | null | undefined): string {
	const normalizedValue = normalizeUrlInput(value);

	if (!normalizedValue) {
		return "";
	}

	/* Relative URLs are considered safe for internal navigation. */
	if (isRelativeUrl(normalizedValue)) {
		return normalizedValue;
	}

	/* Protocol-relative URLs are blocked to prevent redirection ambiguity. */
	if (normalizedValue.startsWith("//")) {
		return "";
	}

	try {
		const url = new URL(normalizedValue);

		/* Only allow standard web protocols. */
		if (url.protocol !== "http:" && url.protocol !== "https:") {
			return "";
		}

		return url.toString();
	} catch {
		return "";
	}
}

/**
 * Sanitize an image URL before rendering.
 *
 * @param value         - The URL to sanitize.
 * @param currentOrigin - The current runtime origin.
 * @return The sanitized ImageSource or an empty string.
 */
export function sanitizeImageUrl(
	value: string | null | undefined,
	currentOrigin: string | undefined = getCurrentOrigin()
): ImageSource | "" {
	const normalizedValue = normalizeUrlInput(value);

	if (!normalizedValue) {
		return "";
	}

	/* Trusted relative paths are allowed. */
	if (isRelativeUrl(normalizedValue)) {
		return toImageSource(normalizedValue);
	}

	if (normalizedValue.startsWith("//")) {
		return "";
	}

	try {
		const url = new URL(normalizedValue);
		const trustedOrigins = getTrustedImageOrigins(currentOrigin);

		/* Validate against trusted origins for http/https. */
		if (
			(url.protocol === "http:" || url.protocol === "https:") &&
			isTrustedImageOrigin(trustedOrigins, url.origin)
		) {
			return toImageSource(url.toString());
		}

		/* Validate against trusted origins for blob URLs (object URLs). */
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
 * Check whether a URL points to a root-relative path.
 *
 * @param value - The URL string to check.
 * @return Whether the URL is relative.
 */
export function isRelativeUrl(value: string): boolean {
	return value.startsWith("/") && !value.startsWith("//");
}
