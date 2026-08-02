import type { MouseEvent, WheelEvent } from "react";
import { memo, useEffect, useRef, useState } from "react";
import type { GeoPermissibleObjects } from "@visx/geo";
import { Zoom } from "@visx/zoom";
import type { TransformMatrix } from "@visx/zoom";
import { feature as topojsonFeature } from "topojson-client";
import { scaleLinear } from "d3-scale";
import { iso31661Alpha2ToNumeric } from "iso-3166/1-a2-to-1-n.js";

import { __ } from "@/i18n";
import { useTheme } from "@/components/providers";
import { cn } from "@/utils";

import type {
	GeographyFeature,
	TooltipContent,
	WorldMapDatum,
	WorldMapProps,
} from "../types";
import {
	type RenderedMapFeature,
	WorldMapCanvas,
	WorldMapControls,
	WorldMapLegend,
	WorldMapTooltip,
	type WorldMapTooltipPosition,
} from "./_components";

const geoUrl = "https://cdn.jsdelivr.net/npm/world-atlas@2/countries-50m.json";
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

/**
 * Create a stable map key from a country name when no numeric ISO code exists.
 */
function getCountryNameKey(name?: string | null): string | null {
	const normalizedName = name
		?.trim()
		.normalize("NFD")
		.replace(/[\u0300-\u036f]/g, "")
		.toLowerCase()
		.replace(/[^a-z0-9]/g, "");

	return normalizedName ? `name:${normalizedName}` : null;
}

/**
 * Resolve the numeric atlas identifier, falling back to its geographic name.
 */
function getFeatureCountryKey(feature: GeographyFeature): string | null {
	if (feature.id != null) {
		return String(feature.id).padStart(3, "0");
	}

	return getCountryNameKey(feature.properties?.name);
}

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
			const countryCode = item.countryCode.trim().toUpperCase();
			const countryName = item.countryName?.trim() || countryCode;
			const countryKey =
				iso31661Alpha2ToNumeric[countryCode] ||
				getCountryNameKey(countryName);

			if (countryKey) {
				acc[countryKey] = {
					countryCode,
					countryName,
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

		const countryCode = getFeatureCountryKey(feature);
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
