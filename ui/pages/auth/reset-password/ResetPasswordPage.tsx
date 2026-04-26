import type { KeyboardEvent, SubmitEvent } from "react";
import { useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { CheckCircle2, KeyRound, LockKeyhole } from "lucide-react";
import { AuthLayout } from "@/pages/layout";
import { Button, Input } from "@/components";
import {
	useCheckPasswordResetTokenQuery,
	useResetPasswordMutation,
} from "@/store/slices/api";
import { __ } from "@/i18n";
import { getErrorMessage, requestControlFormSubmit } from "@/utils";

const submitFormOnEnter = (event: KeyboardEvent<HTMLInputElement>) => {
	if ("Enter" !== event.key) {
		return;
	}

	event.preventDefault();
	requestControlFormSubmit(event.currentTarget);
};

function ResetPasswordPage() {
	const navigate = useNavigate();
	const { token = "" } = useParams();
	const resetToken = token.trim();
	const [password, setPassword] = useState("");
	const [confirmPassword, setConfirmPassword] = useState("");
	const [formError, setFormError] = useState("");
	const [isCompleted, setIsCompleted] = useState(false);
	const {
		isError: isTokenError,
		isFetching: isCheckingToken,
		isLoading: isLoadingToken,
	} = useCheckPasswordResetTokenQuery(resetToken, {
		skip: !resetToken,
	});
	const [resetPassword, { isLoading }] = useResetPasswordMutation();
	const isTokenInvalid = !resetToken || isTokenError;
	const isCheckingResetLink = isCheckingToken || isLoadingToken;

	const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		setFormError("");

		if (isCheckingResetLink || isTokenInvalid) {
			setFormError(__("The password reset link is invalid."));
			return;
		}

		if (password.length < 8) {
			setFormError(__("Password must be at least 8 characters."));
			return;
		}

		if (password !== confirmPassword) {
			setFormError(__("Passwords do not match."));
			return;
		}

		try {
			await resetPassword({
				token: resetToken,
				password,
			}).unwrap();
			setIsCompleted(true);
			window.setTimeout(() => {
				navigate("/login", { replace: true });
			}, 1600);
		} catch (error) {
			setFormError(
				getErrorMessage(
					error,
					__("PeakURL could not reset the password with that link.")
				)
			);
		}
	};

	return (
		<AuthLayout
			badgeIcon={LockKeyhole}
			badgeLabel={__("Secure Reset")}
			showcaseTitle={__("Choose a new password and get back to work.")}
			showcaseCopy={__(
				"Once the password changes, PeakURL revokes the old sessions so only the new login remains valid."
			)}
			noteTitle={__("Use at least 8 characters.")}
			noteCopy={__(
				"For best results, combine uppercase, lowercase, numbers, and a unique phrase you do not reuse elsewhere."
			)}
			cardTitle={__("Reset your password")}
			cardCopy={__(
				"Set a new account password, then sign in again with the updated credentials."
			)}
		>
			{isCheckingResetLink ? (
				<div className="auth-page-status">
					<div className="reset-password-page-status">
						<LockKeyhole
							size={18}
							className="reset-password-page-status-icon"
						/>
						<div>
							<p className="auth-page-status-title">
								{__("Loading")}
							</p>
							<p className="auth-page-status-copy">
								{__("Reset your password")}
							</p>
						</div>
					</div>
				</div>
			) : isTokenInvalid ? (
				<div className="auth-page-status">
					<div className="reset-password-page-status">
						<LockKeyhole
							size={18}
							className="reset-password-page-status-icon"
						/>
						<div>
							<p className="auth-page-status-title">
								{__("The password reset link is invalid.")}
							</p>
							<p className="auth-page-status-copy">
								{__(
									"Enter your account email or username and PeakURL will send a secure password reset link."
								)}
							</p>
							<Button
								type="button"
								size="sm"
								className="auth-page-submit"
								onClick={() => navigate("/forgot-password")}
							>
								{__("Send reset link")}
							</Button>
						</div>
					</div>
				</div>
			) : isCompleted ? (
				<div className="auth-page-status">
					<div className="reset-password-page-status">
						<CheckCircle2
							size={18}
							className="reset-password-page-status-icon"
						/>
						<div>
							<p className="auth-page-status-title">
								{__("Password updated")}
							</p>
							<p className="auth-page-status-copy">
								{__(
									"PeakURL updated your password and revoked the older sessions. Redirecting you to the login page now."
								)}
							</p>
						</div>
					</div>
				</div>
			) : (
				<form className="auth-page-form" onSubmit={handleSubmit}>
					<Input
						label={__("New password")}
						type="password"
						icon={KeyRound}
						value={password}
						name="password"
						onChange={(event) => setPassword(event.target.value)}
						autoFocus
						placeholder={__("Enter your new password")}
						autoComplete="new-password"
						required
						error={formError}
						disabled={isCheckingResetLink}
						className="auth-page-input"
					/>
					<Input
						label={__("Confirm new password")}
						type="password"
						icon={LockKeyhole}
						value={confirmPassword}
						name="confirmPassword"
						onChange={(event) =>
							setConfirmPassword(event.target.value)
						}
						onKeyDown={submitFormOnEnter}
						enterKeyHint="go"
						placeholder={__("Confirm your new password")}
						autoComplete="new-password"
						required
						disabled={isCheckingResetLink}
						className="auth-page-input"
					/>
					<Button
						type="submit"
						size="md"
						loading={isLoading || isCheckingResetLink}
						className="auth-page-submit"
					>
						{__("Reset password")}
					</Button>
				</form>
			)}
		</AuthLayout>
	);
}

export default ResetPasswordPage;
