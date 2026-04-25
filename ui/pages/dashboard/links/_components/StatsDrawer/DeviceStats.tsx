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
import {
	AppleIcon,
	AndroidIcon,
	WindowsIcon,
	LinuxIcon,
	DefaultOSIcon,
} from "./Icons";
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

// OS icon/emoji mapping
const getOSIcon = (os: string): ReactNode => {
	const osLower = os.toLowerCase();
	if (osLower.includes("windows")) {
		return <WindowsIcon className="w-4 h-4" />;
	}
	if (osLower.includes("mac") || osLower.includes("ios")) {
		return <AppleIcon className="w-4 h-4" />;
	}
	if (osLower.includes("android")) {
		return <AndroidIcon className="w-4 h-4" />;
	}
	if (osLower.includes("linux")) {
		return <LinuxIcon className="w-4 h-4" />;
	}
	return <DefaultOSIcon className="w-4 h-4" />;
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
