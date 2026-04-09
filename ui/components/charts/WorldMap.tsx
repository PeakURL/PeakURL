import type { RefObject } from 'react';
import { memo, useEffect, useState } from 'react';
import { Mercator } from '@visx/geo';
import type { GeoPermissibleObjects } from '@visx/geo/lib/types';
import { Zoom } from '@visx/zoom';
import { feature as topojsonFeature } from 'topojson-client';
import { scaleLinear } from 'd3-scale';
import { Plus, Minus, Maximize2 } from 'lucide-react';
import { __ } from '@/i18n';
import type {
	GeographyFeature,
	TooltipContent,
	WorldMapDatum,
	WorldMapProps,
} from './types';

const geoUrl = 'https://cdn.jsdelivr.net/npm/world-atlas@2/countries-110m.json';
const MAP_WIDTH = 960;
const MAP_HEIGHT = 500;
const MIN_ZOOM = 1;
const MAX_ZOOM = 4;
const INITIAL_TRANSFORM = {
	scaleX: 1,
	scaleY: 1,
	translateX: 0,
	translateY: 0,
	skewX: 0,
	skewY: 0,
};

// Country code to full name mapping (ISO 3166-1 alpha-3)
const countryNames: Record<string, string> = {
	USA: __('United States'),
	GBR: __('United Kingdom'),
	CAN: __('Canada'),
	AUS: __('Australia'),
	DEU: __('Germany'),
	FRA: __('France'),
	ITA: __('Italy'),
	ESP: __('Spain'),
	NLD: __('Netherlands'),
	BEL: __('Belgium'),
	CHE: __('Switzerland'),
	AUT: __('Austria'),
	SWE: __('Sweden'),
	NOR: __('Norway'),
	DNK: __('Denmark'),
	FIN: __('Finland'),
	POL: __('Poland'),
	CZE: __('Czech Republic'),
	HUN: __('Hungary'),
	ROU: __('Romania'),
	BGR: __('Bulgaria'),
	GRC: __('Greece'),
	PRT: __('Portugal'),
	IRL: __('Ireland'),
	JPN: __('Japan'),
	CHN: __('China'),
	IND: __('India'),
	BRA: __('Brazil'),
	MEX: __('Mexico'),
	ARG: __('Argentina'),
	ZAF: __('South Africa'),
	EGY: __('Egypt'),
	NGA: __('Nigeria'),
	KEN: __('Kenya'),
	SAU: __('Saudi Arabia'),
	ARE: __('United Arab Emirates'),
	TUR: __('Turkey'),
	RUS: __('Russia'),
	UKR: __('Ukraine'),
	KOR: __('South Korea'),
	THA: __('Thailand'),
	VNM: __('Vietnam'),
	SGP: __('Singapore'),
	MYS: __('Malaysia'),
	IDN: __('Indonesia'),
	PHL: __('Philippines'),
	NZL: __('New Zealand'),
	CHL: __('Chile'),
	COL: __('Colombia'),
	PER: __('Peru'),
	VEN: __('Venezuela'),
};

// Convert ISO 3166-1 alpha-2 to alpha-3 (common conversions)
const alpha2ToAlpha3: Record<string, string> = {
	US: 'USA',
	GB: 'GBR',
	CA: 'CAN',
	AU: 'AUS',
	DE: 'DEU',
	FR: 'FRA',
	IT: 'ITA',
	ES: 'ESP',
	NL: 'NLD',
	BE: 'BEL',
	CH: 'CHE',
	AT: 'AUT',
	SE: 'SWE',
	NO: 'NOR',
	DK: 'DNK',
	FI: 'FIN',
	PL: 'POL',
	CZ: 'CZE',
	HU: 'HUN',
	RO: 'ROU',
	BG: 'BGR',
	GR: 'GRC',
	PT: 'PRT',
	IE: 'IRL',
	JP: 'JPN',
	CN: 'CHN',
	IN: 'IND',
	BR: 'BRA',
	MX: 'MEX',
	AR: 'ARG',
	ZA: 'ZAF',
	EG: 'EGY',
	NG: 'NGA',
	KE: 'KEN',
	SA: 'SAU',
	AE: 'ARE',
	TR: 'TUR',
	RU: 'RUS',
	UA: 'UKR',
	KR: 'KOR',
	TH: 'THA',
	VN: 'VNM',
	SG: 'SGP',
	MY: 'MYS',
	ID: 'IDN',
	PH: 'PHL',
	NZ: 'NZL',
	CL: 'CHL',
	CO: 'COL',
	PE: 'PER',
	VE: 'VEN',
};

// Map alpha-3 codes to numeric codes used by world-atlas
const alpha3ToNumeric: Record<string, string> = {
	USA: '840',
	GBR: '826',
	CAN: '124',
	AUS: '036',
	DEU: '276',
	FRA: '250',
	ITA: '380',
	ESP: '724',
	NLD: '528',
	BEL: '056',
	CHE: '756',
	AUT: '040',
	SWE: '752',
	NOR: '578',
	DNK: '208',
	FIN: '246',
	POL: '616',
	CZE: '203',
	HUN: '348',
	ROU: '642',
	BGR: '100',
	GRC: '300',
	PRT: '620',
	IRL: '372',
	JPN: '392',
	CHN: '156',
	IND: '356',
	BRA: '076',
	MEX: '484',
	ARG: '032',
	ZAF: '710',
	EGY: '818',
	NGA: '566',
	KEN: '404',
	SAU: '682',
	ARE: '784',
	TUR: '792',
	RUS: '643',
	UKR: '804',
	KOR: '410',
	THA: '764',
	VNM: '704',
	SGP: '702',
	MYS: '458',
	IDN: '360',
	PHL: '608',
	NZL: '554',
	CHL: '152',
	COL: '170',
	PER: '604',
	VEN: '862',
};

const defaultCountryFill = '#e5e7eb';

/**
 * WorldMap Component
 * Displays a choropleth map showing click distribution by country
 * @param {Object} props
 * @param {Array} props.data - Array of country data objects { countryCode, countryName, clicks }
 * @param {Object} props.hoveredCountry - Currently hovered country code
 * @param {Function} props.onCountryHover - Callback when a country is hovered
 */
const WorldMap = ({
	data = [],
	hoveredCountry,
	onCountryHover,
}: WorldMapProps) => {
	const [tooltipContent, setTooltipContent] = useState<TooltipContent | null>(
		null
	);
	const [geographies, setGeographies] = useState<GeoPermissibleObjects[]>([]);
	const [loadError, setLoadError] = useState(false);

	useEffect(() => {
		const controller = new AbortController();

		const loadMap = async () => {
			try {
				setLoadError(false);

				const response = await fetch(geoUrl, {
					signal: controller.signal,
				});

				if (!response.ok) {
					throw new Error('Failed to load world map data');
				}

				const topology = await response.json();
				const countries = topology.objects?.countries;

				if (!countries) {
					throw new Error('World map data is missing countries');
				}

				const world = topojsonFeature(topology, countries) as {
					features?: GeoPermissibleObjects[];
				};
				setGeographies(world.features || []);
			} catch (error) {
				if (!(error instanceof Error) || error.name !== 'AbortError') {
					setLoadError(true);
				}
			}
		};

		loadMap();

		return () => {
			controller.abort();
		};
	}, []);

	const maxClicks =
		data.length > 0 ? Math.max(...data.map((item) => item.clicks)) : 100;

	const colorScale = scaleLinear<string>()
		.domain([0, maxClicks / 2, maxClicks])
		.range(['#e0f2fe', '#0ea5e9', '#0369a1']);

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
						countryNames[alpha3Code] ||
						alpha2Code,
					clicks: item.clicks,
				};
			}

			return acc;
		},
		{}
	);

	const activeCountryCode = (hoveredCountry || '').toString().toUpperCase();

	const handleCountryEnter = (
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
		onCountryHover?.(countryData);
	};

	const handleCountryLeave = (isDragging: boolean) => {
		if (isDragging) {
			return;
		}

		setTooltipContent(null);
		onCountryHover?.(null);
	};

	return (
		<div className="w-full h-full bg-surface rounded-lg overflow-hidden relative">
			<Zoom
				width={MAP_WIDTH}
				height={MAP_HEIGHT}
				scaleXMin={MIN_ZOOM}
				scaleXMax={MAX_ZOOM}
				scaleYMin={MIN_ZOOM}
				scaleYMax={MAX_ZOOM}
				initialTransformMatrix={INITIAL_TRANSFORM}
				wheelDelta={(event) => {
					const scale = event.deltaY > 0 ? 0.92 : 1.08;

					return {
						scaleX: scale,
						scaleY: scale,
					};
				}}
			>
				{(zoom) => (
					<>
						<div className="inset-inline-end-4 absolute top-4 z-10 flex flex-col gap-2">
							<button
								onClick={() =>
									zoom.scale({
										scaleX: 1.2,
										scaleY: 1.2,
										point: {
											x: MAP_WIDTH / 2,
											y: MAP_HEIGHT / 2,
										},
									})
								}
								disabled={
									zoom.transformMatrix.scaleX >= MAX_ZOOM
								}
								className="w-10 h-10 bg-surface rounded-lg shadow-lg border border-stroke flex items-center justify-center hover:bg-surface-alt disabled:opacity-50 disabled:cursor-not-allowed transition-all"
								title={__('Zoom in')}
							>
								<Plus className="w-5 h-5 text-heading" />
							</button>
							<button
								onClick={() =>
									zoom.scale({
										scaleX: 1 / 1.2,
										scaleY: 1 / 1.2,
										point: {
											x: MAP_WIDTH / 2,
											y: MAP_HEIGHT / 2,
										},
									})
								}
								disabled={
									zoom.transformMatrix.scaleX <= MIN_ZOOM
								}
								className="w-10 h-10 bg-surface rounded-lg shadow-lg border border-stroke flex items-center justify-center hover:bg-surface-alt disabled:opacity-50 disabled:cursor-not-allowed transition-all"
								title={__('Zoom out')}
							>
								<Minus className="w-5 h-5 text-heading" />
							</button>
							<button
								onClick={() => zoom.reset()}
								className="w-10 h-10 bg-surface rounded-lg shadow-lg border border-stroke flex items-center justify-center hover:bg-surface-alt transition-all"
								title={__('Reset view')}
							>
								<Maximize2 className="w-5 h-5 text-heading" />
							</button>
						</div>

						{tooltipContent && (
							<div className="inset-inline-start-4 text-inline-start pointer-events-none absolute top-4 z-10 rounded-lg border border-stroke bg-surface p-3 shadow-xl">
								<p className="text-sm font-semibold text-heading">
									{tooltipContent.name}
								</p>
								<p className="text-xs text-text-muted mt-1">
									{tooltipContent.clicks}{' '}
									{tooltipContent.clicks === 1
										? __('click')
										: __('clicks')}
								</p>
							</div>
						)}

						<svg
							viewBox={`0 0 ${MAP_WIDTH} ${MAP_HEIGHT}`}
							className={`w-full h-full ${
								zoom.isDragging
									? 'cursor-grabbing'
									: 'cursor-grab'
							}`}
							ref={
								zoom.containerRef as unknown as RefObject<SVGSVGElement>
							}
							style={{ touchAction: 'none' }}
							onWheel={zoom.handleWheel}
							onMouseDown={zoom.dragStart}
							onMouseMove={zoom.dragMove}
							onMouseUp={zoom.dragEnd}
							onMouseLeave={() => {
								zoom.dragEnd();
								handleCountryLeave(false);
							}}
						>
							<rect
								width={MAP_WIDTH}
								height={MAP_HEIGHT}
								fill="transparent"
							/>
							<g transform={zoom.toString()}>
								{geographies.length > 0 && (
									<Mercator
										data={geographies}
										scale={147}
										translate={[
											MAP_WIDTH / 2,
											MAP_HEIGHT / 2,
										]}
										center={[0, 20]}
									>
										{({ features }) =>
											(
												features as Array<{
													feature: GeographyFeature;
													path: string | null;
												}>
											).map(
												({ feature, path }, index) => {
													const countryCode =
														feature.id == null
															? null
															: String(
																	feature.id
																).padStart(
																	3,
																	'0'
																);
													const featureKey =
														countryCode ||
														`feature-${
															feature.properties
																?.name || index
														}`;
													const countryData =
														countryCode == null
															? null
															: countryClickMap[
																	countryCode
																];
													const clicks =
														countryData?.clicks ||
														0;
													const isActive =
														countryData?.countryCode ===
														activeCountryCode;

													return (
														<path
															key={featureKey}
															d={
																'string' ===
																typeof path
																	? path
																	: undefined
															}
															fill={
																clicks > 0
																	? colorScale(
																			clicks
																		)
																	: defaultCountryFill
															}
															stroke={
																isActive
																	? '#0f172a'
																	: '#cbd5e1'
															}
															strokeWidth={
																isActive
																	? 1.25
																	: 0.5
															}
															vectorEffect="non-scaling-stroke"
															style={{
																cursor:
																	clicks > 0
																		? 'pointer'
																		: 'default',
																transition:
																	'fill 0.2s ease-in-out, stroke 0.2s ease-in-out, stroke-width 0.2s ease-in-out',
															}}
															onMouseEnter={() =>
																handleCountryEnter(
																	countryData,
																	zoom.isDragging
																)
															}
															onMouseLeave={() =>
																handleCountryLeave(
																	zoom.isDragging
																)
															}
														/>
													);
												}
											)
										}
									</Mercator>
								)}
							</g>
						</svg>

						{loadError && (
							<div className="absolute inset-0 flex items-center justify-center bg-bg/90 text-sm font-medium text-text-muted">
								{__('Unable to load the world map right now.')}
							</div>
						)}

						{!loadError && geographies.length === 0 && (
							<div className="absolute inset-0 flex items-center justify-center bg-bg/80 text-sm font-medium text-text-muted">
								{__('Loading map...')}
							</div>
						)}

						<div className="inset-inline-start-4 text-inline-start absolute bottom-4 rounded-lg border border-stroke bg-surface p-3 shadow-lg">
							<div className="text-xs font-medium text-heading mb-2">
								{__('Clicks')}
							</div>
							<div className="flex items-center gap-2">
								<span className="text-xs text-text-muted">
									0
								</span>
								<div className="flex h-4 w-32 rounded overflow-hidden">
									<div
										className="flex-1"
										style={{ backgroundColor: '#e0f2fe' }}
									></div>
									<div
										className="flex-1"
										style={{ backgroundColor: '#7dd3fc' }}
									></div>
									<div
										className="flex-1"
										style={{ backgroundColor: '#38bdf8' }}
									></div>
									<div
										className="flex-1"
										style={{ backgroundColor: '#0ea5e9' }}
									></div>
									<div
										className="flex-1"
										style={{ backgroundColor: '#0284c7' }}
									></div>
									<div
										className="flex-1"
										style={{ backgroundColor: '#0369a1' }}
									></div>
								</div>
								<span className="text-xs text-text-muted">
									{maxClicks}
								</span>
							</div>
						</div>
					</>
				)}
			</Zoom>
		</div>
	);
};

export default memo(WorldMap);
