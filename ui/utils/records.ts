import type { ErrorRecord } from "./types";

/**
 * Narrow unknown values to an inspectable object record.
 *
 * Shared by utility helpers that need to inspect API payloads without falling
 * back to loose assertions or repeating the same object checks everywhere.
 *
 * @param value - The value to check.
 * @return Whether the value is a valid object record.
 */
export function isObjectRecord(value: unknown): value is ErrorRecord {
	return "object" === typeof value && null !== value;
}

/**
 * Read a nested object record property when the value is itself an object.
 *
 * @param record - The source record.
 * @param key    - The property key to read.
 * @return The nested record or null.
 */
export function getNestedRecord(
	record: ErrorRecord,
	key: string
): ErrorRecord | null {
	const value = record[key];

	return isObjectRecord(value) ? value : null;
}

/**
 * Read a string property from an object record when present and non-empty.
 *
 * @param record - The source record.
 * @param key    - The property key to read.
 * @return The string value or null.
 */
export function getStringRecordValue(
	record: ErrorRecord,
	key: string
): string | null {
	const value = record[key];

	return "string" === typeof value && value.length > 0 ? value : null;
}
