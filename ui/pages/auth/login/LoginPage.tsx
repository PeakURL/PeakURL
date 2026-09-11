import type { KeyboardEvent } from "react";
import { Link, Navigate } from "react-router";
import {
	ArrowLeft,
	ArrowRight,
	BarChart3,
	KeyRound,
	Link2,
	LockKeyhole,
	Shield,
	UserRound,
} from "lucide-react";

import { LOGIN_LOGO_URL, LOGIN_POWERED_BY_URL } from "@constants";
import {
	ApiErrorPage,
	BrandLockup,
	CaptchaWidget,
	Input,
	PageLoader,
	VerificationCodeInput,
} from "@/components";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import {
	redirectToInstallRecovery,
	requestClosestFormSubmit,
	requestControlFormSubmit,
} from "@/utils";

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
		isRetryingApiCheck,
		isPending,
		isApiError,
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

	if (isApiError) {
		return (
			<ApiErrorPage
				title={__("Authentication is temporarily unavailable")}
				description={__(
					"PeakURL could not verify your signed-in state. The server may be restarting or unreachable."
				)}
				error={error}
				isRetrying={isRetryingApiCheck}
				onRetry={refetch}
			/>
		);
	}
	return (
		<main id="page-container" className="login-page-layout">
			<aside className="login-page-aside">
				<div className="login-page-brand">
					<BrandLockup tone="dark" size="md" href={LOGIN_LOGO_URL} />
				</div>

				<div className="login-page-content">
					<h1 className="login-page-heading">
						{twoFactorRequired ? (
							<>
								{__("Almost there.")}
								<br />
								<span className="login-page-heading-accent">
									{__("Verify to continue.")}
								</span>
							</>
						) : (
							<>
								{__("Manage every link")}
								<br />
								<span className="login-page-heading-accent">
									{__("from one place.")}
								</span>
							</>
						)}
					</h1>
					<p className="login-page-summary">
						{twoFactorRequired
							? __(
									"One more verification step and you’ll be in your workspace."
								)
							: __(
									"Shorten URLs, track clicks, and manage your audience, all from your own dashboard."
								)}
					</p>

					<LoginHighlights highlights={highlights} />
				</div>

				<a
					href={LOGIN_POWERED_BY_URL}
					target="_blank"
					rel="noopener noreferrer"
					dir={isRtl ? "rtl" : "ltr"}
					className="login-page-meta"
				>
					{sprintf(__("Powered by %s"), "PeakURL")}
				</a>
			</aside>

			<section
				className="login-page-panel"
				aria-labelledby="page-heading"
			>
				<div className="login-page-mobile-header">
					<BrandLockup size="sm" href={LOGIN_LOGO_URL} />
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
								? __("Verify your identity")
								: __("Sign in to your account")}
						</h2>
						<p className="login-page-card-copy">
							{twoFactorRequired
								? useBackupMode
									? __(
											"Enter one of the backup codes you saved when setting up 2FA."
										)
									: __(
											"Enter the 6-digit code from your authenticator app."
										)
								: __(
										"Enter your credentials to continue to the dashboard."
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
								className="login-page-input"
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
								className="login-page-input"
							/>

							{!twoFactorRequired ? (
								<div className="login-page-remember-me">
									<label className="login-page-remember-me-label">
										<input
											type="checkbox"
											className="login-page-remember-me-checkbox"
											checked={rememberMe}
											onChange={(e) =>
												setRememberMe(e.target.checked)
											}
											disabled={submitPending}
										/>
										<span className="login-page-remember-me-text">
											{__("Remember me")}
										</span>
									</label>
								</div>
							) : null}

							{!twoFactorRequired ? (
								<div
									className={`login-page-link-row ${
										isRtl ? "login-page-link-row-rtl" : ""
									}`}
								>
									<Link
										to="/forgot-password"
										className="login-page-link"
									>
										{__("Forgot your password?")}
									</Link>
								</div>
							) : null}

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
													"xxxx-xxxx-xxxx"
												)}
												className="login-page-input"
											/>
											<button
												type="button"
												className="login-page-secondary-link"
												onClick={() => {
													setUseBackupMode(false);
													setBackupCode("");
													setFormError("");
												}}
											>
												{__(
													"Use authenticator code instead"
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
													setToken("");
													setFormError("");
												}}
											>
												{__(
													"Lost your device? Use a backup code"
												)}
											</button>
										</div>
									)}
								</div>
							) : null}

							{!twoFactorRequired ? (
								<CaptchaWidget ref={captchaRef} />
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
												? __("Verifying…")
												: __("Signing in…")}
										</>
									) : (
										<>
											{twoFactorRequired
												? __("Verify & continue")
												: __("Sign in")}
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
										onClick={handleBackToSignIn}
									>
										<BackArrow size={13} />
										{__("Back to sign-in")}
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
