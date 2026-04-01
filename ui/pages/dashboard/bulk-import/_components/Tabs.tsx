// @ts-nocheck
import { Link } from 'react-router-dom';

const Tabs = ({ tabs, activeTab }) => {
	return (
		<div className="flex items-center gap-1 border-b border-stroke">
			{tabs.map((tab) => {
				const TabIcon = tab.icon;

				return (
					<Link
						key={tab.id}
						to={`/dashboard/tools/import/${tab.id}`}
						className={`flex items-center gap-2 px-5 py-2.5 text-sm font-medium transition-all border-b-2 -mb-px ${
							activeTab === tab.id
								? 'border-accent text-accent'
								: 'border-transparent text-text-muted hover:text-heading hover:border-stroke'
						}`}
					>
						<TabIcon className="h-4 w-4" />
						{tab.name}
					</Link>
				);
			})}
		</div>
	);
};

export default Tabs;
