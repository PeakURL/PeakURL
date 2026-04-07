/**
 * Formats a date as a local `YYYY-MM-DD` value for date inputs.
 */
export function getLocalDateValue(date: Date = new Date()): string {
	const offset = date.getTimezoneOffset() * 60000;
	return new Date(date.getTime() - offset).toISOString().split('T')[0];
}

/**
 * Formats a date as a local `YYYY-MM-DDTHH:mm` value for datetime-local inputs.
 */
export function getLocalDateTimeValue(date: Date = new Date()): string {
	const offset = date.getTimezoneOffset() * 60000;
	return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

/**
 * Converts an ISO-like date string into a local datetime-local input value.
 */
export function toLocalDateTimeValue(dateString?: string | null): string {
	if (!dateString) {
		return '';
	}

	const date = new Date(dateString);

	if (Number.isNaN(date.getTime())) {
		return '';
	}

	return getLocalDateTimeValue(date);
}

/**
 * Converts a datetime-local input value into an ISO string.
 */
export function toIsoFromLocalDateTime(
	localDateTime?: string | null
): string | null {
	if (!localDateTime) {
		return null;
	}

	const date = new Date(localDateTime);

	if (Number.isNaN(date.getTime())) {
		return null;
	}

	return date.toISOString();
}

/**
 * Determines whether a datetime-local value resolves to a future instant.
 */
export function isFutureLocalDateTime(
	localDateTime?: string | null
): boolean {
	if (!localDateTime) {
		return true;
	}

	const date = new Date(localDateTime);

	if (Number.isNaN(date.getTime())) {
		return false;
	}

	return date.getTime() > Date.now();
}
