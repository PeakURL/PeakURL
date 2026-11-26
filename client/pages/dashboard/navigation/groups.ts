import { Wrench } from "lucide-react";
import type { LucideIcon } from "lucide-react";

import type { PluginPageDescriptor } from "@/api/types/plugins";
import { __, _x } from "@/i18n";

import { dashboardPageIcons } from "./icons";

export const dashboardRoutes = {
	overview: { path: "", label: () => __("Overview") },
	links: { path: "/links", label: () => __("Links") },
	users: { path: "/users", label: () => __("Users") },
	plugins: { path: "/plugins", label: () => __("Plugins") },
	pluginAdd: { path: "/plugins/add", label: () => __("Add New") },
	tools: { path: "/tools", label: () => __("Tools") },
	import: { path: "/tools/import", label: () => __("Import") },
	export: { path: "/tools/export", label: () => __("Export") },
	activity: { path: "/activity", label: () => __("Activity") },
	scheduledJobs: {
		path: "/tools/scheduled-jobs",
		label: () => __("Scheduled Jobs"),
	},
	systemStatus: {
		path: "/tools/system-status",
		label: () => __("System Status"),
	},
	settings: { path: "/settings", label: () => __("Settings") },
} as const;

export const dashboardToolRoutes = [
	dashboardRoutes.import,
	dashboardRoutes.export,
	dashboardRoutes.activity,
	dashboardRoutes.scheduledJobs,
	dashboardRoutes.systemStatus,
];

export function getDashboardBasePath(path = "/dashboard") {
	return path.replace(/\/+$/, "") || "/dashboard";
}

export function getPluginPagePath(
	page: Pick<PluginPageDescriptor, "pluginSlug" | "pageId">
) {
	return `${dashboardRoutes.plugins.path}/page/${page.pluginSlug}/${page.pageId}`;
}

interface NavigationLink {
	name: string;
	href: string;
	activeBasePath?: string;
}

export interface NavigationItem {
	id: string;
	icon: LucideIcon;
	name: string;
	href?: string;
	activeBasePath?: string;
	children?: NavigationLink[];
	badge?: string;
}

interface NavigationGroup {
	id: string;
	name?: string;
	items: NavigationItem[];
}

/** Returns only groups with destinations available to the current account. */
export function getNavigationGroups(
	base: string,
	canManageUsers: boolean,
	canManagePlugins: boolean,
	pluginPages: readonly PluginPageDescriptor[] = []
): NavigationGroup[] {
	const routeLink = ({
		path,
		label,
	}: {
		path: string;
		label: () => string;
	}): NavigationLink => ({
		name: label(),
		href: base + path,
	});

	const { overview, links, users, plugins, pluginAdd, tools, settings } =
		dashboardRoutes;
	const management: NavigationItem[] = [
		{ id: "links", icon: dashboardPageIcons.links, ...routeLink(links) },
	];
	if (canManageUsers)
		management.push({
			id: "users",
			icon: dashboardPageIcons.users,
			...routeLink(users),
		});
	if (canManagePlugins || pluginPages.length > 0) {
		const installed = routeLink(plugins);
		const destinations = pluginPages.map((page) => ({
			name: page.menuTitle || page.title,
			href: base + getPluginPagePath(page),
		}));
		management.push({
			id: "plugins",
			icon: dashboardPageIcons.plugins,
			name: installed.name,
			href: canManagePlugins ? installed.href : destinations[0]?.href,
			badge: __("Preview"),
			children: destinations.length
				? [
						...(canManagePlugins
							? [
									{
										...installed,
										name: __("Installed Plugins"),
									},
									routeLink(pluginAdd),
								]
							: []),
						...destinations,
					]
				: undefined,
		});
	}

	return [
		{
			id: "overview",
			items: [
				{
					id: "overview",
					icon: dashboardPageIcons.overview,
					...routeLink(overview),
				},
			],
		},
		{
			id: "management",
			name: __("Management"),
			items: management,
		},
		{
			id: "system",
			name: _x("System", "sidebar navigation group"),
			items: [
				...(canManageUsers
					? [
							{
								id: "tools",
								icon: Wrench,
								name: tools.label(),
								children: dashboardToolRoutes.map((route) => {
									const link = routeLink(route);
									return route === dashboardRoutes.import
										? {
												...link,
												href: `${link.href}/file`,
												activeBasePath: link.href,
											}
										: link;
								}),
							},
						]
					: []),
				{
					id: "settings",
					icon: dashboardPageIcons.settings,
					name: settings.label(),
					href: `${base}${settings.path}/general`,
					activeBasePath: base + settings.path,
				},
			],
		},
	].filter((group) => group.items.length > 0);
}
