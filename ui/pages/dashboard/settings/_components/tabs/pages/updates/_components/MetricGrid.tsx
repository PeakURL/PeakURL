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
					<div className="flex items-center justify-between gap-3">
						<p className="settings-updates-metric-value">
							<DirectionalValue direction={item.valueDirection}>
								{item.value}
							</DirectionalValue>
						</p>
						{item.action ? (
							<div className="shrink-0 pt-2">{item.action}</div>
						) : null}
					</div>
				</div>
			))}
		</div>
	);
}

export default MetricGrid;
