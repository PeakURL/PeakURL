function normalizeUrlInput(value: string | null | undefined): string {
	return "string" === typeof value ? value.trim() : "";
}

/**
 * Sanitize a dashboard URL for internal navigation or external linking.
 */
export function escUrl(value: string | null | undefined): string {
	const normalizedValue = normalizeUrlInput(value);

	if (!normalizedValue) {
		return "";
	}

	if (isRelativeUrl(normalizedValue)) {
		return normalizedValue;
	}

	if (normalizedValue.startsWith("/")) {
		return "";
	}

	try {
		const url = new URL(normalizedValue);

		if ("http:" !== url.protocol && "https:" !== url.protocol) {
			return "";
		}

		return url.toString();
	} catch {
		return "";
	}
}

/**
 * Returns whether a URL points to a root-relative dashboard path.
 */
export function isRelativeUrl(value: string): boolean {
	return value.startsWith("/") && !value.startsWith("//");
}
