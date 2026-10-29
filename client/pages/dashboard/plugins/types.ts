/**
 * Supported plugin dashboard tabs.
 */
export type TabId = "installed" | "must_use";

/**
 * Tab metadata rendered in the plugin header.
 */
export interface PluginTabItem {
	/** Stable tab identifier. */
	id: TabId;

	/** Visible tab label. */
	label: string;

	/** Optional count badge rendered beside the label. */
	count?: number;
}

export type {
	InstalledPlugin,
	InstalledPluginsPayload,
	PluginUploadLimit,
	PluginType,
	PluginResourceLinks,
} from "@/api";

export type {
	PublishedPluginListing,
	PublishedPluginAuthor,
	PublishedPluginTag,
	PublishedPluginMedia,
	PublishedPluginTrust,
	PublishedPluginDistribution,
	PublishedPluginsPagination,
	PublishedPluginsResponse,
	PublishedPluginsQueryParams,
	PluginSortOption,
} from "@/api";
