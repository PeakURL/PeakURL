import { getActiveTimeZone } from "./dateFormatting";

interface DateTimeParts {
	year: number;
	month: number;
	day: number;
	hour: number;
	minute: number;
	second: number;
}

function padDatePart(value: number): string {
	return String(value).padStart(2, "0");
}

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

function getTimeZoneOffsetMs(date: Date): number {
	const parts = getZonedParts(date);

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

function toDateInputValue(parts: DateTimeParts): string {
	return [
		String(parts.year).padStart(4, "0"),
		padDatePart(parts.month),
		padDatePart(parts.day),
	].join("-");
}

/**
 * Formats a date as a site-timezone `YYYY-MM-DD` value for date inputs.
 */
export function getLocalDateValue(date: Date = new Date()): string {
	const parts = getZonedParts(date);

	if (!parts) {
		const offset = date.getTimezoneOffset() * 60000;
		return new Date(date.getTime() - offset).toISOString().split("T")[0];
	}

	return toDateInputValue(parts);
}

/**
 * Formats a date as a site-timezone `YYYY-MM-DDTHH:mm` datetime-local value.
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
 * Converts an ISO-like date string into a site-timezone datetime-local value.
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
 * Converts a site-timezone datetime-local input value into an ISO string.
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
	const offset = getTimeZoneOffsetMs(new Date(localUtcTime));
	const firstPassTime = localUtcTime - offset;
	const secondOffset = getTimeZoneOffsetMs(new Date(firstPassTime));
	const resolvedTime =
		secondOffset === offset ? firstPassTime : localUtcTime - secondOffset;
	const date = new Date(resolvedTime);

	return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

/**
 * Determines whether a datetime-local value resolves to a future instant.
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
