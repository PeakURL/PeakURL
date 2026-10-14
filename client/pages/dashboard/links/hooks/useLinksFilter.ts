import { useEffect, useMemo, useRef, useState } from "react";
import { useSearchParams } from "react-router";

import { DEFAULT_PAGE_SIZE_OPTIONS, normalizePageSize } from "@/components";
import type { GetUrlsQueryArgs } from "@/state/slices/api";

import type {
	LinksCustomDateRange,
	LinksDateRange,
	LinksSortBy,
	LinksSortOrder,
	LinksStatusFilter,
} from "../types";

const LS_KEYS = {
	sortBy: "peakurl_admin_links_sortBy",
	sortOrder: "peakurl_admin_links_sortOrder",
	limit: "peakurl_admin_links_limit",
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

export const VALID_SORT_OPTIONS: LinksSortBy[] = [
	"createdAt",
	"updatedAt",
	"clicks",
	"uniqueClicks",
	"alias",
	"title",
	"health",
];

export function validateStoredSortBy(stored: string | null): LinksSortBy {
	if (!stored || !VALID_SORT_OPTIONS.includes(stored as LinksSortBy)) {
		return "createdAt";
	}
	return stored as LinksSortBy;
}

export function resolveEffectiveSortBy(
	sortBy: LinksSortBy,
	isAdmin: boolean
): LinksSortBy {
	const isClickSort = sortBy === "clicks" || sortBy === "uniqueClicks";
	return !isAdmin && isClickSort ? "createdAt" : sortBy;
}

export function getSafeSortByTarget(
	newSortBy: LinksSortBy,
	isAdmin: boolean
): LinksSortBy {
	return !isAdmin && (newSortBy === "clicks" || newSortBy === "uniqueClicks")
		? "createdAt"
		: newSortBy;
}

export function shouldSyncSortStorage(
	currentSort: LinksSortBy,
	isAdmin: boolean,
	isAuthLoading: boolean
): boolean {
	if (isAuthLoading) return false;
	if (isAdmin) return false;
	return currentSort === "clicks" || currentSort === "uniqueClicks";
}

/**
 * Hook to manage LinksPage filter, search, sort, and pagination state.
 */
export function useLinksFilter({
	isAdmin = false,
	isAuthLoading = false,
}: {
	isAdmin?: boolean;
	isAuthLoading?: boolean;
} = {}) {
	const [sortBy, setSortByState] = useState<LinksSortBy>(() => {
		if (typeof window === "undefined") {
			return "createdAt";
		}
		return validateStoredSortBy(localStorage.getItem(LS_KEYS.sortBy));
	});

	const [sortOrder, setSortOrderState] = useState<LinksSortOrder>(() =>
		typeof window !== "undefined"
			? (localStorage.getItem(LS_KEYS.sortOrder) as LinksSortOrder) ||
				"desc"
			: "desc"
	);

	const [statusFilter, setStatusFilterState] =
		useState<LinksStatusFilter>("all");

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
	const [clickRange, setClickRangeState] = useState<LinksDateRange>("all");
	const [customClickRange, setCustomClickRangeState] =
		useState<LinksCustomDateRange>(() => getDefaultCustomClickRange());

	const [searchParams, setSearchParams] = useSearchParams();
	const statsShortId = searchParams.get("stats");
	const urlSearchParam = searchParams.get("search") || "";
	const [searchQuery, setSearchQueryState] = useState(urlSearchParam);
	const [debouncedSearch, setDebouncedSearch] = useState(urlSearchParam);
	const lastWrittenParamRef = useRef(urlSearchParam);

	// Synchronize when URL search param changes externally (e.g. browser back/forward)
	useEffect(() => {
		const currentParam = searchParams.get("search") || "";
		if (currentParam !== lastWrittenParamRef.current) {
			lastWrittenParamRef.current = currentParam;
			setSearchQueryState(currentParam);
			setDebouncedSearch(currentParam);
			setCurrentPage(1);
		}
	}, [searchParams]);

	// Debounce syncing to URL search params to avoid excessive requests / history spam while typing
	useEffect(() => {
		const timer = setTimeout(() => {
			const trimmed = searchQuery.trim();
			setDebouncedSearch(trimmed);
			const currentParam = searchParams.get("search") || "";
			if (trimmed !== currentParam) {
				lastWrittenParamRef.current = trimmed;
				const updatedParams = new URLSearchParams(searchParams);
				if (trimmed) {
					updatedParams.set("search", trimmed);
				} else {
					updatedParams.delete("search");
				}
				setSearchParams(updatedParams, { replace: true });
			}
		}, 300);

		return () => clearTimeout(timer);
	}, [searchQuery, searchParams, setSearchParams]);

	const setSearchQuery = (newQuery: string) => {
		setSearchQueryState(newQuery);
		setCurrentPage(1);
		if (!newQuery.trim()) {
			setDebouncedSearch("");
			const currentParam = searchParams.get("search") || "";
			if (currentParam) {
				lastWrittenParamRef.current = "";
				const updatedParams = new URLSearchParams(searchParams);
				updatedParams.delete("search");
				setSearchParams(updatedParams, { replace: true });
			}
		}
	};

	const effectiveSortBy = resolveEffectiveSortBy(sortBy, isAdmin);

	useEffect(() => {
		if (shouldSyncSortStorage(sortBy, isAdmin, isAuthLoading)) {
			if (typeof window !== "undefined") {
				localStorage.setItem(LS_KEYS.sortBy, "createdAt");
			}
		}
	}, [isAdmin, isAuthLoading, sortBy]);

	const setSortBy = (newSortBy: LinksSortBy) => {
		const targetSort = getSafeSortByTarget(newSortBy, isAdmin);
		setSortByState(targetSort);
		setCurrentPage(1);
		if (typeof window !== "undefined") {
			localStorage.setItem(LS_KEYS.sortBy, targetSort);
		}
	};

	const setSortOrder = (newSortOrder: LinksSortOrder) => {
		setSortOrderState(newSortOrder);
		setCurrentPage(1);
		if (typeof window !== "undefined") {
			localStorage.setItem(LS_KEYS.sortOrder, newSortOrder);
		}
	};

	const setLimit = (newLimit: number) => {
		setLimitState(newLimit);
		setCurrentPage(1);
		if (typeof window !== "undefined") {
			localStorage.setItem(LS_KEYS.limit, String(newLimit));
		}
	};

	const setStatusFilter = (newStatus: LinksStatusFilter) => {
		setStatusFilterState(newStatus);
		setCurrentPage(1);
	};

	const setClickRange = (newRange: LinksDateRange) => {
		setClickRangeState(newRange);
		setCurrentPage(1);
	};

	const setCustomClickRange = (
		newRange:
			| LinksCustomDateRange
			| ((prev: LinksCustomDateRange) => LinksCustomDateRange)
	) => {
		setCustomClickRangeState(newRange);
		setCurrentPage(1);
	};

	const urlsQueryArgs = useMemo<GetUrlsQueryArgs>(() => {
		const baseQuery = {
			page: currentPage,
			limit,
			sortBy: effectiveSortBy,
			sortOrder,
			status: statusFilter,
			search: debouncedSearch,
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
		debouncedSearch,
		effectiveSortBy,
		sortOrder,
		statusFilter,
	]);

	return {
		sortBy: effectiveSortBy,
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
		setSearchQuery,
		statsShortId,
		urlsQueryArgs,
	};
}
