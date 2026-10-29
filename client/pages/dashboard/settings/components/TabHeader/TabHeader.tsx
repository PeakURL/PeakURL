import type { ReactNode } from "react";

import type { SettingsIconComponent } from "@/pages/dashboard/settings/components/SectionTitle";
import { SectionTitle } from "@/pages/dashboard/settings/components/SectionTitle";
import { DocumentationLink } from "./DocumentationLink";

interface TabHeaderProps {
	icon: SettingsIconComponent;
	title: string;
	description: string;
	documentationHref?: string;
	actions?: ReactNode;
	badge?: ReactNode;
}

/** Plain tab introduction, distinct from the grouped section headings beneath it. */
export function TabHeader({
	icon,
	title,
	description,
	documentationHref,
	actions,
	badge,
}: TabHeaderProps) {
	return (
		<div className="settings-tab-header">
			<div className="settings-tab-header-copy">
				<SectionTitle icon={icon} badge={badge}>
					{title}
				</SectionTitle>
				<p className="settings-group-description">{description}</p>
			</div>
			{(documentationHref || actions) && (
				<div className="settings-tab-header-actions">
					{documentationHref && (
						<DocumentationLink href={documentationHref} />
					)}
					{actions}
				</div>
			)}
		</div>
	);
}
