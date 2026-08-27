import { useEffect, useMemo, useState } from "react";
import { useDispatch } from "react-redux";
import { useSearchParams } from "react-router";
import { CircleCheckBig, Link2, MousePointerClick, Users } from "lucide-react";

import { DEFAULT_PAGE_SIZE_OPTIONS, normalizePageSize } from "@/components";
import { __ } from "@/i18n";
import type { AppDispatch } from "@/store";
import { urlsApi, useGetUrlQuery, useGetUrlsQuery } from "@/store/slices/api";
import type { GetUrlsQueryArgs } from "@/store/slices/api";
import { formatCount } from "@/utils";

import {
	Header,
	UrlShorteningForm,
	LinksTable,
	TableFooter,
	Pagination,
	LinksSkeleton,
} from "./_components";
import type {
	LinkRecord,
	LinksCustomDateRange,
	LinksDateRange,
	LinksMeta,
	LinksSortBy,
	LinksSortOrder,
} from "./_components/types";
import type { GetUrlsResponse } from "./types";

// LocalStorage keys for persistence (defined outside component to satisfy hook deps)
const LS_KEYS = {
	sortBy: "admin_links_sortBy",
	sortOrder: "admin_links_sortOrder",
	limit: "admin_links_limit",
};

const DATE_RANGE_DAY_MS = 24 * 60 * 60 * 1000;

function formatDateInput(date: Date): string {
	const year = date.getFullYear();
	const month = String(date.getMonth() + 1).padStart(2, "0");
	const day = String(date.getDate()).padStart(2, "0");

	return `${year}-${month}-${day}`;
}

function getDefaultCustomClickRange(): LinksCustomDateRange {
	const today = new Date();
	const weekStart = new Date(today.getTime() - 6 * DATE_RANGE_DAY_MS);

	return {
		from: formatDateInput(weekStart),
		to: formatDateInput(today),
	};
}

function LinksPage() {
	const dispatch = useDispatch<AppDispatch>();

	// State for Sorting and Pagination
	const [sortBy, setSortBy] = useState<LinksSortBy>(() =>
		typeof window !== "undefined"
			? (localStorage.getItem(LS_KEYS.sortBy) as LinksSortBy) ||
				"createdAt"
			: "createdAt"
	);
	const [sortOrder, setSortOrder] = useState<LinksSortOrder>(() =>
		typeof window !== "undefined"
			? (localStorage.getItem(LS_KEYS.sortOrder) as LinksSortOrder) ||
				"desc"
			: "desc"
	);
	const [limit, setLimit] = useState<number>(() => {
		if (typeof window !== "undefined") {
			return normalizePageSize(
				localStorage.getItem(LS_KEYS.limit),
				DEFAULT_PAGE_SIZE_OPTIONS[0] ?? 25
			);
		}

		return DEFAULT_PAGE_SIZE_OPTIONS[0] ?? 25;
	});
	const [currentPage, setCurrentPage] = useState(1);
	const [clickRange, setClickRange] = useState<LinksDateRange>("all");
	const [customClickRange, setCustomClickRange] =
		useState<LinksCustomDateRange>(() => getDefaultCustomClickRange());
	const [searchParams] = useSearchParams();
	const statsShortId = searchParams.get("stats");
	const searchQuery = searchParams.get("search")?.trim() || "";

	// No need for a load effect since initial state derives from localStorage

	// Persist settings
	useEffect(() => {
		try {
			localStorage.setItem(LS_KEYS.sortBy, sortBy);
			localStorage.setItem(LS_KEYS.sortOrder, sortOrder);
			localStorage.setItem(LS_KEYS.limit, String(limit));
		} catch {}
	}, [sortBy, sortOrder, limit]);

	const urlsQueryArgs = useMemo<GetUrlsQueryArgs>(() => {
		const query = {
			page: currentPage,
			limit,
			sortBy,
			sortOrder,
			search: searchQuery,
		};

		if ("custom" === clickRange) {
			return {
				...query,
				range: "custom",
				from: customClickRange.from,
				to: customClickRange.to,
			};
		}

		if ("all" === clickRange) {
			return query;
		}

		return {
			...query,
			range: clickRange,
		};
	}, [
		clickRange,
		currentPage,
		customClickRange.from,
		customClickRange.to,
		limit,
		searchQuery,
		sortBy,
		sortOrder,
	]);

	const {
		data: urlsRes,
		refetch: refetchUrls,
		isLoading: isUrlsLoading,
	} = useGetUrlsQuery(urlsQueryArgs);
	const typedUrlsRes = urlsRes as GetUrlsResponse | undefined;

	const apiItems: LinkRecord[] = typedUrlsRes?.data?.items ?? [];
	const apiMeta: LinksMeta = {
		page: typedUrlsRes?.data?.meta?.page ?? currentPage,
		limit: typedUrlsRes?.data?.meta?.limit ?? limit,
		totalItems: typedUrlsRes?.data?.meta?.totalItems ?? apiItems.length,
		totalPages: typedUrlsRes?.data?.meta?.totalPages ?? 1,
		totalClicks: typedUrlsRes?.data?.meta?.totalClicks ?? 0,
		uniqueClicks: typedUrlsRes?.data?.meta?.uniqueClicks ?? 0,
		activeLinks: typedUrlsRes?.data?.meta?.activeLinks ?? 0,
		lastPeriodTotalClicks: typedUrlsRes?.data?.meta?.lastPeriodTotalClicks,
		lastPeriodUniqueClicks:
			typedUrlsRes?.data?.meta?.lastPeriodUniqueClicks,
	};
	const { data: statsLinkRes, refetch: refetchStatsLookup } = useGetUrlQuery(
		statsShortId || "",
		{ skip: !statsShortId }
	);
	const statsLink = statsLinkRes?.data ?? null;
	const [isRefreshing, setIsRefreshing] = useState(false);

	// Reset to page 1 during render when query, pagination, or date filters change
	const filterKey = `${searchQuery}:${limit}:${sortBy}:${sortOrder}:${clickRange}:${customClickRange.from}:${customClickRange.to}`;
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

	// Filter
	const filteredLinks = apiItems;

	// Sort
	// Sorting is handled server-side
	const sortedLinks = filteredLinks;

	// Pagination
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

	// Real stats based on the backend API response
	const totalClicks = apiMeta.totalClicks ?? 0;
	const totalUniqueClicks = apiMeta.uniqueClicks ?? 0;
	const activeLinks = apiMeta.activeLinks ?? 0;

	const getPercentChange = (current: number, last: number | undefined) => {
		if (last === undefined) return null;
		const delta = current - last;
		if (last === 0) {
			if (current === 0) return null;
			return { text: "+100%", isPositive: true };
		}
		const percent = Math.abs((delta / last) * 100).toFixed(1);
		return {
			text: `${delta >= 0 ? "+" : "-"}${percent}%`,
			isPositive: delta >= 0,
		};
	};

	const clicksChange = getPercentChange(
		totalClicks,
		apiMeta.lastPeriodTotalClicks
	);
	const visitorsChange = getPercentChange(
		totalUniqueClicks,
		apiMeta.lastPeriodUniqueClicks
	);

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

			{/* Quick Stats - Compact Row */}
			<div className="links-page-stats">
				<div className="links-page-stat-card">
					<div className="links-page-stat-header">
						<div className="links-page-stat-icon links-page-stat-icon-clicks">
							<MousePointerClick className="h-4 w-4 text-blue-600 dark:text-blue-400" />
						</div>
						{clicksChange && (
							<span
								className={`links-page-stat-trend ${
									clicksChange.isPositive
										? "links-page-stat-trend-positive"
										: "links-page-stat-trend-negative"
								}`}
							>
								{clicksChange.text}
							</span>
						)}
					</div>
					<div className="links-page-stat-value">
						{formatCount(totalClicks)}
					</div>
					<div className="links-page-stat-label">
						{__("Total Clicks")}
					</div>
				</div>

				<div className="links-page-stat-card">
					<div className="links-page-stat-header">
						<div className="links-page-stat-icon links-page-stat-icon-visitors">
							<Users className="h-4 w-4 text-purple-600 dark:text-purple-400" />
						</div>
						{visitorsChange && (
							<span
								className={`links-page-stat-trend ${
									visitorsChange.isPositive
										? "links-page-stat-trend-positive"
										: "links-page-stat-trend-negative"
								}`}
							>
								{visitorsChange.text}
							</span>
						)}
					</div>
					<div className="links-page-stat-value">
						{formatCount(totalUniqueClicks)}
					</div>
					<div className="links-page-stat-label">
						{__("Visitors")}
					</div>
				</div>

				<div className="links-page-stat-card">
					<div className="links-page-stat-header">
						<div className="links-page-stat-icon links-page-stat-icon-links">
							<Link2 className="h-4 w-4 text-primary-600 dark:text-primary-400" />
						</div>
					</div>
					<div className="links-page-stat-value">
						{formatCount(totalItems)}
					</div>
					<div className="links-page-stat-label">
						{__("Total Links")}
					</div>
				</div>

				<div className="links-page-stat-card">
					<div className="links-page-stat-header">
						<div className="links-page-stat-icon links-page-stat-icon-active">
							<CircleCheckBig className="h-4 w-4 text-success" />
						</div>
					</div>
					<div className="links-page-stat-value">
						{formatCount(activeLinks)}
					</div>
					<div className="links-page-stat-label">
						{__("Active Links")}
					</div>
				</div>
			</div>

			<UrlShorteningForm />

			<LinksTable
				links={paginatedLinks}
				statsShortId={statsShortId}
				statsLink={statsLink}
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
