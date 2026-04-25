import { formatLocalizedDateTime, getActiveLocale } from "./dateFormatting";

/**
 * Formats a value as a localized full count.
 *
 * Coerces numeric-like values to a safe number so dashboard metrics render
 * consistently even when the source payload is partially typed or nullable.
 */
export function formatCount(value: unknown): string {
	const parsedValue = Number(value || 0);

	return new Intl.NumberFormat(getActiveLocale()).format(
		Number.isFinite(parsedValue) ? parsedValue : 0
	);
}

/**
 * Formats a value as a localized date/time string with a caller-provided fallback.
 *
 * Accepts the common date-like values used across the dashboard. Non-date
 * objects fall back to their string form so diagnostics remain visible.
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
 * Formats a value as a byte-size label with a caller-provided fallback.
 *
 * Keeps the output compact for admin cards and diagnostics while preserving
 * `0 B` for valid zero-sized values.
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

	while (nextSize >= 1024 && index < units.length - 1) {
		nextSize /= 1024;
		index += 1;
	}

	return `${nextSize.toFixed(nextSize >= 10 || 0 === index ? 0 : 1)} ${units[index]}`;
}
