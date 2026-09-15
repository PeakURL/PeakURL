export const VALID_SETTINGS_TABS = [
	"general",
	"security",
	"api",
	"integrations",
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

export const VALID_IMPORT_TABS = ["file", "api", "paste"] as const;

export type ImportTabId = (typeof VALID_IMPORT_TABS)[number];

const IMPORT_TAB_SET = new Set<string>(VALID_IMPORT_TABS);

export function isValidImportTab(tab: string | undefined): tab is ImportTabId {
	return Boolean(tab && IMPORT_TAB_SET.has(tab));
}
