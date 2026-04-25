import type { ReactNode } from "react";

/**
 * All available settings tab identifiers.
 * Used to uniquely identify each tab in the settings UI.
 */
export type SettingsTabId =
	| "general"
	| "security"
	| "api"
	| "integrations"
	| "email"
	| "location"
	| "updates";

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
