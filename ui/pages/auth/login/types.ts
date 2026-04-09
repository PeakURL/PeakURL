/**
 * Props for the API-unavailable login fallback state.
 */
export interface ApiErrorStateProps {
	/** Retries the failed API connectivity check. */
	onRetry: () => void;
}
