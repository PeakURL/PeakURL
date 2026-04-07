import { __ } from '@/i18n';
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
		mobile: 'bg-blue-600 dark:bg-blue-500',
		desktop: 'bg-purple-600 dark:bg-purple-500',
		tablet: 'bg-emerald-600 dark:bg-emerald-500',
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
						] || 'bg-gray-500',
				}))
			: [];

	return (
		<div className="bg-surface border border-stroke rounded-lg p-5">
			<h3 className="text-base font-semibold text-heading mb-4">
				{__('Device Breakdown')}
			</h3>
			{noData ? (
				<div className="text-center py-8">
					<p className="text-sm text-text-muted">
						{__('No device data available')}
					</p>
				</div>
			) : (
				<>
					<div className="space-y-4">
						{formattedDevices.map((device, index: number) => (
							<div key={index}>
								<div className="flex items-center justify-between mb-2">
									<span className="text-sm font-medium text-heading">
										{device.name}
									</span>
									<span className="text-sm font-semibold text-text-muted">
										{device.value}% ({device.count})
									</span>
								</div>
								<div className="w-full bg-surface-alt rounded-full h-2">
									<div
										className={`${device.color} h-2 rounded-full transition-all duration-300`}
										style={{ width: `${device.value}%` }}
									></div>
								</div>
							</div>
						))}
					</div>

					{browsers.length > 0 && (
						<div className="mt-6 pt-6 border-t border-stroke">
							<h4 className="text-sm font-semibold text-heading mb-3">
								{__('Top Browsers')}
							</h4>
							<div className="space-y-2">
								{browsers
									.slice(0, 5)
									.map(
										(
											browser: MetricItem,
											index: number
										) => (
											<div
												key={index}
												className="flex items-center justify-between text-sm"
											>
												<span className="text-text-muted">
													{browser.name}
												</span>
												<span className="font-medium text-heading">
													{browser.count}
												</span>
											</div>
										)
									)}
							</div>
						</div>
					)}

					{operatingSystems.length > 0 && (
						<div className="mt-6 pt-6 border-t border-stroke">
							<h4 className="text-sm font-semibold text-heading mb-3">
								{__('Top Operating Systems')}
							</h4>
							<div className="space-y-2">
								{operatingSystems
									.slice(0, 5)
									.map((os: MetricItem, index: number) => (
										<div
											key={index}
											className="flex items-center justify-between text-sm"
										>
											<span className="text-text-muted">
												{os.name}
											</span>
											<span className="font-medium text-heading">
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
