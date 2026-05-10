function normalizeUrlInput(value: string | null | undefined): string {
	return typeof value === "string" ? value.trim() : "";
}

/**
 * Sanitize a dashboard URL for internal navigation or external linking.
 */
export function sanitizeUrl(value: string | null | undefined): string {
	const normalizedValue = normalizeUrlInput(value);

	if (!normalizedValue) {
		return "";
	}

	if (isRelativeUrl(normalizedValue)) {
		return normalizedValue;
	}

	if (normalizedValue.startsWith("//")) {
		return "";
	}

	try {
		const url = new URL(normalizedValue);

		if (url.protocol !== "http:" && url.protocol !== "https:") {
			return "";
		}

		return url.toString();
	} catch {
		return "";
	}
}

/**
 * Sanitize an image preview URL before assigning it to an image `src`.
 */
export function sanitizeImageUrl(value: string | null | undefined): string {
	const normalizedValue = normalizeUrlInput(value);

	if (!normalizedValue) {
		return "";
	}

	if (isRelativeUrl(normalizedValue)) {
		return normalizedValue;
	}

	if (normalizedValue.startsWith("//")) {
		return "";
	}

	try {
		const url = new URL(normalizedValue);

		if ("http:" === url.protocol || "https:" === url.protocol) {
			return url.toString();
		}

		if ("blob:" === url.protocol) {
			const blobOrigin = new URL(normalizedValue.slice("blob:".length));

			return "http:" === blobOrigin.protocol ||
				"https:" === blobOrigin.protocol
				? url.toString()
				: "";
		}

		return "";
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
