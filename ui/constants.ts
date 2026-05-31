import {
	DEFAULT_API_PATH,
	formatBasePath,
	getDataString,
	getPeakURLData,
} from "@/data";

import rawPeakurlVersion from "../.version?raw";

const DEFAULT_PEAKURL_ORIGIN = "https://peakurl.dev";
const FALLBACK_VERSION = rawPeakurlVersion.trim() || "0.0.0";
const IS_BROWSER = "undefined" !== typeof window;

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
	if (!value || "string" !== typeof value) {
		return fallback;
	}

	return value.replace(/^https?:\/\//i, "").replace(/\/.*$/, "") || fallback;
}

const peakurlData = getPeakURLData();
const appBasePath = IS_BROWSER ? formatBasePath(peakurlData.basePath) : "";
const appOrigin = IS_BROWSER ? window.location.origin : DEFAULT_PEAKURL_ORIGIN;
const appSiteUrl = IS_BROWSER ? getDataString(peakurlData.siteUrl) : "";
const appApiBase = IS_BROWSER ? getDataString(peakurlData.apiBase) : "";
const fallbackSiteUrl = IS_BROWSER
	? `${appOrigin}${appBasePath}`
	: DEFAULT_PEAKURL_ORIGIN;
const fallbackApiPath = IS_BROWSER
	? `${appBasePath}${DEFAULT_API_PATH}`
	: DEFAULT_API_PATH;
const appSiteName = IS_BROWSER ? getDataString(peakurlData.siteName) : "";
const appVersion = IS_BROWSER ? getDataString(peakurlData.version) : "";
const appDebug = import.meta.env.DEV
	? true
	: IS_BROWSER
		? true === peakurlData.debug
		: false;

/**
 * Canonical product name used throughout the dashboard UI.
 */
export const PEAKURL_NAME = "PeakURL";

/**
 * Site name shown in UI copy and page titles.
 */
export const PEAKURL_SITE_NAME = appSiteName || PEAKURL_NAME;

/**
 * Current application version available to the dashboard.
 */
export const PEAKURL_VERSION = appVersion || FALLBACK_VERSION;

/**
 * Whether debug mode is enabled for the current install.
 */
export const PEAKURL_DEBUG = appDebug;

/**
 * Support contact address shown in contributor-facing UI copy.
 */
export const SUPPORT_EMAIL =
	import.meta.env.VITE_SUPPORT_EMAIL || "support@example.com";

/**
 * Canonical public site URL for the current install.
 */
export const PEAKURL_URL = toAbsoluteUrl(
	appSiteUrl || import.meta.env.VITE_PEAKURL_URL || fallbackSiteUrl,
	appOrigin
);

/**
 * Router basename used when PeakURL is mounted below the site root.
 */
export const PEAKURL_BASENAME = appBasePath;

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
export const PEAKURL_DOMAIN = PEAKURL_HOST.replace(/^www\./i, "");

/**
 * Client-visible API base path used by RTK Query and app data fetches.
 */
export const API_CLIENT_BASE_URL =
	appApiBase || import.meta.env.VITE_API_BASE_URL || fallbackApiPath;

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
export const PLUGINS_WAITLIST_URL = "https://go.peakurl.org/join-plugins-list";
