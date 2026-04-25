import type { KeyboardEvent, SubmitEvent } from "react";
import { useState } from "react";
import { Link } from "react-router-dom";
import { ArrowLeft, ArrowRight, KeyRound, Mail } from "lucide-react";
import { isDocumentRtl } from "@/i18n/direction";
import { AuthLayout } from "@/pages/layout";
import { Button, Input } from "@/components";
import { useForgotPasswordMutation } from "@/store/slices/api";
import { __, sprintf } from "@/i18n";
import { getErrorMessage, requestControlFormSubmit } from "@/utils";

const submitFormOnEnter = (event: KeyboardEvent<HTMLInputElement>) => {
	if ("Enter" !== event.key) {
		return;
	}

	event.preventDefault();
	requestControlFormSubmit(event.currentTarget);
};

function ForgotPasswordPage() {
	const isRtl = isDocumentRtl();
	const ReturnArrow = isRtl ? ArrowLeft : ArrowRight;
	const [identifier, setIdentifier] = useState("");
	const [submittedIdentifier, setSubmittedIdentifier] = useState("");
	const [formError, setFormError] = useState("");
	const [isSubmitted, setIsSubmitted] = useState(false);
	const [forgotPassword, { isLoading }] = useForgotPasswordMutation();

	const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		setFormError("");

		if (!identifier.trim()) {
			setFormError(__("Email or username is required."));
			return;
		}

		try {
			await forgotPassword({
				identifier: identifier.trim(),
			}).unwrap();
			setSubmittedIdentifier(identifier.trim());
			setIsSubmitted(true);
		} catch (error) {
			setFormError(
				getErrorMessage(
					error,
					__("PeakURL could not process the password reset request.")
				)
			);
		}
	};

	return (
		<AuthLayout
			badgeIcon={KeyRound}
			badgeLabel={__("Account Recovery")}
			showcaseTitle={__("Reset your password")}
			showcaseCopy={__(
				"Enter the email address or username linked to your PeakURL account and we'll send a secure reset link."
			)}
			noteTitle={__("Password reset links expire after 1 hour.")}
			noteCopy={__(
				"If the account exists, PeakURL sends a single-use link and keeps your existing sessions revoked after the password is changed."
			)}
			cardTitle={__("Forgot your password?")}
			cardCopy={__(
				"Enter your account email or username and PeakURL will send a secure password reset link."
			)}
		>
			{isSubmitted ? (
				<div className="auth-page-status">
					<p className="auth-page-status-title">
						{__("Check your inbox")}
					</p>
					<p className="auth-page-status-copy">
						{sprintf(
							__(
								"If an account exists for %s, PeakURL has sent a password reset link."
							),
							submittedIdentifier
						)}
					</p>
					<Link to="/login" className="auth-page-status-link">
						{__("Return to login")}
						<ReturnArrow size={15} />
					</Link>
				</div>
			) : (
				<form className="auth-page-form" onSubmit={handleSubmit}>
					<Input
						label={__("Email or username")}
						type="text"
						icon={Mail}
						valueDirection="ltr"
						value={identifier}
						name="identifier"
						onChange={(event) => setIdentifier(event.target.value)}
						autoFocus
						placeholder={__("owner@example.com or admin")}
						autoComplete="username"
						autoCapitalize="none"
						spellCheck={false}
						enterKeyHint="go"
						onKeyDown={submitFormOnEnter}
						required
						error={formError}
						className="auth-page-input"
					/>
					<Button
						type="submit"
						size="md"
						loading={isLoading}
						className="auth-page-submit"
					>
						{__("Send reset link")}
					</Button>
				</form>
			)}
		</AuthLayout>
	);
}

export default ForgotPasswordPage;
