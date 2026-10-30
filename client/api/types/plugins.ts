/**
 * Type definitions for installed and official published plugins.
 *
 * @packageDocumentation
 */

import type { ApiDataResponse } from "@/state/slices/api/types";

/**
 * Supported plugin installation category.
 */
export type PluginType = "regular" | "must_use";

/**
 * Manifest resource URLs map.
 */
export interface PluginResourceLinks {
	readonly licenseUri?: string;
	readonly pluginUri?: string;
	readonly authorUri?: string | null;
	readonly documentation?: string;
	readonly repository?: string;
	readonly support?: string;
	readonly issues?: string;
}

/**
 * Discovered local plugin record returned by the backend inventory API.
 */
export interface InstalledPlugin extends PluginResourceLinks {
	/** Directory-derived identifier. */
	readonly id: string;
	/** Installation type ('regular' or 'must_use'). */
	readonly type: PluginType;
	/** Human-readable category label ('Regular' or 'Must-Use'). */
	readonly typeLabel: string;
	/** Display name from manifest. */
	readonly name: string;
	/** Short plain-text description extracted from manifest or README. */
	readonly description?: string | null;
	/** Semantic version string. */
	readonly version: string;
	/** Required minimum PeakURL version. */
	readonly requires: string;
	/** Verified PeakURL version. */
	readonly testedUpTo: string;
	/** Required PHP version notation. */
	readonly php: string;
	/** License identifier. */
	readonly license: string;
	/** Independent translation text domain matching plugin slug. */
	readonly textDomain?: string;
	/** Relative directory containing translation files. */
	readonly domainPath?: string;
	/** Whether manifest passed schema and structural validation. */
	readonly valid: boolean;
	/** Whether plugin is compatible with current host environment. */
	readonly compatible: boolean;
	/** Whether the regular plugin is currently active. */
	readonly active?: boolean;
	/** Author / publisher name if present in manifest or header metadata. */
	readonly author?: string | null;
	/** Verified author / publisher website URL if available. */
	readonly authorUri?: string | null;
	/** Validation error messages. */
	readonly errors: readonly string[];
	/** Compatibility warning messages. */
	readonly warnings: readonly string[];
	/** Optional gradient accent for visual presentation. */
	readonly gradient?: string;
}

/**
 * Effective upload size limit configuration from server runtime.
 */
export interface PluginUploadLimit {
	readonly bytes: number;
	readonly formatted: string;
}

/**
 * Grouped inventory payload returned by GET /api/v1/system/plugins.
 */
export interface InstalledPluginsPayload {
	/** All discovered plugins in alphabetical order. */
	readonly items: readonly InstalledPlugin[];
	/** Regular plugins from content/plugins/. */
	readonly regular: readonly InstalledPlugin[];
	/** Must-use plugins from content/mu-plugins/. */
	readonly mustUse: readonly InstalledPlugin[];
	/** Total count across both roots. */
	readonly total: number;
	/** Dynamic effective upload limit from server configuration. */
	readonly uploadLimit?: PluginUploadLimit;
}

/**
 * Full API response envelope for installed plugins.
 */
export type InstalledPluginsResponse = ApiDataResponse<InstalledPluginsPayload>;

/**
 * Author verification and public profile metadata for a published plugin.
 */
export interface PublishedPluginAuthor {
	readonly name: string;
	readonly username: string;
	readonly isVerifiedAuthor?: boolean;
	readonly profileUrl?: string;
	readonly avatarUrl?: string;
}

/**
 * Category/tag entry for a published plugin.
 */
export interface PublishedPluginTag {
	readonly slug: string;
	readonly label: string;
}

/**
 * Media assets for a published plugin.
 */
export interface PublishedPluginMedia {
	readonly icon128?: string;
	readonly icon256?: string;
	readonly cover?: string;
	readonly cover2x?: string;
	readonly screenshots?: readonly string[];
}

/**
 * Trust and moderation status in the official directory.
 */
export interface PublishedPluginTrust {
	readonly status: "published";
	readonly isOfficial: boolean;
	readonly isVerifiedAuthor: boolean;
	readonly featuredOrder: number | null;
	readonly publishedAt: string;
	readonly updatedAt: string;
}

/**
 * Package distribution details for a published release.
 */
export interface PublishedPluginDistribution {
	readonly downloadUrl: string;
	readonly sha256?: string;
}

/**
 * Release history summary item for a published plugin.
 */
export interface PublishedPluginReleaseSummary {
	readonly version: string;
	readonly releaseDate?: string;
	readonly requires?: string;
	readonly testedUpTo?: string;
	readonly php?: string;
	readonly license?: string;
	readonly licenseUri?: string;
}

/**
 * Canonical published plugin record from the official PeakURL.org directory.
 */
export interface PublishedPluginListing {
	readonly id: string;
	readonly slug: string;
	readonly name: string;
	readonly summary: string;
	readonly tags?: readonly PublishedPluginTag[];
	readonly version: string;
	readonly releaseDate?: string;
	readonly requires: string;
	readonly testedUpTo?: string;
	readonly php?: string;
	readonly license: string;
	readonly licenseUri?: string;
	readonly releases?: readonly PublishedPluginReleaseSummary[];
	readonly author: PublishedPluginAuthor;
	readonly media?: PublishedPluginMedia;
	readonly directoryTrust: PublishedPluginTrust;
	readonly distribution?: PublishedPluginDistribution;
	readonly links?: {
		readonly homepageUrl?: string;
		readonly repositoryUrl?: string;
		readonly documentationUrl?: string;
		readonly supportUrl?: string;
		readonly issuesUrl?: string;
	};
}

/**
 * Pagination metadata from published directory API.
 */
export interface PublishedPluginsPagination {
	readonly total: number;
	readonly page: number;
	readonly limit: number;
	readonly totalPages: number;
}

/**
 * Response payload structure from GET https://api.peakurl.org/v1/plugins.
 */
export interface PublishedPluginsResponseData {
	readonly items: readonly PublishedPluginListing[];
	readonly pagination: PublishedPluginsPagination;
}

/**
 * Full envelope returned by the published plugins directory API.
 */
export interface PublishedPluginsResponse {
	readonly success: boolean;
	readonly statusCode?: number;
	readonly message?: string;
	readonly data: PublishedPluginsResponseData;
}

/**
 * Supported sorting options in the published directory.
 */
export type PluginSortOption = "featured" | "newest";

/**
 * Query filter parameters for the published directory.
 */
export interface PublishedPluginsQueryParams {
	readonly q?: string;
	readonly sort?: PluginSortOption;
	readonly page?: number;
	readonly limit?: number;
	readonly tag?: string;
}

/**
 * Registered scoped browser asset definition.
 */
export interface PluginAssetDescriptor {
	readonly pluginSlug: string;
	readonly file: string;
	readonly url: string;
	readonly type: "script" | "style";
	readonly scope: string;
	readonly pageId?: string | null;
	readonly version?: string;
}

/**
 * Form field definition in declarative plugin admin page.
 */
export interface PluginFormFieldDescriptor {
	readonly key: string;
	readonly label: string;
	readonly type: "text" | "textarea" | "switch" | "select" | "number";
	readonly description?: string;
	readonly default?: unknown;
	readonly placeholder?: string;
	readonly options?: readonly {
		readonly label: string;
		readonly value: string | number;
	}[];
}

/**
 * Declarative section in plugin admin page.
 */
export interface PluginPageSectionDescriptor {
	readonly id: string;
	readonly title: string;
	readonly description?: string;
	readonly fields: readonly PluginFormFieldDescriptor[];
}

/**
 * Page action button definition.
 */
export interface PluginPageActionDescriptor {
	readonly id: string;
	readonly label: string;
	readonly variant?: "primary" | "secondary" | "danger";
	readonly capability?: string;
}

/**
 * Registered admin page descriptor.
 */
export interface PluginPageDescriptor {
	readonly pluginSlug: string;
	readonly pageId: string;
	readonly title: string;
	readonly menuTitle: string;
	readonly capability: string;
	readonly icon: string;
	readonly order: number;
	readonly sections: readonly PluginPageSectionDescriptor[];
	readonly actions: readonly PluginPageActionDescriptor[];
	readonly values?: Record<string, unknown>;
	readonly assets?: readonly PluginAssetDescriptor[];
}

/**
 * Native UI extension point descriptor.
 */
export interface PluginUIExtensionDescriptor {
	readonly id: string;
	readonly pluginSlug: string;
	readonly location: "links_overview_actions";
	readonly label: string;
	readonly actionId: string;
	readonly capability: string;
	readonly icon?: string;
	readonly confirm?: string | null;
	readonly assets?: readonly PluginAssetDescriptor[];
	readonly invalidates?: readonly ("Plugins" | "Analytics" | "Urls")[];
}

/**
 * Payload returned by GET /api/v1/system/plugins/extensions.
 */
export interface PluginExtensionsPayload {
	readonly pages: readonly PluginPageDescriptor[];
	readonly uiExtensions: readonly PluginUIExtensionDescriptor[];
}
