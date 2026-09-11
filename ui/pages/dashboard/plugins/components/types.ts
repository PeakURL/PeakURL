import type { TabId, PluginTabItem } from "../types";

/**
 * Placeholder plugin card data used by the preview screens.
 */
export interface PluginCardData {
	/** Stable placeholder identifier. */
	id: string;

	/** Gradient utility classes applied to the card banner. */
	gradient: string;

	/** Width classes for each skeleton text row. */
	barWidths: [string, string, string];
}

/**
 * Props for a single plugin preview card.
 */
export interface PluginCardProps {
	/** Plugin placeholder content rendered by the card. */
	plugin: PluginCardData;
}

/**
 * Shared props for placeholder skeleton bars in plugin preview surfaces.
 */
export interface PluginPreviewSkeletonProps {
	/** Additional utility classes applied to the skeleton bar. */
	className?: string;
}

/**
 * Props for the installed plugins preview table.
 */
export interface InstalledPluginsTableProps {
	/** Plugin placeholders rendered in the table. */
	plugins: PluginCardData[];
}

/**
 * Props for the installed-plugin status pill preview.
 */
export interface PluginStatusPillProps {
	/** Whether the preview badge should appear active. */
	active: boolean;
}

/**
 * Props for the "coming soon" badge shown on unreleased plugin surfaces.
 */
export interface ComingSoonBadgeProps {
	/** Additional utility classes applied to the badge container. */
	className?: string;
}

/**
 * Props for the plugin tab switcher.
 */
export interface PluginTabsProps {
	/** Currently selected tab. */
	activeTab: TabId;

	/** Updates the selected tab. */
	onTabChange: (tab: TabId) => void;

	/** Tabs rendered in the switcher. */
	tabs: PluginTabItem[];
}
