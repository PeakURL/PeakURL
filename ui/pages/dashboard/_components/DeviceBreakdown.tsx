import { __ } from '@/i18n';
import { cn } from '@/utils';
import type {
	DeviceBreakdownProps,
	DeviceColorKey,
	MetricItem,
} from './types';

const DeviceBreakdown = ({ deviceData }: DeviceBreakdownProps) => {
	// Calculate total for percentage
	const devices = deviceData?.devices ?? [];
	const browsers = deviceData?.browsers ?? [];
	const operatingSystems = deviceData?.operatingSystems ?? [];

	const totalDeviceClicks = devices.reduce(
		(sum: number, device: MetricItem) => sum + device.count,
		0
	);

	const deviceColors: Record<DeviceColorKey, string> = {
		mobile: 'mobile',
		desktop: 'desktop',
		tablet: 'tablet',
	};

	const noData =
		devices.length === 0 &&
		browsers.length === 0 &&
		operatingSystems.length === 0;

	const formattedDevices =
		devices.length > 0
			? devices.map((device: MetricItem) => ({
					name:
						device.name.charAt(0).toUpperCase() +
						device.name.slice(1),
					value:
						totalDeviceClicks > 0
							? Math.round(
									(device.count / totalDeviceClicks) * 100
								)
							: 0,
					count: device.count,
					color:
						deviceColors[
							device.name.toLowerCase() as DeviceColorKey
						] || 'default',
				}))
			: [];

	const getDeviceBarClassName = (color: string) =>
		cn('dashboard-devices-item-bar', `dashboard-devices-item-bar-${color}`);

	return (
		<div className="dashboard-devices">
			<h3 className="dashboard-devices-title">
				{__('Device Breakdown')}
			</h3>

			{noData ? (
				<div className="dashboard-devices-empty">
					<p className="dashboard-devices-empty-text">
						{__('No device data available')}
					</p>
				</div>
			) : (
				<>
					<div className="dashboard-devices-list">
						{formattedDevices.map((device, index: number) => (
							<div key={index} className="dashboard-devices-item">
								<div className="dashboard-devices-item-header">
									<span className="dashboard-devices-item-name">
										{device.name}
									</span>
									<span className="dashboard-devices-item-value">
										{device.value}% ({device.count})
									</span>
								</div>

								<div className="dashboard-devices-item-track">
									<div
										className={getDeviceBarClassName(
											device.color
										)}
										style={{ width: `${device.value}%` }}
									></div>
								</div>
							</div>
						))}
					</div>

					{browsers.length > 0 && (
						<div className="dashboard-devices-section">
							<h4 className="dashboard-devices-section-title">
								{__('Top Browsers')}
							</h4>

							<div className="dashboard-devices-section-list">
								{browsers
									.slice(0, 5)
									.map(
										(
											browser: MetricItem,
											index: number
										) => (
											<div
												key={index}
												className="dashboard-devices-section-row"
											>
												<span className="dashboard-devices-section-label">
													{browser.name}
												</span>
												<span className="dashboard-devices-section-count">
													{browser.count}
												</span>
											</div>
										)
									)}
							</div>
						</div>
					)}

					{operatingSystems.length > 0 && (
						<div className="dashboard-devices-section">
							<h4 className="dashboard-devices-section-title">
								{__('Top Operating Systems')}
							</h4>

							<div className="dashboard-devices-section-list">
								{operatingSystems
									.slice(0, 5)
									.map((os: MetricItem, index: number) => (
										<div
											key={index}
											className="dashboard-devices-section-row"
										>
											<span className="dashboard-devices-section-label">
												{os.name}
											</span>
											<span className="dashboard-devices-section-count">
												{os.count}
											</span>
										</div>
									))}
							</div>
						</div>
					)}
				</>
			)}
		</div>
	);
};

export default DeviceBreakdown;
