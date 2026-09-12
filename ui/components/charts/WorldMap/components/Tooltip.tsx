import { __ } from "@/i18n";

import type { TooltipContent } from "../../types";

const TOOLTIP_OFFSET = "0.75rem";

/**
 * Tooltip coordinates relative to the map container.
 */
export interface WorldMapTooltipPosition {
	/** Horizontal pointer position inside the map container. */
	x: number;

	/** Vertical pointer position inside the map container. */
	y: number;

	/** Whether the tooltip needs to open toward the left. */
	isNearRightEdge: boolean;

	/** Whether the tooltip needs to open upward. */
	isNearBottomEdge: boolean;
}

interface WorldMapTooltipProps {
	/** Country metric displayed in the tooltip. */
	content: TooltipContent;

	/** Tooltip position and edge-awareness flags. */
	position: WorldMapTooltipPosition;
}

/**
 * Render a pointer-following country tooltip.
 */
const WorldMapTooltip = ({ content, position }: WorldMapTooltipProps) => (
	<div
		className="world-map-tooltip"
		style={{
			insetInlineStart: position.x,
			top: position.y,
			transform: `translate(${
				position.isNearRightEdge
					? `calc(-100% - ${TOOLTIP_OFFSET})`
					: TOOLTIP_OFFSET
			}, ${
				position.isNearBottomEdge
					? `calc(-100% - ${TOOLTIP_OFFSET})`
					: TOOLTIP_OFFSET
			})`,
		}}
	>
		<p className="world-map-tooltip-title">{content.name}</p>
		<p className="world-map-tooltip-copy">
			{content.clicks} {content.clicks === 1 ? __("click") : __("clicks")}
		</p>
	</div>
);

export default WorldMapTooltip;
