// @ts-nocheck
'use client';

import { authApi } from '@/store/slices';
import { PageLoader } from '@/components/ui';
import { getInstallRecovery, redirectToInstallRecovery } from '@/utils';
import { Navigate, useLocation } from 'react-router-dom';

const AuthRequiredState = ({ title, description, onRetry }) => {
	return (
		<div className="min-h-[60vh] flex items-center justify-center p-6">
			<div className="w-full max-w-md rounded-2xl border border-stroke bg-surface shadow-sm p-6 space-y-4 text-center">
				<div className="space-y-1">
					<h1 className="text-lg font-semibold text-heading">
						{title}
					</h1>
					<p className="text-sm text-text-muted">{description}</p>
				</div>
				<button
					type="button"
					onClick={onRetry}
					className="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
				>
					Retry
				</button>
			</div>
		</div>
	);
};

/**
 * ProtectedRoute Component
 * Higher-order component that protects routes requiring authentication.
 * Redirects unauthenticated users to the login page.
 * Also normalizes users onto the correct subdomain based on their system role.
 * @param {Object} props
 * @param {React.ReactNode} props.children - Content to render if authenticated
 */
const ProtectedRoute = ({ children }) => {
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
	} = useAuthCheckQuery();
	const currentUser = data?.user || data?.data;
	const isAuthenticated = !!currentUser;
	const isPending = isLoading || isFetching || isUninitialized;
	const errorStatus = typeof error?.status === 'number' ? error.status : null;
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
				title="Unable to load your session"
				description="The dashboard could not reach the PHP API. Make sure the core service is running and try again."
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
