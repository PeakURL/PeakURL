import { Button } from '@/components/ui';
import { __, sprintf } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import { cn, formatDateTimeValue } from '@/utils';
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
import ReleaseInstallProgress from './ReleaseInstallProgress';

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
				? __('A newer PeakURL version is ready to install when you are ready.')
				: __('A newer PeakURL version is available, but this install cannot apply it automatically from the dashboard.'),
		};
	}

	if (reinstallAvailable) {
		return {
			tone: 'success',
			label: __('Latest'),
			title: sprintf(
				__('PeakURL %s is the latest version'),
				status?.currentVersion || __('Unknown')
			),
			description: status?.canApply
				? __('This site is already on the latest version. Reinstall the latest package if you need to restore packaged files.')
				: __('This site is already on the latest version, but this install cannot reinstall the latest package automatically from the dashboard.'),
		};
	}

	return {
		tone: 'success',
		label: __('Latest'),
		title: sprintf(
			__('PeakURL %s is the latest version'),
			status?.currentVersion || __('Unknown')
		),
		description: __('This site is already running the latest known PeakURL version.'),
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
		info: 'settings-updates-status-badge-info',
		success: 'settings-updates-status-badge-success',
		error: 'settings-updates-status-badge-error',
	};

	return (
		<span className={cn('settings-updates-status-badge', styles[tone])}>
			{label}
		</span>
	);
}

function SectionCard({ children }: SectionCardProps) {
	return <div className="settings-updates-card">{children}</div>;
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
			className="settings-updates-card-header"
		>
			<div className="settings-updates-card-copy">
				<div className="settings-updates-card-title-row">
					<h2 className="settings-updates-card-title">
						{title}
					</h2>
					{badge ? <StatusBadge tone={badge.tone} label={badge.label} /> : null}
				</div>
				<p className="settings-updates-card-description">
					{description}
				</p>
			</div>

			<div className="settings-updates-card-actions">
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
		<div className="settings-updates-metric-grid">
			{items.map((item: MetricItem) => (
				<div
					key={item.label}
					dir={direction}
					className="settings-updates-metric-item"
				>
					<p className="settings-updates-metric-label">
						{item.label}
					</p>
					<p className="settings-updates-metric-value">
						{'ltr' === item.valueDirection ? (
							<span className="preserve-ltr-value inline-block">
								{item.value}
							</span>
						) : 'rtl' === item.valueDirection ? (
							<span dir="rtl" className="inline-block">
								{item.value}
							</span>
						) : (
							<bdi dir="auto">{item.value}</bdi>
						)}
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
		info: 'settings-updates-notice-info',
		success: 'settings-updates-notice-success',
		error: 'settings-updates-notice-error',
	};

	return (
		<div className={cn('settings-updates-notice', styles[tone])}>
			<div
				dir={direction}
				className="settings-updates-notice-layout"
			>
				<Icon size={18} className="settings-updates-notice-icon" />
				<div className="settings-updates-notice-content">
					<p className="settings-updates-notice-title">{title}</p>
					<p className="settings-updates-notice-text">{description}</p>
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
			className="settings-updates-install-button"
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
			className="settings-updates-reinstall-button"
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
			className={cn(
				'settings-updates-actions',
				isRtl
					? 'settings-updates-actions-start'
					: 'settings-updates-actions-end'
			)}
		>
			<div
				className={cn(
					'settings-updates-actions-row',
					isRtl
						? 'settings-updates-actions-row-start'
						: 'settings-updates-actions-row-end'
				)}
			>
				{showCheckButton ? (
					<Button
						variant="outline"
						size="sm"
						className="settings-updates-check-button"
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
					className="settings-updates-disabled-reason"
				>
					{disabledReason}
				</div>
			) : null}
		</div>
	);
}

function DetailRow({
	label,
	value,
	icon: Icon,
	href,
	valueDirection = 'auto',
}: DetailRowProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? 'rtl' : 'ltr';
	const valueNode =
		'ltr' === valueDirection ? (
			<span className="preserve-ltr-value inline-block">{value}</span>
		) : 'rtl' === valueDirection ? (
			<span dir="rtl" className="inline-block">
				{value}
			</span>
		) : (
			<bdi dir="auto">{value}</bdi>
		);

	return (
		<div
			dir={direction}
			className="settings-updates-detail-row"
		>
			<div className="settings-updates-detail-label">
				{Icon ? <Icon size={15} /> : null}
				<span>{label}</span>
			</div>
			{href ? (
				<a
					href={href}
					target="_blank"
					rel="noreferrer"
					dir={direction}
					className="settings-updates-detail-link"
				>
					{valueNode}
					<ExternalLink size={14} />
				</a>
			) : (
				<span className="settings-updates-detail-value">
					{valueNode}
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
			className="settings-updates-issues"
		>
			<p className="settings-updates-issues-title">{title}</p>
			<ul className="settings-updates-issues-list">
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
	releaseInstallProgress,
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
			<div className="settings-updates-loading">
				{__('Loading update status...')}
			</div>
		);
	}

	return (
		<div className="settings-updates">
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
							valueDirection: 'ltr',
						},
						{
							label: __('Latest Version'),
							value: status?.latestVersion || __('Unknown'),
							valueDirection: 'ltr',
						},
						{
							label: __('Last Checked'),
							value: formatDateTimeValue(
								status?.lastCheckedAt,
								__('Never')
							),
							valueDirection: 'ltr',
						},
					]}
				/>

				<div className="settings-updates-block">
					<InlineNotice
						icon={errorMessage || status?.lastError ? AlertCircle : updateAvailable ? Download : CheckCircle2}
						title={appState.title}
						description={appState.description}
						tone={appState.tone}
					/>
				</div>

				{releaseInstallProgress && (isApplying || isReinstalling) ? (
					<div className="settings-updates-block">
						<ReleaseInstallProgress progress={releaseInstallProgress} />
					</div>
				) : null}

				{showReleaseMeta ? (
					<div className="settings-updates-divider">
						{status?.releasedAt ? (
							<DetailRow
								label={__('Released')}
								value={formatDateTimeValue(
									status.releasedAt,
									__('Never')
								)}
								icon={Clock3}
								valueDirection="ltr"
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
							className="settings-updates-repair-button"
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
							valueDirection: 'ltr',
						},
						{
							label: __('Required Schema'),
							value: String(databaseStatus?.targetVersion ?? __('Unknown')),
							valueDirection: 'ltr',
						},
						{
							label: __('Last Database Upgrade'),
							value: formatDateTimeValue(
								databaseStatus?.lastUpgradedAt,
								__('Never')
							),
							valueDirection: 'ltr',
						},
					]}
				/>

				<div className="settings-updates-block">
					<InlineNotice
						icon={databaseStatus?.lastError ? AlertCircle : databaseStatus?.upgradeRequired ? AlertCircle : CheckCircle2}
						title={databaseState.title}
						description={databaseState.description}
						tone={databaseState.tone}
					/>
				</div>

				{visibleDatabaseIssues.length > 0 || databaseStatus?.lastError ? (
					<div className="settings-updates-divider">
						{databaseStatus?.lastError ? (
							<div className="settings-updates-error-card">
								<p className="settings-updates-error-title">
									{__('Last database error')}
								</p>
								<p className="settings-updates-error-text">
									{databaseStatus.lastError}
								</p>
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
