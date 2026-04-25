import { authApi } from "@/store/slices";
import { ApiErrorPage } from "@/components/common";
import { PageLoader } from "@/components/ui";
import {
	getErrorStatus,
	getInstallRecovery,
	redirectToInstallRecovery,
} from "@/utils";
import type { AuthInitializerProps } from "../types";

/**
 * AuthInitializer performs the initial session check before rendering the app.
 *
 * @param props Component props
 * @param props.children Application content
 */
const AuthInitializer = ({ children }: AuthInitializerProps) => {
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
	const hasResolvedSession = undefined !== data || undefined !== error;
	const isPending = !hasResolvedSession && (isLoading || isUninitialized);
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

	return <>{children}</>;
};

export default AuthInitializer;
