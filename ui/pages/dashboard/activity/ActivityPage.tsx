import { useEffect, useState } from "react";
import {
	ChevronLeft,
	ChevronRight,
	History,
	Link2,
	MousePointerClick,
	PencilLine,
	RefreshCw,
	Shield,
	Trash2,
	UserMinus,
	UserPen,
	UserPlus,
	Users,
} from "lucide-react";
import {
	ConfirmDialog,
	DEFAULT_PAGE_SIZE_MAX,
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
	useDeleteActivityLogMutation,
	useGetActivityHistoryQuery,
} from "@/store/slices/api";
import {
	cn,
	formatCount,
	formatDate,
	formatLocalizedDateTime,
	getZonedDateKey,
	getLinkDisplayTitle,
} from "@/utils";
import { useSearchParams } from "react-router-dom";
import type { ActivityPerson, RecentActivity } from "../_components/types";

const ACTIVITY_PAGE_LIMIT = DEFAULT_PAGE_SIZE_OPTIONS[0];
const ACTIVITY_PAGE_STORAGE_KEY = "admin_activity_limit";
const ACTIVITY_PAGE_SIZE_OPTIONS = DEFAULT_PAGE_SIZE_OPTIONS;
const ACTIVITY_PAGE_MAX_LIMIT = DEFAULT_PAGE_SIZE_MAX;
const MAX_VISIBLE_PAGES = 5;

type ActivityCategory = "all" | "links" | "users";

interface ActivityDayGroup {
	key: string;
	label: string;
	items: RecentActivity[];
}

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
	let startPage = Math.max(1, safeCurrentPage - halfWindow);
	let endPage = Math.min(safeTotalPages, startPage + MAX_VISIBLE_PAGES - 1);

	startPage = Math.max(1, endPage - MAX_VISIBLE_PAGES + 1);

	return Array.from(
		{ length: endPage - startPage + 1 },
		(_, index) => startPage + index
	);
}

function getActivityPersonName(person?: ActivityPerson | null): string | null {
	if (!person) {
		return null;
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

function getActivityTypeLabel(type?: string | null): string {
	switch (type) {
		case "link_created":
			return __("Link created");
		case "link_updated":
			return __("Link updated");
		case "link_deleted":
			return __("Link deleted");
		case "user_created":
			return __("User created");
		case "user_updated":
			return __("User updated");
		case "user_deleted":
			return __("User deleted");
		case "click":
			return __("Link click");
		default:
			return __("Activity");
	}
}

function getActivityMessage(activity: RecentActivity): string {
	const linkName = getLinkDisplayTitle(
		activity.link?.title,
		activity.link?.shortCode || __("Unknown")
	);
	const userName = getActivityPersonName(activity.user) || __("Unknown user");

	switch (activity.type) {
		case "link_created":
			return sprintf(__("Created new link %s"), linkName);
		case "link_updated":
			return sprintf(__("Updated link %s"), linkName);
		case "link_deleted":
			return sprintf(__("Deleted link %s"), linkName);
		case "user_created":
			return sprintf(__("Created user %s"), userName);
		case "user_updated":
			return sprintf(__("Updated user %s"), userName);
		case "user_deleted":
			return sprintf(__("Deleted user %s"), userName);
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
				? sprintf(__("Link %1$s was clicked %2$s"), [
						linkName,
						location,
					])
				: sprintf(__("Link %s was clicked"), linkName);
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

function getActivityDayGroupLabel(timestamp?: string | null): {
	key: string;
	label: string;
} {
	if (!timestamp) {
		return {
			key: "unknown",
			label: __("Unknown date"),
		};
	}

	const date = new Date(timestamp);

	if (Number.isNaN(date.getTime())) {
		return {
			key: "unknown",
			label: __("Unknown date"),
		};
	}

	const key = getZonedDateKey(date) || date.toISOString().slice(0, 10);
	const todayKey = getZonedDateKey(new Date());
	const yesterday = new Date();
	yesterday.setDate(yesterday.getDate() - 1);
	const yesterdayKey = getZonedDateKey(yesterday);

	if (key === todayKey) {
		return { key, label: __("Today") };
	}

	if (key === yesterdayKey) {
		return { key, label: __("Yesterday") };
	}

	return {
		key,
		label: formatLocalizedDateTime(date, {
			dateStyle: "full",
		}),
	};
}

function groupActivitiesByDay(
	activities: RecentActivity[]
): ActivityDayGroup[] {
	const groups = new Map<string, ActivityDayGroup>();

	activities.forEach((activity) => {
		const { key, label } = getActivityDayGroupLabel(activity.timestamp);
		const existingGroup = groups.get(key);

		if (existingGroup) {
			existingGroup.items.push(activity);
			return;
		}

		groups.set(key, {
			key,
			label,
			items: [activity],
		});
	});

	return Array.from(groups.values());
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
		timeStyle: "short",
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
			<div className="activity-page-day-header">
				<Skeleton className="activity-page-skeleton-day" />
			</div>
			<div className="activity-page-events">
				{Array.from({ length: 6 }, (_, index) => (
					<div key={index} className="activity-page-event">
						<Skeleton className="activity-page-skeleton-icon" />
						<div className="activity-page-skeleton-primary">
							<Skeleton className="activity-page-skeleton-title" />
							<div className="activity-page-skeleton-badges">
								<Skeleton className="activity-page-skeleton-badge" />
								<Skeleton className="activity-page-skeleton-badge" />
							</div>
						</div>
						<div className="activity-page-skeleton-context">
							<div className="activity-page-skeleton-details">
								<Skeleton className="activity-page-skeleton-chip" />
								<Skeleton className="activity-page-skeleton-chip" />
								<Skeleton className="activity-page-skeleton-chip" />
							</div>
						</div>
						<div className="activity-page-skeleton-time-group">
							<Skeleton className="activity-page-skeleton-time" />
							<Skeleton className="activity-page-skeleton-time-secondary" />
						</div>
					</div>
				))}
			</div>
		</div>
	);
}

function ActivityPage() {
	const [limit, setLimit] = useState(() => {
		if (typeof window !== "undefined") {
			return normalizePageSize(
				localStorage.getItem(ACTIVITY_PAGE_STORAGE_KEY),
				ACTIVITY_PAGE_LIMIT,
				ACTIVITY_PAGE_MAX_LIMIT
			);
		}

		return ACTIVITY_PAGE_LIMIT;
	});
	const [currentPage, setCurrentPage] = useState(1);
	const [isRefreshing, setIsRefreshing] = useState(false);
	const [activityPendingDelete, setActivityPendingDelete] =
		useState<RecentActivity | null>(null);
	const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);
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

	const items = historyRes?.data?.items ?? EMPTY_ACTIVITY_ITEMS;
	const meta = historyRes?.data?.meta ?? {
		page: currentPage,
		limit,
		totalItems: items.length,
		totalPages: 1,
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
	const dayGroups = groupActivitiesByDay(items);
	const allEventsCount = allSummaryRes?.data?.meta?.totalItems ?? totalItems;
	const linkEventsCount = linksSummaryRes?.data?.meta?.totalItems ?? 0;
	const userEventsCount = usersSummaryRes?.data?.meta?.totalItems ?? 0;
	const mostRecentTimestamp = items[0]?.timestamp;
	const isBusy =
		isRefreshing ||
		isFetching ||
		isAllSummaryFetching ||
		isLinksSummaryFetching ||
		isUsersSummaryFetching;

	useEffect(() => {
		try {
			localStorage.setItem(ACTIVITY_PAGE_STORAGE_KEY, String(limit));
		} catch {}
	}, [limit]);

	useEffect(() => {
		setCurrentPage(1);
	}, [currentCategory, limit]);

	useEffect(() => {
		if (metaTotalPages && currentPage > metaTotalPages) {
			setCurrentPage(metaTotalPages);
		}
	}, [currentPage, metaTotalPages]);

	useEffect(() => {
		const currentIds = new Set(
			items
				.map((item) => item.id)
				.filter((id): id is string => Boolean(id))
		);

		setSelectedActivityIds((previous) =>
			previous.filter((id) => currentIds.has(id))
		);
	}, [items]);

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

	const handleToggleSelectActivity = (activityId: string) => {
		setSelectedActivityIds((previous) =>
			previous.includes(activityId)
				? previous.filter((id) => id !== activityId)
				: [...previous, activityId]
		);
	};

	const handleToggleSelectGroup = (activityIds: string[]) => {
		if (activityIds.length === 0) {
			return;
		}

		setSelectedActivityIds((previous) => {
			const allGroupSelected = activityIds.every((activityId) =>
				previous.includes(activityId)
			);

			if (allGroupSelected) {
				return previous.filter(
					(activityId) => !activityIds.includes(activityId)
				);
			}

			return Array.from(new Set([...previous, ...activityIds]));
		});
	};

	const handleDeleteActivity = async () => {
		if (!activityPendingDelete?.id || isDeletingActivity) {
			return;
		}

		try {
			await deleteActivityLog(activityPendingDelete.id).unwrap();
			notifications.success(
				__("Activity deleted"),
				__("The activity log entry has been removed.")
			);
			setSelectedActivityIds((previous) =>
				previous.filter((id) => id !== activityPendingDelete.id)
			);
			setActivityPendingDelete(null);
		} catch (_error) {
			notifications.error(
				__("Unable to delete activity"),
				__("The activity log entry could not be removed.")
			);
		}
	};

	const handleBulkDeleteActivities = async () => {
		if (!hasSelection || isBulkDeletingActivities) {
			return;
		}

		try {
			await bulkDeleteActivityLogs(selectedActivityIds).unwrap();
			notifications.success(
				__("Activity deleted"),
				sprintf(
					__("Deleted %s activity log entries."),
					String(selectedCount)
				)
			);
			setSelectedActivityIds([]);
			setBulkDeleteOpen(false);
		} catch (_error) {
			notifications.error(
				__("Unable to delete activity"),
				__("The selected activity log entries could not be removed.")
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
			label: __("All activity"),
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
	];

	return (
		<div className="activity-page">
			<div className="activity-page-hero">
				<div className="activity-page-hero-copy">
					<p className="activity-page-hero-badge">
						<Shield size={14} />
						{__("Audit Log")}
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
					className="dashboard-page-refresh"
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
				<div className="activity-page-overview-items">
					{overviewItems.map((item) => {
						const Icon = item.icon;

						return (
							<div
								key={item.key}
								className="activity-page-overview-item"
							>
								<div className="activity-page-overview-icon">
									<Icon size={16} />
								</div>
								<div className="activity-page-overview-copy">
									<p className="activity-page-overview-label">
										{item.label}
									</p>
									<p className="activity-page-overview-value">
										{item.value}
									</p>
								</div>
							</div>
						);
					})}
				</div>

				<div className="activity-page-overview-status">
					<p className="activity-page-overview-status-label">
						{__("Latest event")}
					</p>
					<p
						className="activity-page-overview-status-value"
						dir="auto"
					>
						{mostRecentTimestamp
							? formatDate(mostRecentTimestamp)
							: __("No recent events")}
					</p>
					{mostRecentTimestamp ? (
						<p
							className="activity-page-overview-status-note"
							dir="auto"
						>
							{formatExactTimestamp(mostRecentTimestamp)}
						</p>
					) : null}
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
								<Icon size={15} />
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
					max={ACTIVITY_PAGE_MAX_LIMIT}
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
									{__("Delete selected")}
								</button>
							</div>
						) : null}
					</div>
					<div className="activity-page-table">
						<div
							className={cn(
								"activity-page-table-head",
								isAdmin && "activity-page-table-head-admin"
							)}
						>
							<span aria-hidden="true"></span>
							<span>{__("Event")}</span>
							<span>{__("Details")}</span>
							<span>{__("When")}</span>
							{isAdmin ? (
								<span className="activity-page-table-head-actions">
									{__("Actions")}
								</span>
							) : null}
						</div>

						{dayGroups.length > 0 ? (
							<div className="activity-page-day-groups">
								{dayGroups.map((group) => {
									const groupSelectableIds = group.items
										.map((item) => item.id)
										.filter(
											(
												activityId
											): activityId is string =>
												Boolean(activityId)
										);
									const selectedGroupCount =
										groupSelectableIds.filter(
											(activityId) =>
												selectedActivityIds.includes(
													activityId
												)
										).length;
									const isGroupSelected =
										groupSelectableIds.length > 0 &&
										selectedGroupCount ===
											groupSelectableIds.length;
									const isGroupIndeterminate =
										selectedGroupCount > 0 &&
										!isGroupSelected;

									return (
										<section
											key={group.key}
											className="activity-page-day-group"
										>
											<div className="activity-page-day-header">
												<div className="activity-page-day-header-main">
													{isAdmin &&
													groupSelectableIds.length >
														0 ? (
														<input
															type="checkbox"
															checked={
																isGroupSelected
															}
															onChange={() =>
																handleToggleSelectGroup(
																	groupSelectableIds
																)
															}
															ref={(node) => {
																if (node) {
																	node.indeterminate =
																		isGroupIndeterminate;
																}
															}}
															className="links-checkbox"
															aria-label={sprintf(
																__(
																	"Select %s events"
																),
																group.label
															)}
														/>
													) : null}
													<span
														className="activity-page-day-header-label"
														dir="auto"
													>
														{group.label}
													</span>
												</div>
												<span className="activity-page-day-header-count">
													{formatCount(
														group.items.length
													)}
												</span>
											</div>

											<div className="activity-page-events">
												{group.items.map(
													(activity, index) => {
														const visual =
															getActivityVisual(
																activity.type
															);
														const Icon =
															visual.icon;
														const actorName =
															getActivityPersonName(
																activity.actor
															);
														const userName =
															getActivityPersonName(
																activity.user
															);
														const linkName =
															activity.link
																? getLinkDisplayTitle(
																		activity
																			.link
																			.title,
																		activity
																			.link
																			.shortCode ||
																			__(
																				"Unknown"
																			)
																	)
																: null;
														const locationName =
															activity.location
																? activity
																		.location
																		.city ||
																	activity
																		.location
																		.country ||
																	null
																: null;
														const exactTime =
															formatExactTimestamp(
																activity.timestamp
															);
														const detailItems = [
															actorName
																? {
																		key: "actor",
																		label: __(
																			"By"
																		),
																		value: actorName,
																	}
																: null,
															linkName
																? {
																		key: "link",
																		label: __(
																			"Link"
																		),
																		value: linkName,
																	}
																: null,
															userName
																? {
																		key: "user",
																		label: __(
																			"User"
																		),
																		value: userName,
																	}
																: null,
															locationName
																? {
																		key: "location",
																		label: __(
																			"From"
																		),
																		value: locationName,
																	}
																: null,
														].filter(
															Boolean
														) as Array<{
															key: string;
															label: string;
															value: string;
														}>;

														return (
															<article
																key={
																	activity.id ||
																	`${group.key}-${index}`
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
																		<Icon
																			size={
																				18
																			}
																		/>
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
																	<div className="activity-page-event-badges">
																		<span
																			className={cn(
																				"activity-page-event-badge",
																				`activity-page-event-badge-${visual.tone}`
																			)}
																		>
																			{getActivityTypeLabel(
																				activity.type
																			)}
																		</span>
																		{activity
																			.user
																			?.role ? (
																			<span className="activity-page-event-badge activity-page-event-badge-neutral">
																				{getRoleLabel(
																					activity
																						.user
																						.role
																				)}
																			</span>
																		) : null}
																	</div>
																</div>

																<div className="activity-page-event-context">
																	{detailItems.length >
																	0 ? (
																		detailItems.map(
																			(
																				item
																			) => (
																				<div
																					key={
																						item.key
																					}
																					className="activity-page-event-detail"
																				>
																					<span className="activity-page-event-detail-label">
																						{
																							item.label
																						}
																					</span>
																					<span
																						className="activity-page-event-detail-value"
																						dir="auto"
																					>
																						{
																							item.value
																						}
																					</span>
																				</div>
																			)
																		)
																	) : (
																		<span className="activity-page-event-detail-empty">
																			{__(
																				"No additional details"
																			)}
																		</span>
																	)}
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
																			{
																				exactTime
																			}
																		</p>
																	) : null}
																</div>
																{isAdmin ? (
																	<div className="activity-page-event-actions">
																		{activity.id ? (
																			<button
																				type="button"
																				onClick={() =>
																					setActivityPendingDelete(
																						activity
																					)
																				}
																				className="activity-page-event-action"
																				aria-label={__(
																					"Delete activity log"
																				)}
																				title={__(
																					"Delete activity log"
																				)}
																			>
																				<Trash2
																					size={
																						15
																					}
																				/>
																			</button>
																		) : null}
																	</div>
																) : null}
															</article>
														);
													}
												)}
											</div>
										</section>
									);
								})}
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
				open={bulkDeleteOpen}
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
