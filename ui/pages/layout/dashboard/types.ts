import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * Supported admin notice tones.
 */
export type NoticeTone = 'error' | 'warning' | 'success' | 'info';

/**
 * Optional action link attached to an admin notice.
 */
export interface NoticeActionLink {
	/** Button label shown in the notice. */
	label?: string | null;

	/** Relative or absolute URL opened by the notice action. */
	url?: string | null;
}

/**
 * Single admin notice returned by the system API.
 */
export interface AdminNoticeItem {
	/** Stable notice identifier. */
	id?: string | null;

	/** Visual tone used to style the notice. */
	type?: NoticeTone | null;

	/** Short notice heading. */
	title?: string | null;

	/** Body copy for the notice. */
	message?: string | null;

	/** Optional CTA rendered beside the notice body. */
	action?: NoticeActionLink | null;
}

/**
 * RTK Query response shape for admin notices.
 */
export interface AdminNoticesResponse {
	data?: {
		items?: AdminNoticeItem[];
	};
}

/**
 * Props for the rendered notice action button/link.
 */
export interface NoticeActionProps {
	/** Action payload attached to the notice. */
	action?: NoticeActionLink | null;

	/** Tone-specific action classes. */
	actionClassName: string;
}

/**
 * Props for the dashboard header.
 */
export interface HeaderProps {
	/** Opens the mobile navigation drawer. */
	onMobileMenuToggle: () => void;
}

/**
 * Props for the main dashboard layout wrapper.
 */
export interface LayoutProps {
	/** Page content rendered inside the dashboard shell. */
	children: ReactNode;
}

/**
 * Props for an individual search result button.
 */
export interface ResultButtonProps {
	/** Icon shown beside the result. */
	icon: LucideIcon;

	/** Primary result label. */
	title: string;

	/** Optional supporting description text. */
	description?: string;

	/** Optional metadata row shown below the description. */
	meta?: string;

	/** Click handler used to navigate to the result. */
	onClick: () => void;
}

/**
 * Props for a grouped search result section.
 */
export interface ResultSectionProps {
	/** Visible section heading. */
	title: string;

	/** Result content rendered in the section body. */
	children: ReactNode;
}

/**
 * Child navigation entry nested under a sidebar section.
 */
export interface NavChild {
	/** Visible label for the child link. */
	name: string;

	/** Router destination for the child link. */
	href: string;

	/** Whether the child requires admin access. */
	adminOnly?: boolean;
}

/**
 * Top-level dashboard sidebar item.
 */
export interface NavItem {
	/** Visible label for the navigation item. */
	name: string;

	/** Optional direct destination when the item is not a section. */
	href?: string;

	/** Optional parent route prefix used to mark nested routes as active. */
	activeBasePath?: string;

	/** Lucide icon rendered beside the item label. */
	icon: LucideIcon;

	/** Whether the item requires admin access. */
	adminOnly?: boolean;

	/** Nested child links for collapsible sections. */
	children?: NavChild[];
}

/**
 * Props for the responsive dashboard sidebar.
 */
export interface SidebarProps {
	/** Base path used when the dashboard is mounted below root. */
	basePath?: string;

	/** Whether the mobile drawer is currently open. */
	isMobileOpen: boolean;

	/** Closes the mobile drawer. */
	onMobileClose: () => void;
}
