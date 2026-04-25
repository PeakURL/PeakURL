import {
	Activity,
	Settings,
	Shield,
	Key,
	Mail,
	MapPin,
	Globe,
	Plug,
	Download,
} from "lucide-react";
import { Link } from "react-router-dom";
import { getDocumentDirection } from "@/i18n/direction";
import { cn } from "@/utils";
import type { SettingsTabIcon, SettingsTabItem, SidebarProps } from "./types";

// Icon mapping
const iconMap: Record<SettingsTabIcon, typeof Settings> = {
	settings: Settings,
	activity: Activity,
	shield: Shield,
	key: Key,
	mail: Mail,
	mapPin: MapPin,
	globe: Globe,
	plug: Plug,
	download: Download,
};

const Sidebar = ({ tabs, activeTab }: SidebarProps) => {
	const direction = getDocumentDirection();
	const getLinkClassName = (isActive: boolean) =>
		cn(
			"settings-sidebar-link",
			isActive
				? "settings-sidebar-link-active"
				: "settings-sidebar-link-inactive"
		);
	const getIconClassName = (isActive: boolean) =>
		cn(
			"settings-sidebar-icon",
			isActive
				? "settings-sidebar-icon-active"
				: "settings-sidebar-icon-inactive"
		);

	return (
		<div className="settings-sidebar">
			<div className="settings-sidebar-panel">
				<nav className="settings-sidebar-nav">
					{tabs.map((tab: SettingsTabItem) => {
						const IconComponent = iconMap[tab.icon];
						const isActive = activeTab === tab.id;
						return (
							<Link
								key={tab.id}
								to={`/dashboard/settings/${tab.id}`}
								dir={direction}
								className={getLinkClassName(isActive)}
							>
								{IconComponent && (
									<IconComponent
										size={16}
										className={getIconClassName(isActive)}
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
