import { __ } from "@/i18n";

const VALID_SETTINGS_TABS = [
	"general",
	"security",
	"api",
	"integrations",
	"mcp",
	"performance",
	"email",
	"location",
	"updates",
] as const;

export type SettingsTabId = (typeof VALID_SETTINGS_TABS)[number];

const SETTINGS_TAB_SET = new Set<string>(VALID_SETTINGS_TABS);

export function isValidSettingsTab(
	tab: string | undefined
): tab is SettingsTabId {
	return Boolean(tab && SETTINGS_TAB_SET.has(tab));
}

/** Localized labels shared by Settings navigation, headings, and breadcrumbs. */
export function getSettingsLabels() {
	return {
		general: __("General"),
		security: __("Security"),
		api: __("API Keys"),
		integrations: __("Integrations"),
		mcp: __("MCP Server"),
		performance: __("Performance"),
		email: __("Email SMTP"),
		location: __("Location Data"),
		updates: __("Updates"),
	} satisfies Record<SettingsTabId, string>;
}

/** The display order also defines the valid Settings route segments. */
export const settingsTabOrder: readonly SettingsTabId[] = VALID_SETTINGS_TABS;

export const VALID_IMPORT_TABS = ["file", "api", "paste"] as const;

export type ImportTabId = (typeof VALID_IMPORT_TABS)[number];

const IMPORT_TAB_SET = new Set<string>(VALID_IMPORT_TABS);

export function isValidImportTab(tab: string | undefined): tab is ImportTabId {
	return Boolean(tab && IMPORT_TAB_SET.has(tab));
}
