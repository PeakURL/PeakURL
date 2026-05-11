import { PEAKURL_URL } from "@constants";
import { getStringRecordValue, isObjectRecord } from "./records";
import type { ShortUrlLinkLike } from "./types";

/**
 * Resolve a public host from the link payload when a custom domain exists.
 *
 * @param link - The link record containing domain information.
 * @return The resolved host string or an empty string.
 */
export const getLinkHost = (link?: ShortUrlLinkLike | null): string => {
	const rawDomain = link?.domain;

	if (!rawDomain) {
		return "";
	}

	/* If domain is a string, ensure it looks like a valid hostname. */
	if ("string" === typeof rawDomain) {
		return rawDomain.includes(".") ? rawDomain : "";
	}

	if (!isObjectRecord(rawDomain)) {
		return "";
	}

	/* Extract domain or name properties from structured domain objects. */
	return (
		getStringRecordValue(rawDomain, "domain") ||
		getStringRecordValue(rawDomain, "name") ||
		""
	);
};

/**
 * Normalize the configured site URL for public short-link construction.
 *
 * @param value    - The URL string to normalize.
 * @param fallback - The fallback URL to use if normalization fails.
 * @return The normalized site URL.
 */
const normalizeSiteUrl = (
	value: string | null | undefined,
	fallback: string = PEAKURL_URL
): string => {
	if (!value || "string" !== typeof value) {
		return fallback;
	}

	try {
		const url = new URL(value, fallback);

		/* Standardize by removing "www." prefixes from the hostname. */
		if (url.hostname.startsWith("www.")) {
			url.hostname = url.hostname.slice(4);
		}

		return `${url.origin}${url.pathname}`.replace(/\/+$/, "");
	} catch {
		return fallback;
	}
};

/**
 * Return the normalized site URL configured for the current install.
 *
 * @return The site base URL.
 */
export const getSiteUrl = (): string => normalizeSiteUrl(PEAKURL_URL);

/**
 * Return the public short URL for a link payload.
 *
 * @param link - The link record.
 * @return The full public short URL.
 */
export const getShortUrl = (link?: ShortUrlLinkLike | null): string => {
	/* Prefer explicit shortUrl values if provided in the payload. */
	if ("string" === typeof link?.shortUrl && link.shortUrl.trim()) {
		return link.shortUrl.trim();
	}

	const host = getLinkHost(link);
	const base = host ? `https://${host}` : getSiteUrl();
	const code = link?.alias || link?.shortCode || "";

	/* Construct the short URL by appending the alias to the base origin. */
	return code ? `${base}/${code}` : base;
};
