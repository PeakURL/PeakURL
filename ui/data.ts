import type { FaviconData, I18nCatalog, TextDirection } from "@/i18n/types";

/**
 * Public REST API base used when PHP has not injected app data.
 */
export const DEFAULT_API_PATH = "/api/v1";

/**
 * Supported dashboard clock display preferences.
 */
export type PeakURLTimeFormat = "12" | "24";

/**
 * Public configuration for the active CAPTCHA provider.
 */
export interface CaptchaChallenge {
	provider: "recaptcha" | "turnstile";
	siteKey: string;
	action?: string;
	responseField: string;
	scriptUrl: string;
}

/**
 * PHP-injected app data consumed by the React dashboard.
 */
export interface PeakURLData {
	/** Base path used when PeakURL is installed in a subdirectory. */
	basePath?: string;

	/** Client-visible API base path used by dashboard requests. */
	apiBase?: string;

	/** Canonical public URL for the connected PeakURL install. */
	siteUrl?: string;

	/** Human-readable site title configured for this install. */
	siteName?: string;

	/** Current installed PeakURL version. */
	version?: string;

	/** Whether debug-mode UI and diagnostics are enabled. */
	debug?: boolean;

	/** Active locale code, such as `en_US`. */
	locale?: string;

	/** HTML language attribute derived from the active locale. */
	htmlLang?: string;

	/** Active document text direction. */
	textDirection?: TextDirection;

	/** WordPress-style translation text domain. */
	textDomain?: string;

	/** Site timezone used for dashboard date/time formatting. */
	timezone?: string;

	/** Dashboard clock display preference. */
	timeFormat?: PeakURLTimeFormat;

	/** Public favicon metadata for the current install. */
	favicon?: FaviconData | null;

	/** Translation catalog consumed by `@wordpress/i18n`. */
	i18n?: I18nCatalog;

	/** Public CAPTCHA configuration, if enabled. */
	captcha?: CaptchaChallenge | null;
}

declare global {
	interface Window {
		/**
		 * PHP-provided dashboard app data, injected before React boots.
		 */
		__PEAKURL__?: PeakURLData;
	}
}

/**
 * String fields accepted from PHP-injected or API-provided app data.
 */
const STRING_DATA_KEYS = [
	"basePath",
	"apiBase",
	"siteUrl",
	"siteName",
	"version",
	"locale",
	"htmlLang",
	"textDomain",
	"timezone",
] as const;

function isObjectRecord(value: unknown): value is Record<string, unknown> {
	return "object" === typeof value && null !== value;
}

/**
 * Return a trimmed app-data string or an empty string.
 */
export function getDataString(value: string | null | undefined): string {
	return "string" === typeof value ? value.trim() : "";
}

/**
 * Format a mounted base path so route and API URLs can be joined safely.
 */
export function formatBasePath(value: string | null | undefined): string {
	const basePath = getDataString(value);

	if (!basePath) {
		return "";
	}

	if ("/" === basePath) {
		return "";
	}

	return `/${basePath.replace(/^\/+|\/+$/g, "")}`;
}

/**
 * Convert unknown PHP/API data into the typed dashboard app data shape.
 */
export function toPeakURLData(value: unknown): PeakURLData {
	if (!isObjectRecord(value)) {
		return {};
	}

	// Accept both the packaged HTML object and the Vite/dev API response body.
	const data = isObjectRecord(value.data) ? value.data : value;
	const peakurlData: PeakURLData = {};

	STRING_DATA_KEYS.forEach((key) => {
		const fieldValue = data[key];

		if ("string" === typeof fieldValue) {
			peakurlData[key] = fieldValue;
		}
	});

	if ("boolean" === typeof data.debug) {
		peakurlData.debug = data.debug;
	}

	if ("rtl" === data.textDirection || "ltr" === data.textDirection) {
		peakurlData.textDirection = data.textDirection;
	}

	if ("12" === data.timeFormat || "24" === data.timeFormat) {
		peakurlData.timeFormat = data.timeFormat;
	}

	if (null === data.favicon || isObjectRecord(data.favicon)) {
		peakurlData.favicon = data.favicon as FaviconData | null;
	}

	if (isObjectRecord(data.i18n)) {
		peakurlData.i18n = data.i18n as I18nCatalog;
	}

	if (null === data.captcha || isObjectRecord(data.captcha)) {
		peakurlData.captcha = data.captcha as CaptchaChallenge | null;
	}

	return peakurlData;
}

/**
 * Read the current dashboard app data from `window.__PEAKURL__`.
 */
export function getPeakURLData(): PeakURLData {
	if ("undefined" === typeof window) {
		return {};
	}

	return toPeakURLData(window.__PEAKURL__);
}

/**
 * Replace the current dashboard app data.
 */
export function setPeakURLData(data: PeakURLData): PeakURLData {
	const nextData = toPeakURLData(data);

	if ("undefined" !== typeof window) {
		window.__PEAKURL__ = nextData;
	}

	return nextData;
}

/**
 * Merge partial dashboard app data into the current `window.__PEAKURL__` value.
 */
export function updatePeakURLData(data: PeakURLData): PeakURLData {
	return setPeakURLData({
		...getPeakURLData(),
		...toPeakURLData(data),
	});
}
