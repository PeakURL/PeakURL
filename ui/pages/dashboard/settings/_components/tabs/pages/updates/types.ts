import type { ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import type { TextDirection } from "@/i18n/types";

/**
 * Text direction passed from the updates mount into all child components.
 */
export type UpdatesDirection = TextDirection;

/**
 * Tone tokens shared by the updates tab status UI.
 */
export type StatusTone = "info" | "success" | "error";

/**
 * Direction hint for values that mix localized labels with technical data.
 */
export type ValueDirection = "auto" | "ltr" | "rtl";

/**
 * Icon contract used by update status helper components.
 */
export type IconComponent = LucideIcon;

/**
 * Single updater or database issue rendered in the UI.
 */
export interface UpdateIssue {
	id?: string | null;
	label: string;
}

/**
 * Human-friendly release-install progress stages shown in the dashboard.
 */
export type ReleaseInstallStage =
	| "preparing"
	| "downloading"
	| "installing"
	| "finishing";

/**
 * Single progress step shown while applying or reinstalling a release.
 */
export interface ReleaseInstallProgressStep {
	id: ReleaseInstallStage;
	label: string;
	state: "complete" | "current" | "upcoming";
}

/**
 * Render-ready progress state for a release install action.
 */
export interface ReleaseInstallProgressState {
	title: string;
	description: string;
	steps: ReleaseInstallProgressStep[];
}

/**
 * Database repair status returned by the updater status API.
 */
export interface DatabaseStatus {
	upgradeRequired?: boolean;
	lastError?: string | null;
	currentVersion?: string | number | null;
	targetVersion?: string | number | null;
	lastUpgradedAt?: string | null;
	issues?: UpdateIssue[] | null;
}

/**
 * Aggregate updater status payload for the updates tab.
 */
export interface UpdateStatusPayload {
	updateAvailable?: boolean;
	reinstallAvailable?: boolean;
	currentVersion?: string | null;
	latestVersion?: string | null;
	canApply?: boolean;
	lastError?: string | null;
	lastCheckedAt?: string | null;
	releasedAt?: string | null;
	releaseNotesUrl?: string | null;
	applyDisabledReason?: string | null;
	database?: DatabaseStatus | null;
}

/**
 * Render-ready state for an update or database badge.
 */
export interface BadgeState {
	tone: StatusTone;
	label: string;
	title: string;
	description: string;
}

/**
 * Props for the updates tab container component.
 */
export interface UpdatesTabProps {
	status?: UpdateStatusPayload | null;
	errorMessage?: string | null;
	releaseInstallProgress?: ReleaseInstallProgressState | null;
	isLoading: boolean;
	isChecking: boolean;
	isApplying: boolean;
	isReinstalling: boolean;
	isRepairing: boolean;
	onCheck: () => void;
	onApply: () => void;
	onReinstall: () => void;
	onRepair: () => void;
}

/**
 * Props for the compact status badge component.
 */
export interface StatusBadgeProps {
	tone?: StatusTone;
	label: string;
}

/**
 * Props for the section wrapper used by update-management cards.
 */
export interface UpdateSectionProps {
	children: ReactNode;
}

/**
 * Props for section headers in the updates tab.
 */
export interface SectionHeaderProps {
	direction: UpdatesDirection;
	title: string;
	description: string;
	badge?: BadgeState | null;
	primaryAction?: ReactNode;
	secondaryAction?: ReactNode;
}

/**
 * Single key-value metric rendered in a metric grid.
 */
export interface MetricItem {
	label: string;
	value: string;
	valueDirection?: ValueDirection;
}

/**
 * Props for the updates-tab metric grid.
 */
export interface MetricGridProps {
	direction: UpdatesDirection;
	items: MetricItem[];
}

/**
 * Props for the directional value renderer shared by metric and detail rows.
 */
export interface DirectionalValueProps {
	children: ReactNode;
	direction?: ValueDirection;
}

/**
 * Props for an inline update notice banner.
 */
export interface InlineNoticeProps {
	direction: UpdatesDirection;
	icon: IconComponent;
	title: string;
	description: string;
	tone?: StatusTone;
}

/**
 * Props for the update action button cluster.
 */
export interface UpdateActionsProps {
	direction: UpdatesDirection;
	updateAvailable: boolean;
	reinstallAvailable: boolean;
	canApply: boolean;
	isLoading: boolean;
	isChecking: boolean;
	isApplying: boolean;
	isReinstalling: boolean;
	isRepairing: boolean;
	disabledReason: string;
	onCheck: () => void;
	onApply: () => void;
	onReinstall: () => void;
}

/**
 * Props for a label/value detail row.
 */
export interface DetailRowProps {
	direction: UpdatesDirection;
	label: string;
	value: string;
	icon?: IconComponent;
	href?: string;
	valueDirection?: ValueDirection;
}

/**
 * Props for a list of updater or schema issues.
 */
export interface IssueListProps {
	direction: UpdatesDirection;
	title: string;
	issues: UpdateIssue[];
}

/**
 * Props for the application-release management section.
 */
export interface ApplicationUpdatesProps {
	direction: UpdatesDirection;
	status?: UpdateStatusPayload | null;
	appState: BadgeState;
	updateAvailable: boolean;
	reinstallAvailable: boolean;
	canApply: boolean;
	showReleaseMeta: boolean;
	releaseInstallProgress?: ReleaseInstallProgressState | null;
	isLoading: boolean;
	isChecking: boolean;
	isApplying: boolean;
	isReinstalling: boolean;
	isRepairing: boolean;
	onCheck: () => void;
	onApply: () => void;
	onReinstall: () => void;
}

/**
 * Props for the database-schema management section.
 */
export interface DatabaseSchemaProps {
	direction: UpdatesDirection;
	databaseStatus?: DatabaseStatus | null;
	databaseState: BadgeState;
	visibleDatabaseIssues: UpdateIssue[];
	isLoading: boolean;
	isChecking: boolean;
	isApplying: boolean;
	isRepairing: boolean;
	onRepair: () => void;
}
