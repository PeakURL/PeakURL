import { useMemo, useState } from "react";
import { CalendarDays, ChevronDown, ChevronUp } from "lucide-react";
import { __, sprintf } from "@/i18n";
import { formatCount, formatDateOnly } from "@/utils";
import { formatClickCount, formatUniqueVisitorCount } from "./analytics";
import type { LinkClickHistoryDay, LinkStatsViewProps } from "./types";

/**
 * Parsed pieces from the API's date-only `YYYY-MM-DD` values.
 */
interface DateParts {
	/** Four-digit calendar year. */
	year: string;

	/** Two-digit month number. */
	month: string;

	/** Two-digit day number. */
	day: string;
}

/**
 * Display-ready day row used by the click-history tree.
 */
interface ClickHistoryDayGroup extends LinkClickHistoryDay {
	/** Localized day-of-month label shown in the tree. */
	dayLabel: string;
}

/**
 * Month bucket containing all active click days for that month.
 */
interface ClickHistoryMonthGroup {
	/** Stable `YYYY-MM` key. */
	key: string;

	/** Localized month label. */
	label: string;

	/** Total clicks across all active days in the month. */
	totalClicks: number;

	/** Active day rows for this month. */
	days: ClickHistoryDayGroup[];
}

/**
 * Year bucket containing all active months for that year.
 */
interface ClickHistoryYearGroup {
	/** Stable four-digit year key. */
	key: string;

	/** Localized year label. */
	label: string;

	/** Total clicks across all active days in the year. */
	totalClicks: number;

	/** Active month rows for this year. */
	months: ClickHistoryMonthGroup[];
}

/**
 * Props for the compact click-history summary row.
 */
interface ClickHistorySummaryProps {
	/** Number of days that have at least one click. */
	activeDayCount: number;

	/** All-time total clicks for the link. */
	allTimeTotalClicks: number;

	/** All-time unique visitors for the link. */
	allTimeUniqueClicks: number;

	/** Whether the year/month/day tree is expanded. */
	showDetails: boolean;

	/** Toggle callback for the details tree. */
	onToggleDetails: () => void;
}

/**
 * Props for the complete click-history tree.
 */
interface ClickHistoryTreeProps {
	/** Year-grouped click history rows. */
	yearGroups: ClickHistoryYearGroup[];
}

/**
 * Props for one year row and its month children.
 */
interface ClickHistoryYearProps {
	/** Year bucket to render. */
	yearGroup: ClickHistoryYearGroup;
}

/**
 * Props for one month row and its day children.
 */
interface ClickHistoryMonthProps {
	/** Month bucket to render. */
	monthGroup: ClickHistoryMonthGroup;
}

/**
 * Props for one active click day row.
 */
interface ClickHistoryDayProps {
	/** Active day to render. */
	day: ClickHistoryDayGroup;
}

/** Stable empty array used to keep memo dependencies from changing. */
const EMPTY_CLICK_DAYS: LinkClickHistoryDay[] = [];

/** Strict date-only pattern used by the API for day buckets. */
const DATE_PARTS_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;

/**
 * Parse a date-only value without constructing a timezone-sensitive Date.
 *
 * @param dateValue Date string in `YYYY-MM-DD` format.
 * @returns Parsed date parts or `null` for malformed values.
 */
function getDateParts(dateValue: string): DateParts | null {
	const match = dateValue.match(DATE_PARTS_PATTERN);

	if (!match) {
		return null;
	}

	return {
		year: match[1],
		month: match[2],
		day: match[3],
	};
}

/**
 * Format the active-day count with the right singular/plural label.
 *
 * @param count Number of active click days.
 * @returns Localized active-day count label.
 */
function formatActiveDayCount(count: number): string {
	return sprintf(
		1 === count ? __("%s active day") : __("%s active days"),
		formatCount(count)
	);
}

/**
 * Groups active click days into a compact year/month/day tree for display.
 *
 * @param clickDays Active click days returned by the stats API.
 * @returns Click history grouped by year, month, and day.
 */
function groupClickHistoryDays(
	clickDays: LinkClickHistoryDay[]
): ClickHistoryYearGroup[] {
	const yearGroups = new Map<string, ClickHistoryYearGroup>();
	/* The API returns oldest first; the drawer presents recent activity first. */
	const sortedDays = [...clickDays].sort((left, right) =>
		right.date.localeCompare(left.date)
	);

	sortedDays.forEach((day) => {
		const dateParts = getDateParts(day.date);

		if (!dateParts) {
			return;
		}

		const yearKey = dateParts.year;
		const monthKey = `${dateParts.year}-${dateParts.month}`;
		const monthDate = `${monthKey}-01`;
		const totalClicks = Math.max(0, Number(day.totalClicks || 0));

		/* Guard against inconsistent analytics rows before rendering totals. */
		const dayGroup: ClickHistoryDayGroup = {
			...day,
			totalClicks,
			uniqueClicks: Math.max(
				0,
				Math.min(Number(day.uniqueClicks || 0), totalClicks)
			),
			dayLabel:
				formatDateOnly(day.date, {
					day: "numeric",
				}) || dateParts.day,
		};
		let yearGroup = yearGroups.get(yearKey);

		if (!yearGroup) {
			yearGroup = {
				key: yearKey,
				label: sprintf(__("Year %s"), yearKey),
				totalClicks: 0,
				months: [],
			};
			yearGroups.set(yearKey, yearGroup);
		}

		let monthGroup = yearGroup.months.find(
			(group) => group.key === monthKey
		);

		if (!monthGroup) {
			monthGroup = {
				key: monthKey,
				label:
					formatDateOnly(monthDate, {
						month: "long",
					}) || monthKey,
				totalClicks: 0,
				days: [],
			};
			yearGroup.months.push(monthGroup);
		}

		yearGroup.totalClicks += totalClicks;
		monthGroup.totalClicks += totalClicks;
		monthGroup.days.push(dayGroup);
	});

	return Array.from(yearGroups.values());
}

/**
 * Render the summary line above the expandable click-history tree.
 *
 * @param props Summary totals and toggle state.
 * @returns Click-history summary markup.
 */
function ClickHistorySummary({
	activeDayCount,
	allTimeTotalClicks,
	allTimeUniqueClicks,
	showDetails,
	onToggleDetails,
}: ClickHistorySummaryProps) {
	return (
		<p className="links-best-day-copy">
			<span className="links-best-day-value">
				{formatClickCount(allTimeTotalClicks)}
			</span>{" "}
			<span className="links-best-day-unique">
				{formatUniqueVisitorCount(allTimeUniqueClicks)}
			</span>{" "}
			<span className="links-best-day-unique">
				{sprintf(__("across %s"), formatActiveDayCount(activeDayCount))}
			</span>{" "}
			<button
				type="button"
				onClick={onToggleDetails}
				className="links-best-day-toggle"
			>
				{showDetails ? __("Hide details") : __("View all days")}
				{showDetails ? (
					<ChevronUp className="w-3 h-3" />
				) : (
					<ChevronDown className="w-3 h-3" />
				)}
			</button>
		</p>
	);
}

/**
 * Render the expanded year/month/day click-history tree.
 *
 * @param props Grouped click-history rows.
 * @returns Click-history tree markup.
 */
function ClickHistoryTree({ yearGroups }: ClickHistoryTreeProps) {
	return (
		<div className="links-best-day-details">
			<div className="links-best-day-tree">
				{yearGroups.map((yearGroup) => (
					<ClickHistoryYear
						key={yearGroup.key}
						yearGroup={yearGroup}
					/>
				))}
			</div>
		</div>
	);
}

/**
 * Render one year group in the click-history tree.
 *
 * @param props Year group details.
 * @returns Year row and month children.
 */
function ClickHistoryYear({ yearGroup }: ClickHistoryYearProps) {
	return (
		<div className="links-best-day-tree">
			<div className="links-best-day-tree-row">
				<span className="links-best-day-tree-dot-large"></span>
				<span className="links-best-day-tree-label">
					{yearGroup.label}
				</span>
				<span className="links-best-day-tree-label-muted">
					{formatClickCount(yearGroup.totalClicks)}
				</span>
			</div>
			<div className="links-best-day-tree-branch">
				{yearGroup.months.map((monthGroup) => (
					<ClickHistoryMonth
						key={monthGroup.key}
						monthGroup={monthGroup}
					/>
				))}
			</div>
		</div>
	);
}

/**
 * Render one month group in the click-history tree.
 *
 * @param props Month group details.
 * @returns Month row and day children.
 */
function ClickHistoryMonth({ monthGroup }: ClickHistoryMonthProps) {
	return (
		<div className="links-best-day-tree">
			<div className="links-best-day-tree-row">
				<span className="links-best-day-tree-dot-medium"></span>
				<span className="links-best-day-tree-label-muted">
					{monthGroup.label}
				</span>
				<span className="links-best-day-tree-label-muted">
					{formatClickCount(monthGroup.totalClicks)}
				</span>
			</div>
			<div className="links-best-day-tree-branch">
				{monthGroup.days.map((day) => (
					<ClickHistoryDay key={day.date} day={day} />
				))}
			</div>
		</div>
	);
}

/**
 * Render one active day in the click-history tree.
 *
 * @param props Day row details.
 * @returns Day row markup.
 */
function ClickHistoryDay({ day }: ClickHistoryDayProps) {
	return (
		<div className="links-best-day-tree-row">
			<span className="links-best-day-tree-dot-small"></span>
			<span className="links-best-day-tree-label">{day.dayLabel}:</span>
			<span className="links-best-day-tree-label">
				{formatClickCount(day.totalClicks)}
			</span>
			<span className="links-best-day-tree-label-muted">
				{formatUniqueVisitorCount(day.uniqueClicks)}
			</span>
		</div>
	);
}

/**
 * Show day-level click history for the current link.
 *
 * @param props Link stats view state.
 * @returns Click-history card markup.
 */
function ClickHistory({ link, stats, isLoading }: LinkStatsViewProps) {
	const [showDetails, setShowDetails] = useState(false);
	const clickHistory = stats?.clickHistory;
	const clickDays = clickHistory?.days ?? EMPTY_CLICK_DAYS;
	const yearGroups = useMemo(
		() => groupClickHistoryDays(clickDays),
		[clickDays]
	);

	/* Prefer exact API summaries, falling back to the list row while loading. */
	const activeDayCount = clickHistory?.activeDayCount ?? clickDays.length;
	const allTimeTotalClicks =
		stats?.periodSummaries?.allTime?.totalClicks ??
		Number(link.clicks || 0);
	const allTimeUniqueClicks =
		stats?.periodSummaries?.allTime?.uniqueClicks ??
		Math.min(Number(link.uniqueClicks || 0), allTimeTotalClicks);

	/* Keep the card empty until grouped day rows are available. */
	const hasClickHistory = yearGroups.length > 0;
	const toggleDetails = () => setShowDetails((isShowing) => !isShowing);

	return (
		<div className="links-best-day">
			<div className="links-historical-stats-header">
				<CalendarDays className="links-drawer-section-icon" />
				<h3 className="links-historical-stats-title">
					{__("Click history")}
				</h3>
			</div>

			{isLoading ? (
				<p className="links-best-day-copy">
					{__("Loading click history...")}
				</p>
			) : hasClickHistory ? (
				<>
					<ClickHistorySummary
						activeDayCount={activeDayCount}
						allTimeTotalClicks={allTimeTotalClicks}
						allTimeUniqueClicks={allTimeUniqueClicks}
						showDetails={showDetails}
						onToggleDetails={toggleDetails}
					/>

					{showDetails && (
						<ClickHistoryTree yearGroups={yearGroups} />
					)}
				</>
			) : (
				<p className="links-best-day-empty">
					{__("No click activity has been recorded yet.")}
				</p>
			)}
		</div>
	);
}

export default ClickHistory;
