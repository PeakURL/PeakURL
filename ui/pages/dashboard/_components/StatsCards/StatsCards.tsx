import {
	ArrowDown,
	ArrowUp,
	ChartLine,
	Link2,
	MousePointerClick,
	Users,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";

import { __, sprintf } from "@/i18n";
import { cn, formatNumber } from "@/utils";

import type { StatsCardsProps } from "../types";

type DashboardStatTone = "clicks" | "links" | "rate" | "users";
type DashboardStatChangeType = "positive" | "negative";

interface DashboardStatChange {
	text: string;
	type: DashboardStatChangeType;
}

interface DashboardStatCard {
	title: string;
	value: string;
	change: DashboardStatChange | null;
	icon: LucideIcon;
	tone: DashboardStatTone;
}

/**
 * Format a dashboard-card delta against the last-month value.
 *
 * Count cards show percentage movement. Rate cards show percentage-point
 * movement while keeping the compact percent badge style.
 */
function getLastMonthChange(
	current: number,
	lastMonth?: number,
	isRate = false
): DashboardStatChange | null {
	if (lastMonth === undefined) {
		return null;
	}

	const delta = current - lastMonth;
	let formatted: string;

	if (isRate) {
		formatted = `${Math.abs(delta).toFixed(1)}%`;
	} else if (lastMonth === 0) {
		if (current === 0) {
			return null;
		}

		formatted = "100%";
	} else {
		formatted = `${Math.abs((delta / lastMonth) * 100).toFixed(1)}%`;
	}

	return {
		text: `${delta >= 0 ? "+" : "-"}${formatted}`,
		type: delta >= 0 ? "positive" : "negative",
	};
}

/**
 * Return hover copy for the last-month date window.
 */
function getLastMonthTitle(
	lastMonth: StatsCardsProps["stats"]["lastMonth"]
): string {
	if (!lastMonth?.startDate || !lastMonth?.endDate) {
		return __("Compares with the same selected day range from last month.");
	}

	return sprintf(__("Compared with %1$s to %2$s."), [
		lastMonth.startDate,
		lastMonth.endDate,
	]);
}

const StatsCards = ({ stats }: StatsCardsProps) => {
	const uniqueClickRate = Number(stats.uniqueClickRate ?? 0);
	const lastMonthUniqueClickRate =
		stats.lastMonthUniqueClickRate === undefined
			? undefined
			: Number(stats.lastMonthUniqueClickRate);

	const clicksChange = getLastMonthChange(
		stats.totalClicks,
		stats.lastMonthTotalClicks
	);
	const linksChange = getLastMonthChange(
		stats.totalLinks,
		stats.lastMonthTotalLinks
	);
	const rateChange = getLastMonthChange(
		uniqueClickRate,
		lastMonthUniqueClickRate,
		true
	);
	const uniqueChange = getLastMonthChange(
		stats.uniqueClicks,
		stats.lastMonthUniqueClicks
	);
	const lastMonthTitle = getLastMonthTitle(stats.lastMonth);

	const statsData: DashboardStatCard[] = [
		{
			title: __("Total Clicks"),
			value: formatNumber(stats.totalClicks),
			change: clicksChange,
			icon: MousePointerClick,
			tone: "clicks",
		},
		{
			title: __("Active Links"),
			value: formatNumber(stats.totalLinks),
			change: linksChange,
			icon: Link2,
			tone: "links",
		},
		{
			title: __("Unique Click Rate"),
			value: `${uniqueClickRate.toFixed(1)}%`,
			change: rateChange,
			icon: ChartLine,
			tone: "rate",
		},
		{
			title: __("Visitors"),
			value: formatNumber(stats.uniqueClicks),
			change: uniqueChange,
			icon: Users,
			tone: "users",
		},
	];

	const getIconClassName = (tone: DashboardStatTone) =>
		cn("dashboard-stats-card-icon", `dashboard-stats-card-icon-${tone}`);

	const getIconGlyphClassName = (tone: DashboardStatTone) =>
		cn(
			"dashboard-stats-card-icon-glyph",
			`dashboard-stats-card-icon-glyph-${tone}`
		);

	const getChangeBadgeClassName = (changeType: DashboardStatChangeType) =>
		cn(
			"dashboard-stats-card-change-badge",
			changeType === "positive"
				? "dashboard-stats-card-change-badge-positive"
				: "dashboard-stats-card-change-badge-negative"
		);

	return (
		<div className="dashboard-stats-grid">
			{statsData.map((stat) => {
				const StatIcon = stat.icon;

				return (
					<div key={stat.title} className="dashboard-stats-card">
						<div className="dashboard-stats-card-header">
							<div className="dashboard-stats-card-copy">
								<p className="dashboard-stats-card-title">
									{stat.title}
								</p>
								<p className="dashboard-stats-card-value">
									{stat.value}
								</p>
							</div>
							<div className={getIconClassName(stat.tone)}>
								<StatIcon
									className={getIconGlyphClassName(stat.tone)}
								/>
							</div>
						</div>

						{stat.change && (
							<div className="dashboard-stats-card-change">
								<span
									className={getChangeBadgeClassName(
										stat.change.type
									)}
								>
									{stat.change.type === "positive" ? (
										<ArrowUp className="dashboard-stats-card-change-icon" />
									) : (
										<ArrowDown className="dashboard-stats-card-change-icon" />
									)}
									{stat.change.text}
								</span>
								<span
									className="dashboard-stats-card-change-note"
									title={lastMonthTitle}
								>
									{__("vs last month")}
								</span>
							</div>
						)}
					</div>
				);
			})}
		</div>
	);
};

export default StatsCards;
