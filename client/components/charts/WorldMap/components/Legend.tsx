import { __ } from "@/i18n";

interface WorldMapLegendProps {
	/** Ordered colors rendered in the legend gradient. */
	colors: string[];

	/** Highest click value used as the legend upper bound. */
	maxClicks: number;
}

/**
 * Render the color scale legend for country click density.
 */
const WorldMapLegend = ({ colors, maxClicks }: WorldMapLegendProps) => (
	<div className="world-map-legend">
		<div className="world-map-legend-title">{__("Clicks")}</div>
		<div className="world-map-legend-scale">
			<span className="world-map-legend-value">0</span>
			<div className="world-map-legend-gradient">
				{colors.map((color) => (
					<div
						key={color}
						className="world-map-legend-gradient-stop"
						style={{ backgroundColor: color }}
					></div>
				))}
			</div>
			<span className="world-map-legend-value">{maxClicks}</span>
		</div>
	</div>
);

export default WorldMapLegend;
