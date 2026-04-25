import rawPeakurlVersion from '../.version?raw';

const DEFAULT_PEAKURL_ORIGIN = 'https://peakurl.dev';
const DEFAULT_API_PATH = '/api/v1';
const FALLBACK_VERSION = rawPeakurlVersion.trim() || '0.0.0';
const IS_BROWSER = 'undefined' !== typeof window;

/**
 * Normalizes a mounted base path so route and API URLs can be joined safely.
 */
function normalizeBasePath(value: string | null | undefined): string {
	if (!value || 'string' !== typeof value) {
		return '';
	}

	if ('/' === value.trim()) {
		return '';
	}

	return `/${value.replace(/^\/+|\/+$/g, '')}`;
}

/**
 * Reads a trimmed runtime string injected onto `window`, falling back to `''`.
 */
function getRuntimeString(value: string | null | undefined): string {
	return 'string' === typeof value ? value.trim() : '';
}

/**
 * Converts a relative or absolute URL value into a safe absolute URL string.
 */
function toAbsoluteUrl(
	value: string,
	fallbackOrigin: string = DEFAULT_PEAKURL_ORIGIN
): string {
	try {
		return new URL(value, fallbackOrigin).toString();
	} catch {
		return fallbackOrigin;
	}
}

/**
 * Normalizes a host-like value by stripping protocol and path segments.
 */
function sanitizeHost(
	value: string | null | undefined,
	fallback: string
): string {
	if (!value || 'string' !== typeof value) {
		return fallback;
	}

	return value.replace(/^https?:\/\//i, '').replace(/\/.*$/, '') || fallback;
}

const runtimeBasePath = IS_BROWSER
	? normalizeBasePath(window.__PEAKURL_BASE_PATH__)
	: '';
const runtimeOrigin = IS_BROWSER
	? window.location.origin
	: DEFAULT_PEAKURL_ORIGIN;
const runtimePeakurlUrl = IS_BROWSER
	? window.__PEAKURL_URL__ || `${runtimeOrigin}${runtimeBasePath}`
	: DEFAULT_PEAKURL_ORIGIN;
const runtimeApiPath = IS_BROWSER
	? window.__PEAKURL_API_BASE__ || `${runtimeBasePath}${DEFAULT_API_PATH}`
	: DEFAULT_API_PATH;
const runtimeSiteName = IS_BROWSER
	? getRuntimeString(window.__PEAKURL_SITE_NAME__)
	: '';
const runtimeVersion = IS_BROWSER
	? getRuntimeString(window.__PEAKURL_VERSION__)
	: '';
const runtimeDebug = import.meta.env.DEV
	? true
	: IS_BROWSER
		? true === window.__PEAKURL_DEBUG__
		: false;

/**
 * Canonical product name used throughout the dashboard UI.
 */
export const PEAKURL_NAME = 'PeakURL';

/**
 * Site name shown in UI copy and page titles.
 */
export const PEAKURL_SITE_NAME = runtimeSiteName || PEAKURL_NAME;

/**
 * Current application version available to the dashboard.
 */
export const PEAKURL_VERSION = runtimeVersion || FALLBACK_VERSION;

/**
 * Whether runtime debug mode is enabled for the current install.
 */
export const PEAKURL_DEBUG = runtimeDebug;

/**
 * Support contact address shown in contributor-facing UI copy.
 */
export const SUPPORT_EMAIL =
	import.meta.env.VITE_SUPPORT_EMAIL || 'support@example.com';

/**
 * Canonical public site URL for the current install.
 */
export const PEAKURL_URL = toAbsoluteUrl(
	runtimePeakurlUrl || import.meta.env.VITE_PEAKURL_URL || runtimeOrigin,
	runtimeOrigin
);

/**
 * Router basename used when PeakURL is mounted below the site root.
 */
export const PEAKURL_BASENAME = runtimeBasePath;

/**
 * Public host for the current install without path information.
 */
export const PEAKURL_HOST = sanitizeHost(
	import.meta.env.VITE_PEAKURL_HOST,
	new URL(PEAKURL_URL).host
);

/**
 * Public host normalized without a leading `www.` prefix.
 */
export const PEAKURL_DOMAIN = PEAKURL_HOST.replace(/^www\./i, '');

/**
 * Client-visible API base path used by RTK Query and runtime fetches.
 */
export const API_CLIENT_BASE_URL =
	runtimeApiPath || import.meta.env.VITE_API_BASE_URL || DEFAULT_API_PATH;

/**
 * Absolute server API base URL resolved against the current install URL.
 */
export const API_SERVER_BASE_URL = toAbsoluteUrl(
	API_CLIENT_BASE_URL,
	PEAKURL_URL
);

/**
 * API origin used by integrations and diagnostics that need an absolute host.
 */
export const API_ORIGIN = new URL(API_SERVER_BASE_URL).origin;

/**
 * Optional internal API origin override for proxied or split-host setups.
 */
export const INTERNAL_API_ORIGIN =
	import.meta.env.VITE_INTERNAL_API_ORIGIN || API_ORIGIN;

/**
 * Public waitlist URL for the plugins preview surface.
 */
export const PLUGINS_WAITLIST_URL = 'https://go.peakurl.org/join-plugins-list';
