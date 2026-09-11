import { Clock, History, Link2, RefreshCw, Shield, Users } from "lucide-react";

import { __, sprintf } from "@/i18n";
import { formatCount, formatDate } from "@/shared/formatting";

import { getActivityMessage } from "../../lib";
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
	const latestMessage = summaryCounts.latest
		? getActivityMessage(summaryCounts.latest)
		: __("No recent events");

	const latestDate = summaryCounts.latest?.timestamp
		? formatDate(summaryCounts.latest.timestamp)
		: "";

	return (
		<div className="space-y-4">
			<div className="activity-page-hero">
				<div className="activity-page-hero-copy">
					<div className="activity-page-hero-badge">
						<Shield className="h-3 w-3" />
						<span>{__("Audit Log")}</span>
					</div>
					<h1 className="activity-page-title">
						{__("Activity Log")}
					</h1>
					<p className="activity-page-summary">
						{__(
							"Track all link lifecycle events, user management changes, and administrative actions across your PeakURL install."
						)}
					</p>
				</div>
				<button
					type="button"
					onClick={onRefresh}
					disabled={isRefreshing}
					className="btn btn-secondary shrink-0"
					title={__("Refresh activity log")}
					aria-label={__("Refresh activity log")}
				>
					<RefreshCw
						className={`h-4 w-4 ${isRefreshing ? "animate-spin" : ""}`}
					/>
					<span className="hidden sm:inline">{__("Refresh")}</span>
				</button>
			</div>

			<div className="activity-page-overview">
				<div className="activity-page-overview-grid">
					{/* Card 1: Total Events */}
					<div className="activity-page-overview-item">
						<div className="activity-page-overview-header">
							<div className="activity-page-overview-copy">
								<p className="activity-page-overview-title">
									{__("Total Events")}
								</p>
								<p className="activity-page-overview-value">
									{formatCount(summaryCounts.all)}
								</p>
							</div>
							<div className="activity-page-overview-icon activity-page-overview-icon-all">
								<History className="activity-page-overview-icon-glyph" />
							</div>
						</div>
						<p className="activity-page-overview-note">
							{__("Recorded system actions")}
						</p>
					</div>

					{/* Card 2: Link Actions */}
					<div className="activity-page-overview-item">
						<div className="activity-page-overview-header">
							<div className="activity-page-overview-copy">
								<p className="activity-page-overview-title">
									{__("Link Activity")}
								</p>
								<p className="activity-page-overview-value">
									{formatCount(summaryCounts.links)}
								</p>
							</div>
							<div className="activity-page-overview-icon activity-page-overview-icon-links">
								<Link2 className="activity-page-overview-icon-glyph" />
							</div>
						</div>
						<p className="activity-page-overview-note">
							{__("Creation, edits & trash")}
						</p>
					</div>

					{/* Card 3: User Actions */}
					<div className="activity-page-overview-item">
						<div className="activity-page-overview-header">
							<div className="activity-page-overview-copy">
								<p className="activity-page-overview-title">
									{__("User Activity")}
								</p>
								<p className="activity-page-overview-value">
									{formatCount(summaryCounts.users)}
								</p>
							</div>
							<div className="activity-page-overview-icon activity-page-overview-icon-users">
								<Users className="activity-page-overview-icon-glyph" />
							</div>
						</div>
						<p className="activity-page-overview-note">
							{__("User management events")}
						</p>
					</div>

					{/* Card 4: Latest Activity */}
					<div className="activity-page-overview-item">
						<div className="activity-page-overview-header">
							<div className="activity-page-overview-copy">
								<p className="activity-page-overview-title">
									{__("Latest Activity")}
								</p>
								<p
									className="activity-page-overview-value-latest"
									title={latestMessage}
								>
									{latestMessage}
								</p>
							</div>
							<div className="activity-page-overview-icon activity-page-overview-icon-latest">
								<Clock className="activity-page-overview-icon-glyph" />
							</div>
						</div>
						<p className="activity-page-overview-note">
							{latestDate
								? sprintf(__("At %s"), latestDate)
								: __("No activity")}
						</p>
					</div>
				</div>
			</div>
		</div>
	);
}
