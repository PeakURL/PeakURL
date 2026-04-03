import {
	__,
	_n,
	_x,
	setLocaleData,
	sprintf,
} from '@wordpress/i18n';
import { API_CLIENT_BASE_URL } from '@/constants';

const DEFAULT_TEXT_DOMAIN = 'peakurl';
const DEFAULT_LOCALE = 'en_US';
const DEFAULT_LOCALE_DATA = {
	'': {
		domain: DEFAULT_TEXT_DOMAIN,
		lang: DEFAULT_LOCALE,
		'plural-forms': 'nplurals=2; plural=n != 1;',
	},
};

let initialized = false;
let initializationPromise: Promise<void> | null = null;

export const TEXT_DOMAIN =
	'undefined' !== typeof window && window.__PEAKURL_TEXT_DOMAIN__
		? window.__PEAKURL_TEXT_DOMAIN__
		: DEFAULT_TEXT_DOMAIN;

function getLocaleDataFromCatalog(
	runtimeCatalog: unknown
): Record<string, string[] | Record<string, string>> {
	if (
		runtimeCatalog &&
		'object' === typeof runtimeCatalog &&
		'locale_data' in runtimeCatalog &&
		runtimeCatalog.locale_data &&
		'object' === typeof runtimeCatalog.locale_data &&
		'messages' in runtimeCatalog.locale_data &&
		runtimeCatalog.locale_data.messages &&
		'object' === typeof runtimeCatalog.locale_data.messages
	) {
		return runtimeCatalog.locale_data.messages as Record<
			string,
			string[] | Record<string, string>
		>;
	}

	return DEFAULT_LOCALE_DATA;
}

function setDocumentLocale(locale?: string, htmlLang?: string): void {
	if ('undefined' === typeof document) {
		return;
	}

	const resolvedLang = htmlLang || locale?.replace(/_/g, '-').toLowerCase() || 'en';
	document.documentElement.lang = resolvedLang;
}

async function fetchRuntimeCatalog(): Promise<{
	catalog?: unknown;
	locale?: string;
	htmlLang?: string;
	textDomain?: string;
} | null> {
	try {
		const response = await fetch(`${API_CLIENT_BASE_URL}/system/i18n`, {
			credentials: 'include',
			headers: {
				Accept: 'application/json',
			},
		});

		if (!response.ok) {
			return null;
		}

		const payload = await response.json();
		const data =
			payload?.data && 'object' === typeof payload.data
				? payload.data
				: payload;

		if (!data || 'object' !== typeof data) {
			return null;
		}

		return {
			catalog: 'catalog' in data ? data.catalog : undefined,
			locale:
				'locale' in data && 'string' === typeof data.locale
					? data.locale
					: undefined,
			htmlLang:
				'htmlLang' in data && 'string' === typeof data.htmlLang
					? data.htmlLang
					: undefined,
			textDomain:
				'textDomain' in data && 'string' === typeof data.textDomain
					? data.textDomain
					: undefined,
		};
	} catch {
		return null;
	}
}

export function initializeI18n(): Promise<void> {
	if ('undefined' === typeof window) {
		return Promise.resolve();
	}

	if (initialized) {
		return Promise.resolve();
	}

	if (initializationPromise) {
		return initializationPromise;
	}

	initializationPromise = (async () => {
		const runtimeCatalog = window.__PEAKURL_I18N__;
		const payload =
			runtimeCatalog && 'object' === typeof runtimeCatalog
				? {
						catalog: runtimeCatalog,
						locale: window.__PEAKURL_LOCALE__ || DEFAULT_LOCALE,
						textDomain: window.__PEAKURL_TEXT_DOMAIN__ || TEXT_DOMAIN,
				  }
				: await fetchRuntimeCatalog();
		const localeData = getLocaleDataFromCatalog(payload?.catalog);
		const domain = payload?.textDomain || window.__PEAKURL_TEXT_DOMAIN__ || TEXT_DOMAIN;
		const locale = payload?.locale || window.__PEAKURL_LOCALE__ || DEFAULT_LOCALE;

		setLocaleData(
			localeData as Record<string, string[] | Record<string, string>>,
			domain
		);
		setDocumentLocale(locale, payload?.htmlLang);

		if (payload?.catalog) {
			window.__PEAKURL_I18N__ =
				payload.catalog as Window['__PEAKURL_I18N__'];
		}

		window.__PEAKURL_LOCALE__ = locale;
		window.__PEAKURL_TEXT_DOMAIN__ = domain;
		initialized = true;
	})().finally(() => {
		initializationPromise = null;
	});

	return initializationPromise;
}

export const translate = (text: string): string => __(text, TEXT_DOMAIN);
export const translateWithContext = (
	text: string,
	context: string
): string => _x(text, context, TEXT_DOMAIN);
export const translatePlural = (
	single: string,
	plural: string,
	count: number
): string => _n(single, plural, count, TEXT_DOMAIN);

export {
	sprintf,
	translate as __,
	translatePlural as _n,
	translateWithContext as _x,
};
