import { getSiteTimeZone } from "@/shared/dates";

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
	const match = value.match(
		/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/
	);

	if (!match) {
		return null;
	}

	return {
		year: Number(match[1]),
		month: Number(match[2]),
		day: Number(match[3]),
		hour: Number(match[4]),
		minute: Number(match[5]),
		second: Number(match[6] || 0),
	};
}

/**
 * Extract date parts in the given time zone (defaults to site time zone).
 *
 * @param date     - The Date object to format.
 * @param timeZone - The IANA time zone identifier.
 * @return The zoned parts or null if Intl fails.
 */
function getZonedParts(
	date: Date,
	timeZone: string = getSiteTimeZone()
): DateTimeParts | null {
	try {
		const parts = new Intl.DateTimeFormat("en-US", {
			timeZone,
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
 * Calculate the offset between UTC and the target time zone in milliseconds.
 *
 * @param date     - The reference date.
 * @param timeZone - The IANA time zone identifier.
 * @return The offset in milliseconds, or null if zoning fails.
 */
function getTimeZoneOffsetMs(
	date: Date,
	timeZone: string = getSiteTimeZone()
): number | null {
	const parts = getZonedParts(date, timeZone);

	if (!parts) {
		return null;
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
 * @param date     - The Date object.
 * @param timeZone - Optional IANA time zone identifier.
 * @return The formatted date string.
 */
export function getLocalDateValue(
	date: Date = new Date(),
	timeZone: string = getSiteTimeZone()
): string {
	const parts = getZonedParts(date, timeZone);

	if (!parts) {
		return "";
	}

	return toDateInputValue(parts);
}

/**
 * Format a date as a site-timezone `YYYY-MM-DDTHH:mm` datetime-local value.
 *
 * @param date     - The Date object.
 * @param timeZone - Optional IANA time zone identifier.
 * @return The formatted datetime-local string.
 */
export function getLocalDateTimeValue(
	date: Date = new Date(),
	timeZone: string = getSiteTimeZone()
): string {
	const parts = getZonedParts(date, timeZone);

	if (!parts) {
		return "";
	}

	return `${toDateInputValue(parts)}T${padDatePart(parts.hour)}:${padDatePart(
		parts.minute
	)}`;
}

/**
 * Convert an ISO-like date string into a site-timezone datetime-local value.
 *
 * @param dateString - The raw date string.
 * @param timeZone   - Optional IANA time zone identifier.
 * @return The formatted datetime-local value or an empty string.
 */
export function toLocalDateTimeValue(
	dateString?: string | null,
	timeZone: string = getSiteTimeZone()
): string {
	if (!dateString) {
		return "";
	}

	const date = new Date(dateString);

	if (Number.isNaN(date.getTime())) {
		return "";
	}

	return getLocalDateTimeValue(date, timeZone);
}

/**
 * Convert a site-timezone datetime-local input value into an ISO string.
 *
 * @param localDateTime - The datetime-local string.
 * @param timeZone      - Optional IANA time zone identifier.
 * @return The ISO string or null.
 */
export function toIsoFromLocalDateTime(
	localDateTime?: string | null,
	timeZone: string = getSiteTimeZone()
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
		parts.minute,
		parts.second
	);

	/*
	 * Iteratively resolve the UTC timestamp from the local wall-clock time
	 * by accounting for the time zone offset at the resulting instant.
	 */
	const offset = getTimeZoneOffsetMs(new Date(localUtcTime), timeZone);
	if (offset === null) {
		return null;
	}

	const firstPassTime = localUtcTime - offset;
	const secondOffset = getTimeZoneOffsetMs(new Date(firstPassTime), timeZone);
	if (secondOffset === null) {
		return null;
	}

	const timeValue =
		secondOffset === offset ? firstPassTime : localUtcTime - secondOffset;

	const date = new Date(timeValue);

	return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

/**
 * Determine whether a datetime-local value resolves to a future instant.
 *
 * @param localDateTime - The datetime-local string.
 * @param timeZone      - Optional IANA time zone identifier.
 * @return Whether the date is in the future.
 */
export function isFutureLocalDateTime(
	localDateTime?: string | null,
	timeZone: string = getSiteTimeZone()
): boolean {
	if (!localDateTime) {
		return true;
	}

	const isoDateTime = toIsoFromLocalDateTime(localDateTime, timeZone);

	if (!isoDateTime) {
		return false;
	}

	return new Date(isoDateTime).getTime() > Date.now();
}
