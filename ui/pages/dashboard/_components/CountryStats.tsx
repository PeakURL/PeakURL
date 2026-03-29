// @ts-nocheck
const CountryStats = ({ countryData }) => {
	// Calculate total clicks for percentage
	const totalClicks = countryData.reduce(
		(sum, country) => sum + country.count,
		0
	);

	// Get country flag emoji from country code
	const getFlag = (code) => {
		if (!code || code === '??') return '🌐';
		const codePoints = code
			.toUpperCase()
			.split('')
			.map((char) => 127397 + char.charCodeAt());
		return String.fromCodePoint(...codePoints);
	};

	const formattedCountries =
		countryData.length > 0
			? countryData.slice(0, 5).map((country) => ({
					flag: getFlag(country.code),
					name: country.name || 'Unknown',
					value:
						totalClicks > 0
							? Math.round((country.count / totalClicks) * 100)
							: 0,
					count: country.count,
				}))
			: [];

	return (
		<div className="bg-surface border border-stroke rounded-lg p-5">
			<h3 className="text-base font-semibold text-heading mb-4">
				Top Countries
			</h3>
			{countryData.length === 0 ? (
				<div className="text-center py-8">
					<p className="text-sm text-text-muted">
						No country data available
					</p>
				</div>
			) : (
				<div className="space-y-4">
					{formattedCountries.map((country, index) => (
						<div
							key={`${country.name}-${index}`}
							className="flex items-center gap-3"
						>
							<span className="text-xl">{country.flag}</span>
							<div className="flex-1 min-w-0">
								<div className="flex items-center justify-between mb-2">
									<span className="text-sm font-medium text-heading truncate">
										{country.name}
									</span>
									<span className="text-sm font-semibold text-text-muted ml-2">
										{country.value}% ({country.count})
									</span>
								</div>
								<div className="w-full bg-surface-alt rounded-full h-2">
									<div
										className="bg-linear-to-r from-primary-600 to-purple-600 dark:from-primary-500 dark:to-purple-500 h-2 rounded-full transition-all duration-300"
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
