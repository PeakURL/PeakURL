import type { ReactNode } from "react";

/**
 * Props for the protected route wrapper.
 */
export interface ProtectedRouteProps {
	/** Protected content rendered when the session is valid. */
	children: ReactNode;
}
