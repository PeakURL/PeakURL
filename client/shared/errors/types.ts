import type { FetchBaseQueryError } from "@reduxjs/toolkit/query";

/**
 * Generic object record used by utility guards before reading arbitrary keys.
 *
 * Provides a narrow object shape for helper functions that inspect unknown
 * error payloads without falling back to loose `any` access.
 */
export type ErrorRecord = Record<string, unknown>;

/**
 * API error payload shape exposed by RTK Query responses with friendly text.
 */
export interface ApiErrorData {
	/** Human-readable error text returned by the API. */
	message?: string;
}

/**
 * RTK Query error variant backed by a numeric HTTP status code.
 *
 * Narrows the broader `FetchBaseQueryError` union to the branch that
 * represents an actual HTTP response status.
 */
export type NumericStatusQueryError = Extract<
	FetchBaseQueryError,
	{ status: number }
>;
