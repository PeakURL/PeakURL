import { useState } from 'react';
import {
	AlertCircle,
	CheckCircle2,
	ChevronDown,
	ChevronUp,
	Copy,
} from 'lucide-react';
import { useNotification } from '@/components';
import { __, sprintf } from '@/i18n';
import { useGetSystemStatusQuery } from '@/store/slices/api';
import {
	copyToClipboard,
	extractErrorMessage,
	formatByteSize,
	formatCount,
	formatDateTimeValue,
} from '@/utils';
import type {
	ErrorStateProps,
	InfoItem,
	InfoSectionData,
	InfoSectionProps,
	IssueRowProps,
	IssueSectionProps,
	StatusTabsProps,
	StatusTone,
	StatusView,
	SystemCheck,
} from './types';

function hasValue(value: unknown) {
	return value !== undefined && value !== null && '' !== value;
}

function displayValue(value: unknown) {
	return hasValue(value) ? String(value) : __('Not available');
}

function joinHelperText(parts: Array<string | undefined | null>) {
	return parts.filter((part) => hasValue(part)).join(' • ');
}

function formatBoolean(
	value: unknown,
	truthy: string = __('Yes'),
	falsy: string = __('No')
) {
	return value ? truthy : falsy;
}

function getOverallLabel(status: string | undefined) {
	return 'ok' === status ? __('Good') : __('Should be improved');
}

function getStatusTone(status: string | undefined): StatusTone {
	switch (status) {
		case 'error':
			return {
				ring: 'border-red-300',
				dot: 'bg-red-500',
				text: 'text-red-700 dark:text-red-300',
				badge: 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200',
				panel: 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200',
			};
		case 'ok':
			return {
				ring: 'border-emerald-300',
				dot: 'bg-emerald-500',
				text: 'text-emerald-700 dark:text-emerald-300',
				badge: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
				panel: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
			};
		default:
			return {
				ring: 'border-amber-300',
				dot: 'bg-amber-500',
				text: 'text-amber-700 dark:text-amber-300',
				badge: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
				panel: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
			};
	}
}

function getCheckCategoryLabel(checkId: string | null | undefined) {
	switch (checkId) {
		case 'database':
			return __('Database');
		case 'content':
			return __('Storage');
		case 'languages':
			return __('Translations');
		case 'mail':
			return __('Email');
		case 'geoip':
			return __('Location Data');
		case 'zip':
			return __('Updates');
		default:
			return __('System');
	}
}

function formatHeadingCount(count: number, singular: string, plural: string) {
	return 1 === count ? singular : plural.replace('%s', formatCount(count));
}

function buildExportText(sections: InfoSectionData[]) {
	return [
		'PeakURL System Status',
		...sections.map((section: InfoSectionData) => {
			const rows = section.items.map((item: InfoItem) => {
				const value = displayValue(item.value);
				return item.helperText
					? `${item.label}: ${value} (${item.helperText})`
					: `${item.label}: ${value}`;
			});

			return `${section.title}\n${rows.join('\n')}`;
		}),
	].join('\n\n');
}

function LoadingState() {
	return (
		<div className="rounded-lg border border-stroke bg-surface p-6 text-sm text-text-muted">
			{__('Loading system status...')}
		</div>
	);
}

function ErrorState({ errorMessage }: ErrorStateProps) {
	return (
		<div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
			<div className="flex items-start gap-3">
				<AlertCircle size={18} className="mt-0.5 shrink-0" />
				<div className="space-y-1">
					<h2 className="font-semibold">
						{__('System status unavailable')}
					</h2>
					<p className="leading-6">{errorMessage}</p>
				</div>
			</div>
		</div>
	);
}

function StatusTabs({ activeView, onChange }: StatusTabsProps) {
	const tabs: Array<{ id: StatusView; label: string }> = [
		{ id: 'status', label: __('Status') },
		{ id: 'info', label: __('Info') },
	];

	return (
		<div className="mt-6 flex items-center justify-center gap-6">
			{tabs.map((tab) => (
				<button
					key={tab.id}
					type="button"
					onClick={() => onChange(tab.id)}
					className={`border-b-2 px-1 pb-3 text-sm font-medium transition-colors ${
						activeView === tab.id
							? 'border-accent text-heading'
							: 'border-transparent text-text-muted hover:text-heading'
					}`}
				>
					{tab.label}
				</button>
			))}
		</div>
	);
}

function IssueRow({ check, isOpen, onToggle, showBorder }: IssueRowProps) {
	return (
		<div className={showBorder ? 'border-t border-stroke' : ''}>
			<button
				type="button"
				onClick={onToggle}
				className="flex w-full items-center justify-between gap-4 px-4 py-4 text-left transition-colors hover:bg-surface-alt/60"
			>
				<div className="min-w-0">
					<p className="text-sm font-semibold text-heading">
						{check?.label || __('Check')}
					</p>
				</div>
				<div className="flex shrink-0 items-center gap-3">
					<span className="rounded-md border border-accent/20 bg-accent/5 px-2.5 py-1 text-xs font-semibold text-accent">
						{getCheckCategoryLabel(check?.id)}
					</span>
					{isOpen ? (
						<ChevronUp size={16} className="text-text-muted" />
					) : (
						<ChevronDown size={16} className="text-text-muted" />
					)}
				</div>
			</button>
			{isOpen ? (
				<div className="border-t border-stroke bg-surface-alt/40 px-4 py-4 text-sm leading-6 text-text-muted">
					{check?.description || __('Not available')}
				</div>
			) : null}
		</div>
	);
}

function IssueSection({
	title,
	description,
	checks,
	expandedChecks,
	onToggleCheck,
}: IssueSectionProps) {
	return (
		<section className="space-y-4">
			<div className="space-y-2">
				<h3 className="text-2xl font-semibold text-heading">{title}</h3>
				<p className="max-w-3xl text-sm leading-6 text-text-muted">
					{description}
				</p>
			</div>

			<div className="overflow-hidden rounded-lg border border-stroke bg-surface">
				{checks.map((check: SystemCheck, index: number) => {
					const checkKey =
						check?.id || check?.label || `check-${index}`;

					return (
						<IssueRow
							key={checkKey}
							check={check}
							isOpen={expandedChecks.has(checkKey)}
							onToggle={() => onToggleCheck(checkKey)}
							showBorder={index > 0}
						/>
					);
				})}
			</div>
		</section>
	);
}

function InfoSection({ section, isOpen, onToggle }: InfoSectionProps) {
	return (
		<div className="overflow-hidden rounded-lg border border-stroke bg-surface">
			<button
				type="button"
				onClick={onToggle}
				className="flex w-full items-center justify-between gap-4 px-4 py-3.5 text-left transition-colors hover:bg-surface-alt/60"
			>
				<span className="text-sm font-semibold text-heading">
					{section.title}
				</span>
				{isOpen ? (
					<ChevronUp size={16} className="text-text-muted" />
				) : (
					<ChevronDown size={16} className="text-text-muted" />
				)}
			</button>

			{isOpen ? (
				<div className="overflow-x-auto border-t border-stroke">
					<table className="min-w-full text-sm">
						<tbody>
							{section.items.map(
								(item: InfoItem, index: number) => (
									<tr
										key={`${section.id}-${item.label}`}
										className={
											index > 0
												? 'border-t border-stroke'
												: ''
										}
									>
										<th className="w-[34%] min-w-[180px] bg-surface-alt px-4 py-3 text-left align-top font-medium text-heading">
											{item.label}
										</th>
										<td className="px-4 py-3 align-top text-text-muted">
											<p
												className={`text-heading ${
													item.monospace
														? 'break-all font-mono text-xs'
														: ''
												}`}
											>
												{displayValue(item.value)}
											</p>
											{item.helperText ? (
												<p className="mt-1 text-xs leading-5 text-text-muted">
													{item.helperText}
												</p>
											) : null}
										</td>
									</tr>
								)
							)}
						</tbody>
					</table>
				</div>
			) : null}
		</div>
	);
}

function SystemStatusPage() {
	const notification = useNotification();
	const {
		data: systemStatusResponse,
		error: systemStatusError,
		isLoading,
	} = useGetSystemStatusQuery(undefined);
	const status = systemStatusResponse?.data || null;
	const errorMessage = extractErrorMessage(systemStatusError);
	const [activeView, setActiveView] = useState<StatusView>('status');
	const [showPassedChecks, setShowPassedChecks] = useState(false);
	const [copiedInfo, setCopiedInfo] = useState(false);
	const [expandedChecks, setExpandedChecks] = useState<Set<string>>(
		new Set()
	);
	const [expandedSections, setExpandedSections] = useState(
		new Set<string>(['peakurl'])
	);

	if (isLoading && !status) {
		return <LoadingState />;
	}

	if (!status) {
		return (
			<ErrorState
				errorMessage={
					errorMessage ||
					__('System status data is not available right now.')
				}
			/>
		);
	}

	const overallStatus = status?.summary?.overall || 'warning';
	const overallTone = getStatusTone(overallStatus);
	const overallLabel = getOverallLabel(overallStatus);
	const checks: SystemCheck[] = status?.checks || [];
	const errorChecks = checks.filter(
		(check: SystemCheck) => 'error' === check.status
	);
	const warningChecks = checks.filter(
		(check: SystemCheck) => 'warning' === check.status
	);
	const passingChecks = checks.filter(
		(check: SystemCheck) => 'ok' === check.status
	);
	const mailDriver =
		'smtp' === status?.mail?.driver ? __('SMTP') : __('PHP mail()');
	const languageName =
		status?.site?.languageNativeName || status?.site?.languageLabel;
	const maxExecutionTime = status?.server?.maxExecutionTime;
	const databasePort = status?.database?.port;
	const recordedSchemaVersion = status?.database?.schemaVersion;
	const requiredSchemaVersion = status?.database?.requiredSchemaVersion;
	const schemaIssuesCount = status?.database?.schemaIssuesCount;

	const peakurlItems = [
		{ label: __('Version'), value: status?.site?.version || __('Unknown') },
		{
			label: __('Site Language'),
			value: languageName,
			helperText: status?.site?.locale || '',
		},
		{ label: __('Environment'), value: status?.site?.environment },
		{
			label: __('Site URL'),
			value: status?.site?.url,
			monospace: true,
		},
		{
			label: __('Install Type'),
			value:
				'release' === status?.site?.installType
					? __('Packaged Release')
					: __('Source Checkout'),
		},
		{
			label: __('Debug Mode'),
			value: formatBoolean(
				status?.site?.debugEnabled,
				__('Enabled'),
				__('Disabled')
			),
		},
		{
			label: __('Last Checked'),
			value: formatDateTimeValue(status?.generatedAt, __('Not available')),
		},
	];

	const storageItems = [
		{
			label: __('Content Directory'),
			value: status?.storage?.contentDirectory,
			helperText: status?.storage?.contentExists
				? joinHelperText([
						formatBoolean(
							status?.storage?.contentWritable,
							__('Writable'),
							__('Not Writable')
						),
						formatByteSize(
							status?.storage?.contentDirectorySizeBytes,
							''
						),
					])
				: __('Missing'),
			monospace: true,
		},
		{
			label: __('Languages Directory'),
			value: status?.storage?.languagesDirectory,
			helperText: status?.storage?.languagesDirectoryExists
				? joinHelperText([
						formatBoolean(
							status?.storage?.languagesDirectoryReadable,
							__('Readable'),
							__('Not Readable')
						),
						formatByteSize(
							status?.storage?.languagesDirectorySizeBytes,
							''
						),
					])
				: __('Missing'),
			monospace: true,
		},
		{
			label: __('Config File'),
			value: status?.storage?.configPath,
			helperText: status?.storage?.configExists
				? joinHelperText([
						__('Present'),
						formatByteSize(status?.storage?.configSizeBytes, ''),
					])
				: __('Missing'),
			monospace: true,
		},
		{
			label: __('Debug Log'),
			value: status?.storage?.debugLogPath,
			helperText: status?.storage?.debugLogExists
				? joinHelperText([
						formatBoolean(
							status?.storage?.debugLogReadable,
							__('Readable'),
							__('Not Readable')
						),
						formatByteSize(status?.storage?.debugLogSizeBytes, ''),
					])
				: __('Not created yet'),
			monospace: true,
		},
		{
			label: __('App Directory'),
			value: status?.storage?.appDirectory,
			helperText: joinHelperText([
				formatBoolean(
					status?.storage?.appWritable,
					__('Writable'),
					__('Not Writable')
				),
				formatByteSize(status?.storage?.appDirectorySizeBytes, ''),
			]),
			monospace: true,
		},
		{
			label: __('Release Root'),
			value: status?.storage?.releaseRoot,
			helperText: formatByteSize(
				status?.storage?.releaseRootSizeBytes,
				''
			),
			monospace: true,
		},
	];

	const serverItems = [
		{ label: __('PHP Version'), value: status?.server?.phpVersion },
		{ label: __('PHP SAPI'), value: status?.server?.phpSapi },
		{
			label: __('Web Server'),
			value: status?.server?.serverSoftware || __('Unknown'),
		},
		{
			label: __('Operating System'),
			value: status?.server?.operatingSystem || __('Unknown'),
		},
		{ label: __('Timezone'), value: status?.server?.timezone },
		{ label: __('Memory Limit'), value: status?.server?.memoryLimit },
		{
			label: __('Max Execution Time'),
			value: hasValue(maxExecutionTime)
				? `${String(maxExecutionTime)}s`
				: __('Unknown'),
		},
		{
			label: __('Upload Max Filesize'),
			value: status?.server?.uploadMaxFilesize,
		},
		{ label: __('POST Max Size'), value: status?.server?.postMaxSize },
		{
			label: __('Intl Extension'),
			value: formatBoolean(
				status?.server?.extensions?.intl,
				__('Available'),
				__('Missing')
			),
		},
		{
			label: __('cURL Extension'),
			value: formatBoolean(
				status?.server?.extensions?.curl,
				__('Available'),
				__('Missing')
			),
		},
		{
			label: __('ZipArchive'),
			value: formatBoolean(
				status?.server?.extensions?.zip,
				__('Available'),
				__('Missing')
			),
		},
	];

	const databaseItems = [
		{
			label: __('Database Server'),
			value: status?.database?.serverType || __('Unknown'),
		},
		{
			label: __('Version'),
			value: status?.database?.version || __('Unknown'),
		},
		{ label: __('Host'), value: status?.database?.host },
		{
			label: __('Port'),
			value: hasValue(databasePort)
				? String(databasePort)
				: __('Not available'),
		},
		{ label: __('Database Name'), value: status?.database?.name },
		{ label: __('Charset'), value: status?.database?.charset },
		{
			label: __('Table Prefix'),
			value: status?.database?.prefix || __('None'),
		},
		{
			label: __('Recorded Schema'),
			value: hasValue(recordedSchemaVersion)
				? String(recordedSchemaVersion)
				: __('Unknown'),
		},
		{
			label: __('Required Schema'),
			value: hasValue(requiredSchemaVersion)
				? String(requiredSchemaVersion)
				: __('Unknown'),
		},
		{
			label: __('Schema Status'),
			value: status?.database?.schemaUpgradeRequired
				? __('Upgrade Recommended')
				: __('Current'),
			helperText: schemaIssuesCount
				? `${schemaIssuesCount} ${__('issue(s) detected')}`
				: '',
		},
	];

	const mailItems = [
		{ label: __('Driver'), value: mailDriver },
		{
			label: __('Transport Ready'),
			value: formatBoolean(
				status?.mail?.transportReady,
				__('Ready'),
				__('Needs Setup')
			),
		},
		{ label: __('From Email'), value: status?.mail?.fromEmail },
		{ label: __('From Name'), value: status?.mail?.fromName },
		{
			label: __('SMTP Host'),
			value: status?.mail?.smtpHost || __('Not configured'),
		},
		{
			label: __('SMTP Port'),
			value: status?.mail?.smtpPort || __('Not configured'),
		},
		{
			label: __('Encryption'),
			value: status?.mail?.smtpEncryption || __('None'),
		},
		{
			label: __('Authentication'),
			value: formatBoolean(
				status?.mail?.smtpAuth,
				__('Enabled'),
				__('Disabled')
			),
		},
		{
			label: __('Settings Storage'),
			value: status?.mail?.configurationLabel || __('Not available'),
			helperText: status?.mail?.configurationPath || '',
		},
	];

	const locationItems = [
		{
			label: __('Status'),
			value: status?.location?.locationAnalyticsReady
				? __('Ready')
				: __('Setup Required'),
		},
		{
			label: __('Database Updated'),
			value: formatDateTimeValue(
				status?.location?.lastDownloadedAt ||
					status?.location?.databaseUpdatedAt,
				__('Not available')
			),
		},
		{
			label: __('Database Size'),
			value: formatByteSize(
				status?.location?.databaseSizeBytes,
				__('Not available')
			),
		},
		{
			label: __('Credentials Saved'),
			value: formatBoolean(
				status?.location?.credentialsConfigured,
				__('Yes'),
				__('No')
			),
		},
		{
			label: __('Account ID'),
			value: status?.location?.accountId || __('Not configured'),
		},
		{
			label: __('Database Path'),
			value: status?.location?.databasePath,
			helperText: formatBoolean(
				status?.location?.databaseReadable,
				__('Readable'),
				__('Not Readable')
			),
			monospace: true,
		},
		{
			label: __('Refresh Command'),
			value: status?.location?.downloadCommand,
			monospace: true,
		},
	];

	const dataItems = [
		{ label: __('Users'), value: formatCount(status?.data?.users) },
		{ label: __('Short Links'), value: formatCount(status?.data?.links) },
		{ label: __('Clicks'), value: formatCount(status?.data?.clicks) },
		{
			label: __('Active Sessions'),
			value: formatCount(status?.data?.sessions),
		},
		{ label: __('API Keys'), value: formatCount(status?.data?.apiKeys) },
		{ label: __('Webhooks'), value: formatCount(status?.data?.webhooks) },
		{
			label: __('Activity Events'),
			value: formatCount(status?.data?.auditEvents),
		},
		{
			label: __('Managed Tables'),
			value: formatCount(status?.data?.managedTables),
		},
	];

	const infoSections: InfoSectionData[] = [
		{ id: 'peakurl', title: 'PeakURL', items: peakurlItems },
		{
			id: 'directories',
			title: __('Directories and Sizes'),
			items: storageItems,
		},
		{ id: 'server', title: __('Server'), items: serverItems },
		{ id: 'database', title: __('Database'), items: databaseItems },
		{ id: 'email', title: __('Email'), items: mailItems },
		{
			id: 'location',
			title: __('Location Data'),
			items: locationItems,
		},
		{ id: 'footprint', title: __('Data Footprint'), items: dataItems },
	];

	const toggleCheck = (checkKey: string) => {
		setExpandedChecks((current) => {
			const next = new Set(current);

			if (next.has(checkKey)) {
				next.delete(checkKey);
			} else {
				next.add(checkKey);
			}

			return next;
		});
	};

	const toggleSection = (sectionId: string) => {
		setExpandedSections((current) => {
			const next = new Set(current);

			if (next.has(sectionId)) {
				next.delete(sectionId);
			} else {
				next.add(sectionId);
			}

			return next;
		});
	};

	const handleCopyInfo = async () => {
		try {
			await copyToClipboard(buildExportText(infoSections));
			setCopiedInfo(true);
			notification.success(
				__('Copied'),
				__('System status info copied to clipboard')
			);
			window.setTimeout(() => setCopiedInfo(false), 2000);
		} catch {
			notification.error(
				__('Copy failed'),
				__('PeakURL could not copy the system status information.')
			);
		}
	};

	return (
		<div className="space-y-4">
			<div className="overflow-hidden rounded-lg border border-stroke bg-surface shadow-sm">
				<div className="border-b border-stroke bg-surface px-5 pt-8 sm:px-8">
					<div className="mx-auto max-w-2xl text-center">
						<h1 className="text-3xl font-semibold tracking-tight text-heading">
							{__('System Status')}
						</h1>

						<div className="mt-4 flex items-center justify-center gap-2">
							<span
								className={`flex h-5 w-5 items-center justify-center rounded-full border-2 ${overallTone.ring}`}
							>
								<span
									className={`h-2 w-2 rounded-full ${overallTone.dot}`}
								/>
							</span>
							<span
								className={`text-sm font-semibold ${overallTone.text}`}
							>
								{overallLabel}
							</span>
						</div>

						<p className="mt-3 text-sm text-text-muted">
							{sprintf(
								__('Last checked: %s'),
								formatDateTimeValue(
									status?.generatedAt,
									__('Not available')
								)
							)}
						</p>
					</div>

					<StatusTabs
						activeView={activeView}
						onChange={setActiveView}
					/>
				</div>

				<div className="space-y-8 bg-bg px-5 py-6 sm:px-8">
					{errorMessage ? (
						<ErrorState errorMessage={errorMessage} />
					) : null}

					{'status' === activeView ? (
						<div className="space-y-8">
							<section className="space-y-3">
								<h2 className="text-2xl font-semibold text-heading">
									{__('System Status')}
								</h2>
								<p className="max-w-3xl text-sm leading-6 text-text-muted">
									{__(
										'The system status check shows information about your PeakURL configuration and items that may need your attention.'
									)}
								</p>
							</section>

							{errorChecks.length > 0 ? (
								<IssueSection
									title={formatHeadingCount(
										errorChecks.length,
										__('1 critical issue'),
										__('%s critical issues')
									)}
									description={__(
										'Critical issues are items that may have a high impact on your site performance or security. Resolving these issues should be prioritized.'
									)}
									checks={errorChecks}
									expandedChecks={expandedChecks}
									onToggleCheck={toggleCheck}
								/>
							) : null}

							{warningChecks.length > 0 ? (
								<IssueSection
									title={formatHeadingCount(
										warningChecks.length,
										__('1 recommended improvement'),
										__('%s recommended improvements')
									)}
									description={__(
										'Recommended improvements are beneficial for your site, though not as urgent as a critical issue. They may include improvements in areas such as security, performance, and user experience.'
									)}
									checks={warningChecks}
									expandedChecks={expandedChecks}
									onToggleCheck={toggleCheck}
								/>
							) : null}

							{0 === errorChecks.length &&
							0 === warningChecks.length ? (
								<div
									className={`rounded-lg border px-4 py-3 text-sm ${getStatusTone('ok').panel}`}
								>
									{__(
										'All system status checks are currently passing.'
									)}
								</div>
							) : null}

							{passingChecks.length > 0 ? (
								<section className="space-y-4">
									<div className="flex justify-center">
										<button
											type="button"
											onClick={() =>
												setShowPassedChecks(
													(current) => !current
												)
											}
											className="inline-flex items-center gap-2 rounded-lg border border-accent/30 bg-surface px-4 py-2 text-sm font-medium text-accent transition-colors hover:bg-accent/5"
										>
											<CheckCircle2 size={16} />
											<span>
												{showPassedChecks
													? __('Hide passed tests')
													: formatHeadingCount(
															passingChecks.length,
															__('1 passed test'),
															__(
																'%s passed tests'
															)
														)}
											</span>
											{showPassedChecks ? (
												<ChevronUp size={16} />
											) : (
												<ChevronDown size={16} />
											)}
										</button>
									</div>

									{showPassedChecks ? (
										<div className="overflow-hidden rounded-lg border border-stroke bg-surface">
											{passingChecks.map(
												(check, index) => {
													const checkKey =
														check?.id ||
														check?.label ||
														`passed-check-${index}`;

													return (
														<IssueRow
															key={checkKey}
															check={check}
															isOpen={expandedChecks.has(
																checkKey
															)}
															onToggle={() =>
																toggleCheck(
																	checkKey
																)
															}
															showBorder={
																index > 0
															}
														/>
													);
												}
											)}
										</div>
									) : null}
								</section>
							) : null}
						</div>
					) : (
						<div className="space-y-6">
							<section className="space-y-3">
								<h2 className="text-2xl font-semibold text-heading">
									{__('System Status Info')}
								</h2>
								<p className="max-w-3xl text-sm leading-6 text-text-muted">
									{__(
										'This page can show you every detail about the configuration of your PeakURL install.'
									)}
								</p>
								<p className="max-w-3xl text-sm leading-6 text-text-muted">
									{__(
										'If you want to export a full snapshot of this page, you can use the button below to copy it to the clipboard.'
									)}
								</p>
							</section>

							<div>
								<button
									type="button"
									onClick={handleCopyInfo}
									className="inline-flex items-center gap-2 rounded-lg border border-stroke bg-surface px-4 py-2 text-sm font-medium text-heading transition-colors hover:border-accent/30 hover:text-accent"
								>
									<Copy size={16} />
									{copiedInfo
										? __('Copied')
										: __('Copy site info to clipboard')}
								</button>
							</div>

							<div className="space-y-3">
								{infoSections.map((section) => (
									<InfoSection
										key={section.id}
										section={section}
										isOpen={expandedSections.has(
											section.id
										)}
										onToggle={() =>
											toggleSection(section.id)
										}
									/>
								))}
							</div>
						</div>
					)}
				</div>
			</div>
		</div>
	);
}

export default SystemStatusPage;
