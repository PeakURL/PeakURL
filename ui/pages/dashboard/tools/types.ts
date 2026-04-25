import type { LucideIcon } from "lucide-react";
import type { LinkRecord } from "../links/_components/types";

/**
 * Supported overall and per-check system status keys.
 */
export type StatusKey = "ok" | "warning" | "error";

/**
 * Tabs available on the system status page.
 */
export type StatusView = "status" | "info";

/**
 * Export formats available from the tools export screen.
 */
export type ExportFormat = "csv" | "json" | "xml";

/**
 * Presentation tokens used for status badges and panels.
 */
export interface StatusTone {
	/** Border class for the outer status ring. */
	ring: string;

	/** Dot color class for the center indicator. */
	dot: string;

	/** Text color class for the label. */
	text: string;

	/** Badge styling class list. */
	badge: string;

	/** Panel styling class list. */
	panel: string;
}

/**
 * Single system check returned by the status API.
 */
export interface SystemCheck {
	/** Stable check identifier. */
	id?: string | null;

	/** Human-readable check label. */
	label?: string | null;

	/** Longer description shown when the check expands. */
	description?: string | null;

	/** Current health state for the check. */
	status?: StatusKey | string | null;
}

/**
 * Label/value row shown inside an info section.
 */
export interface InfoItem {
	/** Display label for the row. */
	label: string;

	/** Value rendered for the row. */
	value?: unknown;

	/** Secondary helper copy shown beneath the value. */
	helperText?: string;

	/** Whether the value should use monospace styling. */
	monospace?: boolean;
}

/**
 * Collapsible information section on the system status page.
 */
export interface InfoSectionData {
	/** Stable section identifier. */
	id: string;

	/** Visible section heading. */
	title: string;

	/** Rows rendered inside the section. */
	items: InfoItem[];
}

/**
 * Status page payload returned by the system API.
 */
export interface SystemStatusPayload {
	/** Summary information for the current system status snapshot. */
	summary?: {
		/** Overall health state for the system. */
		overall?: StatusKey | string | null;
	};

	/** Individual system checks included in the response. */
	checks?: SystemCheck[];

	/** Timestamp indicating when the status payload was generated. */
	generatedAt?: string | null;

	/** Site-level application metadata and runtime details. */
	site?: {
		/** Current application version. */
		version?: string | null;

		/** Native display name of the active language. */
		languageNativeName?: string | null;

		/** Human-readable label for the active language. */
		languageLabel?: string | null;

		/** Active locale identifier. */
		locale?: string | null;

		/** Current runtime environment name. */
		environment?: string | null;

		/** Base site URL. */
		url?: string | null;

		/** Installation type or deployment mode. */
		installType?: string | null;

		/** Whether debug mode is enabled. */
		debugEnabled?: boolean;
	};

	/** File system, configuration, and storage health information. */
	storage?: {
		/** Path to the content directory. */
		contentDirectory?: string | null;

		/** Whether the content directory exists. */
		contentExists?: boolean;

		/** Whether the content directory is writable. */
		contentWritable?: boolean;

		/** Total size of the content directory in bytes. */
		contentDirectorySizeBytes?: number | string | null;

		/** Path to the languages directory. */
		languagesDirectory?: string | null;

		/** Whether the languages directory exists. */
		languagesDirectoryExists?: boolean;

		/** Whether the languages directory is readable. */
		languagesDirectoryReadable?: boolean;

		/** Total size of the languages directory in bytes. */
		languagesDirectorySizeBytes?: number | string | null;

		/** Path to the main configuration file. */
		configPath?: string | null;

		/** Whether the configuration file exists. */
		configExists?: boolean;

		/** Size of the configuration file in bytes. */
		configSizeBytes?: number | string | null;

		/** Path to the debug log file. */
		debugLogPath?: string | null;

		/** Whether the debug log file exists. */
		debugLogExists?: boolean;

		/** Whether the debug log file is readable. */
		debugLogReadable?: boolean;

		/** Size of the debug log file in bytes. */
		debugLogSizeBytes?: number | string | null;

		/** Path to the application directory. */
		appDirectory?: string | null;

		/** Whether the application directory is writable. */
		appWritable?: boolean;

		/** Total size of the application directory in bytes. */
		appDirectorySizeBytes?: number | string | null;

		/** Path to the release root directory. */
		releaseRoot?: string | null;

		/** Total size of the release root in bytes. */
		releaseRootSizeBytes?: number | string | null;
	};

	/** Server runtime and PHP environment details. */
	server?: {
		/** Installed PHP version. */
		phpVersion?: string | null;

		/** Active PHP SAPI value. */
		phpSapi?: string | null;

		/** Web server software signature. */
		serverSoftware?: string | null;

		/** Operating system reported by the server. */
		operatingSystem?: string | null;

		/** Server timezone. */
		timezone?: string | null;

		/** Configured PHP memory limit. */
		memoryLimit?: string | null;

		/** Maximum PHP execution time. */
		maxExecutionTime?: number | string | null;

		/** Maximum allowed upload file size. */
		uploadMaxFilesize?: string | null;

		/** Maximum allowed POST body size. */
		postMaxSize?: string | null;

		/** Availability of required or optional PHP extensions. */
		extensions?: {
			/** Whether the intl extension is available. */
			intl?: boolean;

			/** Whether the curl extension is available. */
			curl?: boolean;

			/** Whether the zip extension is available. */
			zip?: boolean;
		};
	};

	/** Database connection, schema, and versioning details. */
	database?: {
		/** Database server type. */
		serverType?: string | null;

		/** Database server version. */
		version?: string | null;

		/** Database host name. */
		host?: string | null;

		/** Database port. */
		port?: number | string | null;

		/** Database name. */
		name?: string | null;

		/** Configured character set. */
		charset?: string | null;

		/** Table name prefix. */
		prefix?: string | null;

		/** Current installed schema version. */
		schemaVersion?: number | string | null;

		/** Required schema version for the current app release. */
		requiredSchemaVersion?: number | string | null;

		/** Whether a schema upgrade is required. */
		schemaUpgradeRequired?: boolean;

		/** Number of detected schema issues. */
		schemaIssuesCount?: number | string | null;
	};

	/** Mail transport and SMTP configuration details. */
	mail?: {
		/** Active mail driver. */
		driver?: string | null;

		/** Whether the configured mail transport is ready to use. */
		transportReady?: boolean;

		/** Default sender email address. */
		fromEmail?: string | null;

		/** Default sender name. */
		fromName?: string | null;

		/** SMTP host name. */
		smtpHost?: string | null;

		/** SMTP port value. */
		smtpPort?: string | null;

		/** SMTP encryption mode. */
		smtpEncryption?: string | null;

		/** Whether SMTP authentication is enabled. */
		smtpAuth?: boolean;

		/** Human-readable configuration label. */
		configurationLabel?: string | null;

		/** Path to the mail configuration source. */
		configurationPath?: string | null;
	};

	/** Location and geo-data service configuration details. */
	location?: {
		/** Whether location analytics is ready for use. */
		locationAnalyticsReady?: boolean;

		/** When location data was last downloaded. */
		lastDownloadedAt?: string | null;

		/** When the location database was last updated. */
		databaseUpdatedAt?: string | null;

		/** Size of the location database in bytes. */
		databaseSizeBytes?: number | string | null;

		/** Whether location service credentials are configured. */
		credentialsConfigured?: boolean;

		/** Configured account identifier. */
		accountId?: string | null;

		/** Path to the location database. */
		databasePath?: string | null;

		/** Whether the location database is readable. */
		databaseReadable?: boolean;

		/** Command used to download or refresh location data. */
		downloadCommand?: string | null;
	};

	/** High-level application data counts. */
	data?: {
		/** Total number of users. */
		users?: number | string | null;

		/** Total number of links. */
		links?: number | string | null;

		/** Total number of clicks. */
		clicks?: number | string | null;

		/** Total number of sessions. */
		sessions?: number | string | null;

		/** Total number of API keys. */
		apiKeys?: number | string | null;

		/** Total number of webhooks. */
		webhooks?: number | string | null;

		/** Total number of audit events. */
		auditEvents?: number | string | null;

		/** Total number of managed tables. */
		managedTables?: number | string | null;
	};
}

/**
 * API response wrapper for the system status endpoint.
 */
export interface SystemStatusResponse {
	/** Returned status payload. */
	data?: SystemStatusPayload;
}

/**
 * Props for the system status error state.
 */
export interface ErrorStateProps {
	/** Human-readable error message shown to the user. */
	errorMessage: string;
}

/**
 * Props for the system status tab switcher.
 */
export interface StatusTabsProps {
	/** Currently selected system status tab. */
	activeView: StatusView;

	/** Updates the selected tab. */
	onChange: (view: StatusView) => void;
}

/**
 * Props for a single expandable issue row.
 */
export interface IssueRowProps {
	/** Check rendered in the row. */
	check: SystemCheck;

	/** Whether the row is expanded. */
	isOpen: boolean;

	/** Toggles the row open state. */
	onToggle: () => void;

	/** Whether to render a top border separator. */
	showBorder: boolean;
}

/**
 * Props for an issue list section on the status tab.
 */
export interface IssueSectionProps {
	/** Section heading. */
	title: string;

	/** Section description copy. */
	description: string;

	/** Checks rendered within the section. */
	checks: SystemCheck[];

	/** Expanded row keys. */
	expandedChecks: Set<string>;

	/** Toggles a single row by key. */
	onToggleCheck: (checkKey: string) => void;
}

/**
 * Props for a collapsible system information section.
 */
export interface InfoSectionProps {
	/** Section content to render. */
	section: InfoSectionData;

	/** Whether the section is currently open. */
	isOpen: boolean;

	/** Toggles the section open state. */
	onToggle: () => void;
}

/**
 * Props for a single export option card.
 */
export interface ExportCardProps {
	/** Card heading shown to the user. */
	title: string;

	/** Supporting description copy. */
	description: string;

	/** Human-readable format label. */
	formatLabel: string;

	/** Icon rendered for the export type. */
	icon: LucideIcon;

	/** Whether the export is currently being generated. */
	isLoading: boolean;

	/** Whether the action button should be disabled. */
	isDisabled: boolean;

	/** Starts the export for this format. */
	onExport: () => void;
}

/**
 * Static metadata for an export format option.
 */
export interface ExportOption {
	/** Machine-readable export format identifier. */
	id: ExportFormat;

	/** Card heading shown to the user. */
	title: string;

	/** Supporting description copy. */
	description: string;

	/** Human-readable format label. */
	formatLabel: string;

	/** Icon rendered for the export type. */
	icon: LucideIcon;
}

/**
 * API response wrapper for the export endpoint.
 */
export interface UrlExportResponse {
	/** Export response payload. */
	data?: {
		/** Exported link records included in the response. */
		items?: LinkRecord[];
	};
}
