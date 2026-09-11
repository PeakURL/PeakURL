import { Navigate, useLocation } from "react-router";

import { PageLoader } from "@/components/ui";
import { selectSessionUser, useAuthCheckQuery } from "@/store/slices/api";
import {
	getErrorStatus,
	getInstallRecovery,
	redirectToInstallRecovery,
} from "@/utils";

import type { ProtectedRouteProps } from "./types";

export function ProtectedRoute({ children }: ProtectedRouteProps) {
	const location = useLocation();
	const { data, error, isFetching, isLoading } = useAuthCheckQuery(undefined);
	const user = selectSessionUser(data);
	const hasResolvedSession = undefined !== data || undefined !== error;
	const isPending = !hasResolvedSession && (isLoading || isFetching);
	const errorStatus = getErrorStatus(error);
	const isAuthError = 401 === errorStatus || 403 === errorStatus;
	const installRecovery = getInstallRecovery(error);

	if (isPending) {
		return <PageLoader />;
	}

	if (installRecovery) {
		redirectToInstallRecovery(error);
		return <PageLoader />;
	}

	if (!user || isAuthError) {
		return <Navigate to="/login" replace state={{ from: location }} />;
	}

	return <>{children}</>;
}

export default ProtectedRoute;
