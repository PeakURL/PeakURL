/**
 * Normalize a stored link title by trimming surrounding whitespace.
 *
 * @param title - The raw title value.
 * @return The normalized title string.
 */
export const normalizeLinkTitle = (title: unknown): string => {
	if ("string" !== typeof title) {
		return "";
	}

	return title.trim();
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
