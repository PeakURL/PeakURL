import {
	formatLocalizedDateTime,
	formatRelativeTime,
	getActiveLocale,
} from "./dateFormatting";

const RECENT_DATE_DAY_LIMIT = 7;
const DAY_MS = 1000 * 60 * 60 * 24;

/**
 * Format a timestamp for dashboard activity feeds.
 *
 * Recent values are shown relatively, while older values use a medium date.
 * Invalid or missing values resolve to an empty string.
 *
 * @param dateValue - The raw date value to format.
 * @return The formatted date string.
 */
export function formatDate(
	dateValue: string | number | Date | null | undefined
): string {
	if (null == dateValue) {
		return "";
	}

	const date = new Date(dateValue);
	if (Number.isNaN(date.getTime())) {
		return "";
	}

	const now = new Date();
	const diffDays = Math.abs(now.getTime() - date.getTime()) / DAY_MS;

	/* Use relative formatting for dates within the last week. */
	if (diffDays < RECENT_DATE_DAY_LIMIT) {
		return formatRelativeTime(date, {
			style: "long",
			numeric: "auto",
		});
	}

	/* Fall back to localized medium date format for older entries. */
	return formatLocalizedDateTime(date, {
		dateStyle: "medium",
	});
}

/**
 * Format a number into a compact dashboard-friendly label.
 *
 * @param value - The number to format.
 * @return The formatted compact string.
 */
export function formatNumber(value: number): string {
	return new Intl.NumberFormat(getActiveLocale(), {
		notation: "compact",
		maximumFractionDigits: 1,
	}).format(value);
}

/**
 * Format a value as a localized full count.
 *
 * Coerces numeric-like values to a safe number so dashboard metrics render
 * consistently even when the source payload is partially typed or nullable.
 *
 * @param value - The raw numeric value.
 * @return The formatted count string.
 */
export function formatCount(value: unknown): string {
	const parsedValue = Number(value || 0);

	return new Intl.NumberFormat(getActiveLocale()).format(
		Number.isFinite(parsedValue) ? parsedValue : 0
	);
}

/**
 * Format a value as a localized date/time string with a caller-provided fallback.
 *
 * Accepts the common date-like values used across the dashboard. Non-date
 * objects fall back to their string form so diagnostics remain visible.
 *
 * @param value    - The raw date/time value.
 * @param fallback - The string to return if formatting fails.
 * @return The localized date/time string.
 */
export function formatDateTimeValue(
	value: unknown,
	fallback: string = ""
): string {
	if (undefined === value || null === value || "" === value) {
		return fallback;
	}

	if (
		value instanceof Date ||
		"string" === typeof value ||
		"number" === typeof value
	) {
		return formatLocalizedDateTime(value) || fallback;
	}

	return String(value);
}

/**
 * Format a value as a byte-size label with a caller-provided fallback.
 *
 * Keeps the output compact for admin cards and diagnostics while preserving
 * `0 B` for valid zero-sized values.
 *
 * @param value    - The size in bytes.
 * @param fallback - The string to return if formatting fails.
 * @return The formatted byte-size string.
 */
export function formatByteSize(value: unknown, fallback: string = ""): string {
	if (undefined === value || null === value || "" === value) {
		return fallback;
	}

	const size = Number(value);

	if (!Number.isFinite(size) || size < 0) {
		return fallback;
	}

	if (0 === size) {
		return "0 B";
	}

	const units = ["B", "KB", "MB", "GB"];
	let nextSize = size;
	let index = 0;

	/* Iteratively divide by 1024 to find the appropriate unit scale. */
	while (nextSize >= 1024 && index < units.length - 1) {
		nextSize /= 1024;
		index += 1;
	}

	/* Show one decimal point for small fractional values (e.g., 1.5 KB). */
	return `${nextSize.toFixed(nextSize >= 10 || 0 === index ? 0 : 1)} ${units[index]}`;
}
