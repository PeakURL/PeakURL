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
	badgeAction,
	primaryAction,
	secondaryAction,
}: SectionHeaderProps) {
	return (
		<>
			<legend className="settings-legend flex items-center gap-2">
				{title}
				{badge ? (
					<StatusBadge tone={badge.tone} label={badge.label} />
				) : null}
				{badgeAction}
			</legend>
			<div dir={direction} className="settings-updates-card-header">
				<div className="settings-updates-card-copy">
					<p className="settings-group-description mb-0! mt-0!">
						{description}
					</p>
				</div>

				{primaryAction || secondaryAction ? (
					<div className="settings-updates-card-actions">
						{secondaryAction}
						{primaryAction}
					</div>
				) : null}
			</div>
		</>
	);
}

export default SectionHeader;
