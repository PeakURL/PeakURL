import type { ErrorRecord } from './types';

/**
 * Narrows unknown values to an inspectable object record.
 *
 * Shared by utility helpers that need to inspect API payloads without falling
 * back to loose assertions or repeating the same object checks everywhere.
 */
export function isObjectRecord(value: unknown): value is ErrorRecord {
	return 'object' === typeof value && null !== value;
}

/**
 * Reads a nested object record property when the value is itself an object.
 */
export function getNestedRecord(
	record: ErrorRecord,
	key: string
): ErrorRecord | null {
	const value = record[key];
	return isObjectRecord(value) ? value : null;
}

/**
 * Reads a string property from an object record when present and non-empty.
 */
export function getStringRecordValue(
	record: ErrorRecord,
	key: string
): string | null {
	const value = record[key];
	return 'string' === typeof value && value.length > 0 ? value : null;
}
