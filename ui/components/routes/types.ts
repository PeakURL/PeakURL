import type { ReactNode } from 'react';

/**
 * Props for the retry state shown when the auth check cannot reach the API.
 */
export interface AuthRequiredStateProps {
	/** Title shown in the retry card. */
	title: string;

	/** Supporting description shown beneath the title. */
	description: string;

	/** Retries the protected-route auth check. */
	onRetry: () => void;
}

/**
 * Props for the protected route wrapper.
 */
export interface ProtectedRouteProps {
	/** Protected content rendered when the session is valid. */
	children: ReactNode;
}
