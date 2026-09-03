const NAMED_ENTITIES: Record<string, string> = {
	"&amp;": "&",
	"&lt;": "<",
	"&gt;": ">",
	"&quot;": '"',
	"&#039;": "'",
	"&#39;": "'",
	"&apos;": "'",
	"&nbsp;": " ",
	"&hellip;": "…",
	"&mdash;": "—",
	"&ndash;": "–",
	"&ldquo;": '"',
	"&rdquo;": '"',
	"&lsquo;": "'",
	"&rsquo;": "'",
};

const HTML_ENTITY_REGEX = /&(?:[a-zA-Z]+|#\d+|#[xX][0-9a-fA-F]+);/g;

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
			/* Fall back to single-pass regex replacement when DOM parser is unavailable. */
		}
	}

	return value.replace(HTML_ENTITY_REGEX, (match) => {
		const named = NAMED_ENTITIES[match];
		if (undefined !== named) {
			return named;
		}

		if (match.startsWith("&#x") || match.startsWith("&#X")) {
			const code = parseInt(match.slice(3, -1), 16);
			return Number.isNaN(code) ? match : String.fromCharCode(code);
		}

		if (match.startsWith("&#")) {
			const code = parseInt(match.slice(2, -1), 10);
			return Number.isNaN(code) ? match : String.fromCharCode(code);
		}

		return match;
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
