import { Skeleton } from "@/components";
import { __ } from "@/i18n";

type Panel = "traffic" | "recent-clicks" | "countries" | "devices" | "activity";

export function DashboardPanelSkeleton({ panel }: { panel: Panel }) {
	const surface = {
		traffic: {
			title: __("Traffic Overview"),
			className: "dashboard-traffic",
		},
		"recent-clicks": {
			title: __("Recent Clicks"),
			className: "dashboard-recent-clicks",
		},
		countries: {
			title: __("Top Countries"),
			className: "dashboard-countries",
		},
		devices: {
			title: __("Device Breakdown"),
			className: "dashboard-devices",
		},
		activity: {
			title: __("Recent Activity"),
			className: "dashboard-activity",
		},
	}[panel];

	return (
		<section className={surface.className} aria-busy="true">
			<h3 className="dashboard-skeleton-panel-title">{surface.title}</h3>
			<div aria-hidden="true">
				{panel === "traffic" ? (
					<Skeleton className="traffic-chart" />
				) : panel === "countries" ? (
					<>
						<Skeleton className="dashboard-countries-map" />
						<PanelRows />
					</>
				) : panel === "devices" ? (
					<div className="dashboard-devices-chart-panel">
						<Skeleton className="dashboard-skeleton-donut" />
						<PanelRows />
					</div>
				) : (
					<PanelRows />
				)}
			</div>
		</section>
	);
}

function PanelRows() {
	return (
		<div className="dashboard-skeleton-rows">
			{[1, 2, 3].map((row) => (
				<div className="dashboard-skeleton-row" key={row}>
					<Skeleton className="dashboard-skeleton-row-mark" />
					<Skeleton className="dashboard-skeleton-row-label" />
					<Skeleton className="dashboard-skeleton-row-value" />
				</div>
			))}
		</div>
	);
}
