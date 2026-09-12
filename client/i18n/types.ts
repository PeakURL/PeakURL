/**
 * Supported document text directions.
 */
export type TextDirection = "ltr" | "rtl";

/**
 * Installed language metadata returned by the language discovery flow.
 */
export interface InstalledLanguage {
	/** Locale identifier such as `en_US` or `fr_FR`. */
	locale?: string;

	/** Localized display label for the language. */
	label?: string;

	/** English display label for the language. */
	englishLabel?: string;

	/** Active text direction for the locale. */
	textDirection?: TextDirection;

	/** Whether the locale uses right-to-left layout. */
	isRtl?: boolean;
}

/**
 * Translation entry value returned by WordPress-style locale catalogs.
 *
 * Entries may either be the traditional array form used by `@wordpress/i18n`
 * or a keyed object when plural/context variants are materialized differently.
 */
export type LocaleMessageEntry = string[] | Record<string, string>;

/**
 * Message table keyed by the original untranslated string.
 */
export type LocaleMessageMap = Record<string, LocaleMessageEntry>;

/**
 * Nested `locale_data` block exposed by dashboard translation catalogs.
 */
export interface I18nLocaleData {
	/** Translation messages grouped under the default `messages` domain. */
	messages?: LocaleMessageMap;
}

/**
 * Translation catalog injected into `window.__PEAKURL__` or returned by the API.
 */
export interface I18nCatalog {
	/** Locale data consumed by `setLocaleData()`. */
	locale_data?: I18nLocaleData;
}

/**
 * Public favicon metadata exposed to the dashboard app.
 */
export interface FaviconData {
	/** Whether a site favicon is currently configured. */
	configured?: boolean;

	/** Whether the active favicon comes from a user-uploaded override. */
	isCustom?: boolean;

	/** Primary PNG favicon URL. */
	url?: string | null;

	/** Legacy favicon route URL. */
	iconUrl?: string | null;

	/** Apple touch icon URL. */
	appleTouchUrl?: string | null;

	/** Web manifest URL. */
	manifestUrl?: string | null;

	/** MIME type for the favicon asset. */
	mimeType?: string | null;

	/** Declared icon size string such as `512x512`. */
	sizes?: string | null;

	/** Last updated timestamp used for cache-busting managed asset URLs. */
	updatedAt?: string | null;
}
