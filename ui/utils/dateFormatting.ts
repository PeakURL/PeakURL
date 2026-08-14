import { getDataString, getPeakURLData } from "@/data";
import type { PeakURLData } from "@/data";

const SECOND_MS = 1000;
const MINUTE_MS = 60 * SECOND_MS;
const HOUR_MS = 60 * MINUTE_MS;
const DAY_MS = 24 * HOUR_MS;
const WEEK_MS = 7 * DAY_MS;

/* Thresholds for relative time unit promotion. */
const SECOND_TO_MINUTE_THRESHOLD = 45;
const MINUTE_TO_HOUR_THRESHOLD = 45;
const HOUR_TO_DAY_THRESHOLD = 22;
const DAY_TO_WEEK_THRESHOLD = 6;
const WEEK_TO_MONTH_THRESHOLD = 4;

const DEFAULT_LOCALE = "en-US";
const DEFAULT_TIMEZONE = "UTC";

/**
 * Supported relative time units.
 */
type RelativeTimeUnit =
	"second" | "minute" | "hour" | "day" | "week" | "month" | "year";

/**
 * Safely convert a value to a Date object.
 *
 * @param value - The raw date value.
 * @return The Date object or null if invalid.
 */
function toDate(value: string | number | Date | null | undefined): Date | null {
	if (value instanceof Date) {
		return Number.isNaN(value.getTime()) ? null : new Date(value.getTime());
	}

	if (typeof value === "string" || typeof value === "number") {
		const date = new Date(value);
		return Number.isNaN(date.getTime()) ? null : date;
	}

	return null;
}

/**
 * Parse a YYYY-MM-DD string into a UTC Date object.
 *
 * @param value - The date string.
 * @return The Date object or null if invalid.
 */
function toDateOnly(value: string | null | undefined): Date | null {
	const match = value?.match(/^(\d{4})-(\d{2})-(\d{2})$/);

	if (!match) {
		return null;
	}

	const year = Number(match[1]);
	const month = Number(match[2]);
	const day = Number(match[3]);
	const date = new Date(Date.UTC(year, month - 1, day));

	return Number.isNaN(date.getTime()) ? null : date;
}

/**
 * Resolve the active locale from the environment.
 *
 * @param data - Optional app data to avoid redundant parsing.
 * @return The BCP 47 locale string.
 */
export function getActiveLocale(data?: PeakURLData): string {
	const peakurlData = data ?? getPeakURLData();
	const appLocale = getDataString(peakurlData.locale);

	/* Prefer the server-provided locale if available. */
	if (appLocale) {
		return appLocale.replace(/_/g, "-");
	}

	/* Fall back to the document language attribute. */
	const documentLocale =
		typeof document === "undefined"
			? null
			: getDataString(document.documentElement?.lang);

	if (documentLocale) {
		return documentLocale;
	}

	return DEFAULT_LOCALE;
}

/**
 * Resolve the active time zone from the environment.
 *
 * @param data - Optional app data to avoid redundant parsing.
 * @return The IANA time zone identifier.
 */
export function getActiveTimeZone(data?: PeakURLData): string {
	const peakurlData = data ?? getPeakURLData();
	const timezone = getDataString(peakurlData.timezone);

	return timezone || DEFAULT_TIMEZONE;
}

/**
 * Resolve the active time format preference (12/24 hour).
 *
 * @param data - Optional app data to avoid redundant parsing.
 * @return The time format identifier.
 */
function getActiveTimeFormat(data?: PeakURLData): "12" | "24" {
	const peakurlData = data ?? getPeakURLData();
	const timeFormat = getDataString(peakurlData.timeFormat);

	if (timeFormat === "24") {
		return "24";
	}

	return "12";
}

/**
 * Check if formatting options include any visual display configuration.
 *
 * @param options - The formatting options.
 * @return Whether display options are present.
 */
function hasDateTimeDisplayOption(
	options: Intl.DateTimeFormatOptions
): boolean {
	return [
		"weekday",
		"era",
		"year",
		"month",
		"day",
		"dayPeriod",
		"hour",
		"minute",
		"second",
		"fractionalSecondDigits",
		"timeZoneName",
		"dateStyle",
		"timeStyle",
	].some((key) => key in options);
}

/**
 * Determine if seconds should be included in the formatted output.
 *
 * @param options - The formatting options.
 * @return Whether to include seconds.
 */
function shouldIncludeSeconds(options: Intl.DateTimeFormatOptions): boolean {
	const secondsConfigured =
		"second" in options || "fractionalSecondDigits" in options;
	const hasTimeStyle = "timeStyle" in options;
	const hasTimeFields = "hour" in options || "minute" in options;

	return !secondsConfigured && !hasTimeStyle && hasTimeFields;
}

/**
 * Normalize DateTimeFormat options with site-wide preferences.
 *
 * @param options - The raw formatting options.
 * @param data    - Optional app data to avoid redundant parsing.
 * @return The normalized options.
 */
function createDateTimeOptions(
	options: Intl.DateTimeFormatOptions,
	data?: PeakURLData
): Intl.DateTimeFormatOptions {
	const timeFormat = getActiveTimeFormat(data);
	const includeSeconds = shouldIncludeSeconds(options);
	const dateOptions: Intl.DateTimeFormatOptions = {
		timeZone: getActiveTimeZone(data),
		...(hasDateTimeDisplayOption(options)
			? options
			: { dateStyle: "medium", timeStyle: "medium" }),
	};

	/* Apply global 12/24 hour preference. */
	if (timeFormat === "12") {
		dateOptions.hour12 = true;
	} else if (timeFormat === "24") {
		dateOptions.hour12 = false;
	}

	/* Include seconds by default if time fields are present but seconds are omitted. */
	if (includeSeconds) {
		dateOptions.second = "2-digit";
	}

	return dateOptions;
}

/**
 * Return a YYYY-MM-DD key for a date in the active time zone.
 *
 * @param value - The raw date value.
 * @return The zoned date key string.
 */
export function getZonedDateKey(
	value: string | number | Date | null | undefined
): string {
	const targetDate = toDate(value);

	if (!targetDate) {
		return "";
	}

	const peakurlData = getPeakURLData();

	try {
		/* Use Intl to extract date parts in the target time zone. */
		const parts = new Intl.DateTimeFormat("en-US", {
			timeZone: getActiveTimeZone(peakurlData),
			year: "numeric",
			month: "2-digit",
			day: "2-digit",
		}).formatToParts(targetDate);

		const year = parts.find((part) => part.type === "year")?.value;
		const month = parts.find((part) => part.type === "month")?.value;
		const day = parts.find((part) => part.type === "day")?.value;

		return year && month && day ? `${year}-${month}-${day}` : "";
	} catch {
		/* Fall back to UTC ISO string if Intl fails. */
		return targetDate.toISOString().slice(0, 10);
	}
}

/**
 * Format a date-only `YYYY-MM-DD` value without shifting it across zones.
 *
 * @param value   - The YYYY-MM-DD date string.
 * @param options - Formatting options.
 * @return The localized date string.
 */
export function formatDateOnly(
	value: string | null | undefined,
	options: Intl.DateTimeFormatOptions = { dateStyle: "medium" }
): string {
	const date = toDateOnly(value);

	if (!date) {
		return value || "";
	}

	const peakurlData = getPeakURLData();

	try {
		return new Intl.DateTimeFormat(getActiveLocale(peakurlData), {
			timeZone: "UTC",
			...options,
		}).format(date);
	} catch {
		return value || "";
	}
}

/**
 * Return the number of days in a month.
 *
 * @param year  - Full year (e.g., 2026).
 * @param month - Zero-based month index.
 * @return The number of days in the month.
 */
function daysInMonth(year: number, month: number): number {
	return new Date(year, month + 1, 0).getDate();
}

/**
 * Add months to a date while preserving month-end alignment.
 *
 * @param date  - The starting date.
 * @param count - The number of months to add.
 * @return The resulting date.
 */
function addMonths(date: Date, count: number): Date {
	const result = new Date(date.getTime());
	const day = result.getDate();

	result.setDate(1);
	result.setMonth(result.getMonth() + count);
	result.setDate(
		Math.min(day, daysInMonth(result.getFullYear(), result.getMonth()))
	);

	return result;
}

/**
 * Add years to a date while preserving month-end alignment.
 *
 * @param date  - The starting date.
 * @param count - The number of years to add.
 * @return The resulting date.
 */
function addYears(date: Date, count: number): Date {
	const result = new Date(date.getTime());
	const day = result.getDate();

	result.setDate(1);
	result.setFullYear(result.getFullYear() + count);
	result.setDate(
		Math.min(day, daysInMonth(result.getFullYear(), result.getMonth()))
	);

	return result;
}

/**
 * Calculate the number of whole months between two dates.
 *
 * @param startDate - The starting date.
 * @param endDate   - The ending date.
 * @return The number of whole months.
 */
function wholeMonths(startDate: Date, endDate: Date): number {
	let count =
		(endDate.getFullYear() - startDate.getFullYear()) * 12 +
		endDate.getMonth() -
		startDate.getMonth();

	if (addMonths(startDate, count).getTime() > endDate.getTime()) {
		count -= 1;
	}

	return Math.max(0, count);
}

/**
 * Calculate the number of whole years between two dates.
 *
 * @param startDate - The starting date.
 * @param endDate   - The ending date.
 * @return The number of whole years.
 */
function wholeYears(startDate: Date, endDate: Date): number {
	let count = endDate.getFullYear() - startDate.getFullYear();

	if (addYears(startDate, count).getTime() > endDate.getTime()) {
		count -= 1;
	}

	return Math.max(0, count);
}

/**
 * Calculate a rounded calendar unit difference.
 *
 * @param targetDate - The target date.
 * @param nowDate    - The reference date.
 * @param unit       - The unit to calculate (month/year).
 * @return The rounded count.
 */
function roundedCalendarUnit(
	targetDate: Date,
	nowDate: Date,
	unit: "month" | "year"
): number {
	const sign = targetDate.getTime() >= nowDate.getTime() ? 1 : -1;
	const startDate = sign > 0 ? nowDate : targetDate;
	const endDate = sign > 0 ? targetDate : nowDate;
	const wholeCount =
		unit === "month"
			? wholeMonths(startDate, endDate)
			: wholeYears(startDate, endDate);
	const addUnit = unit === "month" ? addMonths : addYears;
	const anchorDate = addUnit(startDate, wholeCount);
	const nextDate = addUnit(startDate, wholeCount + 1);
	const elapsedMs = endDate.getTime() - anchorDate.getTime();
	const unitMs = nextDate.getTime() - anchorDate.getTime();
	const roundedCount =
		unitMs > 0 && elapsedMs >= unitMs / 2 ? wholeCount + 1 : wholeCount;

	return sign * roundedCount;
}

/**
 * Return the relative-time unit and signed value between two dates.
 *
 * @param targetDate - The target date.
 * @param nowDate    - The reference date.
 * @return The unit and value for relative formatting.
 */
function getRelativeUnit(targetDate: Date, nowDate: Date) {
	const deltaMs = targetDate.getTime() - nowDate.getTime();
	const absoluteDeltaMs = Math.abs(deltaMs);

	if (absoluteDeltaMs < SECOND_TO_MINUTE_THRESHOLD * SECOND_MS) {
		return {
			unit: "second" as const,
			value: Math.round(deltaMs / SECOND_MS),
		};
	}

	if (absoluteDeltaMs < MINUTE_TO_HOUR_THRESHOLD * MINUTE_MS) {
		return {
			unit: "minute" as const,
			value: Math.round(deltaMs / MINUTE_MS),
		};
	}

	if (absoluteDeltaMs < HOUR_TO_DAY_THRESHOLD * HOUR_MS) {
		return {
			unit: "hour" as const,
			value: Math.round(deltaMs / HOUR_MS),
		};
	}

	if (absoluteDeltaMs < DAY_TO_WEEK_THRESHOLD * DAY_MS) {
		return {
			unit: "day" as const,
			value: Math.round(deltaMs / DAY_MS),
		};
	}

	if (absoluteDeltaMs < WEEK_TO_MONTH_THRESHOLD * WEEK_MS) {
		return {
			unit: "week" as const,
			value: Math.round(deltaMs / WEEK_MS),
		};
	}

	const monthValue = roundedCalendarUnit(targetDate, nowDate, "month");

	if (Math.abs(monthValue) < 12) {
		return {
			unit: "month" as const,
			value: monthValue,
		};
	}

	return {
		unit: "year" as const,
		value: roundedCalendarUnit(targetDate, nowDate, "year"),
	};
}

/**
 * Fallback relative time formatter for environments without Intl.RelativeTimeFormat.
 *
 * @param value - The numeric value.
 * @param unit  - The time unit.
 * @param style - The display style.
 * @return The formatted string.
 */
function formatRelativeTimeFallback(
	value: number,
	unit: RelativeTimeUnit,
	style: "long" | "compact"
): string {
	if (value === 0) {
		return "now";
	}

	const absoluteValue = Math.abs(value);
	const compactUnitMap: Record<RelativeTimeUnit, string> = {
		second: "s",
		minute: "m",
		hour: "h",
		day: "d",
		week: "w",
		month: "mo",
		year: "y",
	};

	const token =
		style === "compact"
			? `${absoluteValue}${compactUnitMap[unit]}`
			: `${absoluteValue} ${unit}${absoluteValue === 1 ? "" : "s"}`;

	return value < 0 ? `${token} ago` : `in ${token}`;
}

/**
 * Format a value relative to a reference point such as "2 days ago".
 *
 * @param value   - The date value to format.
 * @param options - Formatting options.
 * @return The localized relative time string.
 */
export function formatRelativeTime(
	value: string | number | Date | null | undefined,
	options: {
		style?: "long" | "compact";
		numeric?: "always" | "auto";
		now?: string | number | Date;
	} = {}
): string {
	const { style = "long", numeric = "always", now = new Date() } = options;
	const targetDate = toDate(value);
	const nowDate = toDate(now) || new Date();

	if (!targetDate) {
		return "";
	}

	const { unit, value: relativeValue } = getRelativeUnit(targetDate, nowDate);

	/* Use standard browser APIs if available. */
	if (
		typeof Intl !== "undefined" &&
		typeof Intl.RelativeTimeFormat === "function"
	) {
		const peakurlData = getPeakURLData();

		try {
			return new Intl.RelativeTimeFormat(getActiveLocale(peakurlData), {
				numeric,
				style: style === "compact" ? "narrow" : "long",
			}).format(relativeValue, unit);
		} catch {
			/* Fall back to manual formatting if Intl fails. */
		}
	}

	return formatRelativeTimeFallback(relativeValue, unit, style);
}

/**
 * Format a value as a localized date/time string.
 *
 * @param value   - The date value to format.
 * @param options - Intl formatting options.
 * @return The localized date/time string.
 */
export function formatLocalizedDateTime(
	value: string | number | Date | null | undefined,
	options: Intl.DateTimeFormatOptions = {}
): string {
	const targetDate = toDate(value);

	if (!targetDate) {
		return "";
	}

	const peakurlData = getPeakURLData();
	const dateTimeOptions = createDateTimeOptions(options, peakurlData);
	const locale = getActiveLocale(peakurlData);

	try {
		return new Intl.DateTimeFormat(locale, dateTimeOptions).format(
			targetDate
		);
	} catch {
		/* Fall back to standard toLocaleString if Intl.DateTimeFormat fails. */
		try {
			return targetDate.toLocaleString(locale, dateTimeOptions);
		} catch {
			/* Final safe fallback if locale/options are invalid for both APIs. */
			return targetDate.toISOString();
		}
	}
}
