import { useState } from "react";
import { format, formatDistanceToNow, isValid, parseISO } from "date-fns";
import {
	Activity,
	AlertCircle,
	AlertTriangle,
	Check,
	CheckCircle2,
	ChevronDown,
	ChevronUp,
	Clock,
	Copy,
	Database,
	FileText,
	Globe,
	HardDrive,
	Mail,
	RefreshCw,
	Server,
	ShieldCheck,
} from "lucide-react";

import { useNotification } from "@/components";
import { useTemporaryState } from "@/hooks";
import { __, sprintf } from "@/i18n";
import { useGetSystemStatusQuery } from "@/store/slices/api";
import {
	cn,
	copyToClipboard,
	extractErrorMessage,
	formatByteSize,
	formatCount,
	formatDateTimeValue,
} from "@/utils";

import type {
	ErrorStateProps,
	InfoItem,
	InfoSectionData,
	InfoSectionProps,
	IssueRowProps,
	IssueSectionProps,
	StatusTabsProps,
	StatusView,
	SystemCheck,
} from "../types";
import { SystemStatusSkeleton } from "./_components";

function hasValue(value: unknown) {
	return value !== undefined && value !== null && "" !== value;
}

function displayValue(value: unknown) {
	return hasValue(value) ? String(value) : __("Not available");
}

function joinHelperText(parts: Array<string | undefined | null>) {
	return parts.filter((part) => hasValue(part)).join(" • ");
}

function formatBoolean(value: unknown, truthy?: string, falsy?: string) {
	return value ? (truthy ?? __("Yes")) : (falsy ?? __("No"));
}

function formatStatusTimestamp(
	dateValue: string | number | Date | null | undefined
) {
	if (!dateValue) {
		return {
			relative: __("recently"),
			full: "",
		};
	}

	try {
		const date =
			typeof dateValue === "string"
				? parseISO(dateValue)
				: new Date(dateValue);

		if (!isValid(date)) {
			return {
				relative: String(dateValue),
				full: "",
			};
		}

		const relative = formatDistanceToNow(date, { addSuffix: true });
		const full = format(date, "MMM d, yyyy 'at' h:mm:ss a");

		return { relative, full };
	} catch {
		return {
			relative: String(dateValue),
			full: "",
		};
	}
}

function getSubsystemStatus(
	checks: SystemCheck[],
	categoryIds: string[]
): "ok" | "warning" | "error" {
	const matchingChecks = checks.filter((check) =>
		categoryIds.includes(check.id || "")
	);

	if (matchingChecks.some((c) => "error" === c.status)) {
		return "error";
	}
	if (matchingChecks.some((c) => "warning" === c.status)) {
		return "warning";
	}
	return "ok";
}

function getCheckCategoryLabel(checkId: string | null | undefined) {
	switch (checkId) {
		case "database":
			return __("Database");
		case "content":
			return __("Storage");
		case "languages":
			return __("Translations");
		case "mail":
			return __("Email");
		case "geoip":
			return __("Location Data");
		case "zip":
			return __("Updates");
		default:
			return __("System");
	}
}

function formatHeadingCount(count: number, singular: string, plural: string) {
	return 1 === count ? singular : plural.replace("%s", formatCount(count));
}

function formatExportText(sections: InfoSectionData[]) {
	return [
		"PeakURL System Status",
		...sections.map((section: InfoSectionData) => {
			const rows = section.items.map((item: InfoItem) => {
				const value = displayValue(item.value);
				return item.helperText
					? `${item.label}: ${value} (${item.helperText})`
					: `${item.label}: ${value}`;
			});

			return `${section.title}\n${rows.join("\n")}`;
		}),
	].join("\n\n");
}

function ErrorState({ errorMessage }: ErrorStateProps) {
	return (
		<div className="system-status-page-error">
			<div className="system-status-page-error-layout">
				<AlertCircle
					size={18}
					className="system-status-page-error-icon"
				/>
				<div className="system-status-page-error-copy">
					<h2 className="system-status-page-error-title">
						{__("System status unavailable")}
					</h2>
					<p className="system-status-page-error-summary">
						{errorMessage}
					</p>
				</div>
			</div>
		</div>
	);
}

function StatusTabs({ activeView, onChange }: StatusTabsProps) {
	const tabs: Array<{
		id: StatusView;
		label: string;
		icon: typeof Activity;
	}> = [
		{ id: "status", label: __("Status"), icon: Activity },
		{ id: "info", label: __("Info"), icon: FileText },
	];

	return (
		<div className="system-status-page-tabs">
			{tabs.map((tab) => {
				const Icon = tab.icon;
				const isActive = activeView === tab.id;

				return (
					<button
						key={tab.id}
						type="button"
						onClick={() => onChange(tab.id)}
						className={cn(
							"system-status-page-tab",
							isActive
								? "system-status-page-tab-active"
								: "system-status-page-tab-inactive"
						)}
					>
						<Icon size={14} className="shrink-0" />
						<span>{tab.label}</span>
					</button>
				);
			})}
		</div>
	);
}

function IssueRow({ check, isOpen, onToggle, showBorder }: IssueRowProps) {
	return (
		<div
			className={cn(
				"system-status-page-issue-item",
				showBorder && "system-status-page-issue-row-bordered"
			)}
		>
			<button
				type="button"
				onClick={onToggle}
				className="system-status-page-issue-toggle"
			>
				<div className="system-status-page-issue-copy">
					<p className="system-status-page-issue-label">
						{check?.label || __("Check")}
					</p>
				</div>
				<div className="system-status-page-issue-meta">
					<span className="system-status-page-issue-badge">
						{getCheckCategoryLabel(check?.id)}
					</span>
					<div className="system-status-page-issue-chevron">
						{isOpen ? (
							<ChevronUp size={16} />
						) : (
							<ChevronDown size={16} />
						)}
					</div>
				</div>
			</button>
			{isOpen ? (
				<div className="system-status-page-issue-body">
					{check?.description || __("Not available")}
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
	severity = "warning",
}: IssueSectionProps & { severity?: "error" | "warning" }) {
	return (
		<div className="system-status-page-panel">
			<div className="system-status-page-panel-header">
				<div className="system-status-page-section-header">
					<div className="flex items-center gap-2">
						{severity === "error" ? (
							<span className="system-status-page-badge-error">
								<AlertTriangle size={12} />
								<span>{__("Critical")}</span>
							</span>
						) : (
							<span className="system-status-page-badge-warning">
								<AlertCircle size={12} />
								<span>{__("Improvement")}</span>
							</span>
						)}
						<h3 className="system-status-page-section-title">
							{title}
						</h3>
					</div>
					<p className="system-status-page-section-summary">
						{description}
					</p>
				</div>
			</div>

			<div className="system-status-page-issue-list">
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
		</div>
	);
}

function InfoSection({ section, isOpen, onToggle }: InfoSectionProps) {
	return (
		<div className="system-status-page-info-panel">
			<button
				type="button"
				onClick={onToggle}
				className="system-status-page-info-toggle"
			>
				<span className="system-status-page-info-title">
					{section.title}
				</span>
				<div className="system-status-page-issue-chevron">
					{isOpen ? (
						<ChevronUp size={16} />
					) : (
						<ChevronDown size={16} />
					)}
				</div>
			</button>

			{isOpen ? (
				<div className="system-status-page-info-table-wrap">
					<table className="system-status-page-info-table">
						<tbody>
							{section.items.map(
								(item: InfoItem, index: number) => (
									<tr
										key={`${section.id}-${item.label}`}
										className={
											index > 0
												? "system-status-page-info-row-bordered"
												: ""
										}
									>
										<th className="system-status-page-info-heading">
											{item.label}
										</th>
										<td className="system-status-page-info-value-cell">
											<p
												className={cn(
													"system-status-page-info-value",
													item.monospace &&
														"system-status-page-info-value-monospace"
												)}
											>
												{displayValue(item.value)}
											</p>
											{item.helperText ? (
												<p className="system-status-page-info-helper">
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
		isFetching,
		refetch,
	} = useGetSystemStatusQuery(undefined);
	const status = systemStatusResponse?.data || null;
	const errorMessage = extractErrorMessage(systemStatusError);
	const [activeView, setActiveView] = useState<StatusView>("status");
	const [showPassedChecks, setShowPassedChecks] = useState(false);
	const [copiedInfo, setCopiedInfo] = useTemporaryState(false);
	const [expandedChecks, setExpandedChecks] = useState<Set<string>>(
		new Set()
	);
	const [expandedSections, setExpandedSections] = useState(
		new Set<string>(["peakurl"])
	);

	if (isLoading && !status) {
		return <SystemStatusSkeleton />;
	}

	if (!status) {
		return (
			<ErrorState
				errorMessage={
					errorMessage ||
					__("System status data is not available right now.")
				}
			/>
		);
	}

	const overallStatus = status?.summary?.overall || "warning";
	const isHealthOk = "ok" === overallStatus;
	const checks: SystemCheck[] = status?.checks || [];
	const errorChecks = checks.filter(
		(check: SystemCheck) => "error" === check.status
	);
	const warningChecks = checks.filter(
		(check: SystemCheck) => "warning" === check.status
	);
	const passingChecks = checks.filter(
		(check: SystemCheck) => "ok" === check.status
	);
	const mailDriver =
		"smtp" === status?.mail?.driver ? __("SMTP") : __("PHP mail()");
	const languageName =
		status?.site?.languageNativeName || status?.site?.languageLabel;
	const maxExecutionTime = status?.server?.maxExecutionTime;
	const databasePort = status?.database?.port;
	const recordedSchemaVersion = status?.database?.schemaVersion;
	const requiredSchemaVersion = status?.database?.requiredSchemaVersion;
	const schemaIssuesCount = status?.database?.schemaIssuesCount;

	const timeInfo = formatStatusTimestamp(status?.generatedAt);

	const subsystems = [
		{
			id: "database",
			name: __("Database Engine"),
			icon: Database,
			status: getSubsystemStatus(checks, ["database"]),
			meta: status?.database?.serverType
				? `${status.database.serverType} ${status?.database?.version || ""}`.trim()
				: __("MySQL / MariaDB"),
		},
		{
			id: "storage",
			name: __("File Storage"),
			icon: HardDrive,
			status: getSubsystemStatus(checks, ["content", "storage"]),
			meta: status?.storage?.contentWritable
				? __("Writable Content Directory")
				: __("Read Only Directory"),
		},
		{
			id: "server",
			name: __("PHP Runtime"),
			icon: Server,
			status: getSubsystemStatus(checks, [
				"server",
				"php",
				"intl",
				"curl",
			]),
			meta: status?.server?.phpVersion
				? `PHP ${status.server.phpVersion}`
				: __("Web Server"),
		},
		{
			id: "mail",
			name: __("Email Delivery"),
			icon: Mail,
			status: getSubsystemStatus(checks, ["mail"]),
			meta: mailDriver,
		},
		{
			id: "location",
			name: __("Location Data"),
			icon: Globe,
			status: getSubsystemStatus(checks, ["geoip", "location"]),
			meta: status?.location?.locationAnalyticsReady
				? __("GeoLite2 Installed")
				: __("Setup Recommended"),
		},
		{
			id: "updates",
			name: __("Release & Updates"),
			icon: ShieldCheck,
			status: getSubsystemStatus(checks, ["zip", "updates"]),
			meta: status?.site?.version
				? `PeakURL v${status.site.version}`
				: __("Packaged Release"),
		},
	];

	const peakurlItems = [
		{ label: __("Version"), value: status?.site?.version || __("Unknown") },
		{
			label: __("Site Language"),
			value: languageName,
			helperText: status?.site?.locale || "",
		},
		{ label: __("Environment"), value: status?.site?.environment },
		{
			label: __("Site URL"),
			value: status?.site?.url,
			monospace: true,
		},
		{
			label: __("Install Type"),
			value:
				"release" === status?.site?.installType
					? __("Packaged Release")
					: __("Source Checkout"),
		},
		{
			label: __("Debug Mode"),
			value: formatBoolean(
				status?.site?.debugEnabled,
				__("Enabled"),
				__("Disabled")
			),
		},
		{
			label: __("Last Checked"),
			value: formatDateTimeValue(
				status?.generatedAt,
				__("Not available")
			),
		},
	];

	const storageItems = [
		{
			label: __("Content Directory"),
			value: status?.storage?.contentDirectory,
			helperText: status?.storage?.contentExists
				? joinHelperText([
						formatBoolean(
							status?.storage?.contentWritable,
							__("Writable"),
							__("Not Writable")
						),
						formatByteSize(
							status?.storage?.contentDirectorySizeBytes,
							""
						),
					])
				: __("Missing"),
			monospace: true,
		},
		{
			label: __("Languages Directory"),
			value: status?.storage?.languagesDirectory,
			helperText: status?.storage?.languagesDirectoryExists
				? joinHelperText([
						formatBoolean(
							status?.storage?.languagesDirectoryReadable,
							__("Readable"),
							__("Not Readable")
						),
						formatByteSize(
							status?.storage?.languagesDirectorySizeBytes,
							""
						),
					])
				: __("Missing"),
			monospace: true,
		},
		{
			label: __("Config File"),
			value: status?.storage?.configPath,
			helperText: status?.storage?.configExists
				? joinHelperText([
						__("Present"),
						formatByteSize(status?.storage?.configSizeBytes, ""),
					])
				: __("Missing"),
			monospace: true,
		},
		{
			label: __("Debug Log"),
			value: status?.storage?.debugLogPath,
			helperText: status?.storage?.debugLogExists
				? joinHelperText([
						formatBoolean(
							status?.storage?.debugLogReadable,
							__("Readable"),
							__("Not Readable")
						),
						formatByteSize(status?.storage?.debugLogSizeBytes, ""),
					])
				: __("Not created yet"),
			monospace: true,
		},
		{
			label: __("App Directory"),
			value: status?.storage?.appDirectory,
			helperText: joinHelperText([
				formatBoolean(
					status?.storage?.appWritable,
					__("Writable"),
					__("Not Writable")
				),
				formatByteSize(status?.storage?.appDirectorySizeBytes, ""),
			]),
			monospace: true,
		},
		{
			label: __("Release Root"),
			value: status?.storage?.releaseRoot,
			helperText: formatByteSize(
				status?.storage?.releaseRootSizeBytes,
				""
			),
			monospace: true,
		},
	];

	const serverItems = [
		{ label: __("PHP Version"), value: status?.server?.phpVersion },
		{ label: __("PHP SAPI"), value: status?.server?.phpSapi },
		{
			label: __("Web Server"),
			value: status?.server?.serverSoftware || __("Unknown"),
		},
		{
			label: __("Operating System"),
			value: status?.server?.operatingSystem || __("Unknown"),
		},
		{ label: __("Timezone"), value: status?.server?.timezone },
		{ label: __("Memory Limit"), value: status?.server?.memoryLimit },
		{
			label: __("Max Execution Time"),
			value: hasValue(maxExecutionTime)
				? `${String(maxExecutionTime)}s`
				: __("Unknown"),
		},
		{
			label: __("Upload Max Filesize"),
			value: status?.server?.uploadMaxFilesize,
		},
		{ label: __("POST Max Size"), value: status?.server?.postMaxSize },
		{
			label: __("Intl Extension"),
			value: formatBoolean(
				status?.server?.extensions?.intl,
				__("Available"),
				__("Missing")
			),
		},
		{
			label: __("cURL Extension"),
			value: formatBoolean(
				status?.server?.extensions?.curl,
				__("Available"),
				__("Missing")
			),
		},
		{
			label: __("ZipArchive"),
			value: formatBoolean(
				status?.server?.extensions?.zip,
				__("Available"),
				__("Missing")
			),
		},
	];

	const databaseItems = [
		{
			label: __("Database Server"),
			value: status?.database?.serverType || __("Unknown"),
		},
		{
			label: __("Version"),
			value: status?.database?.version || __("Unknown"),
		},
		{ label: __("Host"), value: status?.database?.host },
		{
			label: __("Port"),
			value: hasValue(databasePort)
				? String(databasePort)
				: __("Not available"),
		},
		{ label: __("Database Name"), value: status?.database?.name },
		{ label: __("Charset"), value: status?.database?.charset },
		{
			label: __("Table Prefix"),
			value: status?.database?.prefix || __("None"),
			monospace: true,
		},
		{
			label: __("Recorded Schema"),
			value: hasValue(recordedSchemaVersion)
				? String(recordedSchemaVersion)
				: __("Unknown"),
		},
		{
			label: __("Required Schema"),
			value: hasValue(requiredSchemaVersion)
				? String(requiredSchemaVersion)
				: __("Unknown"),
		},
		{
			label: __("Schema Status"),
			value: status?.database?.schemaUpgradeRequired
				? __("Upgrade Recommended")
				: __("Current"),
			helperText:
				schemaIssuesCount && Number(schemaIssuesCount) > 0
					? sprintf(
							__("%s schema issue(s) detected"),
							formatCount(Number(schemaIssuesCount))
						)
					: undefined,
		},
	];

	const mailItems = [
		{ label: __("Mail Transport"), value: mailDriver },
		{
			label: __("Transport Ready"),
			value: formatBoolean(
				status?.mail?.transportReady,
				__("Ready"),
				__("Needs Setup")
			),
		},
		{ label: __("From Email"), value: status?.mail?.fromEmail },
		{ label: __("From Name"), value: status?.mail?.fromName },
		{
			label: __("SMTP Host"),
			value: status?.mail?.smtpHost || __("Not configured"),
		},
		{
			label: __("SMTP Port"),
			value: status?.mail?.smtpPort || __("Not configured"),
		},
		{
			label: __("Encryption"),
			value: status?.mail?.smtpEncryption || __("None"),
		},
		{
			label: __("Authentication"),
			value: formatBoolean(
				status?.mail?.smtpAuth,
				__("Enabled"),
				__("Disabled")
			),
		},
		{
			label: __("Settings Storage"),
			value: status?.mail?.configurationLabel || __("Not available"),
			helperText: status?.mail?.configurationPath || "",
		},
	];

	const locationItems = [
		{
			label: __("Status"),
			value: status?.location?.locationAnalyticsReady
				? __("Ready")
				: __("Setup Required"),
		},
		{
			label: __("Database Updated"),
			value: formatDateTimeValue(
				status?.location?.lastDownloadedAt ||
					status?.location?.databaseUpdatedAt,
				__("Not available")
			),
		},
		{
			label: __("Database Size"),
			value: formatByteSize(
				status?.location?.databaseSizeBytes,
				__("Not available")
			),
		},
		{
			label: __("Credentials Saved"),
			value: formatBoolean(
				status?.location?.credentialsConfigured,
				__("Yes"),
				__("No")
			),
		},
		{
			label: __("Account ID"),
			value: status?.location?.accountId || __("Not configured"),
		},
		{
			label: __("Database Path"),
			value: status?.location?.databasePath,
			helperText: formatBoolean(
				status?.location?.databaseReadable,
				__("Readable"),
				__("Not Readable")
			),
			monospace: true,
		},
		{
			label: __("Refresh Command"),
			value: status?.location?.downloadCommand,
			monospace: true,
		},
	];

	const dataItems = [
		{ label: __("Users"), value: formatCount(status?.data?.users) },
		{ label: __("Short Links"), value: formatCount(status?.data?.links) },
		{ label: __("Clicks"), value: formatCount(status?.data?.clicks) },
		{
			label: __("Active Sessions"),
			value: formatCount(status?.data?.sessions),
		},
		{ label: __("API Keys"), value: formatCount(status?.data?.apiKeys) },
		{ label: __("Webhooks"), value: formatCount(status?.data?.webhooks) },
		{
			label: __("Activity Events"),
			value: formatCount(status?.data?.auditEvents),
		},
		{
			label: __("Managed Tables"),
			value: formatCount(status?.data?.managedTables),
		},
	];

	const infoSections: InfoSectionData[] = [
		{ id: "peakurl", title: "PeakURL", items: peakurlItems },
		{
			id: "directories",
			title: __("Directories and Sizes"),
			items: storageItems,
		},
		{ id: "server", title: __("Server"), items: serverItems },
		{ id: "database", title: __("Database"), items: databaseItems },
		{ id: "email", title: __("Email"), items: mailItems },
		{
			id: "location",
			title: __("Location Data"),
			items: locationItems,
		},
		{ id: "footprint", title: __("Data Footprint"), items: dataItems },
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
			await copyToClipboard(formatExportText(infoSections));
			setCopiedInfo(true, 2000);
			notification.success(
				__("Copied"),
				__("System status info copied to clipboard")
			);
		} catch {
			notification.error(
				__("Copy failed"),
				__("PeakURL could not copy the system status information.")
			);
		}
	};

	return (
		<div className="system-status-page">
			{/* Hero Header */}
			<div className="system-status-page-hero">
				<div className="system-status-page-hero-copy">
					<div className="system-status-page-hero-badge">
						<Activity size={13} />
						<span>{__("Site Health")}</span>
					</div>
					<h1 className="system-status-page-title">
						{__("System Status")}
					</h1>
					<p className="system-status-page-summary">
						{__(
							"Monitor installation health, check server environment diagnostics, and review technical configuration details."
						)}
					</p>
				</div>
				<div className="system-status-page-hero-actions">
					<button
						type="button"
						onClick={() => refetch()}
						disabled={isFetching}
						className="dashboard-page-refresh"
						aria-label={__("Refresh site health status")}
						title={__("Refresh site health status")}
					>
						<RefreshCw
							className={cn(
								"dashboard-page-refresh-icon",
								isFetching && "animate-spin"
							)}
						/>
					</button>
				</div>
			</div>

			{/* Health Status Banner */}
			<div
				className={cn(
					"system-status-page-banner",
					isHealthOk && "system-status-page-banner-ok",
					!isHealthOk &&
						errorChecks.length > 0 &&
						"system-status-page-banner-error",
					!isHealthOk &&
						0 === errorChecks.length &&
						"system-status-page-banner-warning"
				)}
			>
				<div className="system-status-page-banner-main">
					<div className="system-status-page-banner-indicator">
						<span className="relative flex h-3.5 w-3.5 shrink-0">
							<span
								className={cn(
									"animate-ping absolute inline-flex h-full w-full rounded-full opacity-75",
									isHealthOk && "bg-emerald-400",
									!isHealthOk &&
										errorChecks.length > 0 &&
										"bg-rose-400",
									!isHealthOk &&
										0 === errorChecks.length &&
										"bg-amber-400"
								)}
							/>
							<span
								className={cn(
									"relative inline-flex rounded-full h-3.5 w-3.5",
									isHealthOk && "bg-emerald-500",
									!isHealthOk &&
										errorChecks.length > 0 &&
										"bg-rose-500",
									!isHealthOk &&
										0 === errorChecks.length &&
										"bg-amber-500"
								)}
							/>
						</span>
					</div>

					<div className="system-status-page-banner-copy">
						<h2 className="system-status-page-banner-title">
							{isHealthOk
								? __("Site Health: Good")
								: errorChecks.length > 0
									? formatHeadingCount(
											errorChecks.length,
											__("Site Health: 1 Critical Issue"),
											__(
												"Site Health: %s Critical Issues"
											)
										)
									: formatHeadingCount(
											warningChecks.length,
											__(
												"Site Health: 1 Recommended Improvement"
											),
											__(
												"Site Health: %s Recommended Improvements"
											)
										)}
						</h2>
						<p className="system-status-page-banner-description">
							{isHealthOk
								? __(
										"PeakURL is configured properly. Your database, file storage, email delivery, and server environment are in good health."
									)
								: errorChecks.length > 0
									? __(
											"Critical items require attention to restore optimal site security and performance. Resolving these should be prioritized."
										)
									: __(
											"All core features are active, with recommended improvements available below to optimize your install."
										)}
						</p>
					</div>
				</div>

				<div className="system-status-page-banner-footer">
					<div className="system-status-page-banner-pills">
						<span className="system-status-page-pill system-status-page-pill-ok">
							<CheckCircle2 size={12} />
							<span>
								{sprintf(
									__("%s Passed"),
									formatCount(passingChecks.length)
								)}
							</span>
						</span>
						{warningChecks.length > 0 && (
							<span className="system-status-page-pill system-status-page-pill-warning">
								<AlertCircle size={12} />
								<span>
									{sprintf(
										__("%s Recommended"),
										formatCount(warningChecks.length)
									)}
								</span>
							</span>
						)}
						{errorChecks.length > 0 && (
							<span className="system-status-page-pill system-status-page-pill-error">
								<AlertTriangle size={12} />
								<span>
									{sprintf(
										__("%s Critical"),
										formatCount(errorChecks.length)
									)}
								</span>
							</span>
						)}
					</div>

					<div className="system-status-page-banner-timestamp">
						<Clock size={13} className="shrink-0 text-text-muted" />
						<span>
							{sprintf(__("Checked %s"), timeInfo.relative)}
						</span>
						{timeInfo.full ? (
							<span className="system-status-page-banner-timestamp-full">
								• {timeInfo.full}
							</span>
						) : null}
					</div>
				</div>
			</div>

			{/* Subsystems Component Grid */}
			<div className="system-status-page-subsystems">
				<div className="system-status-page-subsystems-header">
					<h3 className="system-status-page-subsystems-title">
						{__("System Checks")}
					</h3>
					<span className="system-status-page-subsystems-count">
						{sprintf(
							__("%s checks"),
							formatCount(subsystems.length)
						)}
					</span>
				</div>

				<div className="system-status-page-subsystems-grid">
					{subsystems.map((subsystem) => {
						const Icon = subsystem.icon;
						const isOk = "ok" === subsystem.status;
						const isWarn = "warning" === subsystem.status;

						return (
							<div
								key={subsystem.id}
								className="system-status-page-subsystem-card"
							>
								<div className="system-status-page-subsystem-icon">
									<Icon size={16} />
								</div>
								<div className="system-status-page-subsystem-info">
									<p className="system-status-page-subsystem-name">
										{subsystem.name}
									</p>
									<p className="system-status-page-subsystem-meta">
										{subsystem.meta}
									</p>
								</div>
								<div className="system-status-page-subsystem-badge-wrap">
									<span
										className={cn(
											"system-status-page-subsystem-badge",
											isOk &&
												"system-status-page-subsystem-badge-ok",
											isWarn &&
												"system-status-page-subsystem-badge-warning",
											!isOk &&
												!isWarn &&
												"system-status-page-subsystem-badge-error"
										)}
									>
										<span
											className={cn(
												"system-status-page-subsystem-dot",
												isOk &&
													"system-status-page-subsystem-dot-ok",
												isWarn &&
													"system-status-page-subsystem-dot-warning",
												!isOk &&
													!isWarn &&
													"system-status-page-subsystem-dot-error"
											)}
										/>
										<span>
											{isOk
												? __("Good")
												: isWarn
													? __("Review")
													: __("Action Required")}
										</span>
									</span>
								</div>
							</div>
						);
					})}
				</div>
			</div>

			{/* Toolbar Tabs & Copy Action */}
			<div className="system-status-page-toolbar">
				<StatusTabs activeView={activeView} onChange={setActiveView} />
				{"info" === activeView && (
					<button
						type="button"
						onClick={handleCopyInfo}
						className="system-status-page-copy-button"
					>
						{copiedInfo ? (
							<>
								<Check
									size={14}
									className="text-emerald-600 dark:text-emerald-400"
								/>
								<span>{__("Copied!")}</span>
							</>
						) : (
							<>
								<Copy size={14} />
								<span>{__("Copy site info to clipboard")}</span>
							</>
						)}
					</button>
				)}
			</div>

			{/* Main Content Area */}
			<div className="system-status-page-content">
				{errorMessage ? (
					<ErrorState errorMessage={errorMessage} />
				) : null}

				{"status" === activeView ? (
					<div className="system-status-page-view">
						{errorChecks.length > 0 ? (
							<IssueSection
								title={formatHeadingCount(
									errorChecks.length,
									__("1 critical issue"),
									__("%s critical issues")
								)}
								description={__(
									"Critical issues are items that may have a high impact on your site performance or security. Resolving these issues should be prioritized."
								)}
								checks={errorChecks}
								expandedChecks={expandedChecks}
								onToggleCheck={toggleCheck}
								severity="error"
							/>
						) : null}

						{warningChecks.length > 0 ? (
							<IssueSection
								title={formatHeadingCount(
									warningChecks.length,
									__("1 recommended improvement"),
									__("%s recommended improvements")
								)}
								description={__(
									"Recommended improvements are beneficial for your site, though not as urgent as a critical issue. They may include improvements in areas such as security, performance, and user experience."
								)}
								checks={warningChecks}
								expandedChecks={expandedChecks}
								onToggleCheck={toggleCheck}
								severity="warning"
							/>
						) : null}

						{0 === errorChecks.length &&
						0 === warningChecks.length ? (
							<div className="system-status-page-ok-panel">
								<div className="flex items-center gap-2 font-semibold">
									<CheckCircle2 size={16} />
									<span>
										{__("All system checks passing")}
									</span>
								</div>
								<p className="mt-1 text-text-muted">
									{__(
										"PeakURL is configured properly and all environmental diagnostics are in good standing."
									)}
								</p>
							</div>
						) : null}

						{passingChecks.length > 0 ? (
							<div className="system-status-page-passed-panel">
								<button
									type="button"
									onClick={() =>
										setShowPassedChecks(
											(current) => !current
										)
									}
									className="system-status-page-passed-toggle"
								>
									<div className="system-status-page-passed-toggle-content">
										<CheckCircle2 size={16} />
										<span>
											{showPassedChecks
												? __("Hide passed tests")
												: formatHeadingCount(
														passingChecks.length,
														__("1 passed test"),
														__("%s passed tests")
													)}
										</span>
									</div>
									<div className="system-status-page-issue-chevron">
										{showPassedChecks ? (
											<ChevronUp size={16} />
										) : (
											<ChevronDown size={16} />
										)}
									</div>
								</button>

								{showPassedChecks ? (
									<div className="system-status-page-passed-list">
										{passingChecks.map((check, index) => {
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
														toggleCheck(checkKey)
													}
													showBorder={index > 0}
												/>
											);
										})}
									</div>
								) : null}
							</div>
						) : null}
					</div>
				) : (
					<div className="system-status-page-view-compact">
						<div className="system-status-page-info-list">
							{infoSections.map((section) => (
								<InfoSection
									key={section.id}
									section={section}
									isOpen={expandedSections.has(section.id)}
									onToggle={() => toggleSection(section.id)}
								/>
							))}
						</div>
					</div>
				)}
			</div>
		</div>
	);
}

export default SystemStatusPage;
