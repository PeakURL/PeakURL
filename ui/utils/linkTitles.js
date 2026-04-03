export const normalizeLinkTitle = (title) => {
	if (typeof title !== 'string') {
		return '';
	}

	return title.trim();
};

export const getLinkDisplayTitle = (title, fallback = '') =>
	normalizeLinkTitle(title) || fallback;
