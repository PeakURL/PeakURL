import { useRef } from "react";
import { Link } from "react-router";

import { useActiveIndicator } from "@/hooks";
import { __ } from "@/i18n";
import { getSettingsLabels, settingsTabOrder } from "@/router/tabs";
import type { SettingsTabId } from "@/router/tabs";

interface SettingsNavigationProps {
	activeTab: SettingsTabId;
	allowed: Record<SettingsTabId, boolean>;
}

export function SettingsNavigation({
	activeTab,
	allowed,
}: SettingsNavigationProps) {
	const scroller = useRef<HTMLElement>(null);
	const track = useRef<HTMLDivElement>(null);
	const indicator = useRef<HTMLSpanElement>(null);
	const labels = getSettingsLabels();

	useActiveIndicator(track, indicator, { scroller, duration: 0.24 });

	return (
		<nav
			ref={scroller}
			className="settings-navigation"
			aria-label={__("Settings")}
		>
			<div ref={track} className="settings-navigation-track">
				<span
					ref={indicator}
					className="settings-navigation-indicator"
					aria-hidden="true"
				/>
				{settingsTabOrder
					.filter((tab) => allowed[tab])
					.map((tab) => (
						<Link
							key={tab}
							to={`/dashboard/settings/${tab}`}
							className="settings-navigation-link"
							aria-current={
								tab === activeTab ? "page" : undefined
							}
						>
							{labels[tab]}
							{tab === "mcp" && (
								<span className="settings-navigation-badge">
									{__("New")}
								</span>
							)}
						</Link>
					))}
			</div>
		</nav>
	);
}
