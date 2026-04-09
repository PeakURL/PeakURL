import { __ } from '@/i18n';
import type { CountryStatsProps, CountryMetric } from './types';

const CountryStats = ({ countryData }: CountryStatsProps) => {
	// Calculate total clicks for percentage
	const totalClicks = countryData.reduce(
		(sum: number, country: CountryMetric) => sum + country.count,
		0
	);

	// Get country flag emoji from country code
	const getFlag = (code?: string | null) => {
		if (!code || code === '??') return '🌐';
		const codePoints = code
			.toUpperCase()
			.split('')
			.map((char: string) => 127397 + char.charCodeAt(0));
		return String.fromCodePoint(...codePoints);
	};

	const formattedCountries =
		countryData.length > 0
			? countryData.slice(0, 5).map((country: CountryMetric) => ({
					flag: getFlag(country.code),
					name: country.name || __('Unknown'),
					value:
						totalClicks > 0
							? Math.round((country.count / totalClicks) * 100)
							: 0,
					count: country.count,
				}))
			: [];

	return (
		<div className="dashboard-countries">
			<h3 className="dashboard-countries-title">
				{__('Top Countries')}
			</h3>

			{countryData.length === 0 ? (
				<div className="dashboard-countries-empty">
					<p className="dashboard-countries-empty-text">
						{__('No country data available')}
					</p>
				</div>
			) : (
				<div className="dashboard-countries-list">
					{formattedCountries.map((country, index: number) => (
						<div
							key={`${country.name}-${index}`}
							className="dashboard-countries-row"
						>
							<span className="dashboard-countries-row-flag">
								{country.flag}
							</span>
							<div className="dashboard-countries-row-copy">
								<div className="dashboard-countries-row-header">
									<span className="dashboard-countries-row-name">
										{country.name}
									</span>
									<span className="dashboard-countries-row-value">
										{country.value}% ({country.count})
									</span>
								</div>

								<div className="dashboard-countries-row-track">
									<div
										className="dashboard-countries-row-bar"
										style={{ width: `${country.value}%` }}
									></div>
								</div>
							</div>
						</div>
					))}
				</div>
			)}
		</div>
	);
};

export default CountryStats;
