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

export const getDefaultShortUrlOrigin = (origin = PEAKURL_URL) => {
	const installedBaseUrl = normalizeBaseUrl(PEAKURL_URL);

	if (!origin || typeof origin !== 'string') {
		return installedBaseUrl;
	}

	try {
		const url = new URL(origin);
		const hostname = url.hostname.toLowerCase();

		if (
			hostname === PEAKURL_DOMAIN ||
			hostname.endsWith(`.${PEAKURL_DOMAIN}`)
		) {
			return installedBaseUrl;
		}

		return normalizeBaseUrl(url.toString(), installedBaseUrl);
	} catch {
		return installedBaseUrl;
	}
};

export const buildShortUrl = (link, origin = '') => {
	const host = resolveLinkHost(link);
	const base = host ? `https://${host}` : getDefaultShortUrlOrigin(origin);
	const code = link?.alias || link?.shortCode || '';
	return code ? `${base}/${code}` : base;
};
