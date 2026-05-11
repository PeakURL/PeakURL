import { API_ORIGIN, PEAKURL_URL } from "@/constants";

function normalizeUrlInput(value: string | null | undefined): string {
	return typeof value === "string" ? value.trim() : "";
}

declare const imageSourceBrand: unique symbol;

export type ImageSource = string & {
	readonly [imageSourceBrand]: true;
};

function toImageSource(value: string): ImageSource {
	return value as ImageSource;
}

function addTrustedOrigin(origins: Set<string>, value: string): void {
	try {
		origins.add(new URL(value).origin);
	} catch {
		// Ignore invalid runtime values; image previews fail closed.
	}
}

function getTrustedImageOrigins(): Set<string> {
	const origins = new Set<string>();

	if (typeof window !== "undefined" && window.location?.origin) {
		origins.add(window.location.origin);
	}

	addTrustedOrigin(origins, PEAKURL_URL);
	addTrustedOrigin(origins, API_ORIGIN);

	return origins;
}

let trustedImageOriginsCache: Set<string> | null = null;

function getCachedTrustedImageOrigins(): Set<string> {
	if (trustedImageOriginsCache === null) {
		trustedImageOriginsCache = getTrustedImageOrigins();
	}

	return trustedImageOriginsCache;
}

function isTrustedImageOrigin(origin: string): boolean {
	return getCachedTrustedImageOrigins().has(origin);
}

/**
 * Sanitize a dashboard URL for internal navigation or external linking.
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
 * Sanitize an image preview URL before assigning it to an image `src`.
 */
export function sanitizeImageUrl(
	value: string | null | undefined
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

		if (
			(url.protocol === "http:" || url.protocol === "https:") &&
			isTrustedImageOrigin(url.origin)
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
 */
export function isRelativeUrl(value: string): boolean {
	return value.startsWith("/") && !value.startsWith("//");
}
