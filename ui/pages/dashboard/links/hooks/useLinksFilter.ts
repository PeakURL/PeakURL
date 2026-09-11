import { useMemo, useState } from "react";
import { useSearchParams } from "react-router";

import { DEFAULT_PAGE_SIZE_OPTIONS, normalizePageSize } from "@/components";
import type { GetUrlsQueryArgs } from "@/store/slices/api";

import type {
	LinksCustomDateRange,
	LinksDateRange,
	LinksSortBy,
	LinksSortOrder,
	LinksStatusFilter,
} from "../types";

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

export function getDefaultCustomClickRange(): LinksCustomDateRange {
	const today = new Date();
	const weekStart = new Date(today.getTime() - 6 * DATE_RANGE_DAY_MS);

	return {
		from: formatDateInput(weekStart),
		to: formatDateInput(today),
	};
}

/**
 * Hook to manage LinksPage filter, search, sort, and pagination state.
 */
export function useLinksFilter() {
	const [sortBy, setSortByState] = useState<LinksSortBy>(() => {
		if (typeof window === "undefined") {
			return "createdAt";
		}
		const stored = localStorage.getItem(
			LS_KEYS.sortBy
		) as LinksSortBy | null;
		const validSortOptions: LinksSortBy[] = [
			"createdAt",
			"updatedAt",
			"clicks",
			"uniqueClicks",
			"alias",
			"title",
		];
		return stored && validSortOptions.includes(stored)
			? stored
			: "createdAt";
	});

	const [sortOrder, setSortOrderState] = useState<LinksSortOrder>(() =>
		typeof window !== "undefined"
			? (localStorage.getItem(LS_KEYS.sortOrder) as LinksSortOrder) ||
				"desc"
			: "desc"
	);

	const [statusFilter, setStatusFilter] = useState<LinksStatusFilter>("all");
	const isTrashTab = "trashed" === statusFilter;

	const [limit, setLimitState] = useState<number>(() => {
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

	const setSortBy = (newSortBy: LinksSortBy) => {
		setSortByState(newSortBy);
		if (typeof window !== "undefined") {
			localStorage.setItem(LS_KEYS.sortBy, newSortBy);
		}
	};

	const setSortOrder = (newSortOrder: LinksSortOrder) => {
		setSortOrderState(newSortOrder);
		if (typeof window !== "undefined") {
			localStorage.setItem(LS_KEYS.sortOrder, newSortOrder);
		}
	};

	const setLimit = (newLimit: number) => {
		setLimitState(newLimit);
		if (typeof window !== "undefined") {
			localStorage.setItem(LS_KEYS.limit, String(newLimit));
		}
	};

	// Reset to page 1 during render when query, pagination, status, or date filters change
	const filterKey = `${statusFilter}:${searchQuery}:${limit}:${sortBy}:${sortOrder}:${clickRange}:${customClickRange.from}:${customClickRange.to}`;
	const [prevFilterKey, setPrevFilterKey] = useState(filterKey);
	if (prevFilterKey !== filterKey) {
		setPrevFilterKey(filterKey);
		setCurrentPage(1);
	}

	const urlsQueryArgs = useMemo<GetUrlsQueryArgs>(() => {
		const baseQuery = {
			page: currentPage,
			limit,
			sortBy,
			sortOrder,
			status: statusFilter,
			search: searchQuery,
		};

		if ("custom" === clickRange) {
			return {
				...baseQuery,
				range: "custom" as const,
				from: customClickRange.from,
				to: customClickRange.to,
			};
		}

		if ("all" === clickRange) {
			return baseQuery;
		}

		return {
			...baseQuery,
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
		statusFilter,
	]);

	return {
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
	};
}
