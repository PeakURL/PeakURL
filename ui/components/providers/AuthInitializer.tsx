import { authApi } from '@/store/slices';
import { PageLoader } from '@/components/ui';
import {
	getErrorStatus,
	getInstallRecovery,
	redirectToInstallRecovery,
} from '@/utils';
import { __ } from '@/i18n';
import type { AuthErrorStateProps, AuthInitializerProps } from './types';

const AuthErrorState = ({ onRetry }: AuthErrorStateProps) => {
	return (
		<div className="min-h-screen bg-bg flex items-center justify-center p-6">
			<div className="w-full max-w-md rounded-2xl border border-stroke bg-surface shadow-sm p-6 space-y-4 text-center">
				<div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400">
					<span className="text-xl font-semibold">!</span>
				</div>
				<div className="space-y-1">
					<h1 className="text-lg font-semibold text-heading">
						{__('API unavailable')}
					</h1>
					<p className="text-sm text-text-muted">
						{__(
							'Start the PHP core service and database connection, then try again.'
						)}
					</p>
				</div>
				<button
					type="button"
					onClick={onRetry}
					className="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
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
