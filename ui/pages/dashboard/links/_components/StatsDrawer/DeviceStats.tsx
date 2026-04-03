// @ts-nocheck

import {
	Compass,
	Flame,
	Globe,
	Laptop,
	Monitor,
	Orbit,
	Smartphone,
	Tablet,
} from 'lucide-react';
import { __, _n, sprintf } from '@/i18n';

// Browser icon mapping
const getBrowserIcon = (browser) => {
	const browserLower = browser.toLowerCase();
	if (browserLower.includes('chrome')) return 'chrome';
	if (browserLower.includes('safari')) return 'safari';
	if (browserLower.includes('firefox')) return 'firefox';
	if (browserLower.includes('edge')) return 'edge';
	return 'globe';
};

// Browser icon component
const BrowserIcon = ({ browser, className }) => {
	const iconType = getBrowserIcon(browser);

	if (iconType === 'chrome') {
		return <Globe className={className} />;
	}
	if (iconType === 'safari') {
		return <Compass className={className} />;
	}
	if (iconType === 'firefox') {
		return <Flame className={className} />;
	}
	if (iconType === 'edge') {
		return <Orbit className={className} />;
	}
	return <Globe className={className} />;
};

// Device icon mapping
const getDeviceIcon = (deviceType) => {
	const typeLower = deviceType.toLowerCase();
	if (typeLower.includes('mobile')) return Smartphone;
	if (typeLower.includes('tablet')) return Tablet;
	return Monitor;
};

// OS emoji mapping
const getOSEmoji = (os) => {
	const osLower = os.toLowerCase();
	if (osLower.includes('windows')) return '🪟';
	if (osLower.includes('mac') || osLower.includes('ios')) return '🍎';
	if (osLower.includes('android')) return '🤖';
	if (osLower.includes('linux')) return '🐧';
	return '💻';
};

function DeviceStats({
	devices = [],
	browsers = [],
	os: oses = [],
	isLoading,
}) {
	if (isLoading) {
		return (
			<div className="bg-surface-alt border border-stroke rounded-lg p-4 animate-pulse">
				<h3 className="text-sm font-semibold text-heading mb-4">
					{__('Device & Browser Analytics')}
				</h3>
				<div className="h-64 flex items-center justify-center bg-surface rounded-lg border border-stroke">
					<p className="text-sm text-muted">
						{__('Loading analytics...')}
					</p>
				</div>
			</div>
		);
	}

	const hasData =
		devices.length > 0 || browsers.length > 0 || oses.length > 0;

	if (!hasData) {
		return (
			<div className="bg-surface-alt border border-stroke rounded-lg p-4">
				<h3 className="text-sm font-semibold text-heading mb-4">
					{__('Device & Browser Analytics')}
				</h3>
				<div className="h-64 flex items-center justify-center bg-surface rounded-lg border border-stroke">
					<div className="text-center px-6">
						<Monitor className="w-12 h-12 text-text-muted mx-auto mb-3" />
						<p className="text-sm font-medium text-heading mb-2">
							{__('No device data available yet')}
						</p>
						<p className="text-xs text-text-muted">
							{__(
								'Device and browser information will appear here once clicks are recorded'
							)}
						</p>
					</div>
				</div>
			</div>
		);
	}

	// Calculate total based on devices (assuming consistent data)
	const total = devices.reduce((sum, item) => sum + item.count, 0);

	// Helper to calculate percentage
	const formatData = (items) => {
		return items.map((item) => ({
			...item,
			percentage: total > 0 ? ((item.count / total) * 100).toFixed(1) : 0,
		}));
	};

	const displayDevices = formatData(devices);
	const displayBrowsers = formatData(browsers);
	const displayOses = formatData(oses);

	return (
		<div className="space-y-4">
			{/* Summary Card */}
			<div className="bg-linear-to-br from-accent/5 to-info/5 border border-accent/20 rounded-lg p-4">
				<div className="flex items-center justify-between">
					<div className="flex items-center gap-3">
						<div className="w-10 h-10 rounded-lg bg-accent/10 flex items-center justify-center">
							<Monitor className="w-5 h-5 text-accent" />
						</div>
						<div>
							<p className="text-sm text-text-muted">
								{__('Total Devices')}
							</p>
							<p className="text-xl font-bold text-heading">
								{total}
							</p>
						</div>
					</div>
					<div className="text-right">
						<p className="text-sm text-text-muted">
							{__('Unique Browsers')}
						</p>
						<p className="text-xl font-bold text-heading">
							{displayBrowsers.length}
						</p>
					</div>
				</div>
			</div>

			{/* Browsers */}
			<div className="bg-surface-alt border border-stroke rounded-lg p-4">
				<div className="flex items-center gap-2 mb-4">
					<Globe className="w-4 h-4 text-accent" />
					<h3 className="text-sm font-semibold text-heading">
						{__('Browsers')}
					</h3>
				</div>
				<div className="space-y-3">
					{displayBrowsers.slice(0, 5).map((browser, index) => {
						return (
							<div key={browser.name} className="space-y-1.5">
								<div className="flex items-center justify-between">
									<div className="flex items-center gap-2">
										<BrowserIcon
											browser={browser.name}
											className="w-4 h-4 text-accent"
										/>
										<span className="text-sm font-medium text-heading">
											{browser.name}
										</span>
									</div>
									<div className="flex items-center gap-3">
										<span className="text-sm text-muted">
											{browser.percentage}%
										</span>
										<span className="text-sm font-semibold text-heading min-w-12 text-right">
											{browser.count}
										</span>
									</div>
								</div>
								<div className="w-full bg-surface rounded-full h-2 overflow-hidden">
									<div
										className="bg-accent h-full rounded-full transition-all duration-500"
										style={{
											width: `${browser.percentage}%`,
										}}
									></div>
								</div>
							</div>
						);
					})}
				</div>
			</div>

			{/* Device Types */}
			<div className="bg-surface-alt border border-stroke rounded-lg p-4">
				<div className="flex items-center gap-2 mb-4">
					<Monitor className="w-4 h-4 text-accent" />
					<h3 className="text-sm font-semibold text-heading">
						{__('Device Types')}
					</h3>
				</div>
				<div className="grid grid-cols-1 gap-3">
					{displayDevices.map((device) => {
						const DeviceIcon = getDeviceIcon(device.name);
						return (
							<div
								key={device.name}
								className="flex items-center justify-between py-3 px-4 rounded-lg bg-surface border border-stroke hover:border-accent/50 transition-all"
							>
								<div className="flex items-center gap-3">
									<div className="w-10 h-10 rounded-lg bg-accent/10 flex items-center justify-center">
										<DeviceIcon className="w-5 h-5 text-accent" />
									</div>
									<div>
										<p className="text-sm font-medium text-heading">
											{device.name}
										</p>
										<p className="text-xs text-text-muted">
											{device.count}{' '}
											{_n(
												'device',
												'devices',
												device.count
											)}
										</p>
									</div>
								</div>
								<div className="text-right">
									<p className="text-lg font-bold text-heading">
										{device.percentage}%
									</p>
								</div>
							</div>
						);
					})}
				</div>
			</div>

			{/* Operating Systems */}
			<div className="bg-surface-alt border border-stroke rounded-lg p-4">
				<div className="flex items-center gap-2 mb-4">
					<Laptop className="w-4 h-4 text-accent" />
					<h3 className="text-sm font-semibold text-heading">
						{__('Operating Systems')}
					</h3>
				</div>
				<div className="space-y-2">
					{displayOses.map((os, index) => (
						<div
							key={os.name}
							className="flex items-center justify-between py-2 px-3 rounded-lg hover:bg-surface transition-all"
						>
							<div className="flex items-center gap-3">
								<div className="w-8 h-8 rounded-full bg-accent/10 flex items-center justify-center text-lg">
									{getOSEmoji(os.name)}
								</div>
								<div>
									<p className="text-sm font-medium text-heading">
										{os.name}
									</p>
								</div>
							</div>
							<div className="text-right flex items-center gap-3">
								<span className="text-sm text-muted">
									{os.percentage}%
								</span>
								<span className="text-sm font-semibold text-heading min-w-12">
									{os.count}
								</span>
							</div>
						</div>
					))}
				</div>
			</div>
		</div>
	);
}

export default DeviceStats;
