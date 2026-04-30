const SECOND_MS = 1000;
const MINUTE_MS = 60 * SECOND_MS;
const HOUR_MS = 60 * MINUTE_MS;
const DAY_MS = 24 * HOUR_MS;
const WEEK_MS = 7 * DAY_MS;
const SECOND_TO_MINUTE_THRESHOLD = 45;
const MINUTE_TO_HOUR_THRESHOLD = 45;
const HOUR_TO_DAY_THRESHOLD = 22;
const DAY_TO_WEEK_THRESHOLD = 6;
const WEEK_TO_MONTH_THRESHOLD = 4;
const DEFAULT_LOCALE = "en-US";
const DEFAULT_TIMEZONE = "UTC";

type RelativeTimeUnit =
	| "second"
	| "minute"
	| "hour"
	| "day"
	| "week"
	| "month"
	| "year";

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

function getNonEmptyString(value: unknown): string | null {
	return typeof value === "string" && value ? value : null;
}

export function getActiveLocale(): string {
	const windowLocale =
		typeof window === "undefined"
			? null
			: getNonEmptyString(window.__PEAKURL_LOCALE__);

	if (windowLocale) {
		return windowLocale.replace(/_/g, "-");
	}

	const documentLocale =
		typeof document === "undefined"
			? null
			: getNonEmptyString(document.documentElement?.lang);

	if (documentLocale) {
		return documentLocale;
	}

	return DEFAULT_LOCALE;
}

export function getActiveTimeZone(): string {
	const timezone =
		typeof window === "undefined"
			? null
			: getNonEmptyString(window.__PEAKURL_TIMEZONE__);

	return timezone ?? DEFAULT_TIMEZONE;
}

function getActiveTimeFormat(): "12" | "24" {
	const timeFormat =
		typeof window === "undefined"
			? null
			: getNonEmptyString(window.__PEAKURL_TIME_FORMAT__);

	if (timeFormat === "24") {
		return "24";
	}

	return "12";
}

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

function shouldIncludeSeconds(options: Intl.DateTimeFormatOptions): boolean {
	const secondsConfigured =
		"second" in options || "fractionalSecondDigits" in options;
	const hasTimeStyle = "timeStyle" in options;
	const hasTimeFields = "hour" in options || "minute" in options;

	return !secondsConfigured && !hasTimeStyle && hasTimeFields;
}

function createDateTimeOptions(
	options: Intl.DateTimeFormatOptions
): Intl.DateTimeFormatOptions {
	const timeFormat = getActiveTimeFormat();
	const includeSeconds = shouldIncludeSeconds(options);
	const dateOptions: Intl.DateTimeFormatOptions = {
		timeZone: getActiveTimeZone(),
		...(hasDateTimeDisplayOption(options)
			? options
			: { dateStyle: "medium", timeStyle: "medium" }),
	};

	if (timeFormat === "12") {
		dateOptions.hour12 = true;
	} else if (timeFormat === "24") {
		dateOptions.hour12 = false;
	}

	if (includeSeconds) {
		dateOptions.second = "2-digit";
	}

	return dateOptions;
}

export function getZonedDateKey(
	value: string | number | Date | null | undefined
): string {
	const targetDate = toDate(value);

	if (!targetDate) {
		return "";
	}

	try {
		const parts = new Intl.DateTimeFormat("en-US", {
			timeZone: getActiveTimeZone(),
			year: "numeric",
			month: "2-digit",
			day: "2-digit",
		}).formatToParts(targetDate);
		const year = parts.find((part) => part.type === "year")?.value;
		const month = parts.find((part) => part.type === "month")?.value;
		const day = parts.find((part) => part.type === "day")?.value;

		return year && month && day ? `${year}-${month}-${day}` : "";
	} catch {
		return targetDate.toISOString().slice(0, 10);
	}
}

/**
 * Formats a date-only `YYYY-MM-DD` value without shifting it across zones.
 */
export function formatDateOnly(
	value: string | null | undefined,
	options: Intl.DateTimeFormatOptions = { dateStyle: "medium" }
): string {
	const date = toDateOnly(value);

	if (!date) {
		return value || "";
	}

	try {
		return new Intl.DateTimeFormat(getActiveLocale(), {
			timeZone: "UTC",
			...options,
		}).format(date);
	} catch {
		return value || "";
	}
}

/**
 * Returns the number of days in a month.
 *
 * @param year Full year (for example, 2026).
 * @param month Zero-based month index, matching JavaScript Date.
 * `new Date(year, month + 1, 0)` uses day 0 as the day before
 * the first day of `month + 1`, which is the last day of `month`.
 */
function daysInMonth(year: number, month: number): number {
	return new Date(year, month + 1, 0).getDate();
}

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

function wholeYears(startDate: Date, endDate: Date): number {
	let count = endDate.getFullYear() - startDate.getFullYear();

	if (addYears(startDate, count).getTime() > endDate.getTime()) {
		count -= 1;
	}

	return Math.max(0, count);
}

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
		unitMs > 0 && elapsedMs >= unitMs / 2
			? wholeCount + 1
			: wholeCount;

	return sign * roundedCount;
}

/**
 * Returns the relative-time unit and signed value between two dates.
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
 * Formats a value relative to a reference point such as "2 days ago".
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

	const { unit, value: relativeValue } = getRelativeUnit(
		targetDate,
		nowDate
	);

	if (
		typeof Intl !== "undefined" &&
		typeof Intl.RelativeTimeFormat === "function"
	) {
		try {
			return new Intl.RelativeTimeFormat(getActiveLocale(), {
				numeric,
				style: style === "compact" ? "narrow" : "long",
			}).format(relativeValue, unit);
		} catch {
			return formatRelativeTimeFallback(relativeValue, unit, style);
		}
	}

	return formatRelativeTimeFallback(relativeValue, unit, style);
}

/**
 * Formats a value as a localized date/time string.
 */
export function formatLocalizedDateTime(
	value: string | number | Date | null | undefined,
	options: Intl.DateTimeFormatOptions = {}
): string {
	const targetDate = toDate(value);

	if (!targetDate) {
		return "";
	}

	const dateTimeOptions = createDateTimeOptions(options);

	try {
		return new Intl.DateTimeFormat(
			getActiveLocale(),
			dateTimeOptions
		).format(targetDate);
	} catch {
		return targetDate.toLocaleString(getActiveLocale(), dateTimeOptions);
	}
}
