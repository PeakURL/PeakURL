import { matchPath } from "react-router";

import type { PluginPageDescriptor } from "@/api/types/plugins";
import { PEAKURL_NAME } from "@/constants";
import { __ } from "@/i18n";
import {
	dashboardRoutes,
	dashboardToolRoutes,
	getDashboardBasePath,
} from "@/pages/dashboard/navigation";
import {
	getSettingsLabels,
	isValidImportTab,
	isValidSettingsTab,
} from "@/router/tabs";

export interface BreadcrumbItem {
	label: string;
	to?: string;
}

export interface BreadcrumbOptions {
	pathname: string;
	basePath?: string;
	canManagePlugins?: boolean;
	pluginPages?: readonly PluginPageDescriptor[];
}

/**
 * Derive localized, permission-aware breadcrumbs from the current dashboard path.
 */
export function getBreadcrumbs({
	pathname,
	basePath = "/dashboard",
	canManagePlugins = false,
	pluginPages = [],
}: BreadcrumbOptions): BreadcrumbItem[] {
	const dashboardRoot = getDashboardBasePath(basePath);
	const { overview, links, users, plugins, pluginAdd, tools, settings } =
		dashboardRoutes;
	const settingsHome = `${dashboardRoot}${settings.path}/general`;
	const root: BreadcrumbItem = { label: PEAKURL_NAME, to: dashboardRoot };

	for (const route of [overview, links, users, plugins]) {
		if (
			pathname === dashboardRoot + route.path ||
			(route === overview && pathname === "/")
		) {
			return [root, { label: route.label() }];
		}
	}

	if (pathname === `${dashboardRoot}/about`) {
		return [root, { label: __("About PeakURL") }];
	}

	if (pathname === dashboardRoot + pluginAdd.path) {
		return [
			root,
			{
				label: plugins.label(),
				to: canManagePlugins ? dashboardRoot + plugins.path : undefined,
			},
			{ label: pluginAdd.label() },
		];
	}

	const pluginPageMatch = matchPath(
		`${dashboardRoot}${plugins.path}/page/:pluginSlug/:pageId`,
		pathname
	);
	if (pluginPageMatch) {
		const { pluginSlug, pageId } = pluginPageMatch.params;
		const matchedPage = pluginPages.find(
			(page) => page.pluginSlug === pluginSlug && page.pageId === pageId
		);
		return [
			root,
			{
				label: plugins.label(),
				to: canManagePlugins ? dashboardRoot + plugins.path : undefined,
			},
			{
				label:
					matchedPage?.menuTitle ||
					matchedPage?.title ||
					__("Plugin Page"),
			},
		];
	}

	for (const route of dashboardToolRoutes) {
		const path = dashboardRoot + route.path;
		const importMatch =
			route === dashboardRoutes.import
				? matchPath(`${path}/:tab`, pathname)
				: null;
		if (
			pathname === path ||
			(importMatch && isValidImportTab(importMatch.params.tab))
		) {
			return [root, { label: tools.label() }, { label: route.label() }];
		}
	}

	const settingsMatch = matchPath(
		`${dashboardRoot}${settings.path}/:tab`,
		pathname
	);
	if (settingsMatch) {
		const tab = settingsMatch.params.tab ?? "general";
		if (isValidSettingsTab(tab)) {
			return [
				root,
				{
					label: settings.label(),
					to: settingsHome,
				},
				{ label: getSettingsLabels()[tab] },
			];
		}
	}

	if (pathname === dashboardRoot + settings.path) {
		return [
			root,
			{
				label: settings.label(),
				to: settingsHome,
			},
			{ label: getSettingsLabels().general },
		];
	}

	return [root, { label: __("Page Not Found") }];
}
