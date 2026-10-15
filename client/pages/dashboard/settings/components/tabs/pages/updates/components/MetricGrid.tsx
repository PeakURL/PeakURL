import type { MetricGridProps } from "../types";
import DirectionalValue from "./DirectionalValue";

/**
 * Renders the compact key-value metrics for an update-management section.
 */
function MetricGrid({ direction, items }: MetricGridProps) {
	return (
		<dl className="settings-updates-metric-grid">
			{items.map((item) => (
				<div
					key={item.label}
					dir={direction}
					className="settings-updates-metric-item"
				>
					<dt className="settings-updates-metric-label">
						{item.label}
					</dt>
					<dd className="settings-updates-metric-content">
						<p className="settings-updates-metric-value">
							<DirectionalValue direction={item.valueDirection}>
								{item.value}
							</DirectionalValue>
						</p>
						{item.action ? (
							<div className="shrink-0 pt-2">{item.action}</div>
						) : null}
					</dd>
				</div>
			))}
		</dl>
	);
}

export default MetricGrid;
