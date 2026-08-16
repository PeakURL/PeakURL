import type { SubmitEvent } from "react";
import { useState } from "react";
import { AlertCircle, Mail, MailCheck } from "lucide-react";

import { Button, Input, Select, type SelectOption } from "@/components";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { cn } from "@/utils";

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
			dir={direction}
			className={cn(
				"settings-email-method-button",
				isActive
					? "settings-email-method-button-active"
					: "settings-email-method-button-inactive"
			)}
		>
			<p className="settings-email-method-title">{title}</p>
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
			<fieldset className="settings-fieldset">
				<div dir={direction} className="settings-email-intro-layout">
					<div className="settings-email-intro-icon">
						<Mail size={18} />
					</div>
					<div className="settings-email-intro-content">
						<legend className="settings-legend mb-0!">
							{__("Email Delivery")}
						</legend>
						<p className="settings-group-description mb-0! mt-0!">
							{__(
								"PeakURL uses this mail transport for password-reset emails and other account recovery notifications."
							)}
						</p>
					</div>
				</div>
			</fieldset>

			{errorMessage && (
				<div className="settings-email-alert settings-email-alert-error">
					<div
						dir={direction}
						className="settings-email-alert-layout"
					>
						<AlertCircle
							size={18}
							className="settings-email-alert-icon"
						/>
						<div className="settings-email-alert-content">
							<p className="settings-email-alert-title">
								{__("Mail status unavailable")}
							</p>
							<p className="settings-email-alert-text">
								{errorMessage}
							</p>
						</div>
					</div>
				</div>
			)}

			{managementDisabled && (
				<div className="settings-email-alert settings-email-alert-warning">
					<div
						dir={direction}
						className="settings-email-alert-layout"
					>
						<AlertCircle
							size={18}
							className="settings-email-alert-icon"
						/>
						<div className="settings-email-alert-content">
							<p className="settings-email-alert-title">
								{__("Dashboard management unavailable")}
							</p>
							<p className="settings-email-alert-text">
								{status?.manageDisabledReason}
							</p>
						</div>
					</div>
				</div>
			)}

			<form onSubmit={handleSubmit} className="settings-form">
				<fieldset className="settings-fieldset">
					<legend className="settings-legend">
						{__("Delivery method")}
					</legend>
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
				</fieldset>

				{usingSmtp && (
					<fieldset className="settings-fieldset">
						<legend className="settings-legend">
							{__("SMTP Configuration")}
						</legend>
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
							<div className="settings-email-field">
								<label className="settings-section-label">
									{__("Encryption")}
								</label>
								<Select
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
							</div>
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
					</fieldset>
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
