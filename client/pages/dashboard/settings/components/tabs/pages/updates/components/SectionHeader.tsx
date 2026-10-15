import { SectionTitle } from "@/pages/dashboard/settings/components/SectionTitle";

import type { SectionHeaderProps } from "../types";
import StatusBadge from "./StatusBadge";

/**
 * Renders the title, description, and status badge for an update section.
 */
function SectionHeader({
	icon: Icon,
	direction,
	title,
	description,
	badge,
}: SectionHeaderProps) {
	return (
		<div dir={direction} className="settings-updates-section-header">
			<div className="settings-updates-intro">
				<div className="settings-section-copy">
					<div className="settings-section-title-row">
						<SectionTitle icon={Icon}>{title}</SectionTitle>
						{badge ? (
							<StatusBadge
								tone={badge.tone}
								label={badge.label}
							/>
						) : null}
					</div>
					<p className="settings-group-description">{description}</p>
				</div>
			</div>
		</div>
	);
}

export default SectionHeader;
