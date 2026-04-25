const SECOND_MS = 1000;
const MINUTE_MS = 60 * SECOND_MS;
const HOUR_MS = 60 * MINUTE_MS;
const DAY_MS = 24 * HOUR_MS;
const WEEK_MS = 7 * DAY_MS;
const MONTH_MS = 30 * DAY_MS;
const YEAR_MS = 365 * DAY_MS;
const DEFAULT_LOCALE = "en-US";
const DEFAULT_TIMEZONE = "UTC";

function toDate(value: string | number | Date | null | undefined): Date | null {
	if (value instanceof Date) {
		return Number.isNaN(value.getTime()) ? null : new Date(value.getTime());
	}

	if ("string" === typeof value || "number" === typeof value) {
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

export function getActiveLocale(): string {
	if (
		"undefined" !== typeof window &&
		"string" === typeof window.__PEAKURL_LOCALE__ &&
		window.__PEAKURL_LOCALE__
	) {
		return window.__PEAKURL_LOCALE__.replace(/_/g, "-");
	}

	if (
		"undefined" !== typeof document &&
		"string" === typeof document.documentElement?.lang &&
		document.documentElement.lang
	) {
		return document.documentElement.lang;
	}

	return DEFAULT_LOCALE;
}

export function getActiveTimeZone(): string {
	if (
		"undefined" !== typeof window &&
		"string" === typeof window.__PEAKURL_TIMEZONE__ &&
		window.__PEAKURL_TIMEZONE__
	) {
		return window.__PEAKURL_TIMEZONE__;
	}

	return DEFAULT_TIMEZONE;
}

function getActiveTimeFormat(): "12" | "24" {
	if (
		"undefined" !== typeof window &&
		"string" === typeof window.__PEAKURL_TIME_FORMAT__
	) {
		if ("24" === window.__PEAKURL_TIME_FORMAT__) {
			return "24";
		}
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

function buildDateTimeOptions(
	options: Intl.DateTimeFormatOptions
): Intl.DateTimeFormatOptions {
	const timeFormat = getActiveTimeFormat();
	const resolvedOptions: Intl.DateTimeFormatOptions = {
		timeZone: getActiveTimeZone(),
		...(hasDateTimeDisplayOption(options)
			? options
			: { dateStyle: "medium", timeStyle: "medium" }),
	};
	const shouldIncludeSeconds =
		!("second" in resolvedOptions) &&
		!("fractionalSecondDigits" in resolvedOptions) &&
		!("timeStyle" in resolvedOptions) &&
		("hour" in resolvedOptions || "minute" in resolvedOptions);

	if ("12" === timeFormat) {
		resolvedOptions.hour12 = true;
	} else if ("24" === timeFormat) {
		resolvedOptions.hour12 = false;
	}

	if (shouldIncludeSeconds) {
		resolvedOptions.second = "2-digit";
	}

	return resolvedOptions;
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
		const year = parts.find((part) => "year" === part.type)?.value;
		const month = parts.find((part) => "month" === part.type)?.value;
		const day = parts.find((part) => "day" === part.type)?.value;

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

function resolveRelativeUnit(targetDate: Date, nowDate: Date) {
	const deltaMs = targetDate.getTime() - nowDate.getTime();
	const absoluteDeltaMs = Math.abs(deltaMs);

	if (absoluteDeltaMs < 45 * SECOND_MS) {
		return {
			unit: "second" as const,
			value: Math.round(deltaMs / SECOND_MS),
		};
	}

	if (absoluteDeltaMs < 45 * MINUTE_MS) {
		return {
			unit: "minute" as const,
			value: Math.round(deltaMs / MINUTE_MS),
		};
	}

	if (absoluteDeltaMs < 22 * HOUR_MS) {
		return {
			unit: "hour" as const,
			value: Math.round(deltaMs / HOUR_MS),
		};
	}

	if (absoluteDeltaMs < 6 * DAY_MS) {
		return {
			unit: "day" as const,
			value: Math.round(deltaMs / DAY_MS),
		};
	}

	if (absoluteDeltaMs < 4 * WEEK_MS) {
		return {
			unit: "week" as const,
			value: Math.round(deltaMs / WEEK_MS),
		};
	}

	if (absoluteDeltaMs < 11 * MONTH_MS) {
		return {
			unit: "month" as const,
			value: Math.round(deltaMs / MONTH_MS),
		};
	}

	return {
		unit: "year" as const,
		value: Math.round(deltaMs / YEAR_MS),
	};
}

function formatRelativeTimeFallback(
	value: number,
	unit: "second" | "minute" | "hour" | "day" | "week" | "month" | "year",
	style: "long" | "compact"
): string {
	if (0 === value) {
		return "now";
	}

	const absoluteValue = Math.abs(value);
	const compactUnitMap = {
		second: "s",
		minute: "m",
		hour: "h",
		day: "d",
		week: "w",
		month: "mo",
		year: "y",
	};
	const token =
		"compact" === style
			? `${absoluteValue}${compactUnitMap[unit] || ""}`
			: `${absoluteValue} ${unit}${1 === absoluteValue ? "" : "s"}`;

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

	const { unit, value: relativeValue } = resolveRelativeUnit(
		targetDate,
		nowDate
	);

	if (
		"undefined" !== typeof Intl &&
		"function" === typeof Intl.RelativeTimeFormat
	) {
		try {
			return new Intl.RelativeTimeFormat(getActiveLocale(), {
				numeric,
				style: "compact" === style ? "narrow" : "long",
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

	try {
		return new Intl.DateTimeFormat(
			getActiveLocale(),
			buildDateTimeOptions(options)
		).format(targetDate);
	} catch {
		return targetDate.toLocaleString(getActiveLocale(), {
			...buildDateTimeOptions({}),
			timeZone: undefined,
		});
	}
}
