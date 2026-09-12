/**
 * Supported visual styles for the traffic chart component.
 */
export type TrafficChartType = "line" | "bar";

/**
 * Series visibility options supported by the traffic chart.
 */
export type TrafficChartSeriesMode = "both" | "clicks" | "unique";

/**
 * Normalized traffic-series payload consumed by the chart component.
 */
export interface TrafficChartData {
	/** Ordered x-axis labels rendered on the chart. */
	labels: string[];

	/** Total click counts aligned with the labels array. */
	clicks: number[];

	/** Unique visitor counts aligned with the labels array. */
	unique: number[];

	/** Bucket size used by the traffic series. */
	granularity?: "day" | "month";
}

/**
 * Props for the traffic chart component.
 */
export interface TrafficChartProps {
	/** Optional traffic data rendered by the chart. */
	data?: Partial<TrafficChartData> | null;

	/** Active time-range token used for display logic. */
	timeRange?: string;

	/** Desired chart presentation mode. */
	type?: TrafficChartType;

	/** Which traffic series should be visible. */
	seriesMode?: TrafficChartSeriesMode;
}

/**
 * Single segment rendered in a metric donut chart.
 */
export interface MetricDonutSegment {
	/** Segment label shown in the tooltip and legend. */
	label: string;

	/** Segment value used by the chart. */
	value: number;

	/** Segment color. */
	color: string;
}

/**
 * Props for the compact metric donut chart component.
 */
export interface MetricDonutChartProps {
	/** Segments rendered in the chart. */
	segments: MetricDonutSegment[];

	/** Accessible chart label. */
	ariaLabel?: string;

	/** Value shown in the chart center. */
	totalValue?: string;

	/** Label shown below the center value. */
	totalLabel?: string;
}

/**
 * Single country metric rendered on the world map.
 */
export interface WorldMapDatum {
	/** Country code used to map the metric onto the atlas data. */
	countryCode: string;

	/** Optional country name shown in tooltips. */
	countryName?: string | null;

	/** Total clicks attributed to the country. */
	clicks: number;
}

/**
 * Tooltip payload shown while hovering a country on the map.
 */
export interface TooltipContent {
	/** Country label displayed in the tooltip. */
	name: string;

	/** Click count displayed in the tooltip. */
	clicks: number;
}

/**
 * Geographic feature shape read from the world atlas payload.
 */
export interface GeographyFeature {
	/** Numeric or string identifier from the atlas source. */
	id?: string | number | null;

	/** Optional properties exposed by the feature. */
	properties?: {
		/** Display name bundled with the feature. */
		name?: string;
	};
}

/**
 * Props for the interactive world map component.
 */
export interface WorldMapProps {
	/** Country metrics rendered on the map. */
	data?: WorldMapDatum[];

	/** Currently highlighted country code. */
	hoveredCountry?: string | null;

	/** Callback fired when a country gains or loses hover state. */
	onCountryHover?: (country: WorldMapDatum | null) => void;
}
