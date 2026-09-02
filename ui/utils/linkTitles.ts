/**
 * Decode HTML entities in a string.
 *
 * Handles standard named entities, decimal entities (&#...;), and hex entities (&#x...;).
 *
 * @param value - The raw string value.
 * @return The decoded string.
 */
export const decodeHtmlEntities = (value: unknown): string => {
	if ("string" !== typeof value) {
		return "";
	}

	if (!value.includes("&")) {
		return value;
	}

	if (typeof document !== "undefined") {
		try {
			const textarea = document.createElement("textarea");
			textarea.innerHTML = value;
			return textarea.value;
		} catch {
			/* Fall back to regex replacement when DOM parser is unavailable. */
		}
	}

	return value
		.replace(/&amp;/g, "&")
		.replace(/&lt;/g, "<")
		.replace(/&gt;/g, ">")
		.replace(/&quot;/g, '"')
		.replace(/&#039;|&#39;|&apos;/g, "'")
		.replace(/&nbsp;/g, " ")
		.replace(/&hellip;/g, "…")
		.replace(/&mdash;/g, "—")
		.replace(/&ndash;/g, "–")
		.replace(/&ldquo;|&rdquo;/g, '"')
		.replace(/&lsquo;|&rsquo;/g, "'")
		.replace(/&#(\d+);/g, (_, dec) => {
			const code = parseInt(dec, 10);
			return Number.isNaN(code) ? _ : String.fromCharCode(code);
		})
		.replace(/&#x([0-9a-fA-F]+);/g, (_, hex) => {
			const code = parseInt(hex, 16);
			return Number.isNaN(code) ? _ : String.fromCharCode(code);
		});
};

/**
 * Normalize a stored link title by decoding HTML entities and trimming surrounding whitespace.
 *
 * @param title - The raw title value.
 * @return The normalized title string.
 */
export const normalizeLinkTitle = (title: unknown): string => {
	if ("string" !== typeof title) {
		return "";
	}

	return decodeHtmlEntities(title).trim();
};

/**
 * Resolve the best visible title for a link with a caller-provided fallback.
 *
 * @param title    - The raw title value.
 * @param fallback - The string to return if the title is empty.
 * @return The display title.
 */
export const getLinkDisplayTitle = (
	title: unknown,
	fallback: string = ""
): string => normalizeLinkTitle(title) || fallback;
