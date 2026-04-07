import type { ComponentType, ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';

/**
 * Icon contract used by external add-on cards.
 */
export type AddOnIcon = ComponentType<{ className?: string }>;

/**
 * Supported landing states for the post-install and post-update banner.
 */
export type LandingSource = 'install' | 'update';

/**
 * Single call-to-action rendered inside the landing banner.
 */
export interface LandingAction {
	/** Visible button label. */
	label: string;

	/** Dashboard route opened by the action. */
	to: string;
}

/**
 * Text and actions shown in the landing banner for a given source.
 */
export interface LandingMetaEntry {
	/** Small eyebrow label shown above the title. */
	eyebrow: string;

	/** Main landing banner heading. */
	title: string;

	/** Supporting banner copy. */
	description: string;

	/** Dashboard actions rendered beneath the copy. */
	actions: LandingAction[];
}

/**
 * Feature card content rendered on the about page.
 */
export interface Feature {
	/** Icon displayed above the feature title. */
	icon: LucideIcon;

	/** Feature heading. */
	title: string;

	/** Supporting feature description. */
	description: string;
}

/**
 * Summary statistic rendered in the "freedom" section.
 */
export interface Freedom {
	/** Highlighted numeric callout. */
	number: string;

	/** Short heading for the statistic. */
	title: string;

	/** Supporting explanatory copy. */
	description: string;
}

/**
 * Props for a row in the system information grid.
 */
export interface SystemInfoRowProps {
	/** Icon rendered beside the label. */
	icon: LucideIcon;

	/** Row label. */
	label: string;

	/** Row value. */
	value: string;
}

/**
 * Props for the centered section heading helper.
 */
export interface SectionTitleProps {
	/** Main heading content. */
	children: ReactNode;

	/** Optional subtitle shown beneath the heading. */
	subtitle?: string;
}

/**
 * Props shared by inline SVG icon components on the about page.
 */
export interface AboutIconProps {
	/** Utility classes applied to the SVG root. */
	className?: string;
}

/**
 * Add-on card metadata rendered in the extensions section.
 */
export interface AddOnLink {
	/** Visible label for the add-on card. */
	label: string;

	/** Destination URL for the add-on. */
	href: string;

	/** Icon component rendered for the add-on. */
	icon: AddOnIcon;
}

/**
 * Props for the install/update landing banner.
 */
export interface LandingBannerProps {
	/** Raw `source` query-param value from the about page route. */
	source: string | null;
}
