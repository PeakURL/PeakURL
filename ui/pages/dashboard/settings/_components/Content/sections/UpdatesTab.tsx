// @ts-nocheck
import { Button } from '@/components/ui';
import {
	AlertCircle,
	CheckCircle2,
	Clock3,
	Download,
	ExternalLink,
	RefreshCcw,
} from 'lucide-react';

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
	const showInstallAction = updateAvailable && canApply;
	const showReleaseMeta =
		updateAvailable && (status?.releasedAt || status?.changelogUrl);

	return (
		<div className="space-y-5">
			<div className="rounded-lg border border-stroke bg-surface p-5">
				<div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
					<div className="space-y-2">
						<h2 className="text-base font-semibold text-heading">
							PeakURL Updates
						</h2>
						<p className="max-w-2xl text-sm leading-6 text-text-muted">
							PeakURL can check for new releases and, on packaged
							installs, apply them directly from the dashboard.
						</p>
					</div>

					<div className="flex flex-wrap gap-3">
						{showInstallAction ? (
							<Button
								size="sm"
								className="min-w-[11rem] whitespace-nowrap"
								onClick={onApply}
								loading={isApplying}
								icon={Download}
								disabled={isLoading || isChecking}
							>
								Install Update
							</Button>
						) : (
							<Button
								variant="outline"
								size="sm"
								className="min-w-[11rem] whitespace-nowrap"
								onClick={onCheck}
								loading={isChecking}
								icon={RefreshCcw}
								disabled={isApplying}
							>
								Check for Updates
							</Button>
						)}
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
							? canApply
								? 'A newer PeakURL release is ready to install when you are ready.'
								: 'A newer PeakURL release is available. This install cannot apply it automatically from the dashboard.'
							: 'This site is already running the latest known PeakURL release.'
					}
					variant={updateAvailable ? 'info' : 'success'}
				/>
			)}

			{isLoading && !status && !errorMessage && (
				<div className="rounded-lg border border-stroke bg-surface p-5 text-sm text-text-muted">
					Loading update status...
				</div>
			)}

			{showReleaseMeta && (
				<div className="rounded-lg border border-stroke bg-surface p-5 space-y-4">
					<h3 className="text-sm font-semibold text-heading">
						About this release
					</h3>
					<div className="grid gap-3 md:grid-cols-2">
						{status?.releasedAt && (
							<div className="rounded-lg border border-stroke bg-bg px-4 py-3">
								<div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-text-muted">
									<Clock3 size={14} />
									Released
								</div>
								<p className="mt-2 text-sm font-medium text-heading">
									{formatDate(status.releasedAt)}
								</p>
							</div>
						)}
						{status?.changelogUrl && (
							<div className="rounded-lg border border-stroke bg-bg px-4 py-3">
								<div className="text-xs font-semibold uppercase tracking-[0.14em] text-text-muted">
									Changelog
								</div>
								<a
									href={status.changelogUrl}
									target="_blank"
									rel="noreferrer"
									className="mt-2 inline-flex items-center gap-2 text-sm font-medium text-accent hover:underline"
								>
									Review what changed
									<ExternalLink size={14} />
								</a>
							</div>
						)}
					</div>
				</div>
			)}

			{updateAvailable && !canApply && status?.applyDisabledReason && (
				<div className="rounded-lg border border-stroke bg-surface p-4 text-sm leading-6 text-text-muted">
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
