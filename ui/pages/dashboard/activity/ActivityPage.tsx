import { useEffect, useState } from "react";
import {
	ChevronLeft,
	ChevronRight,
	Clock,
	Globe,
	History,
	Link2,
	MapPin,
	MousePointerClick,
	PencilLine,
	RefreshCw,
	RotateCcw,
	Shield,
	Trash2,
	User,
	UserMinus,
	UserPen,
	UserPlus,
	Users,
} from "lucide-react";
import { useSearchParams } from "react-router";

import {
	ConfirmDialog,
	DEFAULT_PAGE_SIZE_OPTIONS,
	PageSizeControl,
	Skeleton,
	normalizePageSize,
	useNotification,
} from "@/components";
import { useAdminAccess } from "@/hooks";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import {
	useBulkDeleteActivityLogsMutation,
	useClearActivityLogsMutation,
	useDeleteActivityLogMutation,
	useGetActivityHistoryQuery,
	useRestoreActivityLinkMutation,
} from "@/store/slices/api";
import {
	cn,
	formatCount,
	formatDate,
	formatLocalizedDateTime,
	getErrorMessage,
} from "@/utils";

import type { ActivityPerson, RecentActivity } from "../_components/types";

const ACTIVITY_PAGE_LIMIT = DEFAULT_PAGE_SIZE_OPTIONS[0] ?? 25;
const ACTIVITY_PAGE_STORAGE_KEY = "admin_activity_limit";
const ACTIVITY_PAGE_SIZE_OPTIONS = DEFAULT_PAGE_SIZE_OPTIONS;
const MAX_VISIBLE_PAGES = 5;

type ActivityCategory = "all" | "links" | "users";

const EMPTY_ACTIVITY_ITEMS: RecentActivity[] = [];

function normalizeActivityCategory(value: string | null): ActivityCategory {
	if ("links" === value || "users" === value) {
		return value;
	}

	return "all";
}

function getVisiblePages(currentPage: number, totalPages: number): number[] {
	const safeTotalPages = Math.max(1, totalPages);
	const safeCurrentPage = Math.min(Math.max(1, currentPage), safeTotalPages);
	const halfWindow = Math.floor(MAX_VISIBLE_PAGES / 2);
	const startPageInitial = Math.max(1, safeCurrentPage - halfWindow);
	const endPage = Math.min(
		safeTotalPages,
		startPageInitial + MAX_VISIBLE_PAGES - 1
	);
	const startPage = Math.max(1, endPage - MAX_VISIBLE_PAGES + 1);

	return Array.from(
		{ length: endPage - startPage + 1 },
		(_, index) => startPage + index
	);
}

function getActivityPersonName(person?: ActivityPerson | null): string | null {
	if (!person) {
		return null;
	}

	if (person.displayName) {
		return person.displayName;
	}

	const fullName = [person.firstName, person.lastName]
		.filter(Boolean)
		.join(" ")
		.trim();

	return fullName || person.username || person.email || null;
}

function getRoleLabel(role?: string | null): string {
	if ("admin" === role) {
		return __("Admin");
	}

	if ("editor" === role) {
		return __("Editor");
	}

	return __("User");
}

function getActivityLinkDisplayName(link?: RecentActivity["link"]): string {
	if (link?.title?.trim()) {
		return link.title.trim();
	}
	const slug = link?.alias || link?.shortCode;
	if (slug) {
		return slug.startsWith("/") ? slug : `/${slug}`;
	}
	return __("Unknown");
}

function getActivityMessage(activity: RecentActivity): string {
	const linkName = getActivityLinkDisplayName(activity.link);
	const userName = getActivityPersonName(activity.user) || __("Unknown user");

	switch (activity.type) {
		case "link_created":
			return sprintf(__('Created new link "%s"'), linkName);
		case "link_updated":
			return sprintf(__('Updated link "%s"'), linkName);
		case "link_deleted":
			return sprintf(__('Permanently deleted link "%s"'), linkName);
		case "link_trashed":
			return sprintf(__('Moved link "%s" to trash'), linkName);
		case "link_restored":
			return sprintf(__('Restored link "%s"'), linkName);
		case "trash_emptied": {
			const count = activity.count;
			if (typeof count === "number" && count > 0) {
				return count === 1
					? __("Permanently deleted 1 link from trash")
					: sprintf(
							__("Permanently deleted %s links from trash"),
							String(count)
						);
			}
			return activity.message || __("Emptied links from trash");
		}
		case "user_created":
			return sprintf(__('Created user "%s"'), userName);
		case "user_updated":
			return sprintf(__('Updated user "%s"'), userName);
		case "user_deleted":
			return sprintf(__('Deleted user "%s"'), userName);
		case "click": {
			const location = activity.location
				? sprintf(
						__("from %s"),
						activity.location.city ||
							activity.location.country ||
							__("Unknown")
					)
				: "";

			return location
				? sprintf(__('Link "%1$s" was clicked %2$s'), [
						linkName,
						location,
					])
				: sprintf(__('Link "%s" was clicked'), linkName);
		}
		default:
			return activity.message || __("Unknown activity");
	}
}

function getActivityVisual(type?: string | null): {
	icon: typeof Shield;
	tone: "neutral" | "success" | "info" | "danger" | "user";
} {
	switch (type) {
		case "link_created":
			return { icon: Link2, tone: "success" };
		case "link_updated":
			return { icon: PencilLine, tone: "info" };
		case "link_deleted":
			return { icon: Trash2, tone: "danger" };
		case "link_trashed":
			return { icon: Trash2, tone: "danger" };
		case "link_restored":
			return { icon: RotateCcw, tone: "success" };
		case "trash_emptied":
			return { icon: Trash2, tone: "danger" };
		case "user_created":
			return { icon: UserPlus, tone: "user" };
		case "user_updated":
			return { icon: UserPen, tone: "info" };
		case "user_deleted":
			return { icon: UserMinus, tone: "danger" };
		case "click":
			return { icon: MousePointerClick, tone: "info" };
		default:
			return { icon: Shield, tone: "neutral" };
	}
}

function formatExactTimestamp(timestamp?: string | null): string {
	if (!timestamp) {
		return "";
	}

	const date = new Date(timestamp);

	if (Number.isNaN(date.getTime())) {
		return "";
	}

	return formatLocalizedDateTime(date, {
		dateStyle: "medium",
		timeStyle: "medium",
	});
}

function ActivityPageSkeleton() {
	return (
		<div className="activity-page-panel">
			<div className="activity-page-panel-header">
				<div>
					<Skeleton className="activity-page-skeleton-heading" />
					<Skeleton className="activity-page-skeleton-copy" />
				</div>
			</div>
			<div className="activity-page-table">
				<div className="activity-page-table-scroll">
					<div className="activity-page-table-element">
						<div className="activity-page-events">
							{Array.from({ length: 6 }, (_, index) => (
								<div
									key={index}
									className="activity-page-event"
								>
									<div className="activity-page-event-identity">
										<Skeleton className="activity-page-skeleton-icon" />
									</div>
									<div className="activity-page-event-primary">
										<Skeleton className="activity-page-skeleton-title" />
									</div>
									<div className="activity-page-event-context">
										<div className="activity-page-skeleton-details">
											<Skeleton className="activity-page-skeleton-chip" />
											<Skeleton className="activity-page-skeleton-chip" />
										</div>
									</div>
									<div className="activity-page-event-time">
										<Skeleton className="activity-page-skeleton-time" />
										<Skeleton className="activity-page-skeleton-time-secondary" />
									</div>
								</div>
							))}
						</div>
					</div>
				</div>
			</div>
		</div>
	);
}

function ActivityPage() {
	const [limit, setLimit] = useState<number>(() => {
		if (typeof window !== "undefined") {
			return normalizePageSize(
				localStorage.getItem(ACTIVITY_PAGE_STORAGE_KEY),
				ACTIVITY_PAGE_LIMIT
			);
		}

		return ACTIVITY_PAGE_LIMIT;
	});
	const [currentPage, setCurrentPage] = useState(1);
	const [isRefreshing, setIsRefreshing] = useState(false);
	const [activityPendingDelete, setActivityPendingDelete] =
		useState<RecentActivity | null>(null);
	const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);
	const [clearAllOpen, setClearAllOpen] = useState(false);
	const [selectedActivityIds, setSelectedActivityIds] = useState<string[]>(
		[]
	);
	const [searchParams, setSearchParams] = useSearchParams();
	const { isAdmin } = useAdminAccess();
	const notifications = useNotification();
	const isRtl = isDocumentRtl();
	const PreviousIcon = isRtl ? ChevronRight : ChevronLeft;
	const NextIcon = isRtl ? ChevronLeft : ChevronRight;
	const currentCategory = normalizeActivityCategory(
		searchParams.get("category")
	);

	const activityHistoryArgs = {
		page: currentPage,
		limit,
		category: currentCategory,
	} as const;
	const {
		data: historyRes,
		refetch,
		isFetching,
		isLoading,
	} = useGetActivityHistoryQuery(activityHistoryArgs);
	const {
		data: allSummaryRes,
		refetch: refetchAllSummary,
		isFetching: isAllSummaryFetching,
	} = useGetActivityHistoryQuery({
		page: 1,
		limit: 1,
		category: "all",
	});
	const {
		data: linksSummaryRes,
		refetch: refetchLinksSummary,
		isFetching: isLinksSummaryFetching,
	} = useGetActivityHistoryQuery({
		page: 1,
		limit: 1,
		category: "links",
	});
	const {
		data: usersSummaryRes,
		refetch: refetchUsersSummary,
		isFetching: isUsersSummaryFetching,
	} = useGetActivityHistoryQuery({
		page: 1,
		limit: 1,
		category: "users",
	});
	const [deleteActivityLog, { isLoading: isDeletingActivity }] =
		useDeleteActivityLogMutation();
	const [bulkDeleteActivityLogs, { isLoading: isBulkDeletingActivities }] =
		useBulkDeleteActivityLogsMutation();
	const [clearActivityLogs, { isLoading: isClearingAllActivities }] =
		useClearActivityLogsMutation();
	const [restoreActivityLink, { isLoading: isRestoringLink }] =
		useRestoreActivityLinkMutation();

	const handleRestoreActivityLink = async (activity: RecentActivity) => {
		if (!activity.id) {
			return;
		}

		if (activity.linkStatus === "active") {
			notifications.info(
				__("Link already active"),
				__(
					"This link is already active and does not need to be restored."
				)
			);
			return;
		}

		if (
			activity.linkStatus === "deleted" ||
			activity.isRestorable === false
		) {
			notifications.info(
				__("Link permanently deleted"),
				__("This link was permanently deleted and cannot be restored.")
			);
			return;
		}

		try {
			await restoreActivityLink(activity.id).unwrap();
			notifications.success(
				__("Link restored"),
				__("The link has been restored successfully.")
			);
		} catch (err) {
			notifications.error(
				__("Unable to restore link"),
				getErrorMessage(
					err,
					__("Failed to restore link from activity record.")
				)
			);
		}
	};

	const items = historyRes?.data?.items ?? EMPTY_ACTIVITY_ITEMS;
	const meta = {
		page: historyRes?.data?.meta?.page ?? currentPage,
		limit: historyRes?.data?.meta?.limit ?? limit,
		totalItems: historyRes?.data?.meta?.totalItems ?? items.length,
		totalPages: historyRes?.data?.meta?.totalPages ?? 1,
	};
	const metaTotalPages = historyRes?.data?.meta?.totalPages ?? null;
	const totalItems = meta.totalItems;
	const totalPages = meta.totalPages;
	const selectedCount = selectedActivityIds.length;
	const hasSelection = selectedCount > 0;
	const startItem = totalItems > 0 ? (meta.page - 1) * meta.limit + 1 : 0;
	const endItem =
		totalItems > 0 ? Math.min(meta.page * meta.limit, totalItems) : 0;
	const visiblePages = getVisiblePages(meta.page, totalPages);
	const allEventsCount = allSummaryRes?.data?.meta?.totalItems ?? totalItems;
	const linkEventsCount = linksSummaryRes?.data?.meta?.totalItems ?? 0;
	const userEventsCount = usersSummaryRes?.data?.meta?.totalItems ?? 0;
	const mostRecentTimestamp = items[0]?.timestamp;
	const isBusy =
		isRefreshing ||
		isFetching ||
		isAllSummaryFetching ||
		isLinksSummaryFetching ||
		isUsersSummaryFetching ||
		isClearingAllActivities;

	useEffect(() => {
		try {
			localStorage.setItem(ACTIVITY_PAGE_STORAGE_KEY, String(limit));
		} catch {}
	}, [limit]);

	const filterKey = `${currentCategory}:${limit}`;
	const [prevFilterKey, setPrevFilterKey] = useState(filterKey);
	if (prevFilterKey !== filterKey) {
		setPrevFilterKey(filterKey);
		setCurrentPage(1);
	}

	const [prevTotalPages, setPrevTotalPages] = useState(metaTotalPages);
	if (metaTotalPages && metaTotalPages !== prevTotalPages) {
		setPrevTotalPages(metaTotalPages);
		if (currentPage > metaTotalPages) {
			setCurrentPage(metaTotalPages);
		}
	}

	const [prevItems, setPrevItems] = useState(items);
	if (prevItems !== items) {
		setPrevItems(items);
		const currentIds = new Set(
			items
				.map((item) => item.id)
				.filter((id): id is string => Boolean(id))
		);

		setSelectedActivityIds((previous) => {
			const next = previous.filter((id) => currentIds.has(id));
			if (next.length === 0 && bulkDeleteOpen) {
				setBulkDeleteOpen(false);
			}
			return next;
		});
	}

	const handleRefresh = async () => {
		if (isRefreshing) {
			return;
		}

		setIsRefreshing(true);
		const startedAt = Date.now();

		try {
			await Promise.allSettled([
				refetch(),
				refetchAllSummary(),
				refetchLinksSummary(),
				refetchUsersSummary(),
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

	const handlePageChange = (page: number) => {
		setCurrentPage(Math.min(Math.max(page, 1), totalPages));
	};

	const pageSelectableIds = items
		.map((item) => item.id)
		.filter((id): id is string => Boolean(id));
	const selectedPageCount = pageSelectableIds.filter((id) =>
		selectedActivityIds.includes(id)
	).length;
	const isAllPageSelected =
		pageSelectableIds.length > 0 &&
		selectedPageCount === pageSelectableIds.length;
	const isAllPageIndeterminate = selectedPageCount > 0 && !isAllPageSelected;

	const handleToggleSelectAllPage = () => {
		if (pageSelectableIds.length === 0) {
			return;
		}

		if (isAllPageSelected) {
			setSelectedActivityIds((previous) =>
				previous.filter((id) => !pageSelectableIds.includes(id))
			);
		} else {
			setSelectedActivityIds((previous) =>
				Array.from(new Set([...previous, ...pageSelectableIds]))
			);
		}
	};

	const handleToggleSelectActivity = (activityId: string) => {
		setSelectedActivityIds((previous) =>
			previous.includes(activityId)
				? previous.filter((id) => id !== activityId)
				: [...previous, activityId]
		);
	};

	const handleDeleteActivity = async () => {
		const target = activityPendingDelete;
		if (!target?.id || isDeletingActivity) {
			return;
		}

		setActivityPendingDelete(null);
		setSelectedActivityIds((previous) =>
			previous.filter((id) => id !== target.id)
		);

		try {
			await deleteActivityLog(target.id).unwrap();
			notifications.success(
				__("Activity deleted"),
				__("The activity log entry has been removed.")
			);
		} catch (error) {
			notifications.error(
				__("Unable to delete activity"),
				getErrorMessage(
					error,
					__("The activity log entry could not be removed.")
				)
			);
		}
	};

	const handleBulkDeleteActivities = async () => {
		if (!hasSelection || isBulkDeletingActivities) {
			return;
		}

		const idsToDelete = [...selectedActivityIds];
		const countToDelete = idsToDelete.length;

		setBulkDeleteOpen(false);
		setSelectedActivityIds([]);

		try {
			await bulkDeleteActivityLogs(idsToDelete).unwrap();
			notifications.success(
				__("Activity deleted"),
				sprintf(
					__("Deleted %s activity log entries."),
					String(countToDelete)
				)
			);
		} catch (error) {
			notifications.error(
				__("Unable to delete activity"),
				getErrorMessage(
					error,
					__(
						"The selected activity log entries could not be removed."
					)
				)
			);
		}
	};

	const handleClearAllActivities = async () => {
		if (isClearingAllActivities) {
			return;
		}

		setClearAllOpen(false);
		setSelectedActivityIds([]);

		try {
			await clearActivityLogs().unwrap();
			notifications.success(
				__("Activity deleted"),
				__("All activity log entries have been removed.")
			);
		} catch (error) {
			notifications.error(
				__("Unable to delete activity"),
				getErrorMessage(
					error,
					__("The activity logs could not be removed.")
				)
			);
		}
	};

	const handleCategoryChange = (nextCategory: ActivityCategory) => {
		const nextSearchParams = new URLSearchParams(searchParams);

		if ("all" === nextCategory) {
			nextSearchParams.delete("category");
		} else {
			nextSearchParams.set("category", nextCategory);
		}

		setSearchParams(nextSearchParams, { replace: true });
	};

	const categoryOptions: Array<{
		value: ActivityCategory;
		label: string;
		count: number;
		icon: typeof History;
	}> = [
		{
			value: "all",
			label: __("All"),
			count: allEventsCount,
			icon: History,
		},
		{
			value: "links",
			label: __("Links"),
			count: linkEventsCount,
			icon: Link2,
		},
		{
			value: "users",
			label: __("Users"),
			count: userEventsCount,
			icon: Users,
		},
	];
	const overviewItems: Array<{
		key: string;
		label: string;
		value: string;
		note?: string | null;
		icon: typeof History;
	}> = [
		{
			key: "all",
			label: __("Total events"),
			value: formatCount(allEventsCount),
			icon: History,
		},
		{
			key: "links",
			label: __("Link events"),
			value: formatCount(linkEventsCount),
			icon: Link2,
		},
		{
			key: "users",
			label: __("User events"),
			value: formatCount(userEventsCount),
			icon: Users,
		},
		{
			key: "latest",
			label: __("Latest event"),
			value: mostRecentTimestamp
				? formatDate(mostRecentTimestamp)
				: __("No recent events"),
			note: mostRecentTimestamp
				? formatExactTimestamp(mostRecentTimestamp)
				: null,
			icon: Clock,
		},
	];

	return (
		<div className="activity-page">
			<div className="activity-page-hero">
				<div className="activity-page-hero-copy">
					<p className="activity-page-hero-badge">
						<Shield size={14} />
						<span>{__("Audit Log")}</span>
					</p>
					<h1 className="activity-page-title">{__("Activity")}</h1>
					<p className="activity-page-summary">
						{__(
							"Review link changes and user-management events in one place with filters, timestamps, and actor details."
						)}
					</p>
				</div>
				<button
					type="button"
					onClick={handleRefresh}
					disabled={isBusy}
					className="dashboard-page-refresh mt-1 shrink-0"
					aria-label={__("Refresh activity history")}
					title={__("Refresh activity history")}
				>
					<RefreshCw
						className={cn(
							"dashboard-page-refresh-icon",
							isBusy && "animate-spin"
						)}
					/>
				</button>
			</div>

			<div className="activity-page-overview">
				<div className="activity-page-overview-grid">
					{overviewItems.map((item) => {
						const Icon = item.icon;
						const isLatest = "latest" === item.key;

						return (
							<div
								key={item.key}
								className="activity-page-overview-item"
							>
								<div className="activity-page-overview-header">
									<div className="activity-page-overview-copy">
										<p className="activity-page-overview-title">
											{item.label}
										</p>
										<p
											className={cn(
												"activity-page-overview-value",
												isLatest &&
													"activity-page-overview-value-latest"
											)}
											dir="auto"
										>
											{item.value}
										</p>
									</div>
									<div
										className={cn(
											"activity-page-overview-icon",
											`activity-page-overview-icon-${item.key}`
										)}
									>
										<Icon className="activity-page-overview-icon-glyph" />
									</div>
								</div>
								{item.note ? (
									<p
										className="activity-page-overview-note"
										dir="auto"
									>
										{item.note}
									</p>
								) : null}
							</div>
						);
					})}
				</div>
			</div>

			<div className="activity-page-toolbar">
				<div className="activity-page-filters">
					{categoryOptions.map((option) => {
						const Icon = option.icon;

						return (
							<button
								key={option.value}
								type="button"
								onClick={() =>
									handleCategoryChange(option.value)
								}
								className={cn(
									"activity-page-filter",
									option.value === currentCategory &&
										"activity-page-filter-active"
								)}
							>
								<Icon size={14} className="shrink-0" />
								<span>{option.label}</span>
								<span className="activity-page-filter-count">
									{formatCount(option.count)}
								</span>
							</button>
						);
					})}
				</div>

				<PageSizeControl
					value={limit}
					onChange={setLimit}
					options={ACTIVITY_PAGE_SIZE_OPTIONS}
					className="activity-page-page-size"
					ariaLabel={__("Rows per page")}
				/>
			</div>

			{isLoading ? (
				<ActivityPageSkeleton />
			) : (
				<div className="activity-page-panel">
					<div className="activity-page-panel-header">
						<div>
							<h2 className="activity-page-panel-title">
								{__("Activity history")}
							</h2>
						</div>
						{isAdmin && hasSelection ? (
							<div className="activity-page-panel-actions">
								<span className="activity-page-panel-selection-count">
									{sprintf(
										__("%s selected"),
										String(selectedCount)
									)}
								</span>
								<button
									type="button"
									onClick={() => setBulkDeleteOpen(true)}
									className="activity-page-selection-delete"
								>
									<Trash2 size={13} />
									<span>{__("Delete selected")}</span>
								</button>
								<button
									type="button"
									onClick={() => setClearAllOpen(true)}
									className="activity-page-selection-delete-all"
								>
									<Trash2 size={13} />
									<span>{__("Delete all")}</span>
								</button>
							</div>
						) : null}
					</div>
					<div className="activity-page-table">
						{items.length > 0 ? (
							<div className="activity-page-table-scroll">
								<div className="activity-page-table-element">
									<div
										className={cn(
											"activity-page-table-head",
											isAdmin &&
												"activity-page-table-head-admin"
										)}
									>
										{isAdmin &&
										pageSelectableIds.length > 0 ? (
											<input
												type="checkbox"
												checked={isAllPageSelected}
												onChange={
													handleToggleSelectAllPage
												}
												ref={(node) => {
													if (node) {
														node.indeterminate =
															isAllPageIndeterminate;
													}
												}}
												className="links-checkbox"
												aria-label={__(
													"Select all events on this page"
												)}
											/>
										) : (
											<span aria-hidden="true"></span>
										)}
										<span>{__("Event")}</span>
										<span>{__("Details")}</span>
										<span>{__("When")}</span>
										{isAdmin ? (
											<span className="activity-page-table-head-actions">
												{__("Actions")}
											</span>
										) : null}
									</div>

									<div className="activity-page-events">
										{items.map((activity, index) => {
											const visual = getActivityVisual(
												activity.type
											);
											const Icon = visual.icon;
											const actorName =
												getActivityPersonName(
													activity.actor
												);
											const userName =
												getActivityPersonName(
													activity.user
												);
											const locationName =
												activity.location
													? activity.location.city ||
														activity.location
															.country ||
														null
													: null;
											const exactTime =
												formatExactTimestamp(
													activity.timestamp
												);
											const destinationUrl =
												activity.link?.destinationUrl;
											const hasTargetUser = Boolean(
												userName &&
												userName !== actorName
											);

											return (
												<article
													key={
														activity.id ||
														`activity-${index}`
													}
													className={cn(
														"activity-page-event",
														activity.id &&
															selectedActivityIds.includes(
																activity.id
															) &&
															"activity-page-event-selected",
														isAdmin &&
															"activity-page-event-admin"
													)}
												>
													<div className="activity-page-event-identity">
														{isAdmin &&
														activity.id ? (
															<input
																type="checkbox"
																checked={selectedActivityIds.includes(
																	activity.id
																)}
																onChange={() =>
																	handleToggleSelectActivity(
																		activity.id as string
																	)
																}
																className="links-checkbox"
															/>
														) : null}
														<div
															className={cn(
																"activity-page-event-icon",
																`activity-page-event-icon-${visual.tone}`
															)}
														>
															<Icon size={17} />
														</div>
													</div>

													<div className="activity-page-event-primary">
														<p
															className="activity-page-event-title"
															dir="auto"
														>
															{getActivityMessage(
																activity
															)}
														</p>
														{activity.user?.role &&
														"users" ===
															currentCategory ? (
															<span className="activity-page-event-role-badge">
																{getRoleLabel(
																	activity
																		.user
																		.role
																)}
															</span>
														) : null}
													</div>

													<div className="activity-page-event-context">
														{actorName ? (
															<div
																className="activity-page-detail-item"
																title={sprintf(
																	__(
																		"Actor: %s"
																	),
																	actorName
																)}
															>
																<User
																	size={13}
																	className="text-text-muted/70 shrink-0"
																/>
																<span className="activity-page-detail-actor">
																	<span className="text-text-muted/70 font-normal">
																		{__(
																			"By"
																		)}{" "}
																	</span>
																	<span className="font-medium text-heading">
																		{
																			actorName
																		}
																	</span>
																</span>
															</div>
														) : null}
														{destinationUrl ? (
															<div
																className="activity-page-detail-item activity-page-detail-destination"
																title={
																	destinationUrl
																}
															>
																<Globe
																	size={13}
																	className="text-text-muted/70 shrink-0"
																/>
																<span
																	className="activity-page-detail-destination-url truncate max-w-xs"
																	dir="ltr"
																>
																	{
																		destinationUrl
																	}
																</span>
															</div>
														) : null}
														{hasTargetUser &&
														userName ? (
															<div
																className="activity-page-detail-item"
																title={sprintf(
																	__(
																		"User: %s"
																	),
																	userName
																)}
															>
																<User
																	size={13}
																	className="text-text-muted/70 shrink-0"
																/>
																<span className="activity-page-detail-user font-medium text-heading">
																	{userName}
																</span>
															</div>
														) : null}
														{locationName ? (
															<div
																className="activity-page-detail-item"
																title={sprintf(
																	__(
																		"Location: %s"
																	),
																	locationName
																)}
															>
																<MapPin
																	size={13}
																	className="text-text-muted/70 shrink-0"
																/>
																<span className="activity-page-detail-location">
																	{
																		locationName
																	}
																</span>
															</div>
														) : null}
														{!actorName &&
														!destinationUrl &&
														!hasTargetUser &&
														!locationName ? (
															<span className="activity-page-event-detail-empty">
																—
															</span>
														) : null}
													</div>

													<div className="activity-page-event-time">
														<p
															className="activity-page-event-time-relative"
															dir="auto"
														>
															{formatDate(
																activity.timestamp
															)}
														</p>
														{exactTime ? (
															<p
																className="activity-page-event-time-exact"
																dir="auto"
															>
																{exactTime}
															</p>
														) : null}
													</div>
													{isAdmin ? (
														<div className="activity-page-event-actions">
															{activity.id &&
															activity.link
																?.destinationUrl &&
															("link_deleted" ===
																activity.type ||
																"link_trashed" ===
																	activity.type)
																? (() => {
																		const isRestorable =
																			activity.isRestorable ===
																				true &&
																			activity.linkStatus ===
																				"trashed";
																		const isAlreadyActive =
																			activity.linkStatus ===
																			"active";
																		const isPermanentlyDeleted =
																			activity.linkStatus ===
																				"deleted" ||
																			activity.type ===
																				"link_deleted";

																		if (
																			isPermanentlyDeleted &&
																			!isRestorable
																		) {
																			return null;
																		}

																		return (
																			<button
																				type="button"
																				onClick={() =>
																					handleRestoreActivityLink(
																						activity
																					)
																				}
																				disabled={
																					!isRestorable ||
																					isRestoringLink
																				}
																				className={cn(
																					"activity-page-event-action activity-page-event-action-restore",
																					!isRestorable &&
																						"pointer-events-none cursor-not-allowed opacity-30"
																				)}
																				aria-label={
																					isAlreadyActive
																						? __(
																								"Link is already active"
																							)
																						: __(
																								"Restore link"
																							)
																				}
																				title={
																					isAlreadyActive
																						? __(
																								"Link is already active"
																							)
																						: __(
																								"Restore link"
																							)
																				}
																			>
																				<RotateCcw
																					size={
																						14
																					}
																				/>
																			</button>
																		);
																	})()
																: null}
															{activity.id ? (
																<button
																	type="button"
																	onClick={() =>
																		setActivityPendingDelete(
																			activity
																		)
																	}
																	className="activity-page-event-action activity-page-event-action-delete"
																	aria-label={__(
																		"Delete activity log"
																	)}
																	title={__(
																		"Delete activity log"
																	)}
																>
																	<Trash2
																		size={
																			14
																		}
																	/>
																</button>
															) : null}
														</div>
													) : null}
												</article>
											);
										})}
									</div>
								</div>
							</div>
						) : (
							<div className="activity-page-empty">
								<div className="activity-page-empty-icon">
									<Shield size={24} />
								</div>
								<h3 className="activity-page-empty-title">
									{"users" === currentCategory
										? __("No user activity yet")
										: "links" === currentCategory
											? __("No link activity yet")
											: __("No activity recorded yet")}
								</h3>
								<p className="activity-page-empty-summary">
									{__(
										"Once users manage accounts or links change, the audit log will appear here."
									)}
								</p>
							</div>
						)}
					</div>
				</div>
			)}

			{totalItems > 0 ? (
				<div className="activity-page-pagination">
					<div className="activity-page-pagination-summary-group">
						<p className="activity-page-pagination-summary">
							{sprintf(__("Showing %1$s-%2$s of %3$s events"), [
								formatCount(startItem),
								formatCount(endItem),
								formatCount(totalItems),
							])}
						</p>
						<p className="activity-page-pagination-page-note">
							{sprintf(__("Page %1$s of %2$s"), [
								String(meta.page),
								String(totalPages),
							])}
						</p>
					</div>

					{totalPages > 1 ? (
						<div className="activity-page-pagination-controls">
							<button
								type="button"
								onClick={() => handlePageChange(meta.page - 1)}
								disabled={meta.page === 1}
								className="activity-page-pagination-nav"
							>
								<PreviousIcon size={14} />
								{__("Previous")}
							</button>

							<div className="activity-page-pagination-pages">
								{visiblePages.map((page) => (
									<button
										key={page}
										type="button"
										onClick={() => handlePageChange(page)}
										className={cn(
											"activity-page-pagination-page",
											page === meta.page &&
												"activity-page-pagination-page-current"
										)}
									>
										{page}
									</button>
								))}
							</div>

							<button
								type="button"
								onClick={() => handlePageChange(meta.page + 1)}
								disabled={meta.page === totalPages}
								className="activity-page-pagination-nav"
							>
								{__("Next")}
								<NextIcon size={14} />
							</button>
						</div>
					) : null}
				</div>
			) : null}

			<ConfirmDialog
				open={bulkDeleteOpen && selectedActivityIds.length > 0}
				onClose={() => setBulkDeleteOpen(false)}
				title={__("Delete activity logs")}
				description={
					hasSelection
						? sprintf(
								__(
									"Delete %s selected activity log entries? This action cannot be undone."
								),
								String(selectedCount)
							)
						: ""
				}
				confirmText={__("Delete selected")}
				confirmVariant="danger"
				onConfirm={handleBulkDeleteActivities}
				loading={isBulkDeletingActivities}
			/>
			<ConfirmDialog
				open={clearAllOpen}
				onClose={() => setClearAllOpen(false)}
				title={__("Delete all activity")}
				description={__(
					"Are you sure you want to delete all activity logs? This action cannot be undone."
				)}
				confirmText={__("Delete all activity")}
				confirmVariant="danger"
				onConfirm={handleClearAllActivities}
				loading={isClearingAllActivities}
			/>
			<ConfirmDialog
				open={Boolean(activityPendingDelete)}
				onClose={() => setActivityPendingDelete(null)}
				title={__("Delete activity log")}
				description={
					activityPendingDelete
						? sprintf(
								__(
									'Delete the activity entry "%s"? This action cannot be undone.'
								),
								getActivityMessage(activityPendingDelete)
							)
						: ""
				}
				confirmText={__("Delete activity")}
				confirmVariant="danger"
				onConfirm={handleDeleteActivity}
				loading={isDeletingActivity}
			/>
		</div>
	);
}

export default ActivityPage;
