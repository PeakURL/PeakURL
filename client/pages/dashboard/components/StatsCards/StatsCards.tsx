import { __, sprintf } from "@/i18n";
import { formatDateOnly, getActiveLocale } from "@/shared/dates";
import { formatCount, formatNumber } from "@/shared/formatting";

import type { StatsCardsProps } from "../types";
import { StatCard } from "./StatCard";

function isMetric(value: number | undefined): value is number {
	return typeof value === "number" && Number.isFinite(value) && value >= 0;
}

function getPeriodLabel(
	period: NonNullable<StatsCardsProps["stats"]>["lastMonth"]
) {
	if (!period?.startDate || !period.endDate) return;
	const start = formatDateOnly(period.startDate, { dateStyle: "medium" });
	const end = formatDateOnly(period.endDate, { dateStyle: "medium" });
	if (!start || !end) return;
	return sprintf(__("Compared with %1$s to %2$s."), start, end);
}

/** Render counts and rate changes using the comparison window supplied by analytics. */
export default function StatsCards({
	stats,
	isLoading = false,
}: StatsCardsProps) {
	const rate =
		isMetric(stats?.totalClicks) && stats.totalClicks > 0
			? stats.uniqueClickRate
			: undefined;
	const priorRate =
		isMetric(stats?.lastMonthTotalClicks) && stats.lastMonthTotalClicks > 0
			? stats.lastMonthUniqueClickRate
			: undefined;
	const metrics = [
		{
			id: "clicks",
			label: __("Total Clicks"),
			current: stats?.totalClicks,
			previous: stats?.lastMonthTotalClicks,
		},
		{
			id: "links",
			label: __("Total Links"),
			current: stats?.totalLinks,
			previous: stats?.lastMonthTotalLinks,
		},
		{
			id: "rate",
			label: __("Unique Click Rate"),
			current: rate,
			previous: priorRate,
			isRate: true,
		},
		{
			id: "visitors",
			label: __("Visitors"),
			current: stats?.uniqueClicks,
			previous: stats?.lastMonthUniqueClicks,
		},
	];
	const locale = getActiveLocale();
	const decimal = new Intl.NumberFormat(locale, {
		maximumFractionDigits: 1,
		signDisplay: "exceptZero",
	});
	const percentage = new Intl.NumberFormat(locale, {
		style: "percent",
		maximumFractionDigits: 1,
	});
	const percentageChange = new Intl.NumberFormat(locale, {
		style: "percent",
		maximumFractionDigits: 1,
		signDisplay: "exceptZero",
	});
	const periodLabel = getPeriodLabel(stats?.lastMonth);

	return (
		<dl className="dashboard-stats-grid" aria-busy={isLoading}>
			{metrics.map(({ id, label, current, previous, isRate }) => {
				const currentValue = isMetric(current) ? current : undefined;
				const change =
					currentValue !== undefined && isMetric(previous)
						? isRate
							? currentValue - previous
							: previous > 0
								? (currentValue - previous) / previous
								: currentValue === 0
									? 0
									: undefined
						: undefined;
				// Match the semantic direction to the precision shown in the badge.
				const rounded =
					change === undefined
						? undefined
						: Number((change * (isRate ? 1 : 100)).toFixed(1));
				const tone = !rounded
					? "neutral"
					: rounded > 0
						? "positive"
						: "negative";

				const changeLabel =
					rounded === undefined
						? undefined
						: isRate
							? sprintf(__("%s pp"), decimal.format(rounded))
							: percentageChange.format(rounded / 100);
				const value =
					currentValue === undefined
						? "—"
						: isRate
							? percentage.format(currentValue / 100)
							: formatNumber(currentValue);

				return (
					<StatCard
						key={id}
						label={label}
						value={value}
						valueTitle={
							currentValue !== undefined && !isRate
								? formatCount(currentValue)
								: undefined
						}
						change={
							changeLabel === undefined
								? undefined
								: { text: changeLabel, tone }
						}
						note={
							changeLabel === undefined
								? __("Comparison unavailable")
								: __("vs last month")
						}
						noteTitle={periodLabel}
						isLoading={isLoading}
					/>
				);
			})}
		</dl>
	);
}
