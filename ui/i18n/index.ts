import {
	__ as wpTranslate,
	_n as wpTranslatePlural,
	_x as wpTranslateWithContext,
	setLocaleData,
	sprintf,
} from "@wordpress/i18n";

import { API_ROUTES } from "@/api";
import { API_CLIENT_BASE_URL } from "@/constants";
import {
	getPeakURLData,
	toPeakURLData,
	updatePeakURLData,
	type PeakURLData,
} from "@/data";
import { getManagedFaviconUrl } from "@/utils";
import { getLocaleDirection } from "./direction";
import type {
	FaviconData,
	I18nCatalog,
	LocaleMessageMap,
	TextDirection,
} from "./types";

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
export const TEXT_DOMAIN =
	getPeakURLData().textDomain || DEFAULT_TEXT_DOMAIN;

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
 * Applies the active locale to the document root for accessibility and
 * browser-native formatting behavior.
 */
function setDocumentLocale(
	locale?: string,
	htmlLang?: string,
	textDirection?: TextDirection
): TextDirection {
	if ("undefined" === typeof document) {
		return textDirection || getLocaleDirection(locale || htmlLang);
	}

	const documentLang =
		htmlLang || locale?.replace(/_/g, "-").toLowerCase() || "en";
	const textDirectionValue =
		textDirection || getLocaleDirection(locale || documentLang);
	document.documentElement.lang = documentLang;
	document.documentElement.dir = textDirectionValue;

	if (document.body) {
		document.body.dir = textDirectionValue;
	}

	return textDirectionValue;
}

function removeManagedFaviconTags(): void {
	if ("undefined" === typeof document) {
		return;
	}

	document.head
		.querySelectorAll("[data-peakurl-favicon]")
		.forEach((node) => node.remove());
}

function appendManagedHeadTag(
	tagName: "link" | "meta",
	attributes: Record<string, string>
): void {
	const element = document.createElement(tagName);
	element.setAttribute("data-peakurl-favicon", "1");

	Object.entries(attributes).forEach(([key, value]) => {
		if (value) {
			element.setAttribute(key, value);
		}
	});

	document.head.appendChild(element);
}

/**
 * Applies the current site favicon metadata to the document head.
 */
export function applyDocumentFavicon(
	favicon?: FaviconData | null
): void {
	if ("undefined" === typeof document) {
		return;
	}

	removeManagedFaviconTags();

	if (!favicon?.configured) {
		return;
	}

	const sizes =
		"string" === typeof favicon.sizes && favicon.sizes.trim()
			? favicon.sizes.trim()
			: "";
	const iconUrl = getManagedFaviconUrl("favicon.png", favicon.updatedAt);
	const shortcutIconUrl = getManagedFaviconUrl(
		"favicon.ico",
		favicon.updatedAt
	);
	const appleTouchUrl = getManagedFaviconUrl(
		"apple-touch-icon.png",
		favicon.updatedAt
	);
	const manifestUrl = getManagedFaviconUrl(
		"site.webmanifest",
		favicon.updatedAt
	);

	if (!iconUrl) {
		return;
	}

	appendManagedHeadTag("link", {
		rel: "icon",
		type: "image/png",
		href: iconUrl,
		...(sizes ? { sizes } : {}),
	});

	appendManagedHeadTag("link", {
		rel: "shortcut icon",
		type: "image/png",
		href: shortcutIconUrl || iconUrl,
	});

	if (appleTouchUrl) {
		appendManagedHeadTag("link", {
			rel: "apple-touch-icon",
			href: appleTouchUrl,
		});
	}

	if (manifestUrl) {
		appendManagedHeadTag("link", {
			rel: "manifest",
			href: manifestUrl,
		});
	}

	const siteName = getPeakURLData().siteName;

	if (siteName) {
		appendManagedHeadTag("meta", {
			name: "apple-mobile-web-app-title",
			content: siteName,
		});
	}
}

/**
 * Fetches the dashboard translation payload from the dashboard API.
 */
async function fetchPeakURLData(): Promise<PeakURLData | null> {
	try {
		const response = await fetch(
			`${API_CLIENT_BASE_URL}/${API_ROUTES.system.i18n}`,
			{
				credentials: "include",
				headers: {
					Accept: "application/json",
				},
			}
		);

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
		const textDirection =
			data.textDirection ||
			getLocaleDirection(locale);

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
