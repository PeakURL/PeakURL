import type { InstalledLanguage } from "./types";
import { getBaseLocale, normalizeLocale } from "./direction";

function getVariantCount(
	locale: string,
	availableLanguages: InstalledLanguage[]
): number {
	const baseLocale = getBaseLocale(locale);

	if (!baseLocale) {
		return 1;
	}

	return (
		availableLanguages.filter(
			(language) => getBaseLocale(language?.locale) === baseLocale
		).length || 1
	);
}

function normalizeDisplayLabel(label?: string | null): string {
	if (!label) {
		return "";
	}

	const normalizedLabel = label.replace(/\s+/g, " ").trim();

	if (!normalizedLabel) {
		return "";
	}

	const [firstCharacter, ...remainingCharacters] =
		Array.from(normalizedLabel);

	if (!firstCharacter) {
		return normalizedLabel;
	}

	return firstCharacter.toLocaleUpperCase() + remainingCharacters.join("");
}

function getRegionCode(locale: string): string {
	const normalizedLocale = normalizeLocale(locale);

	if (!normalizedLocale) {
		return "";
	}

	if ("undefined" !== typeof Intl && "Locale" in Intl) {
		try {
			return new Intl.Locale(normalizedLocale).region || "";
		} catch {
			return "";
		}
	}

	const localeParts = normalizedLocale.split("-");

	for (let index = localeParts.length - 1; index >= 1; index -= 1) {
		const value = localeParts[index];

		if (
			value &&
			(/^[A-Za-z]{2}$/.test(value) || /^[0-9]{3}$/.test(value))
		) {
			return value.toUpperCase();
		}
	}

	return "";
}

function getDisplayName(
	locale: string,
	type: "language" | "region",
	code: string
): string {
	if (
		!locale ||
		!code ||
		"undefined" === typeof Intl ||
		!("DisplayNames" in Intl)
	) {
		return "";
	}

	try {
		return (
			new Intl.DisplayNames([locale], {
				type,
			}).of(code) || ""
		);
	} catch {
		return "";
	}
}

export function getInstalledLanguageLabel(
	language: InstalledLanguage,
	availableLanguages: InstalledLanguage[] = []
): string {
	const locale = language?.locale || "";

	if (!locale) {
		return language?.label || language?.englishLabel || "";
	}

	const normalizedLocale = normalizeLocale(locale);
	const languageCode = getBaseLocale(locale);
	const regionCode = getRegionCode(locale);
	const nativeLanguage = normalizeDisplayLabel(
		getDisplayName(normalizedLocale, "language", languageCode)
	);
	const nativeTerritory = normalizeDisplayLabel(
		getDisplayName(normalizedLocale, "region", regionCode)
	);
	const showTerritory =
		"en_US" === locale || getVariantCount(locale, availableLanguages) > 1;

	if (!nativeLanguage) {
		return language?.label || language?.englishLabel || locale;
	}

	if (showTerritory && nativeTerritory) {
		return `${nativeLanguage} (${nativeTerritory})`;
	}

	return nativeLanguage;
}
