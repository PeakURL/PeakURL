import { useMemo, useState } from "react";
import { useSearchParams } from "react-router";

import { DEFAULT_PAGE_SIZE_OPTIONS, normalizePageSize } from "@/components";
import { useGetActivityHistoryQuery } from "@/state/slices/api";

import { normalizeActivityCategory } from "../lib";
import type {
	ActivityCategory,
	ActivityPaginationMeta,
	ActivitySummaryCounts,
	RecentActivity,
} from "../types";

const ACTIVITY_PAGE_STORAGE_KEY = "admin_activity_limit";

export function useActivityFilter() {
	const [searchParams, setSearchParams] = useSearchParams();
	const initialCategory = normalizeActivityCategory(
		searchParams.get("category")
	);

	const [category, setCategoryState] =
		useState<ActivityCategory>(initialCategory);
	const [currentPage, setCurrentPage] = useState<number>(1);

	const [limit, setLimitState] = useState<number>(() => {
		if (typeof window !== "undefined") {
			return normalizePageSize(
				localStorage.getItem(ACTIVITY_PAGE_STORAGE_KEY),
				DEFAULT_PAGE_SIZE_OPTIONS[0] ?? 25
			);
		}
		return DEFAULT_PAGE_SIZE_OPTIONS[0] ?? 25;
	});

	const setCategory = (newCategory: ActivityCategory) => {
		setCategoryState(newCategory);
		setCurrentPage(1);

		const updatedParams = new URLSearchParams(searchParams);
		if (newCategory === "all") {
			updatedParams.delete("category");
		} else {
			updatedParams.set("category", newCategory);
		}
		setSearchParams(updatedParams, { replace: true });
	};

	const setLimit = (newLimit: number) => {
		setLimitState(newLimit);
		setCurrentPage(1);
		if (typeof window !== "undefined") {
			localStorage.setItem(ACTIVITY_PAGE_STORAGE_KEY, String(newLimit));
		}
	};

	const queryArgs = useMemo(() => {
		return {
			page: currentPage,
			limit,
			category: category === "all" ? undefined : category,
		};
	}, [currentPage, limit, category]);

	const {
		data: response,
		isLoading,
		isFetching,
		refetch,
	} = useGetActivityHistoryQuery(queryArgs);

	const items: RecentActivity[] = useMemo(
		() => response?.data?.items ?? [],
		[response?.data?.items]
	);
	const meta: ActivityPaginationMeta = {
		page: response?.data?.meta?.page ?? currentPage,
		limit: response?.data?.meta?.limit ?? limit,
		totalItems: response?.data?.meta?.totalItems ?? items.length,
		totalPages: response?.data?.meta?.totalPages ?? 1,
	};

	const summaryCounts: ActivitySummaryCounts = useMemo(() => {
		const total = response?.data?.meta?.totalItems ?? 0;
		return {
			all: total,
			links: items.filter(
				(i) =>
					i.type?.startsWith("link_") ||
					i.type === "click" ||
					i.type === "trash_emptied"
			).length,
			users: items.filter((i) => i.type?.startsWith("user_")).length,
			latest: items[0] ?? null,
		};
	}, [items, response?.data?.meta?.totalItems]);

	return {
		category,
		setCategory,
		currentPage,
		setCurrentPage,
		limit,
		setLimit,
		items,
		meta,
		summaryCounts,
		isLoading,
		isFetching,
		refetch,
	};
}
