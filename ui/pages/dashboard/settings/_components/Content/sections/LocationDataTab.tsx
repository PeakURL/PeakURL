import type { SubmitEvent } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui';
import { __ } from '@/i18n';
import { formatByteSize, formatDateTimeValue } from '@/utils';
import {
	AlertCircle,
	CheckCircle2,
	CloudDownload,
	MapPin,
	RefreshCcw,
} from 'lucide-react';
import type {
	LocationDataStatus,
	LocationDataTabProps,
	StateCardProps,
	StateCardVariant,
	StatCardProps,
} from './types';

function StateCard({
	icon: Icon,
	title,
	description,
	variant = 'info',
}: StateCardProps) {
	const styles: Record<StateCardVariant, string> = {
		info: 'border-blue-200 bg-blue-50 text-blue-950 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200',
		success:
			'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
		error: 'border-red-200 bg-red-50 text-red-950 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200',
	};

	return (
		<div className={`rounded-lg border p-4 ${styles[variant]}`}>
			<div className="flex items-start gap-3">
				<Icon size={18} className="mt-0.5 shrink-0" />
				<div className="space-y-1">
					<h3 className="text-sm font-semibold">{title}</h3>
					<p className="text-sm leading-6 opacity-80">
						{description}
					</p>
				</div>
			</div>
		</div>
	);
}

function LocationDataTab({
	status,
	errorMessage,
	isLoading,
	isSaving,
	isDownloading,
	onSave,
	onDownload,
}: LocationDataTabProps) {
	const [accountIdInput, setAccountIdInput] = useState<string | null>(null);
	const [licenseKey, setLicenseKey] = useState('');
	const [isEditingCredentials, setIsEditingCredentials] = useState(false);
	const [savedStatusOverride, setSavedStatusOverride] =
		useState<Partial<LocationDataStatus> | null>(null);
	const effectiveStatus = status?.credentialsConfigured
		? status
		: savedStatusOverride
			? { ...(status || {}), ...savedStatusOverride }
			: status;
	const accountId =
		null === accountIdInput
			? effectiveStatus?.accountId || ''
			: accountIdInput;
	const configurationLabel =
		effectiveStatus?.configurationLabel || __('settings table');
	const hasSavedCredentials = Boolean(effectiveStatus?.credentialsConfigured);

	const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		try {
			const nextStatus = await onSave({
				accountId: accountId.trim(),
				licenseKey: licenseKey.trim(),
			});
			if (nextStatus) {
				setSavedStatusOverride(nextStatus);
			}
			setIsEditingCredentials(false);
			setAccountIdInput(null);
			setLicenseKey('');
		} catch {}
	};

	const isReady = Boolean(effectiveStatus?.locationAnalyticsReady);

	return (
		<div className="space-y-5">
			<div className="rounded-lg border border-stroke bg-surface p-5">
				<div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
					<div className="space-y-2">
						<div className="flex items-center gap-3">
							<div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-500/10 text-primary-600 dark:bg-primary-500/20 dark:text-primary-400">
								<MapPin size={18} />
							</div>
							<div>
								<h2 className="text-base font-semibold text-heading">
									{__('Location Data')}
								</h2>
								<p className="text-sm text-text-muted">
									{__(
										'Enable country and city analytics with a local MaxMind GeoLite2 City database stored in your persistent content folder.'
									)}
								</p>
							</div>
						</div>
					</div>

					<Button
						size="sm"
						className="min-w-[10.5rem] whitespace-nowrap"
						onClick={onDownload}
						loading={isDownloading}
						icon={CloudDownload}
						disabled={
							isLoading ||
							isSaving ||
							!effectiveStatus?.credentialsConfigured ||
							Boolean(
								effectiveStatus &&
								!effectiveStatus.canManageFromDashboard
							)
						}
					>
						{isReady
							? __('Update Database')
							: __('Download Database')}
					</Button>
				</div>
			</div>

			<div className="grid grid-cols-1 gap-4 md:grid-cols-3">
				<StatCard
					label={__('Status')}
					value={isReady ? __('Ready') : __('Setup Required')}
				/>
				<StatCard
					label={__('Database Updated')}
					value={formatDateTimeValue(
						effectiveStatus?.lastDownloadedAt ||
							effectiveStatus?.databaseUpdatedAt,
						__('Never')
					)}
				/>
				<StatCard
					label={__('Database Size')}
					value={formatByteSize(
						effectiveStatus?.databaseSizeBytes,
						__('Not available')
					)}
				/>
			</div>

			{errorMessage && (
				<StateCard
					icon={AlertCircle}
					title={__('Location data unavailable')}
					description={errorMessage}
					variant="error"
				/>
			)}

			{effectiveStatus && !errorMessage && (
				<StateCard
					icon={isReady ? CheckCircle2 : RefreshCcw}
					title={
						isReady
							? __('Location analytics is enabled')
							: __('Location analytics is disabled')
					}
					description={
						isReady
							? __(
									'PeakURL is using the local GeoLite2 City database for click locations.'
								)
							: __(
									'Save your MaxMind credentials, then download the GeoLite2 City database to enable visitor country and city analytics.'
								)
					}
					variant={isReady ? 'success' : 'info'}
				/>
			)}

			{effectiveStatus?.manageDisabledReason && (
				<StateCard
					icon={AlertCircle}
					title={__('Dashboard management unavailable')}
					description={effectiveStatus.manageDisabledReason}
					variant="info"
				/>
			)}

			<div className="rounded-lg border border-stroke bg-surface p-5">
				<div className="mb-5 space-y-1">
					<h3 className="text-sm font-semibold text-heading">
						{__('MaxMind Credentials')}
					</h3>
					<p className="text-sm leading-6 text-text-muted">
						PeakURL stores these values encrypted in the
						<code className="mx-1 rounded bg-surface-alt px-1.5 py-0.5 text-xs">
							{configurationLabel}
						</code>
						so it can refresh the GeoLite2 City database later
						without asking again.
					</p>
				</div>

				{hasSavedCredentials && !isEditingCredentials ? (
					<div className="space-y-4">
						<div className="rounded-lg border border-stroke bg-surface-alt p-4">
							<div className="grid grid-cols-1 gap-4 md:grid-cols-2">
								<div>
									<p className="text-xs font-semibold uppercase tracking-[0.14em] text-text-muted">
										{__('Account ID')}
									</p>
									<p className="mt-2 text-sm font-medium text-heading">
										{effectiveStatus?.accountId}
									</p>
								</div>
								<div>
									<p className="text-xs font-semibold uppercase tracking-[0.14em] text-text-muted">
										{__('License Key')}
									</p>
									<p className="mt-2 text-sm font-medium text-heading">
										{effectiveStatus?.licenseKeyHint}
									</p>
								</div>
							</div>
						</div>
						<div className="flex flex-wrap gap-3">
							<Button
								type="button"
								size="sm"
								onClick={() => setIsEditingCredentials(true)}
								disabled={Boolean(
									effectiveStatus &&
									!effectiveStatus.canManageFromDashboard
								)}
							>
								{__('Update Credentials')}
							</Button>
							<Button
								type="button"
								variant="outline"
								size="sm"
								onClick={onDownload}
								loading={isDownloading}
								disabled={
									!effectiveStatus?.credentialsConfigured ||
									Boolean(
										effectiveStatus &&
										!effectiveStatus.canManageFromDashboard
									)
								}
								icon={CloudDownload}
							>
								{isReady
									? __('Refresh Database')
									: __('Download Database')}
							</Button>
						</div>
					</div>
				) : (
					<form className="space-y-4" onSubmit={handleSubmit}>
						<div className="grid grid-cols-1 gap-4 md:grid-cols-2">
							<div>
								<label className="mb-1.5 block text-sm font-medium text-heading">
									{__('MaxMind Account ID')}
								</label>
								<input
									type="text"
									inputMode="numeric"
									autoComplete="off"
									value={accountId}
									onChange={(event) =>
										setAccountIdInput(event.target.value)
									}
									placeholder="123456"
									className="w-full rounded-lg border border-stroke bg-surface-alt px-3 py-2.5 text-sm text-heading outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20"
								/>
							</div>

							<div>
								<label className="mb-1.5 block text-sm font-medium text-heading">
									{__('MaxMind License Key')}
								</label>
								<input
									type="password"
									autoComplete="new-password"
									value={licenseKey}
									onChange={(event) =>
										setLicenseKey(event.target.value)
									}
									placeholder={
										hasSavedCredentials
											? __(
													'Enter a new MaxMind license key'
												)
											: __(
													'Enter your MaxMind license key'
												)
									}
									className="w-full rounded-lg border border-stroke bg-surface-alt px-3 py-2.5 text-sm text-heading outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20"
								/>
							</div>
						</div>

						{hasSavedCredentials && (
							<p className="text-xs text-text-muted">
								{__('Saved license key:')}{' '}
								{effectiveStatus?.licenseKeyHint}
							</p>
						)}

						<div className="flex flex-wrap gap-3">
							<Button
								type="submit"
								size="sm"
								loading={isSaving}
								disabled={Boolean(
									effectiveStatus &&
									!effectiveStatus.canManageFromDashboard
								)}
							>
								{hasSavedCredentials
									? __('Save New Credentials')
									: __('Save Credentials')}
							</Button>
							{hasSavedCredentials && (
								<Button
									type="button"
									variant="outline"
									size="sm"
									onClick={() => {
										setIsEditingCredentials(false);
										setAccountIdInput(null);
										setLicenseKey('');
									}}
								>
									{__('Cancel')}
								</Button>
							)}
						</div>
					</form>
				)}
			</div>
		</div>
	);
}

function StatCard({ label, value }: StatCardProps) {
	return (
		<div className="rounded-lg border border-stroke bg-surface p-5">
			<p className="text-xs font-semibold uppercase tracking-[0.14em] text-text-muted">
				{label}
			</p>
			<p className="mt-3 text-lg font-semibold text-heading">{value}</p>
		</div>
	);
}

export default LocationDataTab;
