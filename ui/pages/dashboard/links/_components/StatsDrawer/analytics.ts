import { __, sprintf } from "@/i18n";
import type { LinkRecord } from "../types";
import type {
	LinkStatsPayload,
	StatsTimeRange,
	StatsTrafficSeries,
} from "./types";

export interface StatsTotals {
	totalClicks: number;
	uniqueClicks: number;
	uniqueClickRate: number;
}

export interface NormalizedTrafficSeries {
	labels: string[];
	clicks: number[];
	unique: number[];
}

const DAY_MS = 24 * 60 * 60 * 1000;

function toFiniteNumber(value: unknown): number {
	const numericValue = Number(value ?? 0);
	return Number.isFinite(numericValue) ? numericValue : 0;
}

function getLinkAgeInDays(createdAt?: string | null): number {
	const createdDate = createdAt ? new Date(createdAt) : null;

	if (!createdDate || Number.isNaN(createdDate.getTime())) {
		return 1;
	}

	return Math.max(
		1,
		Math.ceil((Date.now() - createdDate.getTime()) / DAY_MS)
	);
}

export function getStatsTimeRangeDays(
	range: StatsTimeRange,
	createdAt?: string | null
): number {
	switch (range) {
		case "24h":
			return 1;
		case "30d":
			return 30;
		case "all":
			return getLinkAgeInDays(createdAt);
		case "7d":
		default:
			return 7;
	}
}

export function getStatsTimeRangeLabel(range: StatsTimeRange): string {
	switch (range) {
		case "24h":
			return __("24 hours");
		case "30d":
			return __("30 days");
		case "all":
			return __("All time");
		case "7d":
		default:
			return __("7 days");
	}
}

export function getStatsTotals(
	link: LinkRecord,
	stats?: LinkStatsPayload | null
): StatsTotals {
	const totalClicks = toFiniteNumber(stats?.totalClicks ?? link.clicks);
	const uniqueClicks = Math.min(
		toFiniteNumber(stats?.uniqueClicks ?? link.uniqueClicks),
		totalClicks
	);
	const providedRate = toFiniteNumber(stats?.uniqueClickRate);
	const uniqueClickRate =
		totalClicks > 0
			? providedRate ||
				Number(((uniqueClicks / totalClicks) * 100).toFixed(1))
			: 0;

	return {
		totalClicks,
		uniqueClicks,
		uniqueClickRate,
	};
}

export function normalizeTrafficSeries(
	traffic?: StatsTrafficSeries | null
): NormalizedTrafficSeries {
	const rawLabels = Array.isArray(traffic?.labels)
		? traffic?.labels || []
		: [];
	const rawClicks = Array.isArray(traffic?.clicks)
		? traffic?.clicks || []
		: [];
	const rawUnique = Array.isArray(traffic?.unique)
		? traffic?.unique || []
		: [];
	const length = Math.max(
		rawLabels.length,
		rawClicks.length,
		rawUnique.length
	);

	return {
		labels: Array.from({ length }, (_, index) => rawLabels[index] || ""),
		clicks: Array.from({ length }, (_, index) =>
			Math.max(0, toFiniteNumber(rawClicks[index]))
		),
		unique: Array.from({ length }, (_, index) => {
			const clickCount = Math.max(0, toFiniteNumber(rawClicks[index]));
			const uniqueCount = Math.max(0, toFiniteNumber(rawUnique[index]));
			return Math.min(uniqueCount, clickCount);
		}),
	};
}

export function hasTrafficActivity(series: NormalizedTrafficSeries): boolean {
	return (
		series.labels.length > 0 &&
		(series.clicks.some((value) => value > 0) ||
			series.unique.some((value) => value > 0))
	);
}

export function formatClickCount(count: number): string {
	return sprintf(
		1 === count ? __("%s click") : __("%s clicks"),
		String(count)
	);
}

export function formatAverageClicks(value: number, unit: string): string {
	const formattedValue = value.toFixed("hour" === unit ? 2 : 1);

	return sprintf(
		"hour" === unit ? __("%s per hour") : __("%s per day"),
		formattedValue
	);
}
