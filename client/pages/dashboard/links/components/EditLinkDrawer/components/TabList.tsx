import { Tab, TabList as HeadlessTabList } from "@headlessui/react";
import type { LucideIcon } from "lucide-react";

export interface EditLinkTab {
	name: string;
	icon: LucideIcon;
}

interface TabListProps {
	tabs: EditLinkTab[];
}

function TabList({ tabs }: TabListProps) {
	return (
		<HeadlessTabList className="links-drawer-tabs mb-6">
			{tabs.map((tab) => {
				const Icon = tab.icon;

				return (
					<Tab
						key={tab.name}
						className={({ selected }) =>
							`links-drawer-tab ${
								selected
									? "links-drawer-tab-active"
									: "links-drawer-tab-inactive"
							}`
						}
					>
						<Icon className="links-drawer-tab-icon" />
						{tab.name}
					</Tab>
				);
			})}
		</HeadlessTabList>
	);
}

export default TabList;
