// @ts-nocheck
import { useState } from 'react';
import { AlertCircle, Mail, Send, Server } from 'lucide-react';
import { Button, Input } from '@/components/ui';

function MethodButton({ isActive, title, description, onClick }) {
	return (
		<button
			type="button"
			onClick={onClick}
			className={`rounded-lg border p-4 text-left transition ${
				isActive
					? 'border-accent bg-accent/10 text-heading'
					: 'border-stroke bg-surface-alt text-text-muted hover:border-accent/40 hover:text-heading'
			}`}
		>
			<p className="text-sm font-semibold">{title}</p>
			<p className="mt-1 text-sm leading-6">{description}</p>
		</button>
	);
}

function buildFormState(status) {
	return {
		driver: status?.driver || 'mail',
		smtpHost: status?.smtpHost || '',
		smtpPort: String(status?.smtpPort || 587),
		smtpEncryption: status?.smtpEncryption || 'tls',
		smtpAuth: Boolean(status?.smtpAuth),
		smtpUsername: status?.smtpUsername || '',
		smtpPassword: '',
	};
}

function EmailDeliveryTab({
	status,
	errorMessage,
	isLoading,
	isSaving,
	onSave,
}) {
	const [form, setForm] = useState(() => buildFormState(status));

	const handleSubmit = async (event) => {
		event.preventDefault();
		await onSave({
			driver: form.driver,
			smtpHost: form.smtpHost.trim(),
			smtpPort: form.smtpPort.trim(),
			smtpEncryption: form.smtpEncryption,
			smtpAuth: form.smtpAuth,
			smtpUsername: form.smtpUsername.trim(),
			smtpPassword: form.smtpPassword,
		});

		setForm((current) => ({
			...current,
			smtpPassword: '',
		}));
	};

	const managementDisabled = Boolean(
		status && !status.canManageFromDashboard
	);
	const savedDriver = status?.driver || 'mail';
	const usingSmtp = 'smtp' === form.driver;
	const showSubmitButton = usingSmtp || form.driver !== savedDriver;

	return (
		<div className="space-y-5">
			<div className="rounded-lg border border-stroke bg-surface p-5">
				<div className="flex items-start gap-3">
					<div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-500/10 text-primary-600 dark:bg-primary-500/20 dark:text-primary-400">
						<Mail size={18} />
					</div>
					<div className="space-y-1">
						<h2 className="text-base font-semibold text-heading">
							Email Configuration
						</h2>
						<p className="text-sm leading-6 text-text-muted">
							PeakURL uses this mail transport for password-reset
							emails and other account recovery notifications.
						</p>
					</div>
				</div>
			</div>

			{errorMessage && (
				<div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
					<div className="flex items-start gap-3">
						<AlertCircle size={18} className="mt-0.5" />
						<div>
							<p className="font-semibold">
								Mail status unavailable
							</p>
							<p className="mt-1 leading-6 opacity-80">
								{errorMessage}
							</p>
						</div>
					</div>
				</div>
			)}

			{managementDisabled && (
				<div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
					<div className="flex items-start gap-3">
						<AlertCircle size={18} className="mt-0.5" />
						<div>
							<p className="font-semibold">
								Dashboard management unavailable
							</p>
							<p className="mt-1 leading-6 opacity-80">
								{status?.manageDisabledReason}
							</p>
						</div>
					</div>
				</div>
			)}

			<form
				onSubmit={handleSubmit}
				className="rounded-lg border border-stroke bg-surface p-5 space-y-5"
			>
				<div className="space-y-3">
					<div className="flex items-center gap-2 text-sm font-semibold text-heading">
						<Send size={16} />
						Delivery method
					</div>
					<div className="grid grid-cols-1 gap-3 md:grid-cols-2">
						<MethodButton
							isActive={'mail' === form.driver}
							title="PHP mail()"
							description="Use the hosting server’s built-in PHP mail transport."
							onClick={() =>
								setForm((current) => ({
									...current,
									driver: 'mail',
								}))
							}
						/>
						<MethodButton
							isActive={'smtp' === form.driver}
							title="SMTP"
							description="Send through your own SMTP server with optional authentication."
							onClick={() =>
								setForm((current) => ({
									...current,
									driver: 'smtp',
								}))
							}
						/>
					</div>
				</div>

				{usingSmtp && (
					<div className="space-y-4 rounded-lg border border-stroke bg-surface-alt p-4">
						<div className="flex items-center gap-2 text-sm font-semibold text-heading">
							<Server size={16} />
							SMTP connection
						</div>
						<div className="grid grid-cols-1 gap-4 md:grid-cols-2">
							<Input
								label="SMTP Host"
								value={form.smtpHost}
								onChange={(event) =>
									setForm((current) => ({
										...current,
										smtpHost: event.target.value,
									}))
								}
								placeholder="smtp.example.com"
								required
							/>
							<Input
								label="SMTP Port"
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

						<div className="grid grid-cols-1 gap-4 md:grid-cols-2">
							<div className="space-y-2">
								<label className="block text-sm font-semibold text-heading">
									Encryption
								</label>
								<select
									value={form.smtpEncryption}
									onChange={(event) =>
										setForm((current) => ({
											...current,
											smtpEncryption:
												event.target.value,
										}))
									}
									className="w-full rounded-md border border-stroke bg-surface px-4 py-2 text-heading outline-none transition-all focus:border-accent focus:ring-2 focus:ring-accent"
								>
									<option value="tls">TLS / STARTTLS</option>
									<option value="ssl">SSL</option>
									<option value="none">None</option>
								</select>
							</div>
							<div className="space-y-2">
								<label className="block text-sm font-semibold text-heading">
									Authentication
								</label>
								<button
									type="button"
									onClick={() =>
										setForm((current) => ({
											...current,
											smtpAuth: !current.smtpAuth,
										}))
									}
									className={`inline-flex w-full items-center justify-between rounded-md border px-4 py-2 text-sm font-medium transition ${
										form.smtpAuth
											? 'border-accent bg-accent/10 text-heading'
											: 'border-stroke bg-surface text-text-muted'
									}`}
								>
									<span>
										{form.smtpAuth
											? 'Authentication enabled'
											: 'Authentication disabled'}
									</span>
									<span
										className={`h-5 w-10 rounded-full p-0.5 transition ${
											form.smtpAuth
												? 'bg-accent'
												: 'bg-surface-alt'
										}`}
									>
										<span
											className={`block h-4 w-4 rounded-full bg-white transition ${
												form.smtpAuth
													? 'translate-x-5'
													: 'translate-x-0'
											}`}
										/>
									</span>
								</button>
							</div>
						</div>

						{form.smtpAuth && (
							<div className="grid grid-cols-1 gap-4 md:grid-cols-2">
								<Input
									label="SMTP Username"
									value={form.smtpUsername}
									onChange={(event) =>
										setForm((current) => ({
											...current,
											smtpUsername:
												event.target.value,
										}))
									}
									placeholder="mailer@example.com"
									required
								/>
								<Input
									label="SMTP Password"
									type="password"
									value={form.smtpPassword}
									onChange={(event) =>
										setForm((current) => ({
											...current,
											smtpPassword:
												event.target.value,
										}))
									}
									placeholder={
										status?.smtpPasswordConfigured
											? 'Leave blank to keep the saved password'
											: 'Enter the SMTP password'
									}
									helperText={
										status?.smtpPasswordConfigured
											? `Saved password: ${status.smtpPasswordHint}`
											: 'PeakURL stores this password encrypted in the settings database.'
									}
									required={!status?.smtpPasswordConfigured}
								/>
							</div>
						)}
					</div>
				)}

				{usingSmtp && (
					<div className="grid grid-cols-1 gap-4 md:grid-cols-2">
						<div className="rounded-lg border border-stroke bg-surface-alt p-4">
							<p className="text-xs font-semibold uppercase tracking-[0.14em] text-text-muted">
								Storage
							</p>
							<p className="mt-2 text-sm font-medium text-heading">
								{status?.configurationLabel || 'settings table'}
							</p>
							<p className="mt-1 break-all text-xs text-text-muted">
								{status?.configurationPath || 'Not available'}
							</p>
						</div>
						<div className="rounded-lg border border-stroke bg-surface-alt p-4">
							<p className="text-xs font-semibold uppercase tracking-[0.14em] text-text-muted">
								Active method
							</p>
							<p className="mt-2 text-sm font-medium text-heading">
								SMTP
							</p>
							<p className="mt-1 text-xs text-text-muted">
								PeakURL will use your SMTP settings for account emails.
							</p>
						</div>
					</div>
				)}

				{showSubmitButton && (
					<div className="flex justify-end">
						<Button
							type="submit"
							size="sm"
							loading={isSaving || isLoading}
							disabled={managementDisabled}
						>
							{usingSmtp
								? 'Save Email Configuration'
								: 'Use PHP mail()'}
						</Button>
					</div>
				)}
			</form>
		</div>
	);
}

export default EmailDeliveryTab;
