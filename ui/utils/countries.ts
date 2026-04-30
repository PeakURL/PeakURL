const DEFAULT_COUNTRY_FLAG = "🌐";

export function getCountryFlagEmoji(countryCode?: string | null): string {
	const normalizedCountryCode = countryCode?.trim().toUpperCase();

	if (
		!normalizedCountryCode ||
		"??" === normalizedCountryCode ||
		!/^[A-Z]{2}$/.test(normalizedCountryCode)
	) {
		return DEFAULT_COUNTRY_FLAG;
	}

	const codePoints = normalizedCountryCode
		.split("")
		.map((character) => 127397 + character.charCodeAt(0));

	return String.fromCodePoint(...codePoints);
}
