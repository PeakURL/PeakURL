import type { InstalledPlugin, PluginTabItem, TabId } from "../types";

/**
 * Props for the installed plugins management table.
 */
export interface InstalledPluginsTableProps {
	/** Discovered plugin records. */
	plugins: readonly InstalledPlugin[];

	/** Current tab category. */
	type: "regular" | "must_use";

	/** Loading indicator. */
	isLoading?: boolean;

	/** Error indicator. */
	isError?: boolean;

	/** Optional retry callback when query fails. */
	onRetry?: () => void;
}

/**
 * Props for the plugin tab switcher.
 */
export interface PluginTabsProps {
	/** Currently active tab identifier. */
	activeTab: TabId;

	/** Callback triggered when a tab is selected. */
	onTabChange: (tab: TabId) => void;

	/** List of available tabs with labels and optional badges. */
	tabs: PluginTabItem[];
}
