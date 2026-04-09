/**
 * Supported document text directions.
 */
export type TextDirection = 'ltr' | 'rtl';

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
 * Nested `locale_data` block exposed by runtime translation catalogs.
 */
export interface RuntimeLocaleData {
	/** Translation messages grouped under the default `messages` domain. */
	messages?: LocaleMessageMap;
}

/**
 * Runtime translation catalog injected into `window` or returned by the API.
 */
export interface RuntimeI18nCatalog {
	/** Locale data consumed by `setLocaleData()`. */
	locale_data?: RuntimeLocaleData;
}

/**
 * Normalized payload returned by the dashboard i18n bootstrap endpoint.
 */
export interface RuntimeI18nPayload {
	/** Translation catalog that should be registered with the client. */
	catalog?: RuntimeI18nCatalog;

	/** Locale code that should become the active dashboard locale. */
	locale?: string;

	/** HTML language attribute to apply to the document root. */
	htmlLang?: string;

	/** Text direction that should be applied to the document root. */
	textDirection?: TextDirection;

	/** Whether the active locale uses right-to-left layout. */
	isRtl?: boolean;

	/** Translation domain used for runtime lookups. */
	textDomain?: string;
}
