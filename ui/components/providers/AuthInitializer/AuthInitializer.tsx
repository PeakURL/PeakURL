import { authApi } from '@/store/slices';
import { PageLoader } from '@/components/ui';
import {
	getErrorStatus,
	getInstallRecovery,
	redirectToInstallRecovery,
} from '@/utils';
import { __ } from '@/i18n';
import type { AuthErrorStateProps, AuthInitializerProps } from '../types';

const AuthErrorState = ({ onRetry }: AuthErrorStateProps) => {
	return (
		<div className="auth-status-page">
			<div className="auth-status-card">
				<div className="auth-status-icon">
					<span className="auth-status-icon-label">!</span>
				</div>
				<div className="auth-status-copy">
					<h1 className="auth-status-title">
						{__('API unavailable')}
					</h1>
					<p className="auth-status-description">
						{__(
							'Start the PHP core service and database connection, then try again.'
						)}
					</p>
				</div>
				<button
					type="button"
					onClick={onRetry}
					className="auth-status-retry"
				>
					{__('Retry')}
				</button>
			</div>
		</div>
	);
};

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
		return <AuthErrorState onRetry={refetch} />;
	}

	return <>{children}</>;
};

export default AuthInitializer;
