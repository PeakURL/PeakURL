import type { ReactNode } from "react";

import type { NoticeActionLink } from "@/api";

export type {
	AdminNoticeItem,
	AdminNoticesResponse,
	NoticeActionLink,
	NoticeTone,
} from "@/api";

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
	mobileMenuOpen: boolean;
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
 * Props for the responsive dashboard sidebar.
 */
export interface SidebarProps {
	isDesktop: boolean;
	/** Base path used when the dashboard is mounted below root. */
	basePath?: string;

	/** Whether the mobile drawer is currently open. */
	isMobileOpen: boolean;

	/** Closes the mobile drawer. */
	onMobileClose: () => void;

	/** Whether the desktop sidebar is currently collapsed into an icon rail. */
	isCollapsed?: boolean;

	/** Toggles the desktop sidebar collapsed state. */
	onToggleCollapse?: () => void;
}
