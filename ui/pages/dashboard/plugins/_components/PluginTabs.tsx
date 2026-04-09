import { cn } from '@/utils';
import type { PluginTabsProps } from './types';

function PluginTabs({ activeTab, onTabChange, tabs }: PluginTabsProps) {
	const getButtonClassName = (isActive: boolean) =>
		cn(
			'plugins-tabs-button',
			isActive
				? 'plugins-tabs-button-active'
				: 'plugins-tabs-button-inactive'
		);

	const getCountClassName = (isActive: boolean) =>
		cn(
			'plugins-tabs-count',
			isActive
				? 'plugins-tabs-count-active'
				: 'plugins-tabs-count-inactive'
		);

	return (
		<div className="plugins-tabs">
			{tabs.map((tab) => (
				<button
					key={tab.id}
					onClick={() => onTabChange(tab.id)}
					className={getButtonClassName(activeTab === tab.id)}
				>
					<span className="plugins-tabs-button-content">
						{tab.label}
						{typeof tab.count === 'number' && (
							<span
								className={getCountClassName(
									activeTab === tab.id
								)}
							>
								{tab.count}
							</span>
						)}
					</span>
					{/* Active indicator bar */}
					{activeTab === tab.id && (
						<span className="plugins-tabs-indicator" />
					)}
				</button>
			))}
		</div>
	);
}

export default PluginTabs;
