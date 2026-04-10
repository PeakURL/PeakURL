import { Link } from 'react-router-dom';
import { cn } from '@/utils';
import type { ImportTab, TabsProps } from '../types';

const Tabs = ({ tabs, activeTab }: TabsProps) => {
	return (
		<div className="import-layout-tabs">
			{tabs.map((tab: ImportTab) => {
				const TabIcon = tab.icon;

				return (
					<Link
						key={tab.id}
						to={`/dashboard/tools/import/${tab.id}`}
						className={cn(
							'import-layout-tab',
							activeTab === tab.id && 'import-layout-tab-active'
						)}
					>
						<TabIcon className="import-layout-tab-icon" />
						{tab.name}
					</Link>
				);
			})}
		</div>
	);
};

export default Tabs;
