import { PEAKURL_BASENAME } from "@constants";

export type ManagedFaviconAsset =
	| "favicon.png"
	| "favicon.ico"
	| "apple-touch-icon.png"
	| "site.webmanifest";

function decodePathSegment(segment: string): string {
	let decodedSegment = segment;

	for (let attempts = 0; attempts < 3; attempts += 1) {
		const nextSegment = decodeURIComponent(decodedSegment);

		if (nextSegment === decodedSegment) {
			return decodedSegment;
		}

		decodedSegment = nextSegment;
	}

	return decodedSegment;
}

function getManagedFaviconBasePath(): string {
	const segments: string[] = [];

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

function getUpdatedTimestamp(updatedAt?: string | null): number | undefined {
	if (typeof updatedAt !== "string" || updatedAt.trim().length === 0) {
		return undefined;
	}

	const updatedTimestamp = Date.parse(updatedAt);

	return Number.isFinite(updatedTimestamp) ? updatedTimestamp : undefined;
}

/**
 * Builds a same-origin URL for a managed favicon asset.
 */
export function buildManagedFaviconUrl(
	asset: ManagedFaviconAsset,
	updatedAt?: string | null
): string {
	const basePath = getManagedFaviconBasePath();
	const assetPath = `${basePath}/${asset}`;
	const updatedTimestamp = getUpdatedTimestamp(updatedAt);

	if (updatedTimestamp !== undefined) {
		return `${assetPath}?v=${encodeURIComponent(String(updatedTimestamp))}`;
	}

	return assetPath;
}

/**
 * Returns the managed PNG favicon URL used for dashboard previews.
 */
export function buildFaviconPreviewUrl(updatedAt?: string | null): string {
	return buildManagedFaviconUrl("favicon.png", updatedAt);
}
