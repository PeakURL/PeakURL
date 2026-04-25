import {
	__ as wpTranslate,
	_n as wpTranslatePlural,
	_x as wpTranslateWithContext,
	setLocaleData,
	sprintf,
} from "@wordpress/i18n";

import { API_CLIENT_BASE_URL } from "@/constants";
import { buildManagedFaviconUrl } from "@/utils";
import { getLocaleDirection } from "./direction";
import type {
	RuntimeFaviconPayload,
	LocaleMessageMap,
	RuntimeI18nCatalog,
	RuntimeI18nPayload,
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
	"undefined" !== typeof window && window.__PEAKURL_TEXT_DOMAIN__
		? window.__PEAKURL_TEXT_DOMAIN__
		: DEFAULT_TEXT_DOMAIN;

function isObjectRecord(value: unknown): value is Record<string, unknown> {
	return "object" === typeof value && null !== value;
}

function isLocaleMessageMap(value: unknown): value is LocaleMessageMap {
	return isObjectRecord(value);
}

function isRuntimeI18nCatalog(value: unknown): value is RuntimeI18nCatalog {
	return isObjectRecord(value);
}

function isRuntimeFaviconPayload(
	value: unknown
): value is RuntimeFaviconPayload {
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
	if ("undefined" === typeof document) {
		return textDirection || getLocaleDirection(locale || htmlLang);
	}

	const resolvedLang =
		htmlLang || locale?.replace(/_/g, "-").toLowerCase() || "en";
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
	return "string" === typeof value ? value : undefined;
}

function readRuntimeCatalog(
	record: Record<string, unknown>,
	key: string
): RuntimeI18nCatalog | undefined {
	const value = record[key];
	return isRuntimeI18nCatalog(value) ? value : undefined;
}

function readRuntimeFavicon(
	record: Record<string, unknown>,
	key: string
): RuntimeFaviconPayload | undefined {
	const value = record[key];
	return isRuntimeFaviconPayload(value) ? value : undefined;
}

function readTextDirectionProperty(
	record: Record<string, unknown>,
	key: string
): TextDirection | undefined {
	const value = record[key];

	return "rtl" === value || "ltr" === value ? value : undefined;
}

function readBooleanProperty(
	record: Record<string, unknown>,
	key: string
): boolean | undefined {
	const value = record[key];
	return "boolean" === typeof value ? value : undefined;
}

function readTimeFormatProperty(
	record: Record<string, unknown>,
	key: string
): "12" | "24" | undefined {
	const value = readStringProperty(record, key);

	return "12" === value || "24" === value ? value : undefined;
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
			readRuntimeCatalog(payloadData, "catalog") ||
			(isRuntimeI18nCatalog(payloadData) ? payloadData : undefined),
		locale: readStringProperty(payloadData, "locale"),
		htmlLang: readStringProperty(payloadData, "htmlLang"),
		textDirection: readTextDirectionProperty(payloadData, "textDirection"),
		isRtl: readBooleanProperty(payloadData, "isRtl"),
		textDomain: readStringProperty(payloadData, "textDomain"),
		timezone: readStringProperty(payloadData, "timezone"),
		timeFormat: readTimeFormatProperty(payloadData, "timeFormat"),
		favicon: readRuntimeFavicon(payloadData, "favicon"),
	};
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
	favicon?: RuntimeFaviconPayload | null
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
	const iconUrl = buildManagedFaviconUrl("favicon.png", favicon.updatedAt);
	const shortcutIconUrl = buildManagedFaviconUrl(
		"favicon.ico",
		favicon.updatedAt
	);
	const appleTouchUrl = buildManagedFaviconUrl(
		"apple-touch-icon.png",
		favicon.updatedAt
	);
	const manifestUrl = buildManagedFaviconUrl(
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

	if (window.__PEAKURL_SITE_NAME__) {
		appendManagedHeadTag("meta", {
			name: "apple-mobile-web-app-title",
			content: window.__PEAKURL_SITE_NAME__,
		});
	}
}

/**
 * Fetches the runtime translation payload from the dashboard API.
 */
async function fetchRuntimeCatalog(): Promise<RuntimeI18nPayload | null> {
	try {
		const response = await fetch(`${API_CLIENT_BASE_URL}/system/i18n`, {
			credentials: "include",
			headers: {
				Accept: "application/json",
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
		const runtimeCatalog = window.__PEAKURL_I18N__;
		const payload = isRuntimeI18nCatalog(runtimeCatalog)
			? {
					catalog: runtimeCatalog,
					locale: window.__PEAKURL_LOCALE__ || DEFAULT_LOCALE,
					textDirection:
						window.__PEAKURL_TEXT_DIRECTION__ ||
						getLocaleDirection(
							window.__PEAKURL_LOCALE__ || DEFAULT_LOCALE
						),
					textDomain: window.__PEAKURL_TEXT_DOMAIN__ || TEXT_DOMAIN,
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
			(payload?.isRtl ? "rtl" : undefined) ||
			window.__PEAKURL_TEXT_DIRECTION__ ||
			getLocaleDirection(locale);

		setLocaleData(localeData, domain);
		window.__PEAKURL_TEXT_DIRECTION__ = setDocumentLocale(
			locale,
			payload?.htmlLang,
			textDirection
		);
		window.__PEAKURL_FAVICON__ =
			payload?.favicon || window.__PEAKURL_FAVICON__;
		window.__PEAKURL_TIMEZONE__ =
			payload?.timezone || window.__PEAKURL_TIMEZONE__;
		window.__PEAKURL_TIME_FORMAT__ =
			payload?.timeFormat || window.__PEAKURL_TIME_FORMAT__;
		applyDocumentFavicon(window.__PEAKURL_FAVICON__);

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
