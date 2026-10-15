import { Clock, History, Link2, RefreshCw, Users } from "lucide-react";

import { dashboardPageIcons } from "@/pages/dashboard/navigation";
import { __ } from "@/i18n";
import { PageHeader } from "@/components";
import { cn, formatCount, formatDate } from "@/shared/formatting";

import { formatExactTimestamp } from "../../lib";
import type { ActivitySummaryCounts } from "../../types";

interface ActivityHeaderProps {
	summaryCounts: ActivitySummaryCounts;
	isRefreshing: boolean;
	onRefresh: () => void;
}

export function ActivityHeader({
	summaryCounts,
	isRefreshing,
	onRefresh,
}: ActivityHeaderProps) {
	const mostRecentTimestamp = summaryCounts.latest?.timestamp;

	const overviewItems: Array<{
		key: string;
		label: string;
		value: string;
		note?: string | null;
		icon: typeof History;
	}> = [
		{
			key: "all",
			label: __("Matching events"),
			value: formatCount(summaryCounts.all),
			icon: History,
		},
		{
			key: "links",
			label: __("Link events on this page"),
			value: formatCount(summaryCounts.links),
			icon: Link2,
		},
		{
			key: "users",
			label: __("User events on this page"),
			value: formatCount(summaryCounts.users),
			icon: Users,
		},
		{
			key: "latest",
			label: __("Latest on this page"),
			value: mostRecentTimestamp
				? formatDate(mostRecentTimestamp)
				: __("No recent events"),
			note: mostRecentTimestamp
				? formatExactTimestamp(mostRecentTimestamp)
				: null,
			icon: Clock,
		},
	];

	return (
		<>
			<PageHeader
				icon={dashboardPageIcons["activity"]}
				title={__("Activity")}
				titleLabel={__("Activity Log")}
				description={__(
					"Review link changes and user-management events in one place with filters, timestamps, and actor details."
				)}
				actions={
					<button
						type="button"
						onClick={onRefresh}
						disabled={isRefreshing}
						className="page-header-icon-button"
						aria-label={__("Refresh activity history")}
						title={__("Refresh activity history")}
					>
						<RefreshCw
							className={cn(isRefreshing && "animate-spin")}
						/>
					</button>
				}
			/>

			<div className="activity-page-overview">
				<div className="activity-page-overview-grid">
					{overviewItems.map((item) => {
						const Icon = item.icon;
						const isLatest = "latest" === item.key;

						return (
							<div
								key={item.key}
								className="activity-page-overview-item"
							>
								<div className="activity-page-overview-header">
									<div className="activity-page-overview-copy">
										<p className="activity-page-overview-title">
											{item.label}
										</p>
										<p
											className={cn(
												"activity-page-overview-value",
												isLatest &&
													"activity-page-overview-value-latest"
											)}
											dir="auto"
										>
											{item.value}
										</p>
									</div>
									<div className="activity-page-overview-icon">
										<Icon className="activity-page-overview-icon-glyph" />
									</div>
								</div>
								{item.note ? (
									<p
										className="activity-page-overview-note"
										dir="auto"
									>
										{item.note}
									</p>
								) : null}
							</div>
						);
					})}
				</div>
			</div>
		</>
	);
}
