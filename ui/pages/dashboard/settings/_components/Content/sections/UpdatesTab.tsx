// @ts-nocheck
import { Button } from '@/components/ui';
import { __, sprintf } from '@/i18n';
import {
	AlertCircle,
	CheckCircle2,
	Clock3,
	Download,
	ExternalLink,
	RefreshCcw,
} from 'lucide-react';

function hasUpdateAvailable(status) {
	if (status?.updateAvailable) {
		return true;
	}

	const currentVersion = String(status?.currentVersion || '')
		.trim()
		.replace(/^v/i, '');
	const latestVersion = String(status?.latestVersion || '')
		.trim()
		.replace(/^v/i, '');

	if (!currentVersion || !latestVersion || currentVersion === latestVersion) {
		return false;
	}

	const currentParts = currentVersion
		.split('.')
		.map((part) => Number.parseInt(part, 10));
	const latestParts = latestVersion
		.split('.')
		.map((part) => Number.parseInt(part, 10));

	if (
		currentParts.some((part) => Number.isNaN(part)) ||
		latestParts.some((part) => Number.isNaN(part))
	) {
		return latestVersion !== currentVersion;
	}

	const maxLength = Math.max(currentParts.length, latestParts.length);

	for (let index = 0; index < maxLength; index += 1) {
		const currentPart = currentParts[index] ?? 0;
		const latestPart = latestParts[index] ?? 0;

		if (latestPart > currentPart) {
			return true;
		}

		if (latestPart < currentPart) {
			return false;
		}
	}

	return false;
}

function formatDate(value) {
	if (!value) {
		return __('Never');
	}

	try {
		return new Date(value).toLocaleString();
	} catch {
		return value;
	}
}

function buildAppStatus(status, errorMessage) {
	const updateAvailable = hasUpdateAvailable(status);

	if (errorMessage || status?.lastError) {
		return {
			tone: 'error',
			label: __('Unavailable'),
			title: __('Update service unavailable'),
			description:
				errorMessage ||
				status?.lastError ||
				__('PeakURL could not reach the update manifest.'),
		};
	}

	if (updateAvailable) {
		return {
			tone: 'info',
			label: __('Update Available'),
			title: sprintf(
				__('PeakURL %s is available'),
				status?.latestVersion || __('update')
			),
			description: status?.canApply
				? __('A newer PeakURL release is ready to install when you are ready.')
				: __('A newer PeakURL release is available, but this install cannot apply it automatically from the dashboard.'),
		};
	}

	return {
		tone: 'success',
		label: __('Current'),
		title: sprintf(
			__('PeakURL %s is current'),
			status?.currentVersion || __('Unknown')
		),
		description: __('This site is already running the latest known PeakURL release.'),
	};
}

function buildDatabaseStatus(databaseStatus) {
	if (databaseStatus?.lastError) {
		return {
			tone: 'error',
			label: __('Attention Needed'),
			title: __('Database upgrade failed'),
			description:
				databaseStatus.lastError ||
				__('PeakURL could not repair the database schema.'),
		};
	}

	if (databaseStatus?.upgradeRequired) {
		return {
			tone: 'info',
			label: __('Upgrade Recommended'),
			title: __('Database upgrade recommended'),
			description: __('The database needs attention before every runtime path is fully current.'),
		};
	}

	return {
		tone: 'success',
		label: __('Current'),
		title: __('Database schema is current'),
		description: __('PeakURL has no outstanding schema repairs for this release.'),
	};
}

function StatusBadge({ tone = 'info', label }) {
	const styles = {
		info: 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200',
		success:
			'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
		error: 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200',
	};

	return (
		<span
			className={`inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold ${styles[tone]}`}
		>
			{label}
		</span>
	);
}

function SectionCard({ children }) {
	return (
		<div className="rounded-xl border border-stroke bg-surface p-5 sm:p-6">
			{children}
		</div>
	);
}

function SectionHeader({
	title,
	description,
	badge,
	primaryAction,
	secondaryAction,
}) {
	return (
		<div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
			<div className="space-y-2">
				<div className="flex flex-wrap items-center gap-3">
					<h2 className="text-base font-semibold text-heading">
						{title}
					</h2>
					{badge ? <StatusBadge tone={badge.tone} label={badge.label} /> : null}
				</div>
				<p className="max-w-2xl text-sm leading-6 text-text-muted">
					{description}
				</p>
			</div>

			<div className="flex flex-wrap gap-3">
				{secondaryAction}
				{primaryAction}
			</div>
		</div>
	);
}

function MetricGrid({ items }) {
	return (
		<div className="mt-5 grid grid-cols-1 gap-3 md:grid-cols-3">
			{items.map((item) => (
				<div
					key={item.label}
					className="rounded-lg border border-stroke bg-bg px-4 py-4"
				>
					<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">
						{item.label}
					</p>
					<p className="mt-2 text-lg font-semibold text-heading">
						{item.value}
					</p>
				</div>
			))}
		</div>
	);
}

function InlineNotice({ icon: Icon, title, description, tone = 'info' }) {
	const styles = {
		info: 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200',
		success:
			'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
		error: 'border-red-200 bg-red-50 text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200',
	};

	return (
		<div className={`rounded-lg border p-4 ${styles[tone]}`}>
			<div className="flex items-start gap-3">
				<Icon size={18} className="mt-0.5 shrink-0" />
				<div className="space-y-1">
					<p className="text-sm font-semibold">{title}</p>
					<p className="text-sm leading-6 opacity-80">{description}</p>
				</div>
			</div>
		</div>
	);
}

function DetailRow({ label, value, icon: Icon, href }) {
	return (
		<div className="flex flex-col gap-2 rounded-lg border border-stroke bg-bg px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
			<div className="flex items-center gap-2 text-sm text-text-muted">
				{Icon ? <Icon size={15} /> : null}
				<span>{label}</span>
			</div>
			{href ? (
				<a
					href={href}
					target="_blank"
					rel="noreferrer"
					className="inline-flex items-center gap-2 text-sm font-medium text-accent hover:underline"
				>
					{value}
					<ExternalLink size={14} />
				</a>
			) : (
				<span className="text-sm font-medium text-heading">{value}</span>
			)}
		</div>
	);
}

function IssueList({ title, issues }) {
	return (
		<div className="rounded-lg border border-stroke bg-bg p-4">
			<p className="text-sm font-semibold text-heading">{title}</p>
			<ul className="mt-3 space-y-2 text-sm leading-6 text-text-muted">
				{issues.map((issue) => (
					<li key={issue.id || issue.label}>{issue.label}</li>
				))}
			</ul>
		</div>
	);
}

function UpdatesTab({
	status,
	errorMessage,
	isLoading,
	isChecking,
	isApplying,
	isRepairing,
	onCheck,
	onApply,
	onRepair,
}) {
	const updateAvailable = hasUpdateAvailable(status);
	const canApply = Boolean(status?.canApply);
	const showApplyDisabledReason =
		updateAvailable && !canApply && Boolean(status?.applyDisabledReason);
	const showReleaseMeta =
		updateAvailable && (status?.releasedAt || status?.releaseNotesUrl);
	const databaseStatus = status?.database || null;
	const databaseIssues = Array.isArray(databaseStatus?.issues)
		? databaseStatus.issues
		: [];
	const visibleDatabaseIssues = databaseIssues.slice(0, 6);
	const appState = buildAppStatus(status, errorMessage);
	const databaseState = buildDatabaseStatus(databaseStatus);

	if (isLoading && !status && !errorMessage) {
		return (
			<div className="rounded-xl border border-stroke bg-surface p-5 text-sm text-text-muted">
				{__('Loading update status...')}
			</div>
		);
	}

	return (
		<div className="space-y-5">
			<SectionCard>
				<SectionHeader
					title={__('Application Updates')}
					description={__(
						'Check for new PeakURL releases and install them from the dashboard when this site is running a packaged release.'
					)}
					badge={appState}
					primaryAction={
						updateAvailable ? (
							<div className="flex flex-col items-stretch gap-2 sm:items-end">
								<Button
									size="sm"
									className="min-w-[11rem] whitespace-nowrap"
									onClick={onApply}
									loading={isApplying}
									icon={Download}
									disabled={!canApply || isLoading || isChecking || isRepairing}
									title={!canApply ? status?.applyDisabledReason || '' : ''}
								>
									{__('Install Update')}
								</Button>
								{showApplyDisabledReason ? (
									<p className="max-w-[18rem] text-xs leading-5 text-text-muted sm:text-right">
										{status.applyDisabledReason}
									</p>
								) : null}
							</div>
						) : (
							<Button
								variant="outline"
								size="sm"
								className="min-w-[11rem] whitespace-nowrap"
								onClick={onCheck}
								loading={isChecking}
								icon={RefreshCcw}
								disabled={isApplying || isRepairing}
							>
								{__('Check for Updates')}
							</Button>
						)
					}
				/>

				<MetricGrid
					items={[
						{
							label: __('Installed Version'),
							value: status?.currentVersion || __('Unknown'),
						},
						{
							label: __('Latest Version'),
							value: status?.latestVersion || __('Unknown'),
						},
						{
							label: __('Last Checked'),
							value: formatDate(status?.lastCheckedAt),
						},
					]}
				/>

				<div className="mt-5">
					<InlineNotice
						icon={errorMessage || status?.lastError ? AlertCircle : updateAvailable ? Download : CheckCircle2}
						title={appState.title}
						description={appState.description}
						tone={appState.tone}
					/>
				</div>

				{showReleaseMeta ? (
					<div className="mt-5 space-y-3 border-t border-stroke pt-5">
						{status?.releasedAt ? (
							<DetailRow
								label={__('Released')}
								value={formatDate(status.releasedAt)}
								icon={Clock3}
							/>
						) : null}
						{status?.releaseNotesUrl ? (
							<DetailRow
								label={__('Release Notes')}
								value={__('Read release notes')}
								href={status.releaseNotesUrl}
							/>
						) : null}
					</div>
				) : null}
			</SectionCard>

			<SectionCard>
				<SectionHeader
					title={__('Database Schema')}
					description={__(
						'PeakURL can repair missing tables, columns, indexes, and legacy leftovers so the current release runs against the expected schema.'
					)}
					badge={databaseState}
					primaryAction={
						<Button
							variant={databaseStatus?.upgradeRequired ? 'primary' : 'outline'}
							size="sm"
							className="min-w-[13rem] whitespace-nowrap"
							onClick={onRepair}
							loading={isRepairing}
							disabled={isLoading || isChecking || isApplying}
						>
							{__('Run Database Upgrade')}
						</Button>
					}
				/>

				<MetricGrid
					items={[
						{
							label: __('Recorded Schema'),
							value: String(databaseStatus?.currentVersion ?? __('Unknown')),
						},
						{
							label: __('Required Schema'),
							value: String(databaseStatus?.targetVersion ?? __('Unknown')),
						},
						{
							label: __('Last Database Upgrade'),
							value: formatDate(databaseStatus?.lastUpgradedAt),
						},
					]}
				/>

				<div className="mt-5">
					<InlineNotice
						icon={databaseStatus?.lastError ? AlertCircle : databaseStatus?.upgradeRequired ? AlertCircle : CheckCircle2}
						title={databaseState.title}
						description={databaseState.description}
						tone={databaseState.tone}
					/>
				</div>

				{visibleDatabaseIssues.length > 0 || databaseStatus?.lastError ? (
					<div className="mt-5 space-y-3 border-t border-stroke pt-5">
						{databaseStatus?.lastError ? (
							<div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm leading-6 text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
								<p className="font-semibold">{__('Last database error')}</p>
								<p className="mt-1 opacity-80">{databaseStatus.lastError}</p>
							</div>
						) : null}
						{visibleDatabaseIssues.length > 0 ? (
							<IssueList
								title={__('Recent database findings')}
								issues={visibleDatabaseIssues}
							/>
						) : null}
					</div>
				) : null}
			</SectionCard>
		</div>
	);
}

export default UpdatesTab;
