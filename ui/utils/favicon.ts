import { PEAKURL_BASENAME } from "@constants";

/**
 * Valid managed favicon asset filenames.
 */
export type ManagedFaviconAsset =
	| "favicon.png"
	| "favicon.ico"
	| "apple-touch-icon.png"
	| "site.webmanifest";

/**
 * Recursively decode a path segment to ensure it's fully normalized.
 *
 * @param segment - The path segment to decode.
 * @return The decoded segment.
 */
function decodePathSegment(segment: string): string {
	let decodedSegment = segment;

	/*
	 * Attempt to decode the segment up to 3 times to handle
	 * nested encoding, stopping early if no further changes occur.
	 */
	for (let attempts = 0; attempts < 3; attempts += 1) {
		const nextSegment = decodeURIComponent(decodedSegment);

		if (nextSegment === decodedSegment) {
			return decodedSegment;
		}

		decodedSegment = nextSegment;
	}

	return decodedSegment;
}

/**
 * Derive the base path for managed favicon assets from the site basename.
 *
 * @return The normalized base path, or an empty string.
 */
function getManagedFaviconBasePath(): string {
	const segments: string[] = [];

	/*
	 * Iterate through the basename segments, decoding and validating each
	 * to prevent directory traversal or invalid path characters.
	 */
	for (const segment of PEAKURL_BASENAME.split("/")) {
		let decodedSegment = "";

		try {
			decodedSegment = decodePathSegment(segment);
		} catch {
			return "";
		}

		if (!decodedSegment || decodedSegment === ".") {
			continue;
		}

		if (
			decodedSegment === ".." ||
			decodedSegment.includes("/") ||
			decodedSegment.includes("\\")
		) {
			return "";
		}

		segments.push(encodeURIComponent(decodedSegment));
	}

	return segments.length > 0 ? `/${segments.join("/")}` : "";
}

/**
 * Normalize an "updated at" string into a numeric timestamp.
 *
 * @param updatedAt - ISO date string or timestamp.
 * @return The numeric timestamp, or undefined if invalid.
 */
function getUpdatedTimestamp(updatedAt?: string | null): number | undefined {
	if (typeof updatedAt !== "string" || updatedAt.trim().length === 0) {
		return undefined;
	}

	const updatedTimestamp = Date.parse(updatedAt);

	return Number.isFinite(updatedTimestamp) ? updatedTimestamp : undefined;
}

/**
 * Return a same-origin URL for a managed favicon asset.
 *
 * @param asset     - The favicon asset name.
 * @param updatedAt - Optional timestamp for cache busting.
 * @return The asset URL.
 */
export function getManagedFaviconUrl(
	asset: ManagedFaviconAsset,
	updatedAt?: string | null
): string {
	const basePath = getManagedFaviconBasePath();
	const assetPath = `${basePath}/${asset}`;
	const updatedTimestamp = getUpdatedTimestamp(updatedAt);

	/*
	 * If a valid update timestamp is provided, append it as a version
	 * query parameter to bypass browser caches.
	 */
	if (updatedTimestamp !== undefined) {
		return `${assetPath}?v=${encodeURIComponent(String(updatedTimestamp))}`;
	}

	return assetPath;
}

/**
 * Return the managed PNG favicon URL used for dashboard previews.
 *
 * @param updatedAt - Optional timestamp for cache busting.
 * @return The preview favicon URL.
 */
export function getFaviconPreviewUrl(updatedAt?: string | null): string {
	return getManagedFaviconUrl("favicon.png", updatedAt);
}
