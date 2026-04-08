import {
	__ as wpTranslate,
	_n as wpTranslatePlural,
	_x as wpTranslateWithContext,
	setLocaleData,
	sprintf,
} from '@wordpress/i18n';

import { API_CLIENT_BASE_URL } from '@/constants';
import { getLocaleDirection } from './direction';
import type {
	LocaleMessageMap,
	RuntimeI18nCatalog,
	RuntimeI18nPayload,
	TextDirection,
} from './types';

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

/**
 * Default translation domain used across the dashboard UI.
 */
export const TEXT_DOMAIN =
	'undefined' !== typeof window && window.__PEAKURL_TEXT_DOMAIN__
		? window.__PEAKURL_TEXT_DOMAIN__
		: DEFAULT_TEXT_DOMAIN;

function isObjectRecord(value: unknown): value is Record<string, unknown> {
	return 'object' === typeof value && null !== value;
}

function isLocaleMessageMap(value: unknown): value is LocaleMessageMap {
	return isObjectRecord(value);
}

function isRuntimeI18nCatalog(value: unknown): value is RuntimeI18nCatalog {
	return isObjectRecord(value);
}

function getLocaleDataFromCatalog(
	runtimeCatalog: RuntimeI18nCatalog | null | undefined
): LocaleMessageMap {
	const messages = runtimeCatalog?.locale_data?.messages;
	if (isLocaleMessageMap(messages)) {
		return messages;
	}

	return DEFAULT_LOCALE_DATA;
}

/**
 * Applies the active locale to the document root for accessibility and
 * browser-native formatting behavior.
 */
function setDocumentLocale(
	locale?: string,
	htmlLang?: string,
	textDirection?: TextDirection
): TextDirection {
	if ('undefined' === typeof document) {
		return textDirection || getLocaleDirection(locale || htmlLang);
	}

	const resolvedLang =
		htmlLang || locale?.replace(/_/g, '-').toLowerCase() || 'en';
	const resolvedDirection =
		textDirection || getLocaleDirection(locale || resolvedLang);
	document.documentElement.lang = resolvedLang;
	document.documentElement.dir = resolvedDirection;

	if (document.body) {
		document.body.dir = resolvedDirection;
	}

	return resolvedDirection;
}

function readStringProperty(
	record: Record<string, unknown>,
	key: string
): string | undefined {
	const value = record[key];
	return 'string' === typeof value ? value : undefined;
}

function readRuntimeCatalog(
	record: Record<string, unknown>,
	key: string
): RuntimeI18nCatalog | undefined {
	const value = record[key];
	return isRuntimeI18nCatalog(value) ? value : undefined;
}

function readTextDirectionProperty(
	record: Record<string, unknown>,
	key: string
): TextDirection | undefined {
	const value = record[key];

	return 'rtl' === value || 'ltr' === value ? value : undefined;
}

function readBooleanProperty(
	record: Record<string, unknown>,
	key: string
): boolean | undefined {
	const value = record[key];
	return 'boolean' === typeof value ? value : undefined;
}

function normalizeRuntimePayload(payload: unknown): RuntimeI18nPayload | null {
	if (!isObjectRecord(payload)) {
		return null;
	}

	const payloadData = isObjectRecord(payload.data) ? payload.data : payload;
	if (!isObjectRecord(payloadData)) {
		return null;
	}

	return {
		catalog:
			readRuntimeCatalog(payloadData, 'catalog') ||
			(isRuntimeI18nCatalog(payloadData) ? payloadData : undefined),
		locale: readStringProperty(payloadData, 'locale'),
		htmlLang: readStringProperty(payloadData, 'htmlLang'),
		textDirection: readTextDirectionProperty(payloadData, 'textDirection'),
		isRtl: readBooleanProperty(payloadData, 'isRtl'),
		textDomain: readStringProperty(payloadData, 'textDomain'),
	};
}

/**
 * Fetches the runtime translation payload from the dashboard API.
 */
async function fetchRuntimeCatalog(): Promise<RuntimeI18nPayload | null> {
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

		return normalizeRuntimePayload(await response.json());
	} catch {
		return null;
	}
}

/**
 * Initializes the client-side translation runtime before the app renders.
 */
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
			isRuntimeI18nCatalog(runtimeCatalog)
				? {
						catalog: runtimeCatalog,
						locale: window.__PEAKURL_LOCALE__ || DEFAULT_LOCALE,
						textDirection:
							window.__PEAKURL_TEXT_DIRECTION__ ||
							getLocaleDirection(
								window.__PEAKURL_LOCALE__ || DEFAULT_LOCALE
							),
						textDomain:
							window.__PEAKURL_TEXT_DOMAIN__ || TEXT_DOMAIN,
					}
				: await fetchRuntimeCatalog();
		const localeData = getLocaleDataFromCatalog(payload?.catalog);
		const domain =
			payload?.textDomain ||
			window.__PEAKURL_TEXT_DOMAIN__ ||
			TEXT_DOMAIN;
		const locale =
			payload?.locale || window.__PEAKURL_LOCALE__ || DEFAULT_LOCALE;
		const textDirection =
			payload?.textDirection ||
			(payload?.isRtl ? 'rtl' : undefined) ||
			window.__PEAKURL_TEXT_DIRECTION__ ||
			getLocaleDirection(locale);

		setLocaleData(localeData, domain);
		window.__PEAKURL_TEXT_DIRECTION__ = setDocumentLocale(
			locale,
			payload?.htmlLang,
			textDirection
		);

		if (payload?.catalog) {
			window.__PEAKURL_I18N__ = payload.catalog;
		}

		window.__PEAKURL_LOCALE__ = locale;
		window.__PEAKURL_TEXT_DOMAIN__ = domain;
		initialized = true;
	})().finally(() => {
		initializationPromise = null;
	});

	return initializationPromise;
}

/**
 * Runtime translation helpers bound to PeakURL's active text domain.
 */
export const translate = <Text extends string>(text: Text): Text =>
	wpTranslate(text, TEXT_DOMAIN) as unknown as Text;
export const translateWithContext = <Text extends string>(
	text: Text,
	context: string
): Text =>
	wpTranslateWithContext(text, context, TEXT_DOMAIN) as unknown as Text;
export const translatePlural = <Single extends string, Plural extends string>(
	single: Single,
	plural: Plural,
	count: number
): Single | Plural =>
	wpTranslatePlural(single, plural, count, TEXT_DOMAIN) as unknown as
		| Single
		| Plural;

export {
	sprintf,
	translate as __,
	translatePlural as _n,
	translateWithContext as _x,
};
