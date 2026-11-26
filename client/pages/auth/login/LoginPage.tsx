import type { KeyboardEvent } from "react";
import { Link, Navigate } from "react-router";
import {
	AlertCircle,
	ArrowLeft,
	ArrowRight,
	BarChart3,
	Link2,
	LockKeyhole,
	Shield,
	UserRound,
} from "lucide-react";

import { LOGIN_LOGO_URL, LOGIN_POWERED_BY_URL } from "@constants";
import {
	ApiErrorPage,
	Button,
	CaptchaWidget,
	Input,
	PageLoader,
	VerificationCodeInput,
} from "@/components";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { AuthLayout } from "@/pages/layout";
import {
	requestClosestFormSubmit,
	requestControlFormSubmit,
} from "@/shared/browser";
import { redirectToInstallRecovery } from "@/shared/install";

import { LoginHighlights } from "./components";
import { useLoginWorkflow } from "./hooks";
import type { LoginHighlightItem } from "./types";

const getHighlights = (): LoginHighlightItem[] => [
	{
		icon: Link2,
		label: __("Links"),
		desc: __("Shorten, organize, and share."),
	},
	{
		icon: BarChart3,
		label: __("Analytics"),
		desc: __("Clicks, locations, and devices."),
	},
	{
		icon: Shield,
		label: __("Security"),
		desc: __("Sessions, 2FA, and roles."),
	},
];

const submitVerificationCode = () => {
	requestClosestFormSubmit(
		document.activeElement instanceof Element
			? document.activeElement
			: null
	);
};

const submitFormOnEnter = (event: KeyboardEvent<HTMLInputElement>) => {
	if ("Enter" !== event.key) {
		return;
	}

	event.preventDefault();
	requestControlFormSubmit(event.currentTarget);
};

function LoginPage() {
	const isRtl = isDocumentRtl();
	const ForwardArrow = isRtl ? ArrowLeft : ArrowRight;
	const BackArrow = isRtl ? ArrowRight : ArrowLeft;
	const highlights = getHighlights();

	const {
		identifier,
		setIdentifier,
		password,
		setPassword,
		rememberMe,
		setRememberMe,
		token,
		setToken,
		backupCode,
		setBackupCode,
		useBackupMode,
		setUseBackupMode,
		twoFactorRequired,
		formError,
		setFormError,
		captchaRef,
		currentUser,
		error,
		isRetrying,
		isPending,
		connectionError,
		installRecovery,
		submitPending,
		redirectTo,
		handleSubmit,
		handleBackToSignIn,
		refetch,
	} = useLoginWorkflow();

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

	if (connectionError !== undefined) {
		return (
			<ApiErrorPage
				error={connectionError}
				isRetrying={isRetrying}
				onRetry={refetch}
			/>
		);
	}

	return (
		<AuthLayout
			backTo=""
			badgeIcon={twoFactorRequired ? Shield : Link2}
			badgeLabel={
				twoFactorRequired
					? __("Two-Factor Check")
					: __("Link Management")
			}
			brandHref={LOGIN_LOGO_URL}
			poweredByHref={LOGIN_POWERED_BY_URL}
			illustration={twoFactorRequired ? "verification" : "sign-in"}
			showcaseTitle={
				twoFactorRequired ? (
					__("Verify to continue")
				) : (
					<>
						{__("Manage every link")}
						<br />
						<span className="login-page-heading-accent">
							{__("from one place.")}
						</span>
					</>
				)
			}
			showcaseCopy={
				twoFactorRequired
					? __(
							"Use your authenticator app or a backup code to sign in securely."
						)
					: __(
							"Shorten URLs, track clicks, and manage your links from your own dashboard."
						)
			}
			showcaseFooter={<LoginHighlights highlights={highlights} />}
			cardTitle={
				twoFactorRequired
					? __("Verify your identity")
					: __("Sign in to your account")
			}
			cardCopy={
				twoFactorRequired
					? useBackupMode
						? __(
								"Enter one of the backup codes you saved when setting up 2FA."
							)
						: __(
								"Enter the 6-digit code from your authenticator app."
							)
					: __("Enter your credentials to continue to the dashboard.")
			}
		>
			{formError ? (
				<div className="auth-page-alert login-page-alert" role="alert">
					<AlertCircle
						size={16}
						className="auth-page-alert-icon"
						aria-hidden="true"
					/>
					<p className="auth-page-alert-text">{formError}</p>
				</div>
			) : null}

			<form
				className="auth-page-form"
				onSubmit={handleSubmit}
				aria-busy={submitPending}
			>
				<div hidden={twoFactorRequired}>
					<div className="login-page-credentials">
						<Input
							label={__("Email or username")}
							icon={UserRound}
							valueDirection="ltr"
							value={identifier}
							name="identifier"
							onChange={(event) =>
								setIdentifier(event.target.value)
							}
							autoComplete="username"
							autoCapitalize="none"
							spellCheck={false}
							disabled={submitPending || twoFactorRequired}
							placeholder={__("you@company.com")}
							required
						/>

						<Input
							label={__("Password")}
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
						/>

						<div className="login-page-credentials-actions">
							<label className="login-page-remember-me-label">
								<input
									type="checkbox"
									className="login-page-remember-me-checkbox"
									checked={rememberMe}
									onChange={(event) =>
										setRememberMe(event.target.checked)
									}
									disabled={
										submitPending || twoFactorRequired
									}
								/>
								<span className="login-page-remember-me-text">
									{__("Remember me")}
								</span>
							</label>
							<Link
								to="/forgot-password"
								className="login-page-link"
							>
								{__("Forgot your password?")}
							</Link>
						</div>
					</div>
				</div>

				{twoFactorRequired ? (
					<div className="login-page-two-factor">
						{useBackupMode ? (
							<div className="login-page-two-factor-panel">
								<Input
									label={__("Backup code")}
									valueDirection="ltr"
									value={backupCode}
									name="backupCode"
									onChange={(event) =>
										setBackupCode(event.target.value)
									}
									onKeyDown={submitFormOnEnter}
									enterKeyHint="go"
									autoCapitalize="none"
									spellCheck={false}
									autoComplete="one-time-code"
									disabled={submitPending}
									placeholder={__("xxxx-xxxx-xxxx")}
								/>
								<button
									type="button"
									className="login-page-link login-page-code-link"
									disabled={submitPending}
									onClick={() => {
										setUseBackupMode(false);
										setBackupCode("");
										setFormError("");
									}}
								>
									{__("Use authenticator code instead")}
								</button>
							</div>
						) : (
							<div className="login-page-two-factor-panel">
								<div className="login-page-code-panel">
									<VerificationCodeInput
										value={token}
										onChange={setToken}
										onEnter={submitVerificationCode}
										disabled={submitPending}
									/>
								</div>
								<button
									type="button"
									className="login-page-link login-page-code-link"
									disabled={submitPending}
									onClick={() => {
										setUseBackupMode(true);
										setToken("");
										setFormError("");
									}}
								>
									{__("Lost your device? Use a backup code")}
								</button>
							</div>
						)}
					</div>
				) : null}

				{!twoFactorRequired ? <CaptchaWidget ref={captchaRef} /> : null}

				<Button
					type="submit"
					size="lg"
					loading={submitPending}
					className="auth-page-submit"
					icon={ForwardArrow}
					iconPosition="right"
				>
					{submitPending
						? twoFactorRequired
							? __("Verifying…")
							: __("Signing in…")
						: twoFactorRequired
							? __("Verify & continue")
							: __("Sign in")}
				</Button>

				{twoFactorRequired ? (
					<div className="login-page-actions">
						<button
							type="button"
							className="login-page-link login-page-back-action"
							disabled={submitPending}
							onClick={handleBackToSignIn}
						>
							<BackArrow size={13} />
							{__("Back to sign-in")}
						</button>
					</div>
				) : null}
			</form>
		</AuthLayout>
	);
}

export default LoginPage;
