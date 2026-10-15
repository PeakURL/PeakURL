import type { ReactNode } from "react";
import type { LucideIcon } from "lucide-react";

import { SectionTitle } from "@/pages/dashboard/settings/components/SectionTitle";
import { DocumentationLink } from "./DocumentationLink";

interface TabHeaderProps {
	icon: LucideIcon;
	title: string;
	description: string;
	documentationHref?: string;
	actions?: ReactNode;
}

/** Plain tab introduction, distinct from the grouped section headings beneath it. */
export function TabHeader({
	icon,
	title,
	description,
	documentationHref,
	actions,
}: TabHeaderProps) {
	return (
		<div className="settings-tab-header">
			<div className="settings-tab-header-copy">
				<SectionTitle icon={icon}>{title}</SectionTitle>
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
