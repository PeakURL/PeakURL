import { SectionTitle } from "@/pages/dashboard/settings/components/SectionTitle";

import type { SectionHeaderProps } from "../types";
import StatusBadge from "./StatusBadge";

/**
 * Renders the title, description, and status badge for an update section.
 */
function SectionHeader({
	direction,
	title,
	description,
	badge,
}: SectionHeaderProps) {
	return (
		<div dir={direction} className="settings-updates-section-header">
			<div className="settings-section-title-row">
				<SectionTitle>{title}</SectionTitle>
				{badge && <StatusBadge tone={badge.tone} label={badge.label} />}
			</div>
			{description && (
				<p className="settings-group-description">{description}</p>
			)}
		</div>
	);
}

export default SectionHeader;
