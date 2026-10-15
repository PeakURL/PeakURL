import type { SubmitEvent } from "react";
import { useState } from "react";
import {
	AlertCircle,
	Check,
	Mail,
	MailCheck,
	Send,
	Server,
} from "lucide-react";

import { Button, Input, SelectField, type SelectOption } from "@/components";
import { TabHeader } from "@/pages/dashboard/settings/components/TabHeader";
import { SectionTitle } from "@/pages/dashboard/settings/components/SectionTitle";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { cn } from "@/shared/formatting";

import type {
	EmailDeliveryTabProps,
	EmailFormState,
	EmailStatus,
	MethodButtonProps,
} from "../types";
import type { SmtpEncryption } from "../../types";

function MethodButton({
	isActive,
	title,
	description,
	onClick,
}: MethodButtonProps) {
	const direction = isDocumentRtl() ? "rtl" : "ltr";

	return (
		<button
			type="button"
			onClick={onClick}
			aria-pressed={isActive}
			dir={direction}
			className={cn(
				"settings-email-method-button",
				isActive
					? "settings-email-method-button-active"
					: "settings-email-method-button-inactive"
			)}
		>
			<span className="settings-email-method-heading">
				<span className="settings-email-method-title">{title}</span>
				<span
					className="settings-email-method-indicator"
					aria-hidden="true"
				>
					{isActive && <Check size={12} />}
				</span>
			</span>
			<p className="settings-email-method-description">{description}</p>
		</button>
	);
}

function createMailForm(status?: EmailStatus | null): EmailFormState {
	return {
		driver: status?.driver || "mail",
		fromEmail: status?.configuredFromEmail || "",
		fromName: status?.configuredFromName || "",
		smtpHost: status?.smtpHost || "",
		smtpPort: String(status?.smtpPort || 587),
		smtpEncryption: status?.smtpEncryption || "tls",
		smtpAuth: Boolean(status?.smtpAuth),
		smtpUsername: status?.smtpUsername || "",
		smtpPassword: "",
	};
}

function prepareMailPayload(form: EmailFormState): EmailFormState {
	return {
		driver: form.driver,
		fromEmail: form.fromEmail.trim(),
		fromName: form.fromName.trim(),
		smtpHost: form.smtpHost.trim(),
		smtpPort: form.smtpPort.trim(),
		smtpEncryption: form.smtpEncryption,
		smtpAuth: form.smtpAuth,
		smtpUsername: form.smtpUsername.trim(),
		smtpPassword: form.smtpPassword,
	};
}

function normalizeMailState(form: EmailFormState) {
	return {
		driver: form.driver,
		fromEmail: form.fromEmail.trim(),
		fromName: form.fromName.trim(),
		smtpHost: form.smtpHost.trim(),
		smtpPort: form.smtpPort.trim(),
		smtpEncryption: form.smtpEncryption,
		smtpAuth: form.smtpAuth,
		smtpUsername: form.smtpUsername.trim(),
	};
}

function hasUnsavedMailChanges(
	form: EmailFormState,
	status?: EmailStatus | null
): boolean {
	if (!status) {
		return false;
	}

	if ("" !== form.smtpPassword) {
		return true;
	}

	return (
		JSON.stringify(normalizeMailState(form)) !==
		JSON.stringify(normalizeMailState(createMailForm(status)))
	);
}

function EmailDeliveryTab({
	status,
	errorMessage,
	isLoading,
	isSaving,
	isTesting,
	onSave,
	onSendTest,
}: EmailDeliveryTabProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const [form, setForm] = useState<EmailFormState>(() =>
		createMailForm(status)
	);
	const encryptionOptions: SelectOption<SmtpEncryption>[] = [
		{ value: "tls", label: __("TLS / STARTTLS") },
		{ value: "ssl", label: __("SSL") },
		{ value: "none", label: __("None") },
	];

	const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		await onSave(prepareMailPayload(form));

		setForm((current) => ({
			...current,
			smtpPassword: "",
		}));
	};

	const managementDisabled = Boolean(
		status && !status.canManageFromDashboard
	);
	const usingSmtp = "smtp" === form.driver;
	const hasUnsavedChanges = hasUnsavedMailChanges(form, status);
	const showSubmitButton = status ? hasUnsavedChanges : usingSmtp;
	const testDisabledReason = hasUnsavedChanges
		? __("Save the current email settings before sending a test email.")
		: status?.testDisabledReason || null;
	const canSendTestEmail = Boolean(
		status?.canSendTestEmail &&
		!testDisabledReason &&
		!managementDisabled &&
		!isLoading &&
		!isSaving
	);
	const showTestButton = Boolean(status);

	return (
		<div className="settings-email">
			<section className="settings-section">
				<TabHeader
					icon={Mail}
					title={__("Email Delivery")}
					description={__(
						"PeakURL uses this mail transport for password-reset emails and other account recovery notifications."
					)}
					documentationHref="https://go.peakurl.org/d80593"
				/>
			</section>

			{errorMessage && (
				<div className="settings-notice settings-notice-error">
					<div dir={direction} className="settings-notice-layout">
						<AlertCircle
							size={18}
							className="settings-notice-icon"
						/>
						<div className="settings-notice-content">
							<p className="settings-notice-title">
								{__("Mail status unavailable")}
							</p>
							<p className="settings-notice-text">
								{errorMessage}
							</p>
						</div>
					</div>
				</div>
			)}

			{managementDisabled && (
				<div className="settings-notice settings-notice-warning">
					<div dir={direction} className="settings-notice-layout">
						<AlertCircle
							size={18}
							className="settings-notice-icon"
						/>
						<div className="settings-notice-content">
							<p className="settings-notice-title">
								{__("Dashboard management unavailable")}
							</p>
							<p className="settings-notice-text">
								{status?.manageDisabledReason}
							</p>
						</div>
					</div>
				</div>
			)}

			<form onSubmit={handleSubmit} className="settings-form">
				<section className="settings-section">
					<SectionTitle icon={Send}>
						{__("Delivery method")}
					</SectionTitle>
					<div className="settings-email-method-grid">
						<MethodButton
							isActive={"mail" === form.driver}
							title={__("PHP mail()")}
							description={__(
								"Use the hosting server’s built-in PHP mail transport."
							)}
							onClick={() =>
								setForm((current) => ({
									...current,
									driver: "mail",
								}))
							}
						/>
						<MethodButton
							isActive={"smtp" === form.driver}
							title={__("SMTP")}
							description={__(
								"Send through your own SMTP server with optional authentication."
							)}
							onClick={() =>
								setForm((current) => ({
									...current,
									driver: "smtp",
								}))
							}
						/>
					</div>
				</section>

				{usingSmtp && (
					<section className="settings-section">
						<SectionTitle icon={Server}>
							{__("SMTP Configuration")}
						</SectionTitle>
						<div className="settings-grid">
							<Input
								label={__("From Name")}
								value={form.fromName}
								onChange={(event) =>
									setForm((current) => ({
										...current,
										fromName: event.target.value,
									}))
								}
								placeholder={status?.fromName || __("PeakURL")}
							/>
							<Input
								label={__("From Email")}
								type="email"
								autoCapitalize="off"
								spellCheck={false}
								value={form.fromEmail}
								onChange={(event) =>
									setForm((current) => ({
										...current,
										fromEmail: event.target.value,
									}))
								}
								placeholder={__("noreply@yourdomain.com")}
							/>
						</div>
						<div className="settings-grid">
							<Input
								label={__("SMTP Host")}
								valueDirection="ltr"
								autoCapitalize="off"
								spellCheck={false}
								value={form.smtpHost}
								onChange={(event) =>
									setForm((current) => ({
										...current,
										smtpHost: event.target.value,
									}))
								}
								placeholder={__("smtp.example.com")}
								required
							/>
							<Input
								label={__("SMTP Port")}
								type="number"
								value={form.smtpPort}
								onChange={(event) =>
									setForm((current) => ({
										...current,
										smtpPort: event.target.value,
									}))
								}
								placeholder="587"
								required
							/>
						</div>

						<div className="settings-grid">
							<SelectField
								label={__("Encryption")}
								value={form.smtpEncryption}
								onChange={(value) =>
									setForm((current) => ({
										...current,
										smtpEncryption: value,
									}))
								}
								options={encryptionOptions}
								ariaLabel={__("SMTP encryption")}
							/>
							<div className="settings-email-field">
								<label className="settings-section-label">
									{__("Authentication")}
								</label>
								<button
									type="button"
									onClick={() =>
										setForm((current) => ({
											...current,
											smtpAuth: !current.smtpAuth,
										}))
									}
									dir={direction}
									className={cn(
										"settings-email-auth-toggle",
										form.smtpAuth
											? "settings-email-auth-toggle-active"
											: "settings-email-auth-toggle-inactive"
									)}
								>
									<span>
										{form.smtpAuth
											? __("Authentication enabled")
											: __("Authentication disabled")}
									</span>
									<span
										className={cn(
											"settings-email-auth-switch",
											form.smtpAuth
												? "settings-email-auth-switch-active"
												: "settings-email-auth-switch-inactive"
										)}
									>
										<span
											className={cn(
												"settings-email-auth-knob",
												form.smtpAuth
													? isRtl
														? "settings-email-auth-knob-active-rtl"
														: "settings-email-auth-knob-active-ltr"
													: "settings-email-auth-knob-inactive"
											)}
										/>
									</span>
								</button>
							</div>
						</div>

						{form.smtpAuth && (
							<div className="settings-grid">
								<Input
									label={__("SMTP Username")}
									valueDirection="ltr"
									autoCapitalize="off"
									spellCheck={false}
									value={form.smtpUsername}
									onChange={(event) =>
										setForm((current) => ({
											...current,
											smtpUsername: event.target.value,
										}))
									}
									placeholder={__("mailer@example.com")}
									required
								/>
								<Input
									label={__("SMTP Password")}
									type="password"
									value={form.smtpPassword}
									onChange={(event) =>
										setForm((current) => ({
											...current,
											smtpPassword: event.target.value,
										}))
									}
									placeholder={
										status?.smtpPasswordConfigured
											? __(
													"Leave blank to keep the saved password"
												)
											: __("Enter the SMTP password")
									}
									helperText={
										status?.smtpPasswordConfigured
											? sprintf(
													__("Saved password: %s"),
													status.smtpPasswordHint ||
														""
												)
											: __(
													"PeakURL stores this password encrypted in the database."
												)
									}
									required={!status?.smtpPasswordConfigured}
								/>
							</div>
						)}
					</section>
				)}

				{(showSubmitButton || showTestButton) && (
					<div
						className={cn(
							"settings-email-actions",
							isRtl
								? "settings-email-actions-start"
								: "settings-email-actions-end"
						)}
					>
						{showTestButton && (
							<Button
								type="button"
								variant="secondary"
								size="sm"
								icon={MailCheck}
								loading={isTesting}
								disabled={!canSendTestEmail}
								onClick={onSendTest}
							>
								{__("Send Test Email")}
							</Button>
						)}
						{showSubmitButton && (
							<Button
								type="submit"
								size="sm"
								loading={isSaving || isLoading}
								disabled={managementDisabled || isTesting}
							>
								{usingSmtp
									? __("Save Email Configuration")
									: __("Use PHP mail()")}
							</Button>
						)}
					</div>
				)}
				{showTestButton && testDisabledReason && (
					<p className="settings-email-test-note">
						{testDisabledReason}
					</p>
				)}
			</form>
		</div>
	);
}

export default EmailDeliveryTab;
