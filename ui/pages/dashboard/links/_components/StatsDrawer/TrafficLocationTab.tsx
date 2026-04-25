import { useState } from "react";
import { Globe, MapPin } from "lucide-react";
import { WorldMap } from "@/components";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { useGetLinkLocationQuery } from "@/store/slices/api";
import { getErrorMessage } from "@/utils";
import type { HoveredCountry, TrafficLocationTabProps } from "./types";
import { LocalIcon, UnknownLocationIcon } from "./Icons";

interface LocationNoteItemProps {
	text: string;
	example?: string;
	direction: "rtl" | "ltr";
}

function LocationNoteItem({ text, example, direction }: LocationNoteItemProps) {
	return (
		<div dir={direction} className="links-location-note-item">
			<span className="links-location-note-bullet" />
			<div className="links-location-note-content">
				<p dir={direction}>{text}</p>
				{example ? (
					<code
						dir="ltr"
						className="links-location-note-code preserve-ltr-value"
					>
						{example}
					</code>
				) : null}
			</div>
		</div>
	);
}

function TrafficLocationTab({
	link,
	selectedTab,
	open,
}: TrafficLocationTabProps) {
	const direction = isDocumentRtl() ? "rtl" : "ltr";
	const [hoveredCountry, setHoveredCountry] = useState<HoveredCountry | null>(
		null
	);
	// RTK Query hook
	const shouldFetch = open && selectedTab === 1 && !!link?.id;
	const { data, isLoading, isError, error } = useGetLinkLocationQuery(
		link?.id || "",
		{ skip: !shouldFetch }
	);

	// Add safety check
	if (!link) {
		return (
			<div className="links-location-tab">
				<div className="links-drawer-section">
					<h3 className="links-drawer-section-title mb-4">
						{__("Top Countries")}
					</h3>
					<div className="links-drawer-empty-panel links-drawer-empty-panel-large">
						<div className="text-center">
							<Globe className="links-drawer-empty-icon" />
							<p className="links-drawer-empty-copy">
								{__("No link data available")}
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
	const getFlagEmoji = (countryCode?: string | null) => {
		if (countryCode === "LOCAL") {
			return <LocalIcon className="w-6 h-6" />;
		}
		if (!countryCode || countryCode === "??") {
			return <UnknownLocationIcon className="w-6 h-6" />;
		}
		const codePoints = countryCode
			.toUpperCase()
			.split("")
			.map((char: string) => 127397 + char.charCodeAt(0));
		return (
			<span className="text-2xl">
				{String.fromCodePoint(...codePoints)}
			</span>
		);
	};

	// Calculate percentage
	const getPercentage = (count: number): string | number => {
		return total > 0 ? ((count / total) * 100).toFixed(1) : 0;
	};

	if (isLoading) {
		return (
			<div className="links-location-tab">
				<div className="links-drawer-section animate-pulse">
					<h3 className="links-drawer-section-title mb-4">
						{__("Top Countries")}
					</h3>
					<div className="links-drawer-empty-panel links-drawer-empty-panel-large">
						<div className="links-drawer-empty-content">
							<Globe className="links-drawer-empty-icon-spaced" />
							<p className="links-drawer-empty-title">
								{__("Loading location data...")}
							</p>
						</div>
					</div>
				</div>
			</div>
		);
	}

	if (isError) {
		return (
			<div className="links-location-tab">
				<div className="links-drawer-section">
					<h3 className="links-drawer-section-title mb-4">
						{__("Top Countries")}
					</h3>
					<div className="links-drawer-empty-panel links-drawer-empty-panel-large">
						<div className="links-drawer-empty-content">
							<Globe className="mx-auto mb-3 h-12 w-12 text-error" />
							<p className="links-drawer-empty-title">
								{__("Failed to load location data")}
							</p>
							<p className="links-drawer-empty-copy-small">
								{getErrorMessage(error, __("Unknown error"))}
							</p>
						</div>
					</div>
				</div>
			</div>
		);
	}

	if (!hasData) {
		return (
			<div className="links-location-tab">
				{/* Top Countries Section */}
				<div className="links-drawer-section">
					<h3 className="links-drawer-section-title mb-4">
						{__("Top Countries")}
					</h3>
					<div className="links-location-empty-map">
						<div className="links-location-empty-map-inner">
							<Globe className="links-drawer-empty-icon-spaced" />
							<p className="links-drawer-empty-title">
								{__("No location data available yet")}
							</p>
							<p className="mx-auto max-w-md text-xs text-text-muted">
								{__(
									"Location tracking will show here once clicks are recorded with a configured GeoLite2 City database"
								)}
							</p>
							<div
								dir={direction}
								className="links-location-note"
							>
								<p
									dir={direction}
									className="links-location-note-title"
								>
									{__("Note:")}
								</p>
								<div className="links-location-note-list">
									<LocationNoteItem
										direction={direction}
										text={__(
											"Local and private-network clicks do not include location data."
										)}
										example="127.0.0.1, 172.16-31.x.x, 192.168.x.x"
									/>
									<LocationNoteItem
										direction={direction}
										text={__(
											"Store the GeoLite2 City database here:"
										)}
										example="content/uploads/geoip/GeoLite2-City.mmdb"
									/>
									<LocationNoteItem
										direction={direction}
										text={__(
											"VPN users may show the location of the VPN server."
										)}
									/>
								</div>
							</div>
						</div>
					</div>
				</div>

				{/* Top Cities Section */}
				<div className="links-drawer-section">
					<h3 className="links-drawer-section-title mb-4">
						{__("Top Cities")}
					</h3>
					<div className="links-drawer-empty-panel links-drawer-empty-panel-medium">
						<div className="text-center">
							<MapPin className="mx-auto mb-2 h-10 w-10 text-text-muted" />
							<p className="links-drawer-empty-copy">
								{__("No city data available yet")}
							</p>
						</div>
					</div>
				</div>
			</div>
		);
	}

	return (
		<div className="links-location-tab">
			{/* Summary Card */}
			<div className="links-drawer-summary-alt">
				<div className="links-drawer-summary-inner">
					<div className="links-drawer-summary-main">
						<div className="links-drawer-summary-icon">
							<Globe className="w-5 h-5 text-accent" />
						</div>
						<div>
							<p className="links-drawer-summary-label">
								{__("Total Locations")}
							</p>
							<p className="links-drawer-summary-value">
								{total} {__("clicks")}
							</p>
						</div>
					</div>
					<div className="links-drawer-summary-meta">
						<p className="links-drawer-summary-label">
							{__("Countries")}
						</p>
						<p className="links-drawer-summary-value">
							{countries.length}
						</p>
					</div>
				</div>
			</div>

			{/* World Map */}
			<div className="links-drawer-section">
				<div className="links-drawer-section-header">
					<Globe className="links-drawer-section-icon" />
					<h3 className="links-drawer-section-title">
						{__("Geographic Distribution")}
					</h3>
				</div>
				<div className="links-location-map-wrap">
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
						<div className="links-location-tooltip">
							<p className="links-location-tooltip-title">
								{hoveredCountry.countryName}
							</p>
							<p className="links-location-tooltip-copy">
								{hoveredCountry.clicks} {__("clicks")} (
								{getPercentage(hoveredCountry.clicks)}%)
							</p>
						</div>
					)}
				</div>
			</div>

			{/* Top Countries */}
			<div className="links-drawer-section">
				<div className="links-drawer-section-header">
					<Globe className="links-drawer-section-icon" />
					<h3 className="links-drawer-section-title">
						{__("Top Countries")}
					</h3>
				</div>
				<div className="links-location-list">
					{countries.map((country) => {
						const percentage = getPercentage(country.count);
						return (
							<div
								key={`${country.code}-${country.name}`}
								className="links-location-list-item"
							>
								<div className="links-location-list-row">
									<div className="links-location-list-main">
										{getFlagEmoji(country.code)}
										<span className="text-sm font-medium text-heading">
											{country.name}
										</span>
										<span className="text-xs text-text-muted">
											({country.code})
										</span>
									</div>
									<div className="links-location-list-meta">
										<span className="text-sm text-text-muted">
											{percentage}%
										</span>
										<span className="links-location-list-count">
											{country.count} {__("clicks")}
										</span>
									</div>
								</div>
								<div className="links-drawer-bar-track">
									<div
										className="links-drawer-bar-fill bg-primary-600"
										style={{ width: `${percentage}%` }}
									></div>
								</div>
							</div>
						);
					})}
				</div>
			</div>

			{/* Top Cities */}
			<div className="links-drawer-section">
				<div className="links-drawer-section-header">
					<MapPin className="links-drawer-section-icon" />
					<h3 className="links-drawer-section-title">
						{__("Top Cities")}
					</h3>
				</div>
				<div className="links-location-city-list">
					{cities.map((city, index) => {
						const percentage = getPercentage(city.count);
						return (
							<div
								key={`${city.name}-${city.country}`}
								className="links-location-city-item"
							>
								<div className="links-location-city-main">
									<div className="links-location-city-rank">
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
								<div className="links-location-city-meta">
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
