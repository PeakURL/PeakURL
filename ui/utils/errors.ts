import type { SerializedError } from "@reduxjs/toolkit";
import type { FetchBaseQueryError } from "@reduxjs/toolkit/query";
import {
	getNestedRecord,
	getStringRecordValue,
	isObjectRecord,
} from "./records";
import type { ApiErrorData, NumericStatusQueryError } from "./types";

/**
 * Detect RTK Query errors before narrowing into a specific variant.
 *
 * @param value - The value to check.
 * @return Whether the value is a FetchBaseQueryError.
 */
function isFetchBaseQueryError(value: unknown): value is FetchBaseQueryError {
	return isObjectRecord(value) && "status" in value;
}

/**
 * Detect RTK Query errors that carry a numeric HTTP status code.
 *
 * @param value - The value to check.
 * @return Whether the value has a numeric status.
 */
function hasNumericStatus(value: unknown): value is NumericStatusQueryError {
	return isFetchBaseQueryError(value) && "number" === typeof value.status;
}

/**
 * Detect RTK Query errors that include a structured `data.message` payload.
 *
 * @param value - The value to check.
 * @return Whether the value has structured API error data.
 */
function hasApiErrorData(value: unknown): value is { data: ApiErrorData } {
	if (!isFetchBaseQueryError(value)) {
		return false;
	}

	const data = getNestedRecord(value, "data");

	if (!data) {
		return false;
	}

	return (
		"message" in data &&
		"string" === typeof data.message &&
		data.message.length > 0
	);
}

/**
 * Detect error-like objects that expose a top-level `error` string.
 *
 * @param value - The value to check.
 * @return Whether the value has an error string.
 */
function hasErrorString(value: unknown): value is { error: string } {
	return Boolean(
		isObjectRecord(value) && getStringRecordValue(value, "error")
	);
}

/**
 * Extract the best available message from RTK Query, serialized, or native errors.
 *
 * @param error - The error object to parse.
 * @return The extracted message or null if none found.
 */
export function extractErrorMessage(
	error: FetchBaseQueryError | SerializedError | Error | unknown
): string | null {
	/* Check for structured API error messages (data.message). */
	if (hasApiErrorData(error)) {
		return error.data.message || null;
	}

	/* Check for simple error string properties (error). */
	if (hasErrorString(error)) {
		return error.error || null;
	}

	/* Fall back to native Error object messages. */
	if (error instanceof Error && error.message) {
		return error.message;
	}

	return null;
}

/**
 * Extract a numeric HTTP status from RTK Query errors when available.
 *
 * @param error - The error object to parse.
 * @return The numeric status or null if not available.
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
 * Resolve a readable error message while guaranteeing a stable fallback.
 *
 * @param error    - The error object.
 * @param fallback - The fallback message to use if extraction fails.
 * @return The final error message.
 */
export function getErrorMessage(error: unknown, fallback: string): string {
	return extractErrorMessage(error) || fallback;
}
