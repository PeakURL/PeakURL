import type { KeyboardEvent, SubmitEvent } from 'react';
import { useMemo, useState } from 'react';
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom';
import {
	ArrowLeft,
	ArrowRight,
	BarChart3,
	KeyRound,
	Link2,
	LockKeyhole,
	Shield,
	ShieldCheck,
	UserRound,
} from 'lucide-react';

import {
	BrandLockup,
	Button,
	Input,
	PageLoader,
	VerificationCodeInput,
} from '@/components';
import { isDocumentRtl } from '@/i18n/direction';
import {
	getErrorMessage,
	getErrorStatus,
	getInstallRecovery,
	redirectToInstallRecovery,
	requestClosestFormSubmit,
	requestControlFormSubmit,
} from '@/utils';
import {
	useAuthCheckQuery,
	useLoginMutation,
	useVerifyTwoFactorLoginMutation,
} from '@/store/slices/api';
import { __, sprintf } from '@/i18n';
import type { ApiErrorStateProps } from './types';

const getHighlights = () => [
	{
		icon: Link2,
		label: __('Links'),
		desc: __('Shorten, organize, and share.'),
	},
	{
		icon: BarChart3,
		label: __('Analytics'),
		desc: __('Clicks, locations, and devices.'),
	},
	{
		icon: Shield,
		label: __('Security'),
		desc: __('Sessions, 2FA, and roles.'),
	},
];

const ApiErrorState = ({ onRetry }: ApiErrorStateProps) => (
	<main id="page-container" className="login-page-error-state">
		<section
			className="login-page-error-card"
			aria-labelledby="page-heading"
		>
			<div className="login-page-error-icon">
				<ShieldCheck size={24} />
			</div>
			<h1 id="page-heading" className="login-page-error-title">
				{__('Could not reach the API')}
			</h1>
			<p className="login-page-error-copy">
				{__(
					'The PHP runtime did not answer the session check. Verify the API and database, then retry.'
				)}
			</p>
			<Button className="login-page-error-button" onClick={onRetry}>
				{__('Retry connection')}
			</Button>
		</section>
	</main>
);

const PEAKURL_URL =
	'https://peakurl.org?utm_source=peakurl_login&utm_medium=login&utm_campaign=powered_by';

const getSafeRedirectPath = (candidate: unknown) => {
	if (typeof candidate !== 'string') {
		return '';
	}

	const value = candidate.trim();

	if (!value.startsWith('/') || value.startsWith('//')) {
		return '';
	}

	return value;
};

const submitVerificationCode = () => {
	requestClosestFormSubmit(
		document.activeElement instanceof Element
			? document.activeElement
			: null
	);
};

const submitFormOnEnter = (event: KeyboardEvent<HTMLInputElement>) => {
	if ('Enter' !== event.key) {
		return;
	}

	event.preventDefault();
	requestControlFormSubmit(event.currentTarget);
};

function LoginPage() {
	const isRtl = isDocumentRtl();
	const ForwardArrow = isRtl ? ArrowLeft : ArrowRight;
	const BackArrow = isRtl ? ArrowRight : ArrowLeft;
	const location = useLocation();
	const navigate = useNavigate();
	const highlights = getHighlights();
	const [identifier, setIdentifier] = useState('');
	const [password, setPassword] = useState('');
	const [token, setToken] = useState('');
	const [backupCode, setBackupCode] = useState('');
	const [useBackupMode, setUseBackupMode] = useState(false);
	const [twoFactorRequired, setTwoFactorRequired] = useState(false);
	const [formError, setFormError] = useState('');
	const [login, { isLoading: isLoggingIn }] = useLoginMutation();
	const [verifyLogin, { isLoading: isVerifying }] =
		useVerifyTwoFactorLoginMutation();
	const { data, error, isError, isFetching, isLoading, refetch } =
		useAuthCheckQuery(undefined);

	const currentUser = data?.user || data?.data;
	const errorStatus = getErrorStatus(error);
	const isAuthError = 401 === errorStatus || 403 === errorStatus;
	const isApiError = isError && !isAuthError;
	const installRecovery = getInstallRecovery(error);
	const hasResolvedSession = undefined !== data || undefined !== error;
	const isPending = !hasResolvedSession && (isLoading || isFetching);
	const submitPending = isLoggingIn || isVerifying;
	const redirectTo = useMemo(() => {
		const searchParams = new URLSearchParams(location.search || '');
		const redirectParam = getSafeRedirectPath(
			searchParams.get('redirect') || ''
		);

		if (redirectParam) {
			return redirectParam;
		}

		const from = location.state?.from;

		if (from?.pathname) {
			const fromPath = getSafeRedirectPath(
				`${from.pathname}${from.search || ''}${from.hash || ''}`
			);

			if (fromPath) {
				return fromPath;
			}
		}

		return '/dashboard';
	}, [location.search, location.state]);

	if (isPending) {
		return <PageLoader />;
	}

	if (installRecovery) {
		redirectToInstallRecovery(error);
		return <PageLoader />;
	}

	if (currentUser) {
		return <Navigate replace to={redirectTo} />;
	}

	if (isApiError) {
		return <ApiErrorState onRetry={refetch} />;
	}

	const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		setFormError('');

		if (!identifier.trim()) {
			setFormError(__('Email or username is required.'));
			return;
		}

		if (!password) {
			setFormError(__('Password is required.'));
			return;
		}

		if (twoFactorRequired) {
			const activeToken = useBackupMode
				? backupCode.trim()
				: token.trim();

			if (activeToken.length < 6) {
				setFormError(
					useBackupMode
						? __('Enter your backup code.')
						: __(
								'Enter the 6-digit code from your authenticator app.'
							)
				);
				return;
			}
		}

		try {
			if (twoFactorRequired) {
				const activeToken = useBackupMode
					? backupCode.trim()
					: token.trim();

				await verifyLogin({
					identifier: identifier.trim(),
					password,
					token: activeToken,
				}).unwrap();
			} else {
				const result = await login({
					identifier: identifier.trim(),
					password,
				}).unwrap();

				if (
					result?.requiresTwoFactor ||
					result?.data?.requiresTwoFactor
				) {
					setTwoFactorRequired(true);
					return;
				}
			}

			navigate(redirectTo, { replace: true });
		} catch (submitError) {
			setFormError(
				getErrorMessage(
					submitError,
					twoFactorRequired
						? __('Unable to verify the two-factor code.')
						: __('Unable to sign in with those credentials.')
				)
			);
		}
	};

	return (
		<main id="page-container" className="login-page-layout">
			<aside className="login-page-aside">
				<div className="login-page-brand">
					<BrandLockup tone="dark" size="md" />
				</div>

				<div className="login-page-content">
					<h1 className="login-page-heading">
						{twoFactorRequired ? (
							<>
								{__('Almost there.')}
								<br />
								<span className="login-page-heading-accent">
									{__('Verify to continue.')}
								</span>
							</>
						) : (
							<>
								{__('Manage every link')}
								<br />
								<span className="login-page-heading-accent">
									{__('from one place.')}
								</span>
							</>
						)}
					</h1>
					<p className="login-page-summary">
						{twoFactorRequired
							? __(
									'One more verification step and you’ll be in your workspace.'
								)
							: __(
									'Shorten URLs, track clicks, and manage your audience, all from your own dashboard.'
								)}
					</p>

					<div className="login-page-highlight-list">
						{highlights.map((highlight) => {
							const Icon = highlight.icon;

							return (
								<div
									key={highlight.label}
									className="login-page-highlight"
								>
									<Icon
										size={16}
										className="login-page-highlight-icon"
									/>
									<div>
										<p className="login-page-highlight-title">
											{highlight.label}
										</p>
										<p className="login-page-highlight-copy">
											{highlight.desc}
										</p>
									</div>
								</div>
							);
						})}
					</div>
				</div>

				<a
					href={PEAKURL_URL}
					target="_blank"
					rel="noopener noreferrer"
					dir={isRtl ? 'rtl' : 'ltr'}
					className="login-page-meta"
				>
					{sprintf(__('Powered by %s'), 'PeakURL')}
				</a>
			</aside>

			<section
				className="login-page-panel"
				aria-labelledby="page-heading"
			>
				<div className="login-page-mobile-header">
					<BrandLockup size="sm" />
				</div>

				<div className="login-page-panel-content">
					<div className="login-page-card">
						<div className="login-page-card-icon">
							{twoFactorRequired ? (
								<KeyRound size={20} />
							) : (
								<UserRound size={20} />
							)}
						</div>
						<h2 id="page-heading" className="login-page-card-title">
							{twoFactorRequired
								? __('Verify your identity')
								: __('Sign in to your account')}
						</h2>
						<p className="login-page-card-copy">
							{twoFactorRequired
								? useBackupMode
									? __(
											'Enter one of the backup codes you saved when setting up 2FA.'
										)
									: __(
											'Enter the 6-digit code from your authenticator app.'
										)
								: __(
										'Enter your credentials to continue to the dashboard.'
									)}
						</p>

						{formError ? (
							<div className="login-page-alert">
								<span className="login-page-alert-marker">
									!
								</span>
								<p className="login-page-alert-text">
									{formError}
								</p>
							</div>
						) : null}

						<form
							className="login-page-form"
							onSubmit={handleSubmit}
						>
							<Input
								label={__('Email or username')}
								icon={UserRound}
								valueDirection="ltr"
								value={identifier}
								name="identifier"
								onChange={(event) =>
									setIdentifier(event.target.value)
								}
								autoFocus
								autoComplete="username"
								autoCapitalize="none"
								spellCheck={false}
								disabled={submitPending || twoFactorRequired}
								placeholder={__('you@company.com')}
								required
								className="login-page-input"
							/>

							<Input
								label={__('Password')}
								type="password"
								icon={LockKeyhole}
								value={password}
								name="password"
								onChange={(event) =>
									setPassword(event.target.value)
								}
								onKeyDown={submitFormOnEnter}
								enterKeyHint="go"
								autoComplete="current-password"
								disabled={submitPending || twoFactorRequired}
								placeholder="••••••••"
								required
								className="login-page-input"
							/>

							{!twoFactorRequired ? (
								<div
									className={`login-page-link-row ${
										isRtl ? 'login-page-link-row-rtl' : ''
									}`}
								>
									<Link
										to="/forgot-password"
										className="login-page-link"
									>
										{__('Forgot your password?')}
									</Link>
								</div>
							) : null}

							{twoFactorRequired ? (
								<div className="login-page-two-factor">
									{useBackupMode ? (
										<div className="login-page-two-factor-panel">
											<Input
												label={__('Backup code')}
												valueDirection="ltr"
												value={backupCode}
												name="backupCode"
												onChange={(event) =>
													setBackupCode(
														event.target.value
													)
												}
												onKeyDown={submitFormOnEnter}
												enterKeyHint="go"
												autoCapitalize="none"
												spellCheck={false}
												autoComplete="one-time-code"
												disabled={submitPending}
												placeholder={__(
													'xxxx-xxxx-xxxx'
												)}
												className="login-page-input"
											/>
											<button
												type="button"
												className="login-page-secondary-link"
												onClick={() => {
													setUseBackupMode(false);
													setBackupCode('');
													setFormError('');
												}}
											>
												{__(
													'Use authenticator code instead'
												)}
											</button>
										</div>
									) : (
										<div className="login-page-two-factor-panel">
											<div className="login-page-code-panel">
												<VerificationCodeInput
													value={token}
													onChange={setToken}
													onEnter={
														submitVerificationCode
													}
													disabled={submitPending}
												/>
											</div>
											<button
												type="button"
												className="login-page-muted-link"
												onClick={() => {
													setUseBackupMode(true);
													setToken('');
													setFormError('');
												}}
											>
												{__(
													'Lost your device? Use a backup code'
												)}
											</button>
										</div>
									)}
								</div>
							) : null}

							<button
								type="submit"
								disabled={submitPending}
								className="login-page-submit"
							>
								<span className="login-page-submit-content">
									{submitPending ? (
										<>
											<svg
												className="login-page-spinner"
												fill="none"
												viewBox="0 0 24 24"
											>
												<circle
													className="opacity-25"
													cx="12"
													cy="12"
													r="10"
													stroke="currentColor"
													strokeWidth="4"
												/>
												<path
													className="opacity-75"
													fill="currentColor"
													d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
												/>
											</svg>
											{twoFactorRequired
												? __('Verifying…')
												: __('Signing in…')}
										</>
									) : (
										<>
											{twoFactorRequired
												? __('Verify & continue')
												: __('Sign in')}
											<ForwardArrow size={15} />
										</>
									)}
								</span>
							</button>

							{twoFactorRequired ? (
								<div className="login-page-actions">
									<button
										type="button"
										className="login-page-back-action"
										onClick={() => {
											setTwoFactorRequired(false);
											setToken('');
											setBackupCode('');
											setUseBackupMode(false);
											setFormError('');
										}}
									>
										<BackArrow size={13} />
										{__('Back to sign-in')}
									</button>
								</div>
							) : null}
						</form>
					</div>
				</div>
			</section>
		</main>
	);
}

export default LoginPage;
