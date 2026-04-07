import type { SerializedError } from '@reduxjs/toolkit';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import {
	getNestedRecord,
	getStringRecordValue,
	isObjectRecord,
} from './records';
import type {
	ApiErrorData,
	NumericStatusQueryError,
} from './types';

/**
 * Detects RTK Query errors before narrowing into a specific variant.
 */
function isFetchBaseQueryError(value: unknown): value is FetchBaseQueryError {
	return isObjectRecord(value) && 'status' in value;
}

/**
 * Detects RTK Query errors that carry a numeric HTTP status code.
 */
function hasNumericStatus(value: unknown): value is NumericStatusQueryError {
	return (
		isFetchBaseQueryError(value) &&
		'number' === typeof value.status
	);
}

/**
 * Detects RTK Query errors that include a structured `data.message` payload.
 */
function hasApiErrorData(value: unknown): value is { data: ApiErrorData } {
	if (!isFetchBaseQueryError(value)) {
		return false;
	}

	const data = getNestedRecord(value, 'data');

	if (!data) {
		return false;
	}

	return (
		'message' in data &&
		'string' === typeof data.message &&
		data.message.length > 0
	);
}

/**
 * Detects error-like objects that expose a top-level `error` string.
 */
function hasErrorString(value: unknown): value is { error: string } {
	return Boolean(isObjectRecord(value) && getStringRecordValue(value, 'error'));
}

/**
 * Extracts the best available message from RTK Query, serialized, or native errors.
 *
 * Returns `null` when the value does not contain a readable message.
 */
export function extractErrorMessage(
	error: FetchBaseQueryError | SerializedError | Error | unknown
): string | null {
	if (hasApiErrorData(error)) {
		return error.data.message || null;
	}

	if (hasErrorString(error)) {
		return error.error || null;
	}

	if (error instanceof Error && error.message) {
		return error.message;
	}

	return null;
}

/**
 * Extracts a numeric HTTP status from RTK Query errors when available.
 */
export function getErrorStatus(
	error: FetchBaseQueryError | SerializedError | Error | unknown
): number | null {
	if (hasNumericStatus(error)) {
		return error.status;
	}

	return null;
}

/**
 * Resolves a readable error message while guaranteeing a stable fallback.
 */
export function getErrorMessage(error: unknown, fallback: string): string {
	return extractErrorMessage(error) || fallback;
}
