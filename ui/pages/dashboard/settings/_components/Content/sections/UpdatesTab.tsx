import { Button } from '@/components/ui';
import { __, sprintf } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import { formatDateTimeValue } from '@/utils';
import {
	AlertCircle,
	CheckCircle2,
	Clock3,
	Download,
	ExternalLink,
	RefreshCcw,
} from 'lucide-react';
import type { ButtonVariant } from '@/components/ui';
import type {
	BadgeState,
	DatabaseStatus,
	DetailRowProps,
	InlineNoticeProps,
	IssueListProps,
	MetricGridProps,
	MetricItem,
	SectionCardProps,
	SectionHeaderProps,
	StatusBadgeProps,
	StatusTone,
	UpdateActionsProps,
	UpdateIssue,
	UpdateStatusPayload,
	UpdatesTabProps,
} from './types';

function hasUpdateAvailable(status?: UpdateStatusPayload | null) {
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

function hasReinstallAvailable(status?: UpdateStatusPayload | null) {
	return Boolean(status?.reinstallAvailable);
}

function buildAppStatus(
	status: UpdateStatusPayload | null | undefined,
	errorMessage?: string | null
): BadgeState {
	const updateAvailable = hasUpdateAvailable(status);
	const reinstallAvailable = hasReinstallAvailable(status);

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

	if (reinstallAvailable) {
		return {
			tone: 'success',
			label: __('Latest'),
			title: sprintf(
				__('PeakURL %s is the latest release'),
				status?.currentVersion || __('Unknown')
			),
			description: status?.canApply
				? __('This site is already on the latest release. Reinstall the latest package if you need to restore packaged files.')
				: __('This site is already on the latest release, but this install cannot reinstall the latest package automatically from the dashboard.'),
		};
	}

	return {
		tone: 'success',
		label: __('Latest'),
		title: sprintf(
			__('PeakURL %s is the latest release'),
			status?.currentVersion || __('Unknown')
		),
		description: __('This site is already running the latest known PeakURL release.'),
	};
}

function buildDatabaseStatus(
	databaseStatus: DatabaseStatus | null | undefined
): BadgeState {
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
		label: __('Up to Date'),
		title: __('Database schema is current'),
		description: __('PeakURL has no outstanding schema repairs for this release.'),
	};
}

function StatusBadge({ tone = 'info', label }: StatusBadgeProps) {
	const styles: Record<StatusTone, string> = {
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

function SectionCard({ children }: SectionCardProps) {
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
}: SectionHeaderProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? 'rtl' : 'ltr';

	return (
		<div
			dir={direction}
			className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
		>
			<div className="text-inline-start space-y-2">
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

function MetricGrid({ items }: MetricGridProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? 'rtl' : 'ltr';

	return (
		<div className="mt-5 grid grid-cols-1 gap-3 md:grid-cols-3">
			{items.map((item: MetricItem) => (
				<div
					key={item.label}
					dir={direction}
					className="text-inline-start rounded-lg border border-stroke bg-bg px-4 py-4"
				>
					<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">
						{item.label}
					</p>
					<p className="mt-2 text-lg font-semibold text-heading">
						<bdi dir="auto">{item.value}</bdi>
					</p>
				</div>
			))}
		</div>
	);
}

function InlineNotice({
	icon: Icon,
	title,
	description,
	tone = 'info',
}: InlineNoticeProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? 'rtl' : 'ltr';
	const styles: Record<StatusTone, string> = {
		info: 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200',
		success:
			'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
		error: 'border-red-200 bg-red-50 text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200',
	};

	return (
		<div className={`rounded-lg border p-4 ${styles[tone]}`}>
			<div
				dir={direction}
				className="flex items-start gap-3"
			>
				<Icon size={18} className="mt-0.5 shrink-0" />
				<div className="text-inline-start space-y-1">
					<p className="text-sm font-semibold">{title}</p>
					<p className="text-sm leading-6 opacity-80">{description}</p>
				</div>
			</div>
		</div>
	);
}

function UpdateActions({
	updateAvailable,
	reinstallAvailable,
	canApply,
	isLoading,
	isChecking,
	isApplying,
	isReinstalling,
	isRepairing,
	disabledReason,
	onCheck,
	onApply,
	onReinstall,
}: UpdateActionsProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? 'rtl' : 'ltr';
	const isInstallingRelease = isApplying || isReinstalling;
	const showDisabledReason =
		(updateAvailable || reinstallAvailable) &&
		!canApply &&
		Boolean(disabledReason);
	const primaryVariant: ButtonVariant = canApply ? 'primary' : 'outline';
	const primaryAction = updateAvailable ? (
		<Button
			variant={primaryVariant}
			size="sm"
			className="min-w-44 whitespace-nowrap"
			onClick={onApply}
			loading={isApplying}
			icon={Download}
			disabled={
				!canApply ||
				isLoading ||
				isChecking ||
				isRepairing ||
				isReinstalling
			}
			title={!canApply ? disabledReason || '' : ''}
		>
			{__('Install Update')}
		</Button>
	) : reinstallAvailable ? (
		<Button
			variant={primaryVariant}
			size="sm"
			className="min-w-52 whitespace-nowrap"
			onClick={onReinstall}
			loading={isReinstalling}
			icon={RefreshCcw}
			disabled={
				!canApply ||
				isLoading ||
				isChecking ||
				isRepairing ||
				isApplying
			}
			title={!canApply ? disabledReason || '' : ''}
		>
			{__('Reinstall Latest Version')}
		</Button>
	) : null;
	const showCheckButton = reinstallAvailable || !updateAvailable;

	return (
		<div
			className={`flex w-full flex-col gap-3 lg:max-w-104 ${
				isRtl ? 'lg:items-start' : 'lg:items-end'
			}`}
		>
			<div
				className={`flex flex-wrap gap-3 ${
					isRtl ? 'lg:justify-start' : 'lg:justify-end'
				}`}
			>
				{showCheckButton ? (
					<Button
						variant="outline"
						size="sm"
						className="min-w-44 whitespace-nowrap"
						onClick={onCheck}
						loading={isChecking}
						icon={RefreshCcw}
						disabled={isInstallingRelease || isRepairing}
					>
						{__('Check for Updates')}
					</Button>
				) : null}
				{primaryAction}
			</div>

			{showDisabledReason ? (
				<div
					dir={direction}
					className="text-inline-start max-w-sm rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
				>
					{disabledReason}
				</div>
			) : null}
		</div>
	);
}

function DetailRow({ label, value, icon: Icon, href }: DetailRowProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? 'rtl' : 'ltr';

	return (
		<div
			dir={direction}
			className="flex flex-col gap-2 rounded-lg border border-stroke bg-bg px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
		>
			<div className="flex items-center gap-2 text-sm text-text-muted">
				{Icon ? <Icon size={15} /> : null}
				<span>{label}</span>
			</div>
			{href ? (
				<a
					href={href}
					target="_blank"
					rel="noreferrer"
					dir={direction}
					className="inline-flex items-center gap-2 text-sm font-medium text-accent hover:underline"
				>
					<bdi dir="auto">{value}</bdi>
					<ExternalLink size={14} />
				</a>
			) : (
				<span className="text-inline-start text-sm font-medium text-heading">
					<bdi dir="auto">{value}</bdi>
				</span>
			)}
		</div>
	);
}

function IssueList({ title, issues }: IssueListProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? 'rtl' : 'ltr';

	return (
		<div
			dir={direction}
			className="text-inline-start rounded-lg border border-stroke bg-bg p-4"
		>
			<p className="text-sm font-semibold text-heading">{title}</p>
			<ul className="mt-3 list-disc space-y-2 ps-5 text-sm leading-6 text-text-muted">
				{issues.map((issue: UpdateIssue) => (
					<li key={issue.id || issue.label}>
						<bdi dir="auto">{issue.label}</bdi>
					</li>
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
	isReinstalling,
	isRepairing,
	onCheck,
	onApply,
	onReinstall,
	onRepair,
}: UpdatesTabProps) {
	const updateAvailable = hasUpdateAvailable(status);
	const reinstallAvailable = hasReinstallAvailable(status);
	const canApply = Boolean(status?.canApply);
	const showReleaseMeta =
		(updateAvailable || reinstallAvailable) &&
		(status?.releasedAt || status?.releaseNotesUrl);
	const databaseStatus = status?.database || null;
	const databaseIssues = Array.isArray(databaseStatus?.issues)
		? databaseStatus.issues
		: [];
	const visibleDatabaseIssues: UpdateIssue[] = databaseIssues.slice(0, 6);
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
						'Check for new PeakURL releases, install updates, or reinstall the latest packaged release from the dashboard.'
					)}
					badge={appState}
					primaryAction={
						<UpdateActions
							updateAvailable={updateAvailable}
							reinstallAvailable={reinstallAvailable}
							canApply={canApply}
							isLoading={isLoading}
							isChecking={isChecking}
							isApplying={isApplying}
							isReinstalling={isReinstalling}
							isRepairing={isRepairing}
							disabledReason={status?.applyDisabledReason || ''}
							onCheck={onCheck}
							onApply={onApply}
							onReinstall={onReinstall}
						/>
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
							value: formatDateTimeValue(
								status?.lastCheckedAt,
								__('Never')
							),
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
								value={formatDateTimeValue(
									status.releasedAt,
									__('Never')
								)}
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
							className="min-w-52 whitespace-nowrap"
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
							value: formatDateTimeValue(
								databaseStatus?.lastUpgradedAt,
								__('Never')
							),
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
