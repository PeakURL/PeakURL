// @ts-nocheck
import {
	Settings,
	Shield,
	Key,
	Mail,
	MapPin,
	Globe,
	Plug,
	Download,
} from 'lucide-react';
import { Link } from 'react-router-dom';

// Icon mapping
const iconMap = {
	settings: Settings,
	shield: Shield,
	key: Key,
	mail: Mail,
	mapPin: MapPin,
	globe: Globe,
	plug: Plug,
	download: Download,
};

const Sidebar = ({ tabs, activeTab }) => {
	return (
		<div className="lg:col-span-1">
			<div className="bg-surface border border-stroke rounded-lg p-2 sticky top-6">
				<nav className="space-y-1">
					{tabs.map((tab) => {
						const IconComponent = iconMap[tab.icon];
						return (
							<Link
								key={tab.id}
								to={`/dashboard/settings/${tab.id}`}
								className={`w-full flex items-center gap-2.5 px-3 py-2.5 text-sm text-left rounded-lg transition-all ${
									activeTab === tab.id
										? 'bg-accent/10 dark:bg-accent/20 text-accent font-medium'
										: 'text-text-muted hover:bg-surface-alt hover:text-heading'
								}`}
							>
								{IconComponent && (
									<IconComponent
										size={16}
										className={
											activeTab === tab.id
												? 'text-accent'
												: 'text-text-muted'
										}
									/>
								)}
								{tab.name}
							</Link>
						);
					})}
				</nav>
			</div>
		</div>
	);
};

export default Sidebar;
