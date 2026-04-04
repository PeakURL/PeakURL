import { PEAKURL_DOMAIN, PEAKURL_URL } from '@constants';

export const resolveLinkHost = (link) => {
	const rawDomain = link?.domain;
	if (!rawDomain) return '';

	if (typeof rawDomain === 'string') {
		return rawDomain.includes('.') ? rawDomain : '';
	}

	return rawDomain.domain || rawDomain.name || '';
};

const normalizeBaseUrl = (value, fallback = PEAKURL_URL) => {
	if (!value || typeof value !== 'string') return fallback;

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

export const getSiteUrl = (url = PEAKURL_URL) => {
	const installedBaseUrl = normalizeBaseUrl(PEAKURL_URL);

	if (!url || typeof url !== 'string') {
		return installedBaseUrl;
	}

	try {
		const parsedUrl = new URL(url);
		const hostname = parsedUrl.hostname.toLowerCase();

		if (
			hostname === PEAKURL_DOMAIN ||
			hostname.endsWith(`.${PEAKURL_DOMAIN}`)
		) {
			return installedBaseUrl;
		}

		return normalizeBaseUrl(parsedUrl.toString(), installedBaseUrl);
	} catch {
		return installedBaseUrl;
	}
};

export const buildShortUrl = (link, siteUrl = '') => {
	if (typeof link?.shortUrl === 'string' && link.shortUrl.trim()) {
		return link.shortUrl.trim();
	}

	const host = resolveLinkHost(link);
	const base = host ? `https://${host}` : getSiteUrl(siteUrl);
	const code = link?.alias || link?.shortCode || '';
	return code ? `${base}/${code}` : base;
};
