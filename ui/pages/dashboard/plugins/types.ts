import type { LucideIcon } from 'lucide-react';

/**
 * Supported plugin dashboard tabs.
 */
export type TabId = 'installed' | 'browse' | 'featured' | 'popular';

/**
 * Available layout modes for plugin cards.
 */
export type ViewMode = 'grid' | 'list';

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

/**
 * Props for a single roadmap feature card.
 */
export interface FeatureRoadmapCardProps {
	/** Lucide icon rendered for the roadmap item. */
	icon: LucideIcon;

	/** Roadmap feature title. */
	title: string;

	/** Supporting roadmap description. */
	description: string;

	/** Gradient utility classes applied to the card background. */
	gradient: string;
}
