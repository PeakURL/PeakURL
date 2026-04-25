import { useState } from "react";
import { TrafficChart, type TrafficChartType } from "@/components";
import { BarChart3, LineChart } from "lucide-react";
import { __ } from "@/i18n";
import { cn } from "@/utils";
import type { TrafficOverviewProps } from "../types";

const TrafficOverview = ({ trafficData }: TrafficOverviewProps) => {
	const [chartType, setChartType] = useState<TrafficChartType>("line");

	// Check if there's any real data
	const hasData =
		trafficData &&
		Array.isArray(trafficData.labels) &&
		Array.isArray(trafficData.clicks) &&
		Array.isArray(trafficData.unique) &&
		trafficData.labels.length > 0 &&
		(trafficData.clicks.some((val: number) => val > 0) ||
			trafficData.unique.some((val: number) => val > 0));

	const getChartButtonClassName = (isActive: boolean) =>
		cn(
			"dashboard-traffic-chart-button",
			isActive
				? "dashboard-traffic-chart-button-active"
				: "dashboard-traffic-chart-button-inactive"
		);

	return (
		<div className="dashboard-traffic">
			<div className="dashboard-traffic-header">
				<h3 className="dashboard-traffic-title">
					{__("Traffic Overview")}
				</h3>
				{hasData && (
					<div className="dashboard-traffic-toolbar">
						<div className="dashboard-traffic-chart-toggle">
							<button
								onClick={() => setChartType("line")}
								className={getChartButtonClassName(
									"line" === chartType
								)}
								title={__("Line Chart")}
							>
								<LineChart size={16} />
							</button>
							<button
								onClick={() => setChartType("bar")}
								className={getChartButtonClassName(
									"bar" === chartType
								)}
								title={__("Bar Chart")}
							>
								<BarChart3 size={16} />
							</button>
						</div>

						<div className="dashboard-traffic-legend">
							<div className="dashboard-traffic-legend-item">
								<div className="dashboard-traffic-legend-dot dashboard-traffic-legend-dot-clicks"></div>
								<span className="dashboard-traffic-legend-text">
									{__("Clicks")}
								</span>
							</div>
							<div className="dashboard-traffic-legend-item">
								<div className="dashboard-traffic-legend-dot dashboard-traffic-legend-dot-unique"></div>
								<span className="dashboard-traffic-legend-text">
									{__("Unique")}
								</span>
							</div>
						</div>
					</div>
				)}
			</div>

			{hasData ? (
				<TrafficChart data={trafficData} type={chartType} />
			) : (
				<div className="dashboard-traffic-empty">
					<p className="dashboard-traffic-empty-text">
						{__("No traffic data available")}
					</p>
				</div>
			)}
		</div>
	);
};

export default TrafficOverview;
