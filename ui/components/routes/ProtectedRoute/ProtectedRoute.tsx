import { authApi } from '@/store/slices';
import { PageLoader } from '@/components/ui';
import {
	getErrorStatus,
	getInstallRecovery,
	redirectToInstallRecovery,
} from '@/utils';
import { Navigate, useLocation } from 'react-router-dom';
import { __ } from '@/i18n';
import type { AuthRequiredStateProps, ProtectedRouteProps } from '../types';

const AuthRequiredState = ({
	title,
	description,
	onRetry,
}: AuthRequiredStateProps) => {
	return (
		<div className="protected-route-state">
			<div className="protected-route-card">
				<div className="protected-route-copy">
					<h1 className="protected-route-title">
						{title}
					</h1>
					<p className="protected-route-description">{description}</p>
				</div>
				<button
					type="button"
					onClick={onRetry}
					className="protected-route-retry"
				>
					{__('Retry')}
				</button>
			</div>
		</div>
	);
};

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
	const isPending =
		!hasResolvedSession && (isLoading || isFetching || isUninitialized);
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

	if (isError && !isAuthError) {
		return (
			<AuthRequiredState
				title={__('Unable to load your session')}
				description={__(
					'The dashboard could not reach the PHP API. Make sure the core service is running and try again.'
				)}
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
