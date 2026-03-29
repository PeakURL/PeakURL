// @ts-nocheck
import { Button } from '@/components/ui';
import { AlertCircle, CheckCircle2, Download, RefreshCcw } from 'lucide-react';

function formatDate(value) {
	if (!value) {
		return 'Never';
	}

	try {
		return new Date(value).toLocaleString();
	} catch {
		return value;
	}
}

function DetailRow({ label, value, href }) {
	return (
		<div className="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
			<span className="text-sm font-medium text-heading">{label}</span>
			{href ? (
				<a
					href={href}
					target="_blank"
					rel="noreferrer"
					className="max-w-full break-all text-sm text-accent hover:underline"
				>
					{value || href}
				</a>
			) : (
				<span className="max-w-full break-all text-sm text-text-muted">
					{value || 'Not available'}
				</span>
			)}
		</div>
	);
}

function UpdatesTab({
	status,
	errorMessage,
	isLoading,
	isChecking,
	isApplying,
	onCheck,
	onApply,
}) {
	const updateAvailable = Boolean(status?.updateAvailable);
	const canApply = Boolean(status?.canApply);

	return (
		<div className="space-y-5">
			<div className="rounded-lg border border-stroke bg-surface p-5">
				<div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
					<div className="space-y-2">
						<h2 className="text-base font-semibold text-heading">
							PeakURL Updates
						</h2>
						<p className="max-w-2xl text-sm leading-6 text-text-muted">
							Check the latest PeakURL release, review the package
							details, and install updates directly from the
							dashboard when this site is running from a packaged
							release.
						</p>
					</div>

					<div className="flex flex-wrap gap-3">
						<Button
							variant="outline"
							size="sm"
							onClick={onCheck}
							loading={isChecking}
							icon={RefreshCcw}
							disabled={isApplying}
						>
							Check for Updates
						</Button>
						<Button
							size="sm"
							onClick={onApply}
							loading={isApplying}
							icon={Download}
							disabled={
								isLoading ||
								isChecking ||
								!updateAvailable ||
								!canApply
							}
						>
							Install Update
						</Button>
					</div>
				</div>
			</div>

			<div className="grid grid-cols-1 gap-4 md:grid-cols-3">
				<StatCard
					label="Installed Version"
					value={status?.currentVersion || 'Unknown'}
				/>
				<StatCard
					label="Latest Version"
					value={status?.latestVersion || 'Unknown'}
				/>
				<StatCard
					label="Last Checked"
					value={formatDate(status?.lastCheckedAt)}
				/>
			</div>

			{(errorMessage || status?.lastError) && (
				<StateCard
					icon={AlertCircle}
					title="Update service unavailable"
					description={
						errorMessage ||
						status?.lastError ||
						'PeakURL could not reach the update manifest.'
					}
					variant="error"
				/>
			)}

			{status && !errorMessage && (
				<StateCard
					icon={updateAvailable ? Download : CheckCircle2}
					title={
						updateAvailable
							? `PeakURL ${status.latestVersion} is available`
							: `PeakURL ${status.currentVersion} is current`
					}
					description={
						updateAvailable
							? 'Review the release summary below, then install the latest package when you are ready.'
							: 'This site is already on the latest known PeakURL release.'
					}
					variant={updateAvailable ? 'info' : 'success'}
				/>
			)}

			{isLoading && !status && !errorMessage && (
				<div className="rounded-lg border border-stroke bg-surface p-5 text-sm text-text-muted">
					Loading update status...
				</div>
			)}

			<div className="rounded-lg border border-stroke bg-surface p-5 space-y-4">
				<h3 className="text-sm font-semibold text-heading">
					Release Summary
				</h3>
				<div className="space-y-3">
					<DetailRow
						label="Release Channel"
						value={status?.channel || 'latest'}
					/>
					<DetailRow
						label="Package Integrity"
						value={
							status?.checksumSha256
								? 'SHA-256 checksum available'
								: 'Not available'
						}
					/>
					<DetailRow
						label="Checksum"
						value={status?.checksumSha256 || 'Not available'}
					/>
					<DetailRow
						label="Changelog"
						value={status?.changelogUrl}
						href={status?.changelogUrl}
					/>
					<DetailRow
						label="Released"
						value={formatDate(status?.releasedAt)}
					/>
					<DetailRow
						label="Minimum PHP"
						value={status?.minimumPhp || 'Not specified'}
					/>
					<DetailRow
						label="Minimum MySQL"
						value={status?.minimumMysql || 'Not specified'}
					/>
					<DetailRow
						label="Minimum MariaDB"
						value={status?.minimumMariaDb || 'Not specified'}
					/>
				</div>
			</div>

			{!canApply && status?.applyDisabledReason && (
				<div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
					{status.applyDisabledReason}
				</div>
			)}
		</div>
	);
}

function StatCard({ label, value }) {
	return (
		<div className="rounded-lg border border-stroke bg-surface p-5">
			<p className="text-xs font-semibold uppercase tracking-[0.14em] text-text-muted">
				{label}
			</p>
			<p className="mt-3 text-lg font-semibold text-heading">{value}</p>
		</div>
	);
}

function StateCard({ icon: Icon, title, description, variant = 'info' }) {
	const styles = {
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

export default UpdatesTab;
