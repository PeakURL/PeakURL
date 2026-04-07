import { Select } from '@/components/ui';
import { __ } from '@/i18n';
import type { SelectOption } from '@/components/ui';
import type { HeaderProps } from './types';

const Header = ({ timeRange, onTimeRangeChange }: HeaderProps) => {
	const timeRangeOptions: SelectOption<number>[] = [
		{ value: 7, label: __('Last 7 days') },
		{ value: 30, label: __('Last 30 days') },
		{ value: 90, label: __('Last 90 days') },
	];

	return (
		<div className="flex items-center justify-between">
			<div>
				<h1 className="text-2xl font-bold text-heading">
					{__('Dashboard')}
				</h1>
				<p className="text-sm text-text-muted mt-0.5">
					{__(
						"Welcome back! Here's what's happening with your links."
					)}
				</p>
			</div>
			<div className="flex items-center gap-2">
				<Select
					value={timeRange}
					onChange={onTimeRangeChange}
					options={timeRangeOptions}
					ariaLabel={__('Dashboard time range')}
					className="min-w-[10rem]"
					buttonClassName="rounded-lg border-stroke bg-surface focus:border-primary-500 focus:ring-primary-500/20"
				/>
			</div>
		</div>
	);
};

export default Header;
