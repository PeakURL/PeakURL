import type { ReactNode } from "react";
import {
	Compass,
	Flame,
	Globe,
	Laptop,
	Monitor,
	Orbit,
	Smartphone,
	Tablet,
} from "lucide-react";
import { __, _n } from "@/i18n";
import { useTheme } from "@/components";
import type {
	BrowserIconProps,
	DeviceStatsProps,
	StatsMetricItem,
} from "./types";

// Browser icon mapping
const getBrowserIcon = (browser: string) => {
	const browserLower = browser.toLowerCase();
	if (browserLower.includes("chrome")) return "chrome";
	if (browserLower.includes("safari")) return "safari";
	if (browserLower.includes("firefox")) return "firefox";
	if (browserLower.includes("edge")) return "edge";
	return "globe";
};

const BrowserIcon = ({ browser, className }: BrowserIconProps) => {
	const iconType = getBrowserIcon(browser);

	if (iconType === "chrome") {
		return <Globe className={className} />;
	}
	if (iconType === "safari") {
		return <Compass className={className} />;
	}
	if (iconType === "firefox") {
		return <Flame className={className} />;
	}
	if (iconType === "edge") {
		return <Orbit className={className} />;
	}
	return <Globe className={className} />;
};

// Device icon mapping
const getDeviceIcon = (deviceType: string) => {
	const typeLower = deviceType.toLowerCase();
	if (typeLower.includes("mobile")) return Smartphone;
	if (typeLower.includes("tablet")) return Tablet;
	return Monitor;
};

const AppleIcon = ({ className }: { className?: string }) => {
	const { theme } = useTheme();
	const isDark = theme === "dark";

	return (
		<svg
			viewBox="0 0 41.5 51"
			className={className}
			fill={isDark ? "#FFFFFF" : "#000000"}
			xmlns="http://www.w3.org/2000/svg"
		>
			<path d="M40.2,17.4c-3.4,2.1-5.5,5.7-5.5,9.7c0,4.5,2.7,8.6,6.8,10.3c-0.8,2.6-2,5-3.5,7.2c-2.2,3.1-4.5,6.3-7.9,6.3s-4.4-2-8.4-2 c-3.9,0-5.3,2.1-8.5,2.1s-5.4-2.9-7.9-6.5C2,39.5,0.1,33.7,0,27.6c0-9.9,6.4-15.2,12.8-15.2c3.4,0,6.2,2.2,8.3,2.2 c2,0,5.2-2.3,9-2.3C34.1,12.2,37.9,14.1,40.2,17.4z M28.3,8.1C30,6.1,30.9,3.6,31,1c0-0.3,0-0.7-0.1-1c-2.9,0.3-5.6,1.7-7.5,3.9 c-1.7,1.9-2.7,4.3-2.8,6.9c0,0.3,0,0.6,0.1,0.9c0.2,0,0.5,0.1,0.7,0.1C24.1,11.6,26.6,10.2,28.3,8.1z" />
		</svg>
	);
};

// OS icon/emoji mapping
const getOSIcon = (os: string): ReactNode => {
	const osLower = os.toLowerCase();
	if (osLower.includes("windows")) return "🪟";
	if (osLower.includes("mac") || osLower.includes("ios")) {
		return <AppleIcon className="w-4 h-4" />;
	}
	if (osLower.includes("android")) return "🤖";
	if (osLower.includes("linux")) return "🐧";
	return "💻";
};

function DeviceStats({
	devices = [],
	browsers = [],
	os: oses = [],
	isLoading,
}: DeviceStatsProps) {
	if (isLoading) {
		return (
			<div className="links-device-section animate-pulse">
				<h3 className="links-drawer-section-title mb-4">
					{__("Device & Browser Analytics")}
				</h3>
				<div className="links-device-empty">
					<p className="links-device-empty-copy">
						{__("Loading analytics...")}
					</p>
				</div>
			</div>
		);
	}

	const hasData =
		devices.length > 0 || browsers.length > 0 || oses.length > 0;

	if (!hasData) {
		return (
			<div className="links-device-section">
				<h3 className="links-drawer-section-title mb-4">
					{__("Device & Browser Analytics")}
				</h3>
				<div className="links-device-empty">
					<div className="links-device-empty-content">
						<Monitor className="links-drawer-empty-icon-spaced" />
						<p className="links-device-empty-title">
							{__("No device data available yet")}
						</p>
						<p className="links-device-empty-copy-small">
							{__(
								"Device and browser information will appear here once clicks are recorded"
							)}
						</p>
					</div>
				</div>
			</div>
		);
	}

	// Calculate total based on devices (assuming consistent data)
	const total = devices.reduce(
		(sum: number, item: StatsMetricItem) => sum + item.count,
		0
	);

	// Helper to calculate percentage
	const formatData = (items: StatsMetricItem[]) => {
		return items.map((item) => ({
			...item,
			percentage:
				total > 0 ? ((item.count / total) * 100).toFixed(1) : "0.0",
		}));
	};

	const displayDevices = formatData(devices);
	const displayBrowsers = formatData(browsers);
	const displayOses = formatData(oses);

	return (
		<div className="links-device-stats">
			{/* Summary Card */}
			<div className="links-device-summary">
				<div className="links-drawer-summary-inner">
					<div className="links-drawer-summary-main">
						<div className="links-drawer-summary-icon">
							<Monitor className="w-5 h-5 text-accent" />
						</div>
						<div>
							<p className="links-drawer-summary-label">
								{__("Total Devices")}
							</p>
							<p className="links-drawer-summary-value">
								{total}
							</p>
						</div>
					</div>
					<div className="links-drawer-summary-meta">
						<p className="links-drawer-summary-label">
							{__("Unique Browsers")}
						</p>
						<p className="links-drawer-summary-value">
							{displayBrowsers.length}
						</p>
					</div>
				</div>
			</div>

			{/* Browsers */}
			<div className="links-device-section">
				<div className="links-drawer-section-header">
					<Globe className="links-drawer-section-icon" />
					<h3 className="links-drawer-section-title">
						{__("Browsers")}
					</h3>
				</div>
				<div className="links-device-list">
					{displayBrowsers.slice(0, 5).map(
						(
							browser: StatsMetricItem & {
								percentage: string;
							}
						) => {
							return (
								<div
									key={browser.name}
									className="links-device-list-row"
								>
									<div className="links-device-list-row-header">
										<div className="links-device-list-main">
											<BrowserIcon
												browser={browser.name}
												className="w-4 h-4 text-accent"
											/>
											<span className="text-sm font-medium text-heading">
												{browser.name}
											</span>
										</div>
										<div className="links-device-list-meta">
											<span className="links-device-list-percentage">
												{browser.percentage}%
											</span>
											<span className="links-device-list-count">
												{browser.count}
											</span>
										</div>
									</div>
									<div className="links-drawer-bar-track">
										<div
											className="links-drawer-bar-fill bg-accent"
											style={{
												width: `${browser.percentage}%`,
											}}
										></div>
									</div>
								</div>
							);
						}
					)}
				</div>
			</div>

			{/* Device Types */}
			<div className="links-device-section">
				<div className="links-drawer-section-header">
					<Monitor className="links-drawer-section-icon" />
					<h3 className="links-drawer-section-title">
						{__("Device Types")}
					</h3>
				</div>
				<div className="links-device-types">
					{displayDevices.map(
						(device: StatsMetricItem & { percentage: string }) => {
							const DeviceIcon = getDeviceIcon(device.name);
							return (
								<div
									key={device.name}
									className="links-device-type-card"
								>
									<div className="links-device-type-main">
										<div className="links-device-type-icon">
											<DeviceIcon className="w-5 h-5 text-accent" />
										</div>
										<div>
											<p className="text-sm font-medium text-heading">
												{device.name}
											</p>
											<p className="text-xs text-text-muted">
												{device.count}{" "}
												{_n(
													"device",
													"devices",
													device.count
												)}
											</p>
										</div>
									</div>
									<div className="links-device-type-value">
										<p className="text-lg font-bold text-heading">
											{device.percentage}%
										</p>
									</div>
								</div>
							);
						}
					)}
				</div>
			</div>

			{/* Operating Systems */}
			<div className="links-device-section">
				<div className="links-drawer-section-header">
					<Laptop className="links-drawer-section-icon" />
					<h3 className="links-drawer-section-title">
						{__("Operating Systems")}
					</h3>
				</div>
				<div className="links-device-os-list">
					{displayOses.map(
						(os: StatsMetricItem & { percentage: string }) => (
							<div key={os.name} className="links-device-os-item">
								<div className="links-device-os-main">
									<div className="links-device-os-icon">
										{getOSIcon(os.name)}
									</div>
									<div>
										<p className="text-sm font-medium text-heading">
											{os.name}
										</p>
									</div>
								</div>
								<div className="links-device-os-stats">
									<span className="links-device-list-percentage">
										{os.percentage}%
									</span>
									<span className="links-device-os-count">
										{os.count}
									</span>
								</div>
							</div>
						)
					)}
				</div>
			</div>
		</div>
	);
}

export default DeviceStats;
