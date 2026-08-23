import { getActiveTimeZone } from "./dateFormatting";

/**
 * Breakdown of date and time components.
 */
interface DateTimeParts {
	year: number;
	month: number;
	day: number;
	hour: number;
	minute: number;
	second: number;
}

/**
 * Pad a numeric date part with a leading zero if necessary.
 *
 * @param value - The number to pad.
 * @return The padded string.
 */
function padDatePart(value: number): string {
	return String(value).padStart(2, "0");
}

/**
 * Parse a `YYYY-MM-DDTHH:mm` datetime-local value.
 *
 * @param value - The input value string.
 * @return The parsed parts or null if invalid.
 */
function parseLocalDateTimeValue(value: string): DateTimeParts | null {
	const match = value.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);

	if (!match) {
		return null;
	}

	return {
		year: Number(match[1]),
		month: Number(match[2]),
		day: Number(match[3]),
		hour: Number(match[4]),
		minute: Number(match[5]),
		second: 0,
	};
}

/**
 * Extract date parts in the active site time zone.
 *
 * @param date - The Date object to format.
 * @return The zoned parts or null if Intl fails.
 */
function getZonedParts(date: Date): DateTimeParts | null {
	try {
		const parts = new Intl.DateTimeFormat("en-US", {
			timeZone: getActiveTimeZone(),
			year: "numeric",
			month: "2-digit",
			day: "2-digit",
			hour: "2-digit",
			minute: "2-digit",
			second: "2-digit",
			hourCycle: "h23",
		}).formatToParts(date);

		const getPart = (type: Intl.DateTimeFormatPartTypes) =>
			Number(parts.find((part) => part.type === type)?.value || 0);

		return {
			year: getPart("year"),
			month: getPart("month"),
			day: getPart("day"),
			hour: getPart("hour"),
			minute: getPart("minute"),
			second: getPart("second"),
		};
	} catch {
		return null;
	}
}

/**
 * Calculate the offset between UTC and the site time zone in milliseconds.
 *
 * @param date - The reference date.
 * @return The offset in milliseconds.
 */
function getTimeZoneOffsetMs(date: Date): number {
	const parts = getZonedParts(date);

	/* Fall back to the local browser offset if zoning fails. */
	if (!parts) {
		return -date.getTimezoneOffset() * 60000;
	}

	const zonedUtcTime = Date.UTC(
		parts.year,
		parts.month - 1,
		parts.day,
		parts.hour,
		parts.minute,
		parts.second
	);

	return zonedUtcTime - date.getTime();
}

/**
 * Convert date parts into a YYYY-MM-DD input value.
 *
 * @param parts - The date parts.
 * @return The formatted date string.
 */
function toDateInputValue(parts: DateTimeParts): string {
	return [
		String(parts.year).padStart(4, "0"),
		padDatePart(parts.month),
		padDatePart(parts.day),
	].join("-");
}

/**
 * Format a date as a site-timezone `YYYY-MM-DD` value for date inputs.
 *
 * @param date - The Date object.
 * @return The formatted date string.
 */
export function getLocalDateValue(date: Date = new Date()): string {
	const parts = getZonedParts(date);

	if (!parts) {
		const offset = date.getTimezoneOffset() * 60000;
		return (
			new Date(date.getTime() - offset).toISOString().split("T")[0] || ""
		);
	}

	return toDateInputValue(parts);
}

/**
 * Format a date as a site-timezone `YYYY-MM-DDTHH:mm` datetime-local value.
 *
 * @param date - The Date object.
 * @return The formatted datetime-local string.
 */
export function getLocalDateTimeValue(date: Date = new Date()): string {
	const parts = getZonedParts(date);

	if (!parts) {
		const offset = date.getTimezoneOffset() * 60000;
		return new Date(date.getTime() - offset).toISOString().slice(0, 16);
	}

	return `${toDateInputValue(parts)}T${padDatePart(parts.hour)}:${padDatePart(
		parts.minute
	)}`;
}

/**
 * Convert an ISO-like date string into a site-timezone datetime-local value.
 *
 * @param dateString - The raw date string.
 * @return The formatted datetime-local value or an empty string.
 */
export function toLocalDateTimeValue(dateString?: string | null): string {
	if (!dateString) {
		return "";
	}

	const date = new Date(dateString);

	if (Number.isNaN(date.getTime())) {
		return "";
	}

	return getLocalDateTimeValue(date);
}

/**
 * Convert a site-timezone datetime-local input value into an ISO string.
 *
 * @param localDateTime - The datetime-local string.
 * @return The ISO string or null.
 */
export function toIsoFromLocalDateTime(
	localDateTime?: string | null
): string | null {
	if (!localDateTime) {
		return null;
	}

	const parts = parseLocalDateTimeValue(localDateTime);

	if (!parts) {
		return null;
	}

	const localUtcTime = Date.UTC(
		parts.year,
		parts.month - 1,
		parts.day,
		parts.hour,
		parts.minute
	);

	/*
	 * Iteratively resolve the UTC timestamp from the local wall-clock time
	 * by accounting for the time zone offset at the resulting instant.
	 */
	const offset = getTimeZoneOffsetMs(new Date(localUtcTime));
	const firstPassTime = localUtcTime - offset;
	const secondOffset = getTimeZoneOffsetMs(new Date(firstPassTime));
	const timeValue =
		secondOffset === offset ? firstPassTime : localUtcTime - secondOffset;

	const date = new Date(timeValue);

	return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

/**
 * Determine whether a datetime-local value resolves to a future instant.
 *
 * @param localDateTime - The datetime-local string.
 * @return Whether the date is in the future.
 */
export function isFutureLocalDateTime(localDateTime?: string | null): boolean {
	if (!localDateTime) {
		return true;
	}

	const isoDateTime = toIsoFromLocalDateTime(localDateTime);

	if (!isoDateTime) {
		return false;
	}

	return new Date(isoDateTime).getTime() > Date.now();
}
