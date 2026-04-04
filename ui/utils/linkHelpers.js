import { PEAKURL_URL } from '@constants';

export const resolveLinkHost = (link) => {
	const rawDomain = link?.domain;
	if (!rawDomain) return '';

	if (typeof rawDomain === 'string') {
		return rawDomain.includes('.') ? rawDomain : '';
	}

	return rawDomain.domain || rawDomain.name || '';
};

const normalizeSiteUrl = (value, fallback = PEAKURL_URL) => {
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

export const getSiteUrl = () => normalizeSiteUrl(PEAKURL_URL);

export const buildShortUrl = (link) => {
	if (typeof link?.shortUrl === 'string' && link.shortUrl.trim()) {
		return link.shortUrl.trim();
	}

	const host = resolveLinkHost(link);
	const base = host ? `https://${host}` : getSiteUrl();
	const code = link?.alias || link?.shortCode || '';
	return code ? `${base}/${code}` : base;
};
