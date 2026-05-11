/**
 * The default flag emoji.
 */
const DEFAULT_COUNTRY_FLAG = "🌐";

/**
 * Return the flag emoji for a given country code.
 *
 * @param countryCode - The ISO 3166-1 alpha-2 country code.
 * @return The country flag emoji.
 */
export function getCountryFlagEmoji(countryCode?: string | null): string {
	const normalizedCountryCode = countryCode?.trim().toUpperCase();

	/*
	 * Return the default flag if the code is invalid, empty,
	 * or uses the placeholder "??".
	 */
	if (
		!normalizedCountryCode ||
		"??" === normalizedCountryCode ||
		!/^[A-Z]{2}$/.test(normalizedCountryCode)
	) {
		return DEFAULT_COUNTRY_FLAG;
	}

	/*
	 * Convert the characters to regional indicator symbols.
	 * 127397 is the offset for regional indicator symbol letters.
	 */
	const codePoints = normalizedCountryCode
		.split("")
		.map((character) => 127397 + character.charCodeAt(0));

	return String.fromCodePoint(...codePoints);
}
