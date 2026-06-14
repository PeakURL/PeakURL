import type { MouseEvent, WheelEvent } from "react";
import { memo, useEffect, useRef, useState } from "react";
import type { GeoPermissibleObjects } from "@visx/geo";
import { Zoom } from "@visx/zoom";
import type { TransformMatrix } from "@visx/zoom";
import { feature as topojsonFeature } from "topojson-client";
import { scaleLinear } from "d3-scale";

import { __ } from "@/i18n";
import { useTheme } from "@/components/providers";
import { cn } from "@/utils";

import type { TooltipContent, WorldMapDatum, WorldMapProps } from "../types";
import {
	type RenderedMapFeature,
	WorldMapCanvas,
	WorldMapControls,
	WorldMapLegend,
	WorldMapTooltip,
	type WorldMapTooltipPosition,
} from "./_components";

const geoUrl = "https://cdn.jsdelivr.net/npm/world-atlas@2/countries-110m.json";
const MAP_WIDTH = 960;
const MAP_HEIGHT = 500;
const MIN_ZOOM = 1;
const MAX_ZOOM = 4;
const TOOLTIP_WIDTH = 220;
const TOOLTIP_HEIGHT = 88;
const WORLD_COPY_OFFSETS = [-MAP_WIDTH, 0, MAP_WIDTH];
const WORLD_COPY_RENDER_THRESHOLD = 1;
const INITIAL_TRANSFORM = {
	scaleX: 1,
	scaleY: 1,
	translateX: 0,
	translateY: 0,
	skewX: 0,
	skewY: 0,
};

function isWheelZoomGesture(event: WheelEvent<SVGSVGElement>): boolean {
	return event.metaKey || event.ctrlKey;
}

const getTranslatedCountryName = (alpha3Code: string): string => {
	switch (alpha3Code) {
		case "USA":
			return __("United States");
		case "GBR":
			return __("United Kingdom");
		case "CAN":
			return __("Canada");
		case "AUS":
			return __("Australia");
		case "DEU":
			return __("Germany");
		case "FRA":
			return __("France");
		case "ITA":
			return __("Italy");
		case "ESP":
			return __("Spain");
		case "NLD":
			return __("Netherlands");
		case "BEL":
			return __("Belgium");
		case "CHE":
			return __("Switzerland");
		case "AUT":
			return __("Austria");
		case "SWE":
			return __("Sweden");
		case "NOR":
			return __("Norway");
		case "DNK":
			return __("Denmark");
		case "FIN":
			return __("Finland");
		case "POL":
			return __("Poland");
		case "CZE":
			return __("Czech Republic");
		case "HUN":
			return __("Hungary");
		case "ROU":
			return __("Romania");
		case "BGR":
			return __("Bulgaria");
		case "GRC":
			return __("Greece");
		case "PRT":
			return __("Portugal");
		case "IRL":
			return __("Ireland");
		case "JPN":
			return __("Japan");
		case "CHN":
			return __("China");
		case "IND":
			return __("India");
		case "PAK":
			return __("Pakistan");
		case "BRA":
			return __("Brazil");
		case "MEX":
			return __("Mexico");
		case "ARG":
			return __("Argentina");
		case "ZAF":
			return __("South Africa");
		case "EGY":
			return __("Egypt");
		case "NGA":
			return __("Nigeria");
		case "KEN":
			return __("Kenya");
		case "SAU":
			return __("Saudi Arabia");
		case "ARE":
			return __("United Arab Emirates");
		case "TUR":
			return __("Turkey");
		case "RUS":
			return __("Russia");
		case "UKR":
			return __("Ukraine");
		case "KOR":
			return __("South Korea");
		case "THA":
			return __("Thailand");
		case "VNM":
			return __("Vietnam");
		case "SGP":
			return __("Singapore");
		case "MYS":
			return __("Malaysia");
		case "IDN":
			return __("Indonesia");
		case "PHL":
			return __("Philippines");
		case "PSE":
			return __("Palestine");
		case "NZL":
			return __("New Zealand");
		case "CHL":
			return __("Chile");
		case "COL":
			return __("Colombia");
		case "PER":
			return __("Peru");
		case "VEN":
			return __("Venezuela");
		default:
			return "";
	}
};

// Convert ISO 3166-1 alpha-2 to alpha-3 (common conversions)
const alpha2ToAlpha3: Record<string, string> = {
	US: "USA",
	GB: "GBR",
	CA: "CAN",
	AU: "AUS",
	DE: "DEU",
	FR: "FRA",
	IT: "ITA",
	ES: "ESP",
	NL: "NLD",
	BE: "BEL",
	CH: "CHE",
	AT: "AUT",
	SE: "SWE",
	NO: "NOR",
	DK: "DNK",
	FI: "FIN",
	PL: "POL",
	CZ: "CZE",
	HU: "HUN",
	RO: "ROU",
	BG: "BGR",
	GR: "GRC",
	PT: "PRT",
	IE: "IRL",
	JP: "JPN",
	CN: "CHN",
	IN: "IND",
	PK: "PAK",
	BR: "BRA",
	MX: "MEX",
	AR: "ARG",
	ZA: "ZAF",
	EG: "EGY",
	NG: "NGA",
	KE: "KEN",
	SA: "SAU",
	AE: "ARE",
	TR: "TUR",
	RU: "RUS",
	UA: "UKR",
	KR: "KOR",
	TH: "THA",
	VN: "VNM",
	SG: "SGP",
	MY: "MYS",
	ID: "IDN",
	PH: "PHL",
	PS: "PSE",
	NZ: "NZL",
	CL: "CHL",
	CO: "COL",
	PE: "PER",
	VE: "VEN",
};

// Map alpha-3 codes to numeric codes used by world-atlas
const alpha3ToNumeric: Record<string, string> = {
	USA: "840",
	GBR: "826",
	CAN: "124",
	AUS: "036",
	DEU: "276",
	FRA: "250",
	ITA: "380",
	ESP: "724",
	NLD: "528",
	BEL: "056",
	CHE: "756",
	AUT: "040",
	SWE: "752",
	NOR: "578",
	DNK: "208",
	FIN: "246",
	POL: "616",
	CZE: "203",
	HUN: "348",
	ROU: "642",
	BGR: "100",
	GRC: "300",
	PRT: "620",
	IRL: "372",
	JPN: "392",
	CHN: "156",
	IND: "356",
	PAK: "586",
	BRA: "076",
	MEX: "484",
	ARG: "032",
	ZAF: "710",
	EGY: "818",
	NGA: "566",
	KEN: "404",
	SAU: "682",
	ARE: "784",
	TUR: "792",
	RUS: "643",
	UKR: "804",
	KOR: "410",
	THA: "764",
	VNM: "704",
	SGP: "702",
	MYS: "458",
	IDN: "360",
	PHL: "608",
	PSE: "275",
	NZL: "554",
	CHL: "152",
	COL: "170",
	PER: "604",
	VEN: "862",
};

const LIGHT_MAP_COLORS = {
	defaultFill: "#e5e7eb",
	stroke: "#cbd5e1",
	activeStroke: "#0f172a",
	scale: ["#e0f2fe", "#0ea5e9", "#0369a1"],
	legend: ["#e0f2fe", "#7dd3fc", "#38bdf8", "#0ea5e9", "#0284c7", "#0369a1"],
};

const DARK_MAP_COLORS = {
	defaultFill: "#1f2937",
	stroke: "#374151",
	activeStroke: "#f8fafc",
	scale: ["#172554", "#0284c7", "#7dd3fc"],
	legend: ["#172554", "#0c4a6e", "#075985", "#0284c7", "#38bdf8", "#7dd3fc"],
};

/**
 * Clamp a number within a fixed range.
 */
function clampValue(value: number, minimum: number, maximum: number): number {
	return Math.min(Math.max(value, minimum), maximum);
}

/**
 * Wrap the horizontal map offset inside the current scaled world width.
 */
function wrapMapOffset(translateX: number, worldWidth: number): number {
	if (worldWidth <= 0) {
		return 0;
	}

	const wrappedOffset = translateX % worldWidth;

	return Object.is(wrappedOffset, -0) ? 0 : wrappedOffset;
}

/**
 * Check whether the map has moved enough to need neighboring world copies.
 */
function hasAdjustedMapView(transform: TransformMatrix): boolean {
	return (
		Math.abs(transform.translateX) > WORLD_COPY_RENDER_THRESHOLD ||
		transform.scaleX > MIN_ZOOM + Number.EPSILON
	);
}

/**
 * Return the rendered world copies for the current map position.
 */
function getWorldCopyOffsets(transform: TransformMatrix): number[] {
	return hasAdjustedMapView(transform) ? WORLD_COPY_OFFSETS : [0];
}

/**
 * Keep map movement bounded vertically while allowing horizontal wrapping.
 */
function constrainMapTransform(transform: TransformMatrix): TransformMatrix {
	const scaleX = clampValue(transform.scaleX, MIN_ZOOM, MAX_ZOOM);
	const scaleY = clampValue(transform.scaleY, MIN_ZOOM, MAX_ZOOM);
	const minTranslateY = Math.min(0, MAP_HEIGHT - MAP_HEIGHT * scaleY);
	const worldWidth = MAP_WIDTH * scaleX;

	return {
		...transform,
		scaleX,
		scaleY,
		translateX: wrapMapOffset(transform.translateX, worldWidth),
		translateY: clampValue(transform.translateY, minTranslateY, 0),
	};
}

/**
 * Display an interactive choropleth map of click distribution by country.
 */
const WorldMap = ({
	data = [],
	hoveredCountry,
	onCountryHover,
}: WorldMapProps) => {
	const { theme } = useTheme();
	const mapRef = useRef<HTMLDivElement | null>(null);
	const [tooltipContent, setTooltipContent] = useState<TooltipContent | null>(
		null
	);
	const [tooltipPosition, setTooltipPosition] =
		useState<WorldMapTooltipPosition | null>(null);
	const [geographies, setGeographies] = useState<GeoPermissibleObjects[]>([]);
	const [loadError, setLoadError] = useState(false);
	const [isFullscreen, setIsFullscreen] = useState(false);

	useEffect(() => {
		const controller = new AbortController();

		const loadMap = async () => {
			try {
				setLoadError(false);

				const response = await fetch(geoUrl, {
					signal: controller.signal,
				});

				if (!response.ok) {
					throw new Error("Failed to load world map data");
				}

				const topology = await response.json();
				const countries = topology.objects?.countries;

				if (!countries) {
					throw new Error("World map data is missing countries");
				}

				const world = topojsonFeature(topology, countries) as {
					features?: GeoPermissibleObjects[];
				};
				setGeographies(world.features || []);
			} catch (error) {
				if (!(error instanceof Error) || error.name !== "AbortError") {
					setLoadError(true);
				}
			}
		};

		loadMap();

		return () => {
			// Stop the atlas request when the map unmounts or React re-runs the effect.
			controller.abort();
		};
	}, []);

	useEffect(() => {
		const handleFullscreenChange = () => {
			setIsFullscreen(document.fullscreenElement === mapRef.current);
		};

		document.addEventListener("fullscreenchange", handleFullscreenChange);

		return () => {
			document.removeEventListener(
				"fullscreenchange",
				handleFullscreenChange
			);
		};
	}, []);

	const maxClicks =
		data.length > 0 ? Math.max(...data.map((item) => item.clicks)) : 100;
	const mapColors = theme === "dark" ? DARK_MAP_COLORS : LIGHT_MAP_COLORS;

	const colorScale = scaleLinear<string>()
		.domain([0, maxClicks / 2, maxClicks])
		.range(mapColors.scale);

	const countryClickMap = data.reduce<Record<string, WorldMapDatum>>(
		(acc, item) => {
			const alpha2Code = item.countryCode.toUpperCase();
			const alpha3Code = alpha2ToAlpha3[alpha2Code] || alpha2Code;
			const numericCode = alpha3ToNumeric[alpha3Code];

			if (numericCode) {
				acc[numericCode] = {
					countryCode: alpha2Code,
					countryName:
						item.countryName ||
						getTranslatedCountryName(alpha3Code) ||
						alpha2Code,
					clicks: item.clicks,
				};
			}

			return acc;
		},
		{}
	);

	const activeCountryCode = (hoveredCountry || "").toString().toUpperCase();

	const getTooltipPosition = (
		event: MouseEvent<SVGPathElement>
	): WorldMapTooltipPosition | null => {
		const mapElement = mapRef.current;

		if (!mapElement) {
			return null;
		}

		const bounds = mapElement.getBoundingClientRect();
		const x = event.clientX - bounds.left;
		const y = event.clientY - bounds.top;

		return {
			x,
			y,
			isNearRightEdge: bounds.width - x < TOOLTIP_WIDTH,
			isNearBottomEdge: bounds.height - y < TOOLTIP_HEIGHT,
		};
	};

	const handleCountryEnter = (
		event: MouseEvent<SVGPathElement>,
		countryData: WorldMapDatum | null,
		isDragging: boolean
	) => {
		if (isDragging || !countryData || countryData.clicks <= 0) {
			return;
		}

		setTooltipContent({
			name: countryData.countryName || countryData.countryCode,
			clicks: countryData.clicks,
		});
		setTooltipPosition(getTooltipPosition(event));
		onCountryHover?.(countryData);
	};

	const handleCountryMove = (
		event: MouseEvent<SVGPathElement>,
		countryData: WorldMapDatum | null,
		isDragging: boolean
	) => {
		if (isDragging || !countryData || countryData.clicks <= 0) {
			return;
		}

		setTooltipPosition(getTooltipPosition(event));
	};

	const handleCountryLeave = (isDragging: boolean) => {
		if (isDragging) {
			return;
		}

		setTooltipContent(null);
		setTooltipPosition(null);
		onCountryHover?.(null);
	};

	const handleFullscreenToggle = () => {
		const mapElement = mapRef.current;

		if (!mapElement) {
			return;
		}

		if (document.fullscreenElement === mapElement) {
			const exitRequest = document.exitFullscreen?.();
			exitRequest?.catch(() => undefined);
			return;
		}

		const fullscreenRequest = mapElement.requestFullscreen?.();
		fullscreenRequest?.catch(() => undefined);
	};

	const renderCountryPath = (
		{ feature, path }: RenderedMapFeature,
		index: number,
		isDragging: boolean
	) => {
		if (typeof path !== "string") {
			return null;
		}

		const countryCode =
			feature.id == null ? null : String(feature.id).padStart(3, "0");
		const featureKey =
			countryCode || `feature-${feature.properties?.name || index}`;
		const countryData =
			countryCode == null ? null : countryClickMap[countryCode];
		const clicks = countryData?.clicks || 0;
		const isActive = countryData?.countryCode === activeCountryCode;

		return (
			<path
				key={featureKey}
				d={path}
				fill={clicks > 0 ? colorScale(clicks) : mapColors.defaultFill}
				stroke={isActive ? mapColors.activeStroke : mapColors.stroke}
				strokeWidth={isActive ? 1.25 : 0.5}
				className={cn(
					"world-map-country",
					clicks > 0 && "world-map-country-clickable"
				)}
				vectorEffect="non-scaling-stroke"
				onMouseEnter={(event) =>
					handleCountryEnter(event, countryData, isDragging)
				}
				onMouseMove={(event) =>
					handleCountryMove(event, countryData, isDragging)
				}
				onMouseLeave={() => handleCountryLeave(isDragging)}
			/>
		);
	};

	return (
		<div
			className={cn(
				"world-map",
				theme === "dark" ? "world-map-dark" : "world-map-light"
			)}
			ref={mapRef}
		>
			<Zoom
				width={MAP_WIDTH}
				height={MAP_HEIGHT}
				scaleXMin={MIN_ZOOM}
				scaleXMax={MAX_ZOOM}
				scaleYMin={MIN_ZOOM}
				scaleYMax={MAX_ZOOM}
				initialTransformMatrix={INITIAL_TRANSFORM}
				constrain={constrainMapTransform}
				wheelDelta={(event) => {
					const scale = event.deltaY > 0 ? 0.92 : 1.08;

					return {
						scaleX: scale,
						scaleY: scale,
					};
				}}
			>
				{(zoom) => {
					// Keep the resting map compact, then add neighboring copies once interaction starts.
					const mapCopyOffsets = getWorldCopyOffsets(
						zoom.transformMatrix
					);

					return (
						<>
							<WorldMapControls
								canZoomIn={
									zoom.transformMatrix.scaleX < MAX_ZOOM
								}
								canZoomOut={
									zoom.transformMatrix.scaleX > MIN_ZOOM
								}
								isFullscreen={isFullscreen}
								onZoomIn={() =>
									zoom.scale({
										scaleX: 1.2,
										scaleY: 1.2,
										point: {
											x: MAP_WIDTH / 2,
											y: MAP_HEIGHT / 2,
										},
									})
								}
								onZoomOut={() =>
									zoom.scale({
										scaleX: 1 / 1.2,
										scaleY: 1 / 1.2,
										point: {
											x: MAP_WIDTH / 2,
											y: MAP_HEIGHT / 2,
										},
									})
								}
								onReset={() => zoom.reset()}
								onFullscreenToggle={handleFullscreenToggle}
							/>

							{tooltipContent && tooltipPosition && (
								<WorldMapTooltip
									content={tooltipContent}
									position={tooltipPosition}
								/>
							)}

							<WorldMapCanvas
								width={MAP_WIDTH}
								height={MAP_HEIGHT}
								geographies={geographies}
								mapCopyOffsets={mapCopyOffsets}
								transform={zoom.toString()}
								isDragging={zoom.isDragging}
								onDragStart={zoom.dragStart}
								onDragMove={zoom.dragMove}
								onDragEnd={zoom.dragEnd}
								onWheel={(event) => {
									if (!isWheelZoomGesture(event)) {
										return;
									}

									// Let the page scroll normally unless the user asks to zoom the map.
									event.preventDefault();
									event.stopPropagation();
									zoom.handleWheel(event);
								}}
								onMouseLeave={() => {
									zoom.dragEnd();
									handleCountryLeave(false);
								}}
								renderCountryPath={renderCountryPath}
							/>

							{loadError && (
								<div className="world-map-overlay world-map-overlay-error">
									{__(
										"Unable to load the world map right now."
									)}
								</div>
							)}

							{!loadError && geographies.length === 0 && (
								<div className="world-map-overlay world-map-overlay-loading">
									{__("Loading map...")}
								</div>
							)}

							<WorldMapLegend
								colors={mapColors.legend}
								maxClicks={maxClicks}
							/>
						</>
					);
				}}
			</Zoom>
		</div>
	);
};

export default memo(WorldMap);
