import type { SectionHeaderProps } from "../types";
import StatusBadge from "./StatusBadge";

/**
 * Renders the title, description, badge, and action area for an update section.
 */
function SectionHeader({
	direction,
	title,
	description,
	badge,
	primaryAction,
	secondaryAction,
}: SectionHeaderProps) {
	return (
		<div dir={direction} className="settings-updates-card-header">
			<div className="settings-updates-card-copy">
				<div className="settings-updates-card-title-row">
					<h2 className="settings-updates-card-title">{title}</h2>
					{badge ? (
						<StatusBadge tone={badge.tone} label={badge.label} />
					) : null}
				</div>
				<p className="settings-updates-card-description">
					{description}
				</p>
			</div>

			<div className="settings-updates-card-actions">
				{secondaryAction}
				{primaryAction}
			</div>
		</div>
	);
}

export default SectionHeader;
