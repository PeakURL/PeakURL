import { MousePointerClick } from "lucide-react";
import { useNavigate } from "react-router-dom";

import type { LinkRecord } from "@/api";
import { __, sprintf } from "@/i18n";
import { cn, formatCount, formatDate, getLinkDisplayTitle } from "@/utils";

import type { RecentClick, RecentClicksProps } from "../types";

function getClickTitle(click: RecentClick): string {
	const code = click.link.alias || click.link.shortCode || __("Unknown");

	return getLinkDisplayTitle(click.link.title, code);
}

function getClickLocation(click: RecentClick): string {
	return (
		click.location?.city ||
		click.location?.country ||
		click.device ||
		click.browser ||
		__("Unknown source")
	);
}

function getClickMeta(click: RecentClick): string {
	const clickedAt = formatDate(click.clickedAt);
	const location = getClickLocation(click);

	if (!clickedAt) {
		return location;
	}

	return sprintf(__("%1$s from %2$s"), [clickedAt, location]);
}

function getStatsShortId(link: LinkRecord): string {
	return link.alias || link.shortCode || "";
}

const RecentClicks = ({ recentClicks }: RecentClicksProps) => {
	const navigate = useNavigate();

	const openLinkStats = (link: LinkRecord) => {
		const statsShortId = getStatsShortId(link);

		if (!statsShortId) {
			return;
		}

		navigate(`/dashboard/links?stats=${encodeURIComponent(statsShortId)}`);
	};

	return (
		<div className="dashboard-recent-clicks">
			<h3 className="dashboard-recent-clicks-title">
				{__("Recent Clicks")}
			</h3>

			<div
				className={cn(
					"dashboard-recent-clicks-list",
					recentClicks.length === 0 &&
						"dashboard-recent-clicks-list-empty"
				)}
			>
				{recentClicks.length === 0 ? (
					<div className="dashboard-recent-clicks-empty">
						<p className="dashboard-recent-clicks-empty-text">
							{__("No recent clicks yet")}
						</p>
					</div>
				) : (
					recentClicks.map((click) => {
						const canOpenStats = Boolean(
							getStatsShortId(click.link)
						);

						return (
							<button
								key={click.id}
								type="button"
								className="dashboard-recent-clicks-item"
								onClick={() => openLinkStats(click.link)}
								disabled={!canOpenStats}
								title={__("View stats")}
							>
								<span className="dashboard-recent-clicks-icon">
									<MousePointerClick className="dashboard-recent-clicks-icon-glyph" />
								</span>
								<span className="dashboard-recent-clicks-copy">
									<span className="dashboard-recent-clicks-name">
										{getClickTitle(click)}
									</span>
									<span className="dashboard-recent-clicks-meta">
										{getClickMeta(click)}
									</span>
								</span>
								<span className="dashboard-recent-clicks-stats">
									<span className="dashboard-recent-clicks-count">
										{sprintf(
											__("%s clicks"),
											formatCount(click.link.clicks)
										)}
									</span>
									<span className="dashboard-recent-clicks-action">
										{__("View stats")}
									</span>
								</span>
							</button>
						);
					})
				)}
			</div>
		</div>
	);
};

export default RecentClicks;
