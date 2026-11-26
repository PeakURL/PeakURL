import { Navigate, useLocation } from "react-router";

import { ApiErrorPage } from "@/components/shared";
import { PageLoader } from "@/components/ui";
import { useSessionCheck } from "@/hooks";
import { redirectToInstallRecovery } from "@/shared/install";

import type { ProtectedRouteProps } from "./types";

export function ProtectedRoute({ children }: ProtectedRouteProps) {
	const location = useLocation();
	const {
		user,
		error,
		installRecovery,
		connectionError,
		isRetrying,
		isPending,
		refetch,
	} = useSessionCheck();

	if (isPending) {
		return <PageLoader />;
	}

	if (installRecovery) {
		redirectToInstallRecovery(error);
		return <PageLoader />;
	}

	if (connectionError !== undefined) {
		return (
			<ApiErrorPage
				error={connectionError}
				isRetrying={isRetrying}
				onRetry={refetch}
			/>
		);
	}

	if (!user) {
		return <Navigate to="/login" replace state={{ from: location }} />;
	}

	return <>{children}</>;
}

export default ProtectedRoute;
