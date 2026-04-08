import { useState } from 'react';
import { Globe, MapPin } from 'lucide-react';
import { WorldMap } from '@/components';
import { __ } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import { useGetLinkLocationQuery } from '@/store/slices/api';
import { getErrorMessage } from '@/utils';
import type { HoveredCountry, TrafficLocationTabProps } from './types';

function TrafficLocationTab({
	link,
	selectedTab,
	open,
}: TrafficLocationTabProps) {
	const isRtl = isDocumentRtl();
	const [hoveredCountry, setHoveredCountry] = useState<HoveredCountry | null>(
		null
	);
	// RTK Query hook
	const shouldFetch = open && selectedTab === 1 && !!link?.id;
	const { data, isLoading, isError, error } = useGetLinkLocationQuery(
		link?.id || '',
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
	const getFlagEmoji = (countryCode?: string | null): string => {
		if (countryCode === 'LOCAL') return '🏠';
		if (!countryCode || countryCode === '??') return '🌍';
		const codePoints = countryCode
			.toUpperCase()
			.split('')
			.map((char: string) => 127397 + char.charCodeAt(0));
		return String.fromCodePoint(...codePoints);
	};

	// Calculate percentage
	const getPercentage = (count: number): string | number => {
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
								{getErrorMessage(error, __('Unknown error'))}
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
					<div className="min-h-[22rem] bg-surface rounded-lg border border-stroke px-4 py-6 sm:px-6">
						<div className="mx-auto flex min-h-full w-full max-w-xl flex-col items-center justify-center text-center">
							<Globe className="w-12 h-12 text-text-muted mx-auto mb-3" />
							<p className="text-sm font-medium text-heading mb-2">
								{__('No location data available yet')}
							</p>
							<p className="text-xs text-text-muted max-w-md mx-auto">
								{__(
									'Location tracking will show here once clicks are recorded with a configured GeoLite2 City database'
								)}
							</p>
							<div className="mx-auto mt-4 w-full max-w-lg rounded-lg border border-info/20 bg-info/5 px-4 py-3">
								<p
									dir="auto"
									className="text-xs font-medium text-heading"
									style={{ textAlign: 'start' }}
								>
									{__('Note:')}
								</p>
								<div className="mt-2 space-y-1.5 text-xs leading-5 text-text-muted">
									<div
										dir={isRtl ? 'rtl' : 'ltr'}
										className="flex items-start gap-2"
									>
										<span className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-info/60" />
										<div
											className="min-w-0 flex-1 space-y-1"
											style={{ textAlign: 'start' }}
										>
											<p dir="auto">
												{__(
													'Local and private-network clicks do not include location data.'
												)}
											</p>
											<code
												dir="ltr"
												className="block w-fit max-w-full rounded bg-surface px-1.5 py-0.5 font-mono text-[11px] text-heading break-all"
												style={{ textAlign: 'left' }}
											>
												127.0.0.1, 172.16-31.x.x, 192.168.x.x
											</code>
										</div>
									</div>
									<div
										dir={isRtl ? 'rtl' : 'ltr'}
										className="flex items-start gap-2"
									>
										<span className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-info/60" />
										<div
											className="min-w-0 flex-1 space-y-1"
											style={{ textAlign: 'start' }}
										>
											<p dir="auto">
												{__(
													'Store the GeoLite2 City database here:'
												)}
											</p>
											<code
												dir="ltr"
												className="block w-fit max-w-full rounded bg-surface px-1.5 py-0.5 font-mono text-[11px] text-heading break-all"
												style={{ textAlign: 'left' }}
											>
												content/uploads/geoip/GeoLite2-City.mmdb
											</code>
										</div>
									</div>
									<div
										dir={isRtl ? 'rtl' : 'ltr'}
										className="flex items-start gap-2"
									>
										<span className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-info/60" />
										<div
											dir="auto"
											className="min-w-0 flex-1"
											style={{ textAlign: 'start' }}
										>
											{__(
												'VPN users may show the location of the VPN server.'
											)}
										</div>
									</div>
								</div>
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
					<div className={isRtl ? 'text-left' : 'text-right'}>
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
						data={countries.map((country) => ({
							countryCode: country.code,
							countryName: country.name,
							clicks: country.count,
						}))}
						hoveredCountry={hoveredCountry?.countryCode}
						onCountryHover={(country) =>
							setHoveredCountry(
								country
									? {
											countryCode: country.countryCode,
											countryName:
												country.countryName ||
												country.countryCode,
											clicks: country.clicks,
										}
									: null
							)
						}
					/>
					{hoveredCountry && (
						<div
							className={`absolute top-4 min-w-32 rounded-lg border border-stroke bg-surface p-3 shadow-xl ${
								isRtl ? 'left-4 text-left' : 'right-4 text-right'
							}`}
						>
							<p className="text-sm font-semibold text-heading">
								{hoveredCountry.countryName}
							</p>
							<p className="text-xs text-text-muted mt-1">
								{hoveredCountry.clicks} {__('clicks')} (
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
					{countries.map((country) => {
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
										<span
											className={`min-w-12 text-sm font-semibold text-heading ${
												isRtl ? 'text-left' : 'text-right'
											}`}
										>
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
								<div className={isRtl ? 'text-left' : 'text-right'}>
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
