import {
	__ as wpTranslate,
	_n as wpTranslatePlural,
	_x as wpTranslateWithContext,
	setLocaleData,
	sprintf,
	type TransformedText,
} from "@wordpress/i18n";

import { API_ROUTES, getApiRequestUrl } from "@/api";
import {
	getPeakURLData,
	toPeakURLData,
	updatePeakURLData,
	type PeakURLData,
} from "@/data";
import { applyDocumentFavicon, setDocumentLocale } from "@/shared/browser";

import { getLocaleDirection } from "./direction";
import type { I18nCatalog, LocaleMessageMap } from "./types";

const DEFAULT_TEXT_DOMAIN = "peakurl";
const DEFAULT_LOCALE = "en_US";
const DEFAULT_LOCALE_DATA = {
	"": {
		domain: DEFAULT_TEXT_DOMAIN,
		lang: DEFAULT_LOCALE,
		"plural-forms": "nplurals=2; plural=n != 1;",
	},
};

let initialized = false;
let initializationPromise: Promise<void> | null = null;

/**
 * Default translation domain used across the dashboard UI.
 */
export const TEXT_DOMAIN = getPeakURLData().textDomain || DEFAULT_TEXT_DOMAIN;

function isObjectRecord(value: unknown): value is Record<string, unknown> {
	return "object" === typeof value && null !== value;
}

function isLocaleMessageMap(value: unknown): value is LocaleMessageMap {
	return isObjectRecord(value);
}

function hasLocaleData(catalog: I18nCatalog | null | undefined): boolean {
	return isObjectRecord(catalog?.locale_data);
}

function getLocaleDataFromCatalog(
	catalog: I18nCatalog | null | undefined
): LocaleMessageMap {
	const messages = catalog?.locale_data?.messages;
	if (isLocaleMessageMap(messages)) {
		return messages;
	}

	return DEFAULT_LOCALE_DATA;
}

/**
 * Fetches the dashboard translation payload from the dashboard API.
 */
async function fetchPeakURLData(): Promise<PeakURLData | null> {
	try {
		const response = await fetch(getApiRequestUrl(API_ROUTES.system.i18n), {
			credentials: "include",
			headers: {
				Accept: "application/json",
			},
		});

		if (!response.ok) {
			return null;
		}

		return toPeakURLData(await response.json());
	} catch {
		return null;
	}
}

/**
 * Initializes the client-side translations before the app renders.
 */
export function initializeI18n(): Promise<void> {
	if ("undefined" === typeof window) {
		return Promise.resolve();
	}

	if (initialized) {
		return Promise.resolve();
	}

	if (initializationPromise) {
		return initializationPromise;
	}

	initializationPromise = (async () => {
		const currentData = getPeakURLData();
		const fetchedData = hasLocaleData(currentData.i18n)
			? null
			: await fetchPeakURLData();
		const data = {
			...currentData,
			...(fetchedData || {}),
			favicon:
				undefined !== fetchedData?.favicon
					? fetchedData.favicon
					: currentData.favicon,
			i18n: fetchedData?.i18n || currentData.i18n,
		};
		const localeData = getLocaleDataFromCatalog(data.i18n);
		const domain = data.textDomain || TEXT_DOMAIN;
		const locale = data.locale || DEFAULT_LOCALE;
		const textDirection = data.textDirection || getLocaleDirection(locale);

		setLocaleData(localeData, domain);
		const nextData = updatePeakURLData({
			...data,
			locale,
			textDomain: domain,
			textDirection: setDocumentLocale(
				locale,
				data.htmlLang,
				textDirection
			),
		});

		applyDocumentFavicon(nextData.favicon);
		initialized = true;
	})().finally(() => {
		initializationPromise = null;
	});

	return initializationPromise;
}

/**
 * Translation helpers bound to PeakURL's active text domain.
 */
export const translate = <Text extends string>(
	text: Text
): TransformedText<Text> => wpTranslate(text, TEXT_DOMAIN);
export const translateWithContext = <Text extends string>(
	text: Text,
	context: string
): TransformedText<Text> => wpTranslateWithContext(text, context, TEXT_DOMAIN);
export const translatePlural = <Single extends string, Plural extends string>(
	single: Single,
	plural: Plural,
	count: number
): TransformedText<Single | Plural> =>
	wpTranslatePlural(single, plural, count, TEXT_DOMAIN);

export {
	sprintf,
	translate as __,
	translatePlural as _n,
	translateWithContext as _x,
};

export {
	getBaseLocale,
	getDocumentDirection,
	getFieldDirection,
	getLocaleDirection,
	isDocumentRtl,
	isRtlLocale,
	normalizeLocale,
} from "./direction";
export { getInstalledLanguageLabel } from "./languages";
export type * from "./types";
