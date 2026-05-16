import { useMemo } from "react";
import { __ } from "@/i18n";
import { formatCount } from "@/utils";
import { MetricDonutChart } from "@/components";
import type { DeviceBreakdownProps, MetricItem } from "../types";

const EMPTY_METRICS: MetricItem[] = [];
const MAX_SECONDARY_METRICS = 3;

const DEVICE_COLORS = {
	desktop: "#6366f1",
	mobile: "#0ea5e9",
	tablet: "#10b981",
	default: "#94a3b8",
};

function getMetricTotal(metrics: MetricItem[]): number {
	return metrics.reduce((total, metric) => total + metric.count, 0);
}

function getMetricPercentage(count: number, total: number): number {
	return total > 0 ? Math.round((count / total) * 100) : 0;
}

function formatMetricName(name: string): string {
	const normalizedName = name.trim();

	if (!normalizedName) {
		return __("Unknown");
	}

	return normalizedName.charAt(0).toUpperCase() + normalizedName.slice(1);
}

function getDeviceColor(name: string): string {
	const normalizedName = name.toLowerCase();

	if (normalizedName.includes("desktop")) {
		return DEVICE_COLORS.desktop;
	}

	if (normalizedName.includes("mobile")) {
		return DEVICE_COLORS.mobile;
	}

	if (normalizedName.includes("tablet")) {
		return DEVICE_COLORS.tablet;
	}

	return DEVICE_COLORS.default;
}

const DeviceBreakdown = ({ deviceData }: DeviceBreakdownProps) => {
	const devices = deviceData?.devices ?? EMPTY_METRICS;
	const browsers = deviceData?.browsers ?? EMPTY_METRICS;
	const operatingSystems = deviceData?.operatingSystems ?? EMPTY_METRICS;
	const totalDeviceClicks = getMetricTotal(devices);
	const noData =
		devices.length === 0 &&
		browsers.length === 0 &&
		operatingSystems.length === 0;

	const deviceRows = useMemo(
		() =>
			devices.map((device) => ({
				name: formatMetricName(device.name),
				count: device.count,
				percentage: getMetricPercentage(
					device.count,
					totalDeviceClicks
				),
				color: getDeviceColor(device.name),
			})),
		[devices, totalDeviceClicks]
	);

	const deviceSegments = useMemo(
		() =>
			deviceRows.map((device) => ({
				label: device.name,
				value: device.count,
				color: device.color,
			})),
		[deviceRows]
	);

	return (
		<div className="dashboard-devices">
			<h3 className="dashboard-devices-title">
				{__("Device Breakdown")}
			</h3>

			{noData ? (
				<div className="dashboard-devices-empty">
					<p className="dashboard-devices-empty-text">
						{__("No device data available")}
					</p>
				</div>
			) : (
				<div className="dashboard-devices-content">
					<div className="dashboard-devices-chart-panel">
						{devices.length > 0 ? (
							<>
								<div className="dashboard-devices-chart">
									<MetricDonutChart
										segments={deviceSegments}
										ariaLabel={__("Device Breakdown")}
										totalValue={formatCount(
											totalDeviceClicks
										)}
										totalLabel={__("Clicks")}
									/>
								</div>

								<div className="dashboard-devices-legend">
									{deviceRows.map((device) => (
										<div
											key={device.name}
											className="dashboard-devices-legend-row"
										>
											<span
												className="dashboard-devices-legend-marker"
												style={{
													backgroundColor:
														device.color,
												}}
											/>
											<span className="dashboard-devices-legend-name">
												{device.name}
											</span>
											<span className="dashboard-devices-legend-value">
												{device.percentage}%
											</span>
											<span className="dashboard-devices-legend-count">
												{formatCount(device.count)}
											</span>
										</div>
									))}
								</div>
							</>
						) : (
							<div className="dashboard-devices-chart-empty">
								{__("No device data available")}
							</div>
						)}
					</div>

					<div className="dashboard-devices-details">
						{browsers.length > 0 && (
							<div className="dashboard-devices-section">
								<h4 className="dashboard-devices-section-title">
									{__("Top Browsers")}
								</h4>

								<div className="dashboard-devices-section-list">
									{browsers
										.slice(0, MAX_SECONDARY_METRICS)
										.map((browser) => (
											<div
												key={browser.name}
												className="dashboard-devices-section-row"
											>
												<span className="dashboard-devices-section-label">
													{formatMetricName(
														browser.name
													)}
												</span>
												<span className="dashboard-devices-section-count">
													{formatCount(browser.count)}
												</span>
											</div>
										))}
								</div>
							</div>
						)}

						{operatingSystems.length > 0 && (
							<div className="dashboard-devices-section">
								<h4 className="dashboard-devices-section-title">
									{__("Top Operating Systems")}
								</h4>

								<div className="dashboard-devices-section-list">
									{operatingSystems
										.slice(0, MAX_SECONDARY_METRICS)
										.map((os: MetricItem) => (
											<div
												key={os.name}
												className="dashboard-devices-section-row"
											>
												<span className="dashboard-devices-section-label">
													{formatMetricName(os.name)}
												</span>
												<span className="dashboard-devices-section-count">
													{formatCount(os.count)}
												</span>
											</div>
										))}
								</div>
							</div>
						)}
					</div>
				</div>
			)}
		</div>
	);
};

export default DeviceBreakdown;
