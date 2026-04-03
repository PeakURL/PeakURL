import rawPeakurlVersion from '../.version?raw';

const DEFAULT_PEAKURL_ORIGIN = 'https://peakurl.dev';
const DEFAULT_API_PATH = '/api/v1';
const isBrowser = typeof window !== 'undefined';
const fallbackVersion = rawPeakurlVersion.trim() || '0.0.0';
const normalizeBasePath = (value) => {
	if (!value || typeof value !== 'string') {
		return '';
	}

	if ('/' === value.trim()) {
		return '';
	}

	return `/${value.replace(/^\/+|\/+$/g, '')}`;
};
const runtimeBasePath = isBrowser
	? normalizeBasePath(window.__PEAKURL_BASE_PATH__)
	: '';
const runtimeOrigin = isBrowser
	? window.location.origin
	: DEFAULT_PEAKURL_ORIGIN;
const runtimePeakurlUrl = isBrowser
	? window.__PEAKURL_URL__ || `${runtimeOrigin}${runtimeBasePath}`
	: DEFAULT_PEAKURL_ORIGIN;
const runtimeHost = isBrowser
	? window.location.host
	: new URL(DEFAULT_PEAKURL_ORIGIN).host;
const runtimeApiPath = isBrowser
	? window.__PEAKURL_API_BASE__ || `${runtimeBasePath}${DEFAULT_API_PATH}`
	: DEFAULT_API_PATH;
const runtimeSiteName =
	isBrowser && 'string' === typeof window.__PEAKURL_SITE_NAME__
		? window.__PEAKURL_SITE_NAME__.trim()
		: '';
const runtimeVersion =
	isBrowser && 'string' === typeof window.__PEAKURL_VERSION__
		? window.__PEAKURL_VERSION__.trim()
		: '';

const toAbsoluteUrl = (value, fallbackOrigin = runtimeOrigin) => {
	try {
		return new URL(value, fallbackOrigin).toString();
	} catch {
		return fallbackOrigin;
	}
};

const sanitizeHost = (value, fallback = runtimeHost) => {
	if (!value || typeof value !== 'string') {
		return fallback;
	}

	return value.replace(/^https?:\/\//i, '').replace(/\/.*$/, '') || fallback;
};

export const PEAKURL_NAME = 'PeakURL';
export const PEAKURL_SITE_NAME = runtimeSiteName || PEAKURL_NAME;
export const PEAKURL_VERSION = runtimeVersion || fallbackVersion;
export const SUPPORT_EMAIL =
	import.meta.env.VITE_SUPPORT_EMAIL || 'support@example.com';

export const PEAKURL_URL = toAbsoluteUrl(
	runtimePeakurlUrl || import.meta.env.VITE_PEAKURL_URL || runtimeOrigin,
	runtimeOrigin
);
export const PEAKURL_BASENAME = runtimeBasePath;
export const PEAKURL_HOST = sanitizeHost(
	import.meta.env.VITE_PEAKURL_HOST,
	new URL(PEAKURL_URL).host
);
export const PEAKURL_DOMAIN = PEAKURL_HOST.replace(/^www\./i, '');

export const API_CLIENT_BASE_URL =
	runtimeApiPath || import.meta.env.VITE_API_BASE_URL || DEFAULT_API_PATH;
export const API_SERVER_BASE_URL = toAbsoluteUrl(
	API_CLIENT_BASE_URL,
	PEAKURL_URL
);
export const API_ORIGIN = new URL(API_SERVER_BASE_URL).origin;
export const INTERNAL_API_ORIGIN =
	import.meta.env.VITE_INTERNAL_API_ORIGIN || API_ORIGIN;

export const PLUGINS_WAITLIST_URL = 'https://go.peakurl.org/join-plugins-list';
