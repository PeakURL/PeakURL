import { useMemo, useState } from "react";
import { CalendarDays, ChevronDown, ChevronUp } from "lucide-react";
import { __, sprintf } from "@/i18n";
import { formatCount, formatDateOnly } from "@/utils";
import DetailMetric from "./DetailMetric";
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
 * Display-ready day row used by the click-history details layout.
 */
interface ClickHistoryDayGroup extends LinkClickHistoryDay {
	/** Localized day-of-month label shown in the details layout. */
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

	/** Whether the year/month/day details are expanded. */
	showDetails: boolean;

	/** Toggle callback for the details layout. */
	onToggleDetails: () => void;
}

/**
 * Props for the complete click-history details view.
 */
interface ClickHistoryDetailsProps {
	/** Year-grouped click history rows. */
	yearGroups: ClickHistoryYearGroup[];
}

/**
 * Props for one year group and its month children.
 */
interface ClickHistoryYearProps {
	/** Year bucket to render. */
	yearGroup: ClickHistoryYearGroup;
}

/**
 * Props for one month group and its day children.
 */
interface ClickHistoryMonthProps {
	/** Month bucket to render. */
	monthGroup: ClickHistoryMonthGroup;
}

/**
 * Props for one active click day item.
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
		year: match[1] || "",
		month: match[2] || "",
		day: match[3] || "",
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
 * Groups active click days into year/month/day buckets for display.
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
 * Render the summary line above the expandable click-history details.
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
		<p className="links-click-history-summary">
			<span className="links-click-history-summary-value">
				{formatClickCount(allTimeTotalClicks)}
			</span>{" "}
			<span className="links-click-history-summary-muted">
				{formatUniqueVisitorCount(allTimeUniqueClicks)}
			</span>{" "}
			<span className="links-click-history-summary-muted">
				{sprintf(__("across %s"), formatActiveDayCount(activeDayCount))}
			</span>{" "}
			<button
				type="button"
				onClick={onToggleDetails}
				className="links-click-history-toggle"
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
 * Render the expanded year/month/day click-history details.
 *
 * @param props Grouped click-history rows.
 * @returns Click-history details markup.
 */
function ClickHistoryDetails({ yearGroups }: ClickHistoryDetailsProps) {
	return (
		<div className="links-detail-list">
			{yearGroups.map((yearGroup) => (
				<ClickHistoryYear key={yearGroup.key} yearGroup={yearGroup} />
			))}
		</div>
	);
}

/**
 * Render one year group in the click-history details.
 *
 * @param props Year group details.
 * @returns Year summary and month children.
 */
function ClickHistoryYear({ yearGroup }: ClickHistoryYearProps) {
	const headingId = `links-click-history-year-${yearGroup.key}`;

	return (
		<section className="links-detail-group" aria-labelledby={headingId}>
			<div className="links-detail-row">
				<div className="links-detail-heading">
					<span
						className="links-detail-marker links-detail-marker-primary"
						aria-hidden="true"
					></span>
					<h4 id={headingId} className="links-detail-title">
						{yearGroup.label}
					</h4>
				</div>
				<span className="links-detail-total">
					{formatClickCount(yearGroup.totalClicks)}
				</span>
			</div>
			<div className="links-detail-children">
				{yearGroup.months.map((monthGroup) => (
					<ClickHistoryMonth
						key={monthGroup.key}
						monthGroup={monthGroup}
					/>
				))}
			</div>
		</section>
	);
}

/**
 * Render one month group in the click-history details.
 *
 * @param props Month group details.
 * @returns Month summary and active-day rows.
 */
function ClickHistoryMonth({ monthGroup }: ClickHistoryMonthProps) {
	return (
		<section className="links-detail-group-nested">
			<div className="links-detail-row">
				<span
					className="links-detail-marker links-detail-marker-secondary"
					aria-hidden="true"
				></span>
				<h5 className="links-detail-title-muted">{monthGroup.label}</h5>
				<span className="links-detail-total">
					{formatClickCount(monthGroup.totalClicks)}
				</span>
			</div>
			<div className="links-detail-items">
				{monthGroup.days.map((day) => (
					<ClickHistoryDay key={day.date} day={day} />
				))}
			</div>
		</section>
	);
}

/**
 * Render one active day in the click-history details.
 *
 * @param props Day details.
 * @returns Active-day detail markup.
 */
function ClickHistoryDay({ day }: ClickHistoryDayProps) {
	return (
		<div className="links-detail-item">
			<span className="links-detail-item-label">{day.dayLabel}</span>
			<DetailMetric
				tone="clicks"
				value={formatClickCount(day.totalClicks)}
			/>
			<DetailMetric
				tone="unique"
				value={formatUniqueVisitorCount(day.uniqueClicks)}
			/>
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
		<div className="links-click-history">
			<div className="links-historical-stats-header">
				<CalendarDays className="links-drawer-section-icon" />
				<h3 className="links-historical-stats-title">
					{__("Click history")}
				</h3>
			</div>

			{isLoading ? (
				<p className="links-click-history-loading">
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
						<ClickHistoryDetails yearGroups={yearGroups} />
					)}
				</>
			) : (
				<p className="links-click-history-empty">
					{__("No click activity has been recorded yet.")}
				</p>
			)}
		</div>
	);
}

export default ClickHistory;
