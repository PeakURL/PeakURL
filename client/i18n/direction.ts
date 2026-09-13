import { getPeakURLData } from "@/data";

import type { TextDirection } from "./types";

const RTL_BASE_LOCALES = new Set([
	"ar",
	"arc",
	"azb",
	"ckb",
	"dv",
	"fa",
	"he",
	"ps",
	"sd",
	"ug",
	"ur",
	"yi",
]);

export function normalizeLocale(locale?: string): string {
	return typeof locale === "string" ? locale.replace(/_/g, "-").trim() : "";
}

export function getBaseLocale(locale?: string): string {
	const normalizedLocale = normalizeLocale(locale);
	return normalizedLocale.split("-")[0]?.toLowerCase() || "";
}

export function isRtlLocale(locale?: string): boolean {
	return RTL_BASE_LOCALES.has(getBaseLocale(locale));
}

export function getLocaleDirection(locale?: string): TextDirection {
	return isRtlLocale(locale) ? "rtl" : "ltr";
}

export function getDocumentDirection(): TextDirection {
	const peakurlData = getPeakURLData();

	if (
		"rtl" === peakurlData.textDirection ||
		"ltr" === peakurlData.textDirection
	) {
		return peakurlData.textDirection;
	}

	if (
		"undefined" !== typeof document &&
		("rtl" === document.documentElement?.dir ||
			"ltr" === document.documentElement?.dir)
	) {
		return document.documentElement.dir as TextDirection;
	}

	return getLocaleDirection(
		peakurlData.locale ||
			("undefined" !== typeof document
				? document.documentElement?.lang
				: "")
	);
}

export function isDocumentRtl(): boolean {
	return "rtl" === getDocumentDirection();
}

interface FieldDirectionOptions {
	fallbackDirection?: TextDirection;
	valueDirection?: TextDirection;
	explicitDirection?: string;
}

export function getFieldDirection({
	fallbackDirection = getDocumentDirection(),
	valueDirection,
	explicitDirection,
}: FieldDirectionOptions): TextDirection {
	if ("rtl" === explicitDirection || "ltr" === explicitDirection) {
		return explicitDirection;
	}

	return valueDirection || fallbackDirection;
}
