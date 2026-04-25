import { PEAKURL_URL } from "@constants";

export type ManagedFaviconAsset =
	| "favicon.png"
	| "favicon.ico"
	| "apple-touch-icon.png"
	| "site.webmanifest";

/**
 * Builds a same-origin URL for a managed favicon asset.
 */
export function buildManagedFaviconUrl(
	asset: ManagedFaviconAsset,
	updatedAt?: string | null
): string {
	try {
		const siteUrl = new URL(
			"./",
			PEAKURL_URL.endsWith("/") ? PEAKURL_URL : `${PEAKURL_URL}/`
		);
		const assetUrl = new URL(asset, siteUrl);
		const updatedTimestamp = Date.parse(updatedAt || "");

		if (Number.isFinite(updatedTimestamp)) {
			assetUrl.searchParams.set("v", `${updatedTimestamp}`);
		}

		return assetUrl.toString();
	} catch {
		return "";
	}
}

/**
 * Returns the managed PNG favicon URL used for dashboard previews.
 */
export function buildFaviconPreviewUrl(updatedAt?: string | null): string {
	return buildManagedFaviconUrl("favicon.png", updatedAt);
}
