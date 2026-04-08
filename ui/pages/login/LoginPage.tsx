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
	Input,
	PageLoader,
	VerificationCodeInput,
} from '@/components';
import { isDocumentRtl } from '@/i18n/direction';
import {
	requestClosestFormSubmit,
	requestControlFormSubmit,
	getErrorMessage,
	getErrorStatus,
	getInstallRecovery,
	redirectToInstallRecovery,
} from '@/utils';
import {
	useAuthCheckQuery,
	useLoginMutation,
	useVerifyTwoFactorLoginMutation,
} from '@/store/slices/api';
import { __ } from '@/i18n';
import type { ApiErrorStateProps } from './types';

/* ─── Highlights shown on the branding panel ─── */
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

/* ─── API error state ─── */
const ApiErrorState = ({ onRetry }: ApiErrorStateProps) => (
	<div className="flex min-h-screen items-center justify-center bg-white px-6">
		<div className="login-scale-in w-full max-w-sm text-center">
			<div className="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-red-50 text-red-500">
				<ShieldCheck size={24} />
			</div>
			<h1 className="mt-5 text-xl font-semibold text-slate-900">
				{__('Could not reach the API')}
			</h1>
			<p className="mt-2 text-sm leading-relaxed text-slate-500">
				{__(
					'The PHP runtime did not answer the session check. Verify the API and database, then retry.'
				)}
			</p>
			<button
				className="mt-6 w-full rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition-colors duration-150 hover:bg-slate-800"
				onClick={onRetry}
			>
				{__('Retry connection')}
			</button>
		</div>
	</div>
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
			if (
				useBackupMode ? activeToken.length < 6 : activeToken.length < 6
			) {
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
		<div className="flex min-h-screen">
			{/* ─── Left: branding panel ─── */}
			<div className="hidden flex-col justify-between bg-slate-950 px-10 py-10 text-white lg:flex lg:w-[54%] xl:px-16">
				{/* Top: logo */}
				<div className="login-fade-up">
					<BrandLockup tone="dark" size="md" />
				</div>

				{/* Middle: headline + highlights */}
				<div>
					<h1 className="login-fade-up-d1 text-[2.5rem] font-bold leading-[1.1] tracking-tight xl:text-5xl">
						{twoFactorRequired ? (
							<>
								{__('Almost there.')}
								<br />
								<span className="text-indigo-400">
									{__('Verify to continue.')}
								</span>
							</>
						) : (
							<>
								{__('Manage every link')}
								<br />
								<span className="text-indigo-400">
									{__('from one place.')}
								</span>
							</>
						)}
					</h1>
					<p className="login-fade-up-d2 mt-5 max-w-sm text-[15px] leading-relaxed text-slate-400">
						{twoFactorRequired
							? __(
									'One more verification step and you’ll be in your workspace.'
								)
							: __(
									'Shorten URLs, track clicks, and manage your audience, all from your own dashboard.'
								)}
					</p>

					{/* Highlight chips */}
					<div className="login-fade-up-d3 mt-8 flex flex-wrap gap-3">
						{highlights.map((h) => {
							const Icon = h.icon;
							return (
								<div
									key={h.label}
									className="flex items-center gap-3 rounded-xl bg-white/6 px-4 py-3"
								>
									<Icon
										size={16}
										className="shrink-0 text-indigo-400"
									/>
									<div>
										<p className="text-[13px] font-medium text-white">
											{h.label}
										</p>
										<p className="text-[12px] text-slate-500">
											{h.desc}
										</p>
									</div>
								</div>
							);
						})}
					</div>
				</div>

				{/* Bottom: powered by */}
				<a
					href={PEAKURL_URL}
					target="_blank"
					rel="noopener noreferrer"
					className="login-fade-up-d4 text-xs text-slate-600 transition-colors duration-150 hover:text-slate-400"
				>
					{__('Powered by')}{' '}
					<span className="font-medium text-slate-500">PeakURL</span>
				</a>
			</div>

			{/* ─── Right: form column ─── */}
			<div className="flex flex-1 flex-col bg-white">
				{/* Mobile top bar */}
				<div className="flex items-center justify-between px-6 py-5 lg:hidden">
					<BrandLockup size="sm" />
				</div>

				{/* Centered form */}
				<div className="flex flex-1 items-center justify-center px-6 py-8 sm:px-10">
					<div className="login-scale-in w-full max-w-xs sm:max-w-sm">
						{/* Header */}
						<div className="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-700">
							{twoFactorRequired ? (
								<KeyRound size={20} />
							) : (
								<UserRound size={20} />
							)}
						</div>
						<h2 className="mt-5 text-2xl font-bold tracking-tight text-slate-900">
							{twoFactorRequired
								? __('Verify your identity')
								: __('Sign in to your account')}
						</h2>
						<p className="mt-2 text-sm leading-relaxed text-slate-500">
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

						{/* Error */}
						{formError ? (
							<div className="mt-5 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-4 py-3">
								<span className="mt-px text-xs font-bold text-red-500">
									!
								</span>
								<p className="text-sm leading-relaxed text-red-700">
									{formError}
								</p>
							</div>
						) : null}

						{/* Form */}
						<form
							className="mt-7 space-y-5"
							onSubmit={handleSubmit}
						>
							<Input
								label={__('Email or username')}
								icon={UserRound}
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
								className="rounded-xl border-slate-200 bg-slate-50 py-3 text-sm transition-colors duration-150 placeholder:text-slate-400 focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-500/15"
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
								className="rounded-xl border-slate-200 bg-slate-50 py-3 text-sm transition-colors duration-150 placeholder:text-slate-400 focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-500/15"
							/>

							{!twoFactorRequired ? (
								<div className="-mt-1 flex justify-end">
									<Link
										to="/forgot-password"
										className="text-sm font-medium text-indigo-600 transition-colors duration-150 hover:text-indigo-700"
									>
										{__('Forgot your password?')}
									</Link>
								</div>
							) : null}

							{twoFactorRequired ? (
								<div className="space-y-4">
									{useBackupMode ? (
										/* ── Backup code mode ── */
										<div className="space-y-3">
											<Input
												label={__('Backup code')}
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
												className="rounded-xl border-slate-200 bg-slate-50 py-3 text-sm transition-colors duration-150 placeholder:text-slate-400 focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-500/15"
											/>
											<button
												type="button"
												className="text-xs font-medium text-indigo-600 transition-colors duration-150 hover:text-indigo-700"
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
										/* ── TOTP code mode ── */
										<div className="space-y-3">
											<div className="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
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
												className="text-xs font-medium text-slate-500 transition-colors duration-150 hover:text-slate-700"
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

							{/* Submit */}
							<button
								type="submit"
								disabled={submitPending}
								className="w-full rounded-xl bg-slate-900 px-5 py-3.5 text-sm font-semibold text-white transition-colors duration-150 hover:bg-slate-800 disabled:opacity-60 disabled:pointer-events-none"
							>
								<span className="inline-flex items-center justify-center gap-2">
									{submitPending ? (
										<>
											<svg
												className="h-4 w-4 animate-spin"
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

							{/* Footer */}
							{twoFactorRequired ? (
								<div className="pt-1 text-center">
									<button
										type="button"
										className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 transition-colors duration-150 hover:text-slate-700"
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
			</div>
		</div>
	);
}

export default LoginPage;
