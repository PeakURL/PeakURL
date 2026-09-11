import { Navigate, useLocation } from "react-router";

import { ApiErrorPage } from "@/components/shared";
import { PageLoader } from "@/components/ui";
import { selectSessionUser, useAuthCheckQuery } from "@/state/slices/api";
import {
	getErrorStatus,
	getInstallRecovery,
	redirectToInstallRecovery,
} from "@/utils";

import type { ProtectedRouteProps } from "./types";

export function ProtectedRoute({ children }: ProtectedRouteProps) {
	const location = useLocation();
	const { data, error, isFetching, isLoading, isError, refetch } =
		useAuthCheckQuery(undefined);
	const user = selectSessionUser(data);
	const hasResolvedSession = undefined !== data || undefined !== error;
	const isPending = !hasResolvedSession && (isLoading || isFetching);
	const errorStatus = getErrorStatus(error);
	const isAuthError = 401 === errorStatus || 403 === errorStatus;
	const isRetryingConnection =
		isFetching && !isLoading && undefined === data && !isAuthError;
	const hasConnectionError =
		(isError || isRetryingConnection) && !isAuthError;
	const installRecovery = getInstallRecovery(error);

	if (isPending) {
		return <PageLoader />;
	}

	if (installRecovery) {
		redirectToInstallRecovery(error);
		return <PageLoader />;
	}

	if (hasConnectionError) {
		return (
			<ApiErrorPage
				error={error}
				isRetrying={isRetryingConnection}
				onRetry={refetch}
			/>
		);
	}

	if (!user || isAuthError) {
		return <Navigate to="/login" replace state={{ from: location }} />;
	}

	return <>{children}</>;
}

export default ProtectedRoute;
