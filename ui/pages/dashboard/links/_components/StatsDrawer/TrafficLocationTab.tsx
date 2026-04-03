// @ts-nocheck

import { useState } from 'react';
import { Globe, MapPin, Users } from 'lucide-react';
import { WorldMap } from '@/components';
import { __ } from '@/i18n';
import { useGetLinkLocationQuery } from '@/store/slices/api/analytics';

function TrafficLocationTab({ link, selectedTab, open }) {
	const [hoveredCountry, setHoveredCountry] = useState(null);
	// RTK Query hook
	const shouldFetch = open && selectedTab === 1 && !!link?.id;
	const { data, isLoading, isError, error } = useGetLinkLocationQuery(
		link?.id,
		{ skip: !shouldFetch }
	);

	// Add safety check
	if (!link) {
		return (
			<div className="space-y-4">
				<div className="bg-surface-alt border border-stroke rounded-lg p-4">
					<h3 className="text-sm font-semibold text-heading mb-4">
						{__('Top Countries')}
					</h3>
					<div className="h-64 flex items-center justify-center bg-surface rounded-lg border border-stroke">
						<div className="text-center">
							<Globe className="w-12 h-12 text-text-muted mx-auto mb-2" />
							<p className="text-sm text-text-muted">
								{__('No link data available')}
							</p>
						</div>
					</div>
				</div>
			</div>
		);
	}

	const payload = data?.data || {};
	const countries = (payload.countries || []).slice(0, 5);
	const cities = payload.cities || [];
	const total = payload.totalClicks || 0;
	const hasData = total > 0;

	// Country flag emoji helper
	const getFlagEmoji = (countryCode) => {
		if (countryCode === 'LOCAL') return '🏠';
		if (!countryCode || countryCode === '??') return '🌍';
		const codePoints = countryCode
			.toUpperCase()
			.split('')
			.map((char) => 127397 + char.charCodeAt());
		return String.fromCodePoint(...codePoints);
	};

	// Calculate percentage
	const getPercentage = (count) => {
		return total > 0 ? ((count / total) * 100).toFixed(1) : 0;
	};

	if (isLoading) {
		return (
			<div className="space-y-4">
				<div className="bg-surface-alt border border-stroke rounded-lg p-4 animate-pulse">
					<h3 className="text-sm font-semibold text-heading mb-4">
						{__('Top Countries')}
					</h3>
					<div className="h-64 flex items-center justify-center bg-surface rounded-lg border border-stroke">
						<div className="text-center px-6">
							<Globe className="w-12 h-12 text-text-muted mx-auto mb-3" />
							<p className="text-sm font-medium text-heading mb-2">
								{__('Loading location data...')}
							</p>
						</div>
					</div>
				</div>
			</div>
		);
	}

	if (isError) {
		return (
			<div className="space-y-4">
				<div className="bg-surface-alt border border-stroke rounded-lg p-4">
					<h3 className="text-sm font-semibold text-heading mb-4">
						{__('Top Countries')}
					</h3>
					<div className="h-64 flex items-center justify-center bg-surface rounded-lg border border-stroke">
						<div className="text-center px-6">
							<Globe className="w-12 h-12 text-error mx-auto mb-3" />
							<p className="text-sm font-medium text-heading mb-2">
								{__('Failed to load location data')}
							</p>
							<p className="text-xs text-text-muted">
								{String(error?.message || __('Unknown error'))}
							</p>
						</div>
					</div>
				</div>
			</div>
		);
	}

	if (!hasData) {
		return (
			<div className="space-y-4">
				{/* Top Countries Section */}
				<div className="bg-surface-alt border border-stroke rounded-lg p-4">
					<h3 className="text-sm font-semibold text-heading mb-4">
						{__('Top Countries')}
					</h3>
					<div className="h-64 flex items-center justify-center bg-surface rounded-lg border border-stroke">
						<div className="text-center px-6">
							<Globe className="w-12 h-12 text-text-muted mx-auto mb-3" />
							<p className="text-sm font-medium text-heading mb-2">
								{__('No location data available yet')}
							</p>
							<p className="text-xs text-text-muted max-w-md mx-auto">
								{__(
									'Location tracking will show here once clicks are recorded with a configured GeoLite2 City database'
								)}
							</p>
							<div className="mt-4 p-3 bg-info/5 border border-info/20 rounded-lg text-left">
								<p className="text-xs font-medium text-heading mb-1">
									{__('Note:')}
								</p>
								<p className="text-xs text-text-muted">
									{__(
										'• Local and private-network clicks (127.0.0.1, 172.16-31.x.x, 192.168.x.x) will not show location data'
									)}
									<br />
									{__(
										'• Add `GeoLite2-City.mmdb` under `content/uploads/geoip/`'
									)}
									<br />
									{__('• VPN users will show VPN server location')}
								</p>
							</div>
						</div>
					</div>
				</div>

				{/* Top Cities Section */}
				<div className="bg-surface-alt border border-stroke rounded-lg p-4">
					<h3 className="text-sm font-semibold text-heading mb-4">
						{__('Top Cities')}
					</h3>
					<div className="h-48 flex items-center justify-center bg-surface rounded-lg border border-stroke">
						<div className="text-center">
							<MapPin className="w-10 h-10 text-text-muted mx-auto mb-2" />
							<p className="text-sm text-text-muted">
								{__('No city data available yet')}
							</p>
						</div>
					</div>
				</div>
			</div>
		);
	}

	return (
		<div className="space-y-4">
			{/* Summary Card */}
			<div className="bg-linear-to-br from-blue-500/5 to-green-500/5 border border-info/20 rounded-lg p-4">
				<div className="flex items-center justify-between">
					<div className="flex items-center gap-3">
						<div className="w-10 h-10 rounded-lg bg-accent/10 flex items-center justify-center">
							<Globe className="w-5 h-5 text-accent" />
						</div>
						<div>
							<p className="text-sm text-text-muted">
								{__('Total Locations')}
							</p>
							<p className="text-xl font-bold text-heading">
								{total} {__('clicks')}
							</p>
						</div>
					</div>
					<div className="text-right">
						<p className="text-sm text-text-muted">
							{__('Countries')}
						</p>
						<p className="text-xl font-bold text-heading">
							{countries.length}
						</p>
					</div>
				</div>
			</div>

			{/* World Map */}
			<div className="bg-surface-alt border border-stroke rounded-lg p-4">
				<div className="flex items-center gap-2 mb-4">
					<Globe className="w-4 h-4 text-accent" />
					<h3 className="text-sm font-semibold text-heading">
						{__('Geographic Distribution')}
					</h3>
				</div>
				<div className="relative h-96 rounded-lg overflow-hidden border border-stroke">
					<WorldMap
						data={countries.map((c) => ({
							countryCode: c.code,
							countryName: c.name,
							clicks: c.count,
						}))}
						hoveredCountry={hoveredCountry?.countryCode}
						onCountryHover={setHoveredCountry}
					/>
					{hoveredCountry && (
						<div className="absolute top-4 right-4 bg-surface rounded-lg shadow-xl p-3 border border-stroke min-w-32">
							<p className="text-sm font-semibold text-heading">
								{hoveredCountry.countryName}
							</p>
							<p className="text-xs text-text-muted mt-1">
								{hoveredCountry.clicks} clicks (
								{getPercentage(hoveredCountry.clicks)}%)
							</p>
						</div>
					)}
				</div>
			</div>

			{/* Top Countries */}
			<div className="bg-surface-alt border border-stroke rounded-lg p-4">
				<div className="flex items-center gap-2 mb-4">
					<Globe className="w-4 h-4 text-accent" />
					<h3 className="text-sm font-semibold text-heading">
						{__('Top Countries')}
					</h3>
				</div>
				<div className="space-y-3">
					{countries.map((country, index) => {
						const percentage = getPercentage(country.count);
						return (
							<div
								key={`${country.code}-${country.name}`}
								className="space-y-1.5"
							>
								<div className="flex items-center justify-between">
									<div className="flex items-center gap-2">
										<span className="text-2xl">
											{getFlagEmoji(country.code)}
										</span>
										<span className="text-sm font-medium text-heading">
											{country.name}
										</span>
										<span className="text-xs text-text-muted">
											({country.code})
										</span>
									</div>
									<div className="flex items-center gap-3">
										<span className="text-sm text-text-muted">
											{percentage}%
										</span>
										<span className="text-sm font-semibold text-heading min-w-12 text-right">
											{country.count} {__('clicks')}
										</span>
									</div>
								</div>
								<div className="w-full bg-surface rounded-full h-2 overflow-hidden">
									<div
										className="bg-primary-600 h-full rounded-full transition-all duration-500"
										style={{ width: `${percentage}%` }}
									></div>
								</div>
							</div>
						);
					})}
				</div>
			</div>

			{/* Top Cities */}
			<div className="bg-surface-alt border border-stroke rounded-lg p-4">
				<div className="flex items-center gap-2 mb-4">
					<MapPin className="w-4 h-4 text-accent" />
					<h3 className="text-sm font-semibold text-heading">
						{__('Top Cities')}
					</h3>
				</div>
				<div className="space-y-2">
					{cities.map((city, index) => {
						const percentage = getPercentage(city.count);
						return (
							<div
								key={`${city.name}-${city.country}`}
								className="flex items-center justify-between py-2 px-3 rounded-lg hover:bg-surface transition-all"
							>
								<div className="flex items-center gap-3">
									<div className="w-8 h-8 rounded-full bg-primary-500/10 flex items-center justify-center text-xs font-semibold text-accent">
										#{index + 1}
									</div>
									<div>
										<p className="text-sm font-medium text-heading">
											{city.name}
										</p>
										<p className="text-xs text-text-muted">
											{city.country}
										</p>
									</div>
								</div>
								<div className="text-right">
									<p className="text-sm font-semibold text-heading">
										{city.count}
									</p>
									<p className="text-xs text-text-muted">
										{percentage}%
									</p>
								</div>
							</div>
						);
					})}
				</div>
			</div>
		</div>
	);
}

export default TrafficLocationTab;
