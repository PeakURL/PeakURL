import { useState } from "react";

import { getErrorStatus } from "@/shared/errors";
import { getInstallRecovery } from "@/shared/install";
import { selectSessionUser, useAuthCheckQuery } from "@/state/slices/api";

/** Keep the connection error visible while the rejected session query retries. */
export function useSessionCheck() {
	const {
		data,
		error,
		isError,
		isFetching,
		isLoading,
		isUninitialized,
		refetch,
	} = useAuthCheckQuery(undefined);
	const status = getErrorStatus(error);
	const isAuthError = status === 401 || status === 403;
	const installRecovery = getInstallRecovery(error);
	const failure =
		isError && !isAuthError && !installRecovery ? error : undefined;
	const [lastFailure, setLastFailure] = useState(failure);
	if ((!isFetching || failure !== undefined) && lastFailure !== failure) {
		setLastFailure(failure);
	}

	const connectionError = failure ?? (isFetching ? lastFailure : undefined);
	const isPending =
		data === undefined &&
		error === undefined &&
		connectionError === undefined &&
		(isLoading || isUninitialized || isFetching);

	return {
		user: isAuthError ? undefined : selectSessionUser(data),
		error,
		installRecovery,
		connectionError,
		isRetrying: isFetching && connectionError !== undefined,
		isPending,
		refetch,
	};
}
