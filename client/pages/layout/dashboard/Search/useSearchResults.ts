import { useEffect, useState } from "react";
import { Heart, Link2, Search, User } from "lucide-react";
import { dashboardPageIcons } from "@/pages/dashboard/navigation";

import { useAdminAccess } from "@/hooks";
import { SPONSOR_URL } from "@/constants";
import { __, sprintf } from "@/i18n";
import { getLinkDisplayTitle, getShortUrl } from "@/shared/links";
import { useGetAllUsersQuery, useGetUrlsQuery } from "@/state/slices/api";

import {
	findDashboardRouteMatches,
	findDashboardUserMatches,
	getLinkStatsPath,
	getLinksSearchPath,
} from "./lib";
import type { SearchResultGroup } from "./types";

// Wait for a pause in typing before searching the API; page matching stays immediate.
const SEARCH_DELAY_MS = 250;
const MIN_QUERY_LENGTH = 2;
const RESULT_LIMIT = 5;
/** Permission-aware local navigation and debounced API results for the open dialog. */
export function useSearchResults(query: string) {
	const access = useAdminAccess();
	const trimmedQuery = query.trim();
	const [remoteQuery, setRemoteQuery] = useState("");
	useEffect(() => {
		const timeout = window.setTimeout(
			() => setRemoteQuery(trimmedQuery),
			SEARCH_DELAY_MS
		);
		return () => window.clearTimeout(timeout);
	}, [trimmedQuery]);
	const isPending = remoteQuery !== trimmedQuery;
	const canSearch = trimmedQuery.length >= MIN_QUERY_LENGTH;
	const canFetch =
		canSearch && remoteQuery.length >= MIN_QUERY_LENGTH && !isPending;
	const {
		currentData: links,
		isFetching: loadingLinks,
		isError: linksError,
		refetch: retryLinks,
	} = useGetUrlsQuery(
		{
			page: 1,
			limit: RESULT_LIMIT,
			sortBy: "updatedAt",
			sortOrder: "desc",
			search: remoteQuery,
		},
		{ skip: !access.canViewLinks || !canFetch }
	);
	const {
		currentData: users,
		isFetching: loadingUsers,
		isError: usersError,
		refetch: retryUsers,
	} = useGetAllUsersQuery(undefined, {
		skip: !access.canManageUsers || !canFetch,
	});
	const routeMatches = findDashboardRouteMatches(
		trimmedQuery,
		{
			canViewLinks: access.canViewLinks,
			canManagePerformance: access.canManagePerformance,
			canManageUsers: access.canManageUsers,
			canManagePlugins: access.canManageUsers,
			canManageApiKeys: access.canManageApiKeys,
			canManageIntegrations: access.canManageIntegrations,
			canManageMcp: access.canManageMcp,
			canManageMailDelivery: access.canManageMailDelivery,
			canManageLocationData: access.canManageLocationData,
			canManageUpdates: access.canManageUpdates,
			canImportLinks: access.canManageUsers,
			canExportLinks: access.canViewLinks,
			canViewSystemStatus: access.canManageUsers,
		},
		RESULT_LIMIT
	);
	const groups: SearchResultGroup[] = [
		{
			id: "pages",
			title: trimmedQuery ? __("Pages and tools") : __("Quick access"),
			items: routeMatches.map((item) => ({
				id: item.id,
				title: item.label,
				description: item.description,
				href: item.href,
				icon: dashboardPageIcons[item.id],
			})),
		},
	];
	const sponsorTerms = [
		__("Sponsor"),
		__("Support PeakURL"),
		"sponsor",
		"support",
		"donate",
	];
	if (
		sponsorTerms.some((term) =>
			term.toLowerCase().includes(trimmedQuery.toLowerCase())
		)
	) {
		groups[0]?.items.push({
			id: "sponsor",
			title: __("Sponsor PeakURL"),
			description: __("Support the project"),
			href: SPONSOR_URL,
			icon: Heart,
			external: true,
		});
	}

	if (canSearch && access.canViewLinks) {
		groups.push({
			id: "links",
			title: __("Links"),
			isLoading: isPending || loadingLinks,
			isError: linksError,
			onRetry: () => void retryLinks(),
			items: (links?.data?.items || []).map((link) => {
				const shortCode = link.alias || link.shortCode || "";
				return {
					id: link.id,
					title: getLinkDisplayTitle(
						link.title,
						shortCode || link.destinationUrl || __("Untitled Link")
					),
					description: getShortUrl(link),
					meta: link.destinationUrl || "",
					href: shortCode
						? getLinkStatsPath(shortCode)
						: getLinksSearchPath(trimmedQuery),
					icon: Link2,
				};
			}),
		});
	}
	if (canSearch && access.canManageUsers) {
		groups.push({
			id: "people",
			title: __("People"),
			isLoading: isPending || loadingUsers,
			isError: usersError,
			onRetry: () => void retryUsers(),
			items: findDashboardUserMatches(
				trimmedQuery,
				users?.data || [],
				RESULT_LIMIT
			).map((item) => ({ ...item, icon: User })),
		});
	}
	if (trimmedQuery && access.canViewLinks) {
		groups.push({
			id: "all-links",
			title: __("Search all links"),
			items: [
				{
					id: "all-links",
					title: sprintf(
						__('Search all links for "%s"'),
						trimmedQuery
					),
					description: __("Open the full list of matching links"),
					href: getLinksSearchPath(trimmedQuery),
					icon: Search,
				},
			],
		});
	}
	return {
		groups,
		isSearching:
			canSearch &&
			((access.canViewLinks && (isPending || loadingLinks)) ||
				(access.canManageUsers && (isPending || loadingUsers))),
	};
}
