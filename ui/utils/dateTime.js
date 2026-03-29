export function getLocalDateValue(date = new Date()) {
	const offset = date.getTimezoneOffset() * 60000;
	return new Date(date.getTime() - offset).toISOString().split('T')[0];
}

export function getLocalDateTimeValue(date = new Date()) {
	const offset = date.getTimezoneOffset() * 60000;
	return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

export function toLocalDateTimeValue(dateString) {
	if (!dateString) {
		return '';
	}

	const date = new Date(dateString);

	if (Number.isNaN(date.getTime())) {
		return '';
	}

	return getLocalDateTimeValue(date);
}

export function toIsoFromLocalDateTime(localDateTime) {
	if (!localDateTime) {
		return null;
	}

	const date = new Date(localDateTime);

	if (Number.isNaN(date.getTime())) {
		return null;
	}

	return date.toISOString();
}

export function isFutureLocalDateTime(localDateTime) {
	if (!localDateTime) {
		return true;
	}

	const date = new Date(localDateTime);

	if (Number.isNaN(date.getTime())) {
		return false;
	}

	return date.getTime() > Date.now();
}
