/**
 * Supported visual styles for the traffic chart component.
 */
export type TrafficChartType = 'line' | 'bar';

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
