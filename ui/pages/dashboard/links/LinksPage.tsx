import { useState } from "react";
import { useDispatch } from "react-redux";
import {
	ArrowDown,
	ArrowUp,
	CircleCheckBig,
	Link2,
	MousePointerClick,
	Trash2,
	Users,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";

import { useNotification } from "@/components";
import { __, sprintf } from "@/i18n";
import type { AppDispatch } from "@/store";
import {
	urlsApi,
	useBulkRestoreUrlsMutation,
	useEmptyTrashMutation,
	useGetGeneralSettingsQuery,
	useGetUrlQuery,
	useGetUrlsQuery,
	useRestoreUrlMutation,
} from "@/store/slices/api";
import { cn, formatCount, getErrorMessage } from "@/utils";

import {
	Header,
	UrlShorteningForm,
	LinksTable,
	TableFooter,
	Pagination,
	LinksSkeleton,
} from "./components";
import { useLinksFilter } from "./hooks";
import type { LinkRecord, LinksDateRange, LinksMeta } from "./types";

type LinkStatChangeType = "positive" | "negative";

interface LinkStatChange {
	text: string;
	type: LinkStatChangeType;
}

type LinkStatTone = "clicks" | "visitors" | "links" | "active";

interface LinkStatCardData {
	title: string;
	value: string;
	change: LinkStatChange | null;
	note?: string;
	icon: LucideIcon;
	tone: LinkStatTone;
}

function getPeriodChange(
	current: number,
	last?: number
): LinkStatChange | null {
	if (last === undefined) {
		return null;
	}

	const delta = current - last;
	if (current === 0 && last === 0) {
		return null;
	}

	let formatted: string;
	if (last === 0) {
		formatted = "100%";
	} else if (current === 0) {
		formatted = "100%";
	} else {
		const rawPercent = (delta / last) * 100;
		formatted = `${Math.abs(rawPercent).toFixed(1)}%`;
	}

	return {
		text: `${delta >= 0 ? "+" : "-"}${formatted}`,
		type: delta >= 0 ? "positive" : "negative",
	};
}

function getPeriodNote(range: LinksDateRange): string {
	switch (range) {
		case "24h":
			return __("vs previous day");
		case "7d":
			return __("vs last week");
		case "30d":
			return __("vs last month");
		default:
			return __("vs last period");
	}
}

function LinksPage() {
	const dispatch = useDispatch<AppDispatch>();
	const notifications = useNotification();

	const {
		sortBy,
		setSortBy,
		sortOrder,
		setSortOrder,
		statusFilter,
		setStatusFilter,
		isTrashTab,
		limit,
		setLimit,
		currentPage,
		setCurrentPage,
		clickRange,
		setClickRange,
		customClickRange,
		setCustomClickRange,
		searchQuery,
		statsShortId,
		urlsQueryArgs,
	} = useLinksFilter();

	const [restoreUrl] = useRestoreUrlMutation();
	const [bulkRestoreUrls] = useBulkRestoreUrlsMutation();
	const [emptyTrash] = useEmptyTrashMutation();

	const {
		data: urlsRes,
		refetch: refetchUrls,
		isLoading: isUrlsLoading,
	} = useGetUrlsQuery(urlsQueryArgs);

	const apiItems: LinkRecord[] = urlsRes?.data?.items ?? [];
	const apiMeta: LinksMeta = {
		page: urlsRes?.data?.meta?.page ?? currentPage,
		limit: urlsRes?.data?.meta?.limit ?? limit,
		totalItems: urlsRes?.data?.meta?.totalItems ?? apiItems.length,
		totalPages: urlsRes?.data?.meta?.totalPages ?? 1,
		totalClicks: urlsRes?.data?.meta?.totalClicks ?? 0,
		uniqueClicks: urlsRes?.data?.meta?.uniqueClicks ?? 0,
		activeLinks: urlsRes?.data?.meta?.activeLinks ?? 0,
		trashedLinks: urlsRes?.data?.meta?.trashedLinks ?? 0,
		lastPeriodTotalClicks: urlsRes?.data?.meta?.lastPeriodTotalClicks,
		lastPeriodUniqueClicks: urlsRes?.data?.meta?.lastPeriodUniqueClicks,
	};
	const { data: statsLinkRes, refetch: refetchStatsLookup } = useGetUrlQuery(
		statsShortId || "",
		{ skip: !statsShortId || isTrashTab }
	);
	const statsLink = statsLinkRes?.data ?? null;
	const { data: siteSettingsRes } = useGetGeneralSettingsQuery();
	const trashRetentionDays = siteSettingsRes?.data?.trashRetentionDays ?? 30;
	const [isRefreshing, setIsRefreshing] = useState(false);

	// Reset to page 1 during render when query, pagination, status, or date filters change
	const filterKey = `${statusFilter}:${searchQuery}:${limit}:${sortBy}:${sortOrder}:${clickRange}:${customClickRange.from}:${customClickRange.to}`;
	const [prevFilterKey, setPrevFilterKey] = useState(filterKey);
	if (prevFilterKey !== filterKey) {
		setPrevFilterKey(filterKey);
		setCurrentPage(1);
	}

	const handleRefresh = async () => {
		if (isRefreshing) {
			return;
		}

		setIsRefreshing(true);
		const startedAt = Date.now();

		try {
			await Promise.allSettled([
				refetchUrls(),
				dispatch(
					urlsApi.endpoints.getUrls.initiate(undefined, {
						subscribe: false,
						forceRefetch: true,
					})
				),
				statsShortId ? refetchStatsLookup() : Promise.resolve(),
			]);
		} finally {
			const remaining = 700 - (Date.now() - startedAt);

			if (remaining > 0) {
				window.setTimeout(() => setIsRefreshing(false), remaining);
			} else {
				setIsRefreshing(false);
			}
		}
	};

	const handleRestoreLink = async (link: LinkRecord) => {
		try {
			await restoreUrl(link.id).unwrap();
			notifications.success(
				__("Link restored"),
				__("The link has been restored to active status.")
			);
		} catch (err) {
			notifications.error(
				__("Unable to restore link"),
				getErrorMessage(err, __("Failed to restore link."))
			);
		}
	};

	const handleBulkRestoreLinks = async (ids: string[]) => {
		try {
			await bulkRestoreUrls(ids).unwrap();
			notifications.success(
				__("Links restored"),
				__("Selected links have been restored to active status.")
			);
		} catch (err) {
			notifications.error(
				__("Unable to restore links"),
				getErrorMessage(err, __("Failed to restore selected links."))
			);
		}
	};

	const handleEmptyTrash = async () => {
		try {
			await emptyTrash().unwrap();
			notifications.success(
				__("Trash emptied"),
				__("All trashed links have been permanently deleted.")
			);
		} catch (err) {
			notifications.error(
				__("Unable to empty trash"),
				getErrorMessage(err, __("Failed to empty trash."))
			);
		}
	};

	const filteredLinks = apiItems;
	const sortedLinks = filteredLinks;
	const totalItems = apiMeta.totalItems;
	const totalPages = apiMeta.totalPages;
	const startItem = (apiMeta.page - 1) * apiMeta.limit + 1;
	const endItem = Math.min(apiMeta.page * apiMeta.limit, totalItems);
	const paginatedLinks = sortedLinks;

	const isLoading = isUrlsLoading;

	if (isLoading) {
		return (
			<div className="links-page">
				<Header
					onRefresh={handleRefresh}
					isRefreshing={true}
					clickRange={clickRange}
					customClickRange={customClickRange}
					onClickRangeChange={setClickRange}
					onCustomClickRangeChange={setCustomClickRange}
				/>
				<LinksSkeleton />
			</div>
		);
	}

	const totalClicks = apiMeta.totalClicks ?? 0;
	const totalUniqueClicks = apiMeta.uniqueClicks ?? 0;
	const activeLinks = apiMeta.activeLinks ?? 0;
	const trashedLinksCount = apiMeta.trashedLinks ?? 0;

	const clicksChange = getPeriodChange(
		totalClicks,
		apiMeta.lastPeriodTotalClicks
	);
	const visitorsChange = getPeriodChange(
		totalUniqueClicks,
		apiMeta.lastPeriodUniqueClicks
	);
	const periodNote = getPeriodNote(clickRange);

	const getClicksFallbackNote = () => {
		if (clickRange === "all") {
			return __("All-time clicks");
		}
		if (clickRange === "custom") {
			return __("Selected date range");
		}
		return __("No activity recorded");
	};

	const getVisitorsFallbackNote = () => {
		if (clickRange === "all") {
			return __("All-time visitors");
		}
		if (clickRange === "custom") {
			return __("Selected date range");
		}
		return __("No visitors recorded");
	};

	const statsData: LinkStatCardData[] = [
		{
			title: __("Total Clicks"),
			value: formatCount(totalClicks),
			change: clicksChange,
			note: getClicksFallbackNote(),
			icon: MousePointerClick,
			tone: "clicks",
		},
		{
			title: __("Visitors"),
			value: formatCount(totalUniqueClicks),
			change: visitorsChange,
			note: getVisitorsFallbackNote(),
			icon: Users,
			tone: "visitors",
		},
		{
			title: __("Total Links"),
			value: formatCount(totalItems),
			change: null,
			note: __("Total created links"),
			icon: Link2,
			tone: "links",
		},
		{
			title: __("Active Links"),
			value: formatCount(activeLinks),
			change: null,
			note: __("Currently active"),
			icon: CircleCheckBig,
			tone: "active",
		},
	];

	return (
		<div className="links-page">
			<Header
				onRefresh={handleRefresh}
				isRefreshing={isRefreshing}
				clickRange={clickRange}
				customClickRange={customClickRange}
				onClickRangeChange={setClickRange}
				onCustomClickRangeChange={setCustomClickRange}
			/>

			{/* Quick Stats Grid */}
			<div className="links-page-stats">
				{statsData.map((stat) => {
					const StatIcon = stat.icon;

					return (
						<div key={stat.title} className="links-page-stat-card">
							<div className="links-page-stat-header">
								<div className="links-page-stat-copy">
									<p className="links-page-stat-title">
										{stat.title}
									</p>
									<p className="links-page-stat-value">
										{stat.value}
									</p>
								</div>
								<div
									className={cn(
										"links-page-stat-icon",
										`links-page-stat-icon-${stat.tone}`
									)}
								>
									<StatIcon
										className={cn(
											"links-page-stat-icon-glyph",
											`links-page-stat-icon-glyph-${stat.tone}`
										)}
									/>
								</div>
							</div>

							<div className="links-page-stat-footer">
								{stat.change ? (
									<div className="links-page-stat-change">
										<span
											className={cn(
												"links-page-stat-change-badge",
												stat.change.type === "positive"
													? "links-page-stat-change-badge-positive"
													: "links-page-stat-change-badge-negative"
											)}
										>
											{stat.change.type === "positive" ? (
												<ArrowUp className="links-page-stat-change-icon" />
											) : (
												<ArrowDown className="links-page-stat-change-icon" />
											)}
											{stat.change.text}
										</span>
										<span className="links-page-stat-change-note">
											{periodNote}
										</span>
									</div>
								) : (
									<span className="links-page-stat-change-note">
										{stat.note}
									</span>
								)}
							</div>
						</div>
					);
				})}
			</div>

			<UrlShorteningForm />

			{/* Trash Info Notice - clean informational notice without redundant buttons */}
			{isTrashTab && trashedLinksCount > 0 && (
				<div className="links-trash-banner">
					<div className="links-trash-banner-content">
						<Trash2 size={16} />
						<span>
							{trashRetentionDays === 0
								? __(
										"Items in the trash are retained indefinitely until emptied."
									)
								: sprintf(
										__(
											"Items in the trash will be automatically deleted after %d days."
										),
										trashRetentionDays
									)}
						</span>
					</div>
				</div>
			)}

			<LinksTable
				links={paginatedLinks}
				statsShortId={statsShortId}
				statsLink={statsLink}
				sortBy={sortBy}
				clickRange={clickRange}
				customClickRange={customClickRange}
				isTrashTab={isTrashTab}
				trashedCount={trashedLinksCount}
				onRestore={handleRestoreLink}
				onBulkRestore={handleBulkRestoreLinks}
				onEmptyTrash={handleEmptyTrash}
			/>

			<TableFooter
				totalLinks={totalItems}
				totalClicks={totalClicks}
				sortBy={sortBy}
				setSortBy={setSortBy}
				sortOrder={sortOrder}
				setSortOrder={setSortOrder}
				limit={limit}
				setLimit={setLimit}
				statusFilter={statusFilter}
				setStatusFilter={setStatusFilter}
				trashedCount={trashedLinksCount}
			/>

			{totalPages > 1 && (
				<Pagination
					currentPage={currentPage}
					totalPages={totalPages}
					onPageChange={setCurrentPage}
					startItem={startItem}
					endItem={endItem}
					totalItems={totalItems}
				/>
			)}
		</div>
	);
}

export default LinksPage;
