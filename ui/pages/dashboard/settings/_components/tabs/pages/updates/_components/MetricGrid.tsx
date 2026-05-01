import type { MetricGridProps } from "../types";
import DirectionalValue from "./DirectionalValue";

/**
 * Renders the compact key-value metrics for an update-management section.
 */
function MetricGrid({ direction, items }: MetricGridProps) {
	return (
		<div className="settings-updates-metric-grid">
			{items.map((item) => (
				<div
					key={item.label}
					dir={direction}
					className="settings-updates-metric-item"
				>
					<p className="settings-updates-metric-label">
						{item.label}
					</p>
					<p className="settings-updates-metric-value">
						<DirectionalValue direction={item.valueDirection}>
							{item.value}
						</DirectionalValue>
					</p>
				</div>
			))}
		</div>
	);
}

export default MetricGrid;
