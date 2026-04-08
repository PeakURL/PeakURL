import type { SubmitEvent } from 'react';
import { useState } from 'react';
import { AlertCircle, Mail, Send, Server } from 'lucide-react';
import { Button, Input, Select } from '@/components/ui';
import { __, sprintf } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import type { SmtpEncryption } from '../types';
import type {
	EmailDeliveryTabProps,
	EmailFormState,
	EmailStatus,
	MethodButtonProps,
} from './types';
import type { SelectOption } from '@/components/ui';

function MethodButton({
	isActive,
	title,
	description,
	onClick,
}: MethodButtonProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? 'rtl' : 'ltr';

	return (
		<button
			type="button"
			onClick={onClick}
			dir={direction}
			className={`text-inline-start rounded-lg border p-4 transition ${
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

function buildFormState(status?: EmailStatus | null): EmailFormState {
	return {
		driver: status?.driver || 'mail',
		fromEmail: status?.configuredFromEmail || '',
		fromName: status?.configuredFromName || '',
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
}: EmailDeliveryTabProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? 'rtl' : 'ltr';
	const [form, setForm] = useState<EmailFormState>(() =>
		buildFormState(status)
	);
	const encryptionOptions: SelectOption<SmtpEncryption>[] = [
		{ value: 'tls', label: __('TLS / STARTTLS') },
		{ value: 'ssl', label: __('SSL') },
		{ value: 'none', label: __('None') },
	];

	const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		await onSave({
			driver: form.driver,
			fromEmail: form.fromEmail.trim(),
			fromName: form.fromName.trim(),
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
				<div
					dir={direction}
					className="flex items-start gap-3"
				>
					<div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-500/10 text-primary-600 dark:bg-primary-500/20 dark:text-primary-400">
						<Mail size={18} />
					</div>
					<div className="text-inline-start space-y-1">
						<h2 className="text-base font-semibold text-heading">
							{__('Email Configuration')}
						</h2>
						<p className="text-sm leading-6 text-text-muted">
							{__(
								'PeakURL uses this mail transport for password-reset emails and other account recovery notifications.'
							)}
						</p>
					</div>
				</div>
			</div>

			{errorMessage && (
				<div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
					<div
						dir={direction}
						className="flex items-start gap-3"
					>
						<AlertCircle size={18} className="mt-0.5" />
						<div className="text-inline-start">
							<p className="font-semibold">
								{__('Mail status unavailable')}
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
					<div
						dir={direction}
						className="flex items-start gap-3"
					>
						<AlertCircle size={18} className="mt-0.5" />
						<div className="text-inline-start">
							<p className="font-semibold">
								{__('Dashboard management unavailable')}
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
						{__('Delivery method')}
					</div>
					<div className="grid grid-cols-1 gap-3 md:grid-cols-2">
						<MethodButton
							isActive={'mail' === form.driver}
							title={__('PHP mail()')}
							description={__(
								'Use the hosting server’s built-in PHP mail transport.'
							)}
							onClick={() =>
								setForm((current) => ({
									...current,
									driver: 'mail',
								}))
							}
						/>
						<MethodButton
							isActive={'smtp' === form.driver}
							title={__('SMTP')}
							description={__(
								'Send through your own SMTP server with optional authentication.'
							)}
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
							{__('SMTP connection')}
						</div>
						<div className="grid grid-cols-1 gap-4 md:grid-cols-2">
							<Input
								label={__('From Name')}
								value={form.fromName}
								onChange={(event) =>
									setForm((current) => ({
										...current,
										fromName: event.target.value,
									}))
								}
								placeholder={status?.fromName || __('PeakURL')}
							/>
								<Input
									label={__('From Email')}
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
								placeholder={__('noreply@yourdomain.com')}
							/>
						</div>
						<div className="grid grid-cols-1 gap-4 md:grid-cols-2">
								<Input
									label={__('SMTP Host')}
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
								placeholder={__('smtp.example.com')}
								required
							/>
							<Input
								label={__('SMTP Port')}
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
									{__('Encryption')}
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
									ariaLabel={__('SMTP encryption')}
								/>
							</div>
							<div className="space-y-2">
								<label className="block text-sm font-semibold text-heading">
									{__('Authentication')}
								</label>
								<button
									type="button"
									onClick={() =>
										setForm((current) => ({
											...current,
											smtpAuth: !current.smtpAuth,
										}))
									}
									dir={isRtl ? 'rtl' : 'ltr'}
									className={`inline-flex w-full items-center justify-between rounded-md border px-4 py-2 text-sm font-medium transition ${
										form.smtpAuth
											? 'border-accent bg-accent/10 text-heading'
											: 'border-stroke bg-surface text-text-muted'
									}`}
								>
									<span>
										{form.smtpAuth
											? __('Authentication enabled')
											: __('Authentication disabled')}
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
													? isRtl
														? '-translate-x-5'
														: 'translate-x-5'
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
										label={__('SMTP Username')}
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
									placeholder={__('mailer@example.com')}
									required
								/>
								<Input
									label={__('SMTP Password')}
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
													'Leave blank to keep the saved password'
												)
											: __('Enter the SMTP password')
									}
										helperText={
											status?.smtpPasswordConfigured
												? sprintf(
														__('Saved password: %s'),
														status.smtpPasswordHint ||
															''
													)
												: __(
														'PeakURL stores this password encrypted in the database.'
												)
									}
									required={!status?.smtpPasswordConfigured}
								/>
							</div>
						)}
					</div>
				)}

				{showSubmitButton && (
					<div
						className={`flex ${
							isRtl ? 'justify-start' : 'justify-end'
						}`}
					>
						<Button
							type="submit"
							size="sm"
							loading={isSaving || isLoading}
							disabled={managementDisabled}
						>
							{usingSmtp
								? __('Save Email Configuration')
								: __('Use PHP mail()')}
						</Button>
					</div>
				)}
			</form>
		</div>
	);
}

export default EmailDeliveryTab;
