import { PEAKURL_URL } from '@constants';
import { getStringRecordValue, isObjectRecord } from './records';
import type { ShortUrlLinkLike } from './types';

/**
 * Resolves a public host from the link payload when a custom domain exists.
 */
export const resolveLinkHost = (
	link?: ShortUrlLinkLike | null
): string => {
	const rawDomain = link?.domain;

	if (!rawDomain) {
		return '';
	}

	if ('string' === typeof rawDomain) {
		return rawDomain.includes('.') ? rawDomain : '';
	}

	if (!isObjectRecord(rawDomain)) {
		return '';
	}

	return (
		getStringRecordValue(rawDomain, 'domain') ||
		getStringRecordValue(rawDomain, 'name') ||
		''
	);
};

/**
 * Normalizes the configured site URL for public short-link construction.
 */
const normalizeSiteUrl = (
	value: string | null | undefined,
	fallback: string = PEAKURL_URL
): string => {
	if (!value || 'string' !== typeof value) {
		return fallback;
	}

	try {
		const url = new URL(value, fallback);
		if (url.hostname.startsWith('www.')) {
			url.hostname = url.hostname.slice(4);
		}

		return `${url.origin}${url.pathname}`.replace(/\/+$/, '');
	} catch {
		return fallback;
	}
};

/**
 * Returns the normalized site URL configured for the current install.
 */
export const getSiteUrl = (): string => normalizeSiteUrl(PEAKURL_URL);

/**
 * Builds the public short URL for a link payload.
 */
export const buildShortUrl = (link?: ShortUrlLinkLike | null): string => {
	if ('string' === typeof link?.shortUrl && link.shortUrl.trim()) {
		return link.shortUrl.trim();
	}

	const host = resolveLinkHost(link);
	const base = host ? `https://${host}` : getSiteUrl();
	const code = link?.alias || link?.shortCode || '';

	return code ? `${base}/${code}` : base;
};
