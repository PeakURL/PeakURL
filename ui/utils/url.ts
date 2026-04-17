/**
 * Validate a dashboard URL to an allowed internal or absolute HTTP(S) target.
 */
export function escUrl(
	value: string | null | undefined
): string {
	const normalizedValue = 'string' === typeof value ? value.trim() : '';

	if (!normalizedValue) {
		return '';
	}

	if (normalizedValue.startsWith('/')) {
		return normalizedValue.startsWith('//') ? '' : normalizedValue;
	}

	try {
		const url = new URL(normalizedValue);

		return 'http:' === url.protocol || 'https:' === url.protocol
			? url.toString()
			: '';
	} catch {
		return '';
	}
}

/**
 * Returns whether a validated URL points to an internal dashboard path.
 */
export function isRelativeUrl(value: string): boolean {
	return value.startsWith('/');
}
