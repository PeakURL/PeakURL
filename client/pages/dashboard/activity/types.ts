import type {
	ActivityLink,
	ActivityLocation,
	ActivityPerson,
	LinksMeta,
	RecentActivity,
} from "@/api";

export type {
	ActivityLink,
	ActivityLocation,
	ActivityPerson,
	LinksMeta,
	RecentActivity,
};

export type ActivityCategory = "all" | "links" | "users";

export interface ActivityFilterState {
	category: ActivityCategory;
	page: number;
	limit: number;
}

export interface ActivityActionBadge {
	label: string;
	className: string;
}

export interface ActivityPaginationMeta {
	page: number;
	limit: number;
	totalItems: number;
	totalPages: number;
}

export interface ActivitySummaryCounts {
	all: number;
	links: number;
	users: number;
	latest: RecentActivity | null;
}
