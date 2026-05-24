import {
	ArrowDown,
	ArrowUp,
	ChartLine,
	Link2,
	MousePointerClick,
	Users,
} from "lucide-react";

import { formatNumber } from "@/utils";
import { __ } from "@/i18n";
import { cn } from "@/utils";

import type { StatsCardsProps } from "../types";

type DashboardStatTone = "clicks" | "links" | "rate" | "users";

const StatsCards = ({ stats }: StatsCardsProps) => {
	const uniqueClickRate = Number(stats.uniqueClickRate ?? 0);
	const previousUniqueClickRate = Number(stats.previousUniqueClickRate ?? 0);

	const getChangeData = (
		current: number,
		previous?: number,
		isRate = false
	) => {
		if (previous === undefined) return null;

		const delta = current - previous;
		let formatted: string;

		if (isRate) {
			formatted = Math.abs(delta).toFixed(1) + "%";
		} else {
			if (previous === 0) {
				if (current === 0) return null;
				formatted = "100%";
			} else {
				const percentage = (delta / previous) * 100;
				formatted = Math.abs(percentage).toFixed(1) + "%";
			}
		}

		return {
			text: `${delta >= 0 ? "+" : "-"}${formatted}`,
			type: delta >= 0 ? "positive" : "negative",
		};
	};

	const clicksChange = getChangeData(
		stats.totalClicks,
		stats.previousTotalClicks
	);
	const linksChange = getChangeData(
		stats.totalLinks,
		stats.previousTotalLinks
	);
	const rateChange = getChangeData(
		uniqueClickRate,
		previousUniqueClickRate,
		true
	);
	const uniqueChange = getChangeData(
		stats.uniqueClicks,
		stats.previousUniqueClicks
	);

	const statsData = [
		{
			title: __("Total Clicks"),
			value: formatNumber(stats.totalClicks),
			change: clicksChange?.text || null,
			changeType: clicksChange?.type || "positive",
			icon: MousePointerClick,
			tone: "clicks" as DashboardStatTone,
		},
		{
			title: __("Active Links"),
			value: stats.totalLinks,
			change: linksChange?.text || null,
			changeType: linksChange?.type || "positive",
			icon: Link2,
			tone: "links" as DashboardStatTone,
		},
		{
			title: __("Unique Click Rate"),
			value: `${uniqueClickRate.toFixed(1)}%`,
			change: rateChange?.text || null,
			changeType: rateChange?.type || "positive",
			icon: ChartLine,
			tone: "rate" as DashboardStatTone,
		},
		{
			title: __("Unique Visitors"),
			value: formatNumber(stats.uniqueClicks),
			change: uniqueChange?.text || null,
			changeType: uniqueChange?.type || "positive",
			icon: Users,
			tone: "users" as DashboardStatTone,
		},
	];

	const getIconClassName = (tone: DashboardStatTone) =>
		cn("dashboard-stats-card-icon", `dashboard-stats-card-icon-${tone}`);

	const getIconGlyphClassName = (tone: DashboardStatTone) =>
		cn(
			"dashboard-stats-card-icon-glyph",
			`dashboard-stats-card-icon-glyph-${tone}`
		);

	const getChangeBadgeClassName = (changeType: string) =>
		cn(
			"dashboard-stats-card-change-badge",
			"positive" === changeType
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
										stat.changeType
									)}
								>
									{stat.changeType === "positive" ? (
										<ArrowUp className="dashboard-stats-card-change-icon" />
									) : (
										<ArrowDown className="dashboard-stats-card-change-icon" />
									)}
									{stat.change}
								</span>
								<span className="dashboard-stats-card-change-note">
									{__("vs previous period")}
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
