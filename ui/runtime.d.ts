import type { RuntimeI18nCatalog, TextDirection } from './i18n/types';

export {};

/**
 * Extends the global `Window` interface with PeakURL-specific
 * internationalization (i18n) and localization properties.
 */
declare global {
	interface Window {
		/**
		 * Global i18n data injected by the application.
		 *
		 * Contains localized message catalogs used for runtime translations.
		 */
		__PEAKURL_I18N__?: RuntimeI18nCatalog;

		/**
		 * Current active locale (e.g. "en", "fr", "de").
		 */
		__PEAKURL_LOCALE__?: string;

		/**
		 * Current document text direction derived from the active locale.
		 */
		__PEAKURL_TEXT_DIRECTION__?: TextDirection;

		/**
		 * Base path where the dashboard is mounted when served from a subdirectory.
		 */
		__PEAKURL_BASE_PATH__?: string;

		/**
		 * Canonical public site URL detected or injected by the PHP runtime.
		 */
		__PEAKURL_URL__?: string;

		/**
		 * Client-visible API base path used for dashboard requests.
		 */
		__PEAKURL_API_BASE__?: string;

		/**
		 * Human-readable site name configured for the current install.
		 */
		__PEAKURL_SITE_NAME__?: string;

		/**
		 * Runtime application version exposed to the dashboard shell.
		 */
		__PEAKURL_VERSION__?: string;

		/**
		 * Initial body classes supplied by the PHP runtime before the SPA boots.
		 */
		__PEAKURL_BODY_CLASSES__?: string[];

		/**
		 * Text domain used for translation lookup.
		 * Typically aligns with the application's i18n domain.
		 */
		__PEAKURL_TEXT_DOMAIN__?: string;
	}
}
