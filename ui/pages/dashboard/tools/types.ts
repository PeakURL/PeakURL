import type { LucideIcon } from "lucide-react";
import type { SystemCheck } from "@/api";

export type {
	StatusKey,
	SystemCheck,
	SystemStatusPayload,
	SystemStatusResponse,
	UrlExportResponse,
} from "@/api";


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
