import type { PluginTabsProps } from './types';

function PluginTabs({ activeTab, onTabChange, tabs }: PluginTabsProps) {
	return (
		<div className="flex items-center gap-1 overflow-x-auto border-b border-stroke">
			{tabs.map((tab) => (
				<button
					key={tab.id}
					onClick={() => onTabChange(tab.id)}
					className={`relative shrink-0 px-4 py-3 text-sm font-medium transition-colors ${
						activeTab === tab.id
							? 'text-accent'
							: 'text-text-muted hover:text-heading'
					}`}
				>
					<span className="inline-flex items-center gap-2">
						{tab.label}
						{typeof tab.count === 'number' && (
							<span
								className={`inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[10px] font-semibold ${
									activeTab === tab.id
										? 'bg-accent/10 text-accent'
										: 'bg-surface-alt text-text-muted'
								}`}
							>
								{tab.count}
							</span>
						)}
					</span>
					{/* Active indicator bar */}
					{activeTab === tab.id && (
						<span className="absolute bottom-0 left-0 right-0 h-0.5 rounded-full bg-accent" />
					)}
				</button>
			))}
		</div>
	);
}

export default PluginTabs;
