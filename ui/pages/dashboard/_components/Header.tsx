import { __ } from '@/i18n';
import type { HeaderProps } from './types';

const Header = ({ timeRange, onTimeRangeChange }: HeaderProps) => {
	return (
		<div className="flex items-center justify-between">
			<div>
				<h1 className="text-2xl font-bold text-heading">
					{__('Dashboard')}
				</h1>
				<p className="text-sm text-text-muted mt-0.5">
					{__(
						"Welcome back! Here's what's happening with your links today."
					)}
				</p>
			</div>
			<div className="flex items-center gap-2">
				<select
					value={timeRange}
					onChange={(e) => onTimeRangeChange(Number(e.target.value))}
					className="bg-surface border border-stroke rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none cursor-pointer"
				>
					<option value="7">{__('Last 7 days')}</option>
					<option value="30">{__('Last 30 days')}</option>
					<option value="90">{__('Last 90 days')}</option>
				</select>
			</div>
		</div>
	);
};

export default Header;
