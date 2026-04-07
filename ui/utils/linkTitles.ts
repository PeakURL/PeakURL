/**
 * Normalizes a stored link title by trimming surrounding whitespace.
 */
export const normalizeLinkTitle = (title: unknown): string => {
	if ('string' !== typeof title) {
		return '';
	}

	return title.trim();
};

/**
 * Resolves the best visible title for a link with a caller-provided fallback.
 */
export const getLinkDisplayTitle = (
	title: unknown,
	fallback: string = ''
): string => normalizeLinkTitle(title) || fallback;
