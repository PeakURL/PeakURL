import type { ReactNode } from "react";
import type { SettingsTabId } from "@/router/tabs";

export type { SettingsTabId };

/**
 * Supported icon names for settings tabs.
 * Typically maps to an icon library (e.g. Lucide, Heroicons).
 */
export type SettingsTabIcon =
	| "settings"
	| "activity"
	| "shield"
	| "key"
	| "mail"
	| "mapPin"
	| "globe"
	| "plug"
	| "mcp"
	| "zap"
	| "download";

/**
 * Represents a single item (tab) in the settings navigation.
 */
export interface SettingsTabItem {
	/**
	 * Unique identifier for the tab.
	 */
	id: SettingsTabId;

	/**
	 * Display name shown in the UI.
	 */
	name: string;

	/**
	 * Icon associated with the tab.
	 */
	icon: SettingsTabIcon;

	/**
	 * Optional badge label shown beside the tab name (e.g., "New").
	 */
	badge?: string;

	/**
	 * Visual styling variant for the badge.
	 */
	badgeVariant?: "new" | "preview" | "neutral";
}

/**
 * Props for the Settings layout component.
 */
export interface SettingsLayoutProps {
	/**
	 * Content rendered inside the settings layout.
	 */
	children: ReactNode;
}

/**
 * Props for the settings sidebar navigation.
 */
export interface SidebarProps {
	/** Tabs rendered in the sidebar. */
	tabs: SettingsTabItem[];

	/** Currently active settings tab identifier. */
	activeTab: SettingsTabId;
}
