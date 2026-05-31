import { PEAKURL_VERSION } from "@/constants";
import { getPeakURLData } from "@/data";
import { getLocaleDirection } from "@/i18n/direction";
import type { FaviconData, TextDirection } from "@/i18n/types";
import { getManagedFaviconUrl } from "./favicon";

/**
 * Apply the active locale metadata to the document root.
 *
 * This keeps browser-native direction, language, and accessibility behavior in
 * sync with the locale resolved by PHP and the dashboard i18n loader.
 */
export function setDocumentLocale(
	locale?: string,
	htmlLang?: string,
	textDirection?: TextDirection
): TextDirection {
	if ("undefined" === typeof document) {
		return textDirection || getLocaleDirection(locale || htmlLang);
	}

	const documentLang =
		htmlLang || locale?.replace(/_/g, "-").toLowerCase() || "en";
	const direction =
		textDirection || getLocaleDirection(locale || documentLang);

	document.documentElement.lang = documentLang;
	document.documentElement.dir = direction;

	if (document.body) {
		document.body.dir = direction;
	}

	return direction;
}

/**
 * Remove all favicon/meta tags owned by PeakURL before writing fresh values.
 */
function removeManagedFaviconTags(): void {
	if ("undefined" === typeof document) {
		return;
	}

	document.head
		.querySelectorAll("[data-peakurl-favicon]")
		.forEach((node) => node.remove());
}

/**
 * Append a managed head tag and mark it for future favicon refreshes.
 */
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
 * Apply the current site favicon metadata to the managed document-head tags.
 *
 * PHP-rendered favicon tags use the same `data-peakurl-favicon` marker, so the
 * dashboard can replace stale tags after settings are saved.
 */
export function applyDocumentFavicon(favicon?: FaviconData | null): void {
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
 * Apply the generator meta tag for the current install if missing.
 *
 * flows before the PHP backend can inject the `<head>` block.
 */
export function addGeneratorTag(): void {
	if ("undefined" === typeof document) {
		return;
	}

	if (!document.querySelector('meta[name="generator"]')) {
		const meta = document.createElement("meta");
		meta.name = "generator";
		meta.content = `PeakURL ${PEAKURL_VERSION}`;
		document.head.appendChild(meta);
	}
}
