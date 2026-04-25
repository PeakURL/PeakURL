import { authApi } from "@/store/slices";
import { ApiErrorPage } from "@/components/common";
import { PageLoader } from "@/components/ui";
import {
	getErrorStatus,
	getInstallRecovery,
	redirectToInstallRecovery,
} from "@/utils";
import { Navigate, useLocation } from "react-router-dom";
import type { ProtectedRouteProps } from "../types";

/**
 * ProtectedRoute guards authenticated dashboard routes.
 *
 * @param props Component props
 * @param props.children Content to render if the session is valid
 */
const ProtectedRoute = ({ children }: ProtectedRouteProps) => {
	const location = useLocation();
	const { useAuthCheckQuery } = authApi;
	const {
		data,
		error,
		isLoading,
		isFetching,
		isUninitialized,
		isError,
		refetch,
	} = useAuthCheckQuery(undefined);
	const currentUser = data?.user || data?.data;
	const isAuthenticated = Boolean(currentUser);
	const hasResolvedSession = undefined !== data || undefined !== error;
	const isPending = !hasResolvedSession && (isLoading || isUninitialized);
	const errorStatus = getErrorStatus(error);
	const isAuthError = 401 === errorStatus || 403 === errorStatus;
	const isRetryingConnection =
		isFetching && !isLoading && !currentUser && !isAuthError;
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

	if (isAuthError || !isAuthenticated) {
		return <Navigate replace to="/login" state={{ from: location }} />;
	}

	return <>{children}</>;
};

export default ProtectedRoute;
