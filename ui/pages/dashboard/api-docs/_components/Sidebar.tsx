// @ts-nocheck
import { Link } from 'react-router-dom';
import { Key, Link2, BarChart3, QrCode, Globe, Webhook } from 'lucide-react';

import { API_SERVER_BASE_URL } from '@/constants';
// Icon mapping
const iconMap = {
	key: Key,
	link: Link2,
	'chart-bar': BarChart3,
	qrcode: QrCode,
	globe: Globe,
	webhook: Webhook,
};

const Sidebar = ({ sections, activeSection }) => {
	return (
		<div className="lg:col-span-1">
			<div className="bg-surface border border-stroke rounded-lg p-4 sticky top-6">
				<div className="mb-4 pb-4 border-b border-stroke">
					<div className="text-xs font-medium text-text-muted mb-2">
						Base URL
					</div>
					<code className="text-xs bg-surface-alt px-2 py-1 rounded text-heading block break-all">
						{API_SERVER_BASE_URL}
					</code>
				</div>
				<nav className="space-y-1">
					{sections.map((section) => {
						const IconComponent = iconMap[section.icon] || Key;
						return (
							<Link
								key={section.id}
								to={`/dashboard/api-docs/${section.id}`}
								className={`w-full flex items-center gap-2.5 px-3 py-2.5 text-sm text-left rounded-lg transition-all ${
									activeSection === section.id
										? 'bg-accent/10 text-accent font-medium'
										: 'text-text-muted hover:bg-surface-alt hover:text-heading'
								}`}
							>
								<IconComponent
									size={16}
									className={
										activeSection === section.id
											? 'text-accent'
											: 'text-text-muted'
									}
								/>
								{section.name}
							</Link>
						);
					})}
				</nav>
			</div>
		</div>
	);
};

export default Sidebar;
