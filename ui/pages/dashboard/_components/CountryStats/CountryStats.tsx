import { useMemo, useState } from "react";

import { WorldMap, type WorldMapDatum } from "@/components";
import { __ } from "@/i18n";
import { formatCount, getCountryFlagEmoji } from "@/utils";

import type { CountryStatsProps, CountryMetric } from "../types";

function getMetricTotal(countries: CountryMetric[]): number {
	return countries.reduce((total, country) => total + country.count, 0);
}

function getMetricPercentage(count: number, total: number): number {
	return total > 0 ? Math.round((count / total) * 100) : 0;
}

const CountryStats = ({ countryData }: CountryStatsProps) => {
	const [hoveredCountry, setHoveredCountry] = useState<WorldMapDatum | null>(
		null
	);
	const totalClicks = getMetricTotal(countryData);
	const mapData = useMemo(
		() =>
			countryData
				.filter((country) => country.code)
				.map((country) => ({
					countryCode: String(country.code),
					countryName: country.name,
					clicks: country.count,
				})),
		[countryData]
	);

	const formattedCountries = useMemo(
		() =>
			countryData.length > 0
				? countryData.map((country) => ({
						flag: getCountryFlagEmoji(country.code),
						name: country.name || __("Unknown"),
						percentage: getMetricPercentage(
							country.count,
							totalClicks
						),
						count: country.count,
					}))
				: [],
		[countryData, totalClicks]
	);

	return (
		<div className="dashboard-countries">
			<h3 className="dashboard-countries-title">{__("Top Countries")}</h3>

			{countryData.length === 0 ? (
				<div className="dashboard-countries-empty">
					<p className="dashboard-countries-empty-text">
						{__("No country data available")}
					</p>
				</div>
			) : (
				<>
					<div className="dashboard-countries-map">
						<WorldMap
							data={mapData}
							hoveredCountry={hoveredCountry?.countryCode}
							onCountryHover={setHoveredCountry}
						/>
					</div>

					<div className="dashboard-countries-list">
						{formattedCountries.map((country, index: number) => (
							<div
								key={`${country.name}-${index}`}
								className="dashboard-countries-row"
							>
								<span className="dashboard-countries-row-rank">
									{index + 1}
								</span>
								<span className="dashboard-countries-row-flag">
									{country.flag}
								</span>
								<span className="dashboard-countries-row-name">
									{country.name}
								</span>
								<span className="dashboard-countries-row-percentage">
									{country.percentage}%
								</span>
								<span className="dashboard-countries-row-count">
									{formatCount(country.count)}
								</span>
							</div>
						))}
					</div>
				</>
			)}
		</div>
	);
};

export default CountryStats;
