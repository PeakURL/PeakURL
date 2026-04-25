import {
	ExternalLink,
	Search,
	Share2,
	MessageCircle,
	Video,
	Newspaper,
	Code,
	Mail,
	ShoppingBag,
	Sparkles,
	Globe,
	MousePointerClick,
	TrendingUp,
} from "lucide-react";
import DeviceStats from "./DeviceStats";
import { __ } from "@/i18n";
import type {
	LinkStatsViewProps,
	ReferrerCategoryItem,
	ReferrerItem,
	StatsMetricItem,
	TrafficCategory,
	UtmCampaignItem,
} from "./types";

// Map category to icon and color
const getCategoryInfo = (category: string) => {
	const categoryMap: Record<
		TrafficCategory,
		{ icon: typeof Globe; color: string; bg: string }
	> = {
		"Search Engine": {
			icon: Search,
			color: "text-blue-500",
			bg: "bg-blue-500/10",
		},
		"Social Media": {
			icon: Share2,
			color: "text-pink-500",
			bg: "bg-pink-500/10",
		},
		Messaging: {
			icon: MessageCircle,
			color: "text-green-500",
			bg: "bg-green-500/10",
		},
		Video: { icon: Video, color: "text-red-500", bg: "bg-red-500/10" },
		"News & Content": {
			icon: Newspaper,
			color: "text-orange-500",
			bg: "bg-orange-500/10",
		},
		Developer: { icon: Code, color: "text-gray-500", bg: "bg-gray-500/10" },
		Email: { icon: Mail, color: "text-yellow-500", bg: "bg-yellow-500/10" },
		"Email Marketing": {
			icon: Mail,
			color: "text-yellow-600",
			bg: "bg-yellow-600/10",
		},
		Shopping: {
			icon: ShoppingBag,
			color: "text-emerald-500",
			bg: "bg-emerald-500/10",
		},
		AI: {
			icon: Sparkles,
			color: "text-purple-500",
			bg: "bg-purple-500/10",
		},
		Productivity: {
			icon: TrendingUp,
			color: "text-indigo-500",
			bg: "bg-indigo-500/10",
		},
		Website: { icon: Globe, color: "text-cyan-500", bg: "bg-cyan-500/10" },
		Direct: {
			icon: MousePointerClick,
			color: "text-accent",
			bg: "bg-accent/10",
		},
		Unknown: { icon: Globe, color: "text-text-muted", bg: "bg-surface" },
	};

	return (
		categoryMap[(category as TrafficCategory) || "Unknown"] ||
		categoryMap.Unknown
	);
};

const getCategoryLabel = (category: string) => {
	const labels: Record<TrafficCategory, string> = {
		"Search Engine": __("Search Engine"),
		"Social Media": __("Social Media"),
		Messaging: __("Messaging"),
		Video: __("Video"),
		"News & Content": __("News & Content"),
		Developer: __("Developer"),
		Email: __("Email"),
		"Email Marketing": __("Email Marketing"),
		Shopping: __("Shopping"),
		AI: __("AI"),
		Productivity: __("Productivity"),
		Website: __("Website"),
		Direct: __("Direct"),
		Unknown: __("Unknown"),
	};

	return (
		labels[(category as TrafficCategory) || "Unknown"] ||
		category ||
		__("Unknown")
	);
};

// Get total clicks from referrers array
const getTotalClicks = (referrers: ReferrerItem[]) => {
	return referrers.reduce(
		(sum: number, ref: ReferrerItem) => sum + (ref.count || 0),
		0
	);
};

function TrafficSourcesTab({ stats, isLoading }: LinkStatsViewProps) {
	const devices: StatsMetricItem[] = stats?.devices || [];
	const browsers: StatsMetricItem[] = stats?.browsers || [];
	const operatingSystems: StatsMetricItem[] = stats?.operatingSystems || [];
	const referrers: ReferrerItem[] = stats?.referrers || [];
	const referrerCategories: ReferrerCategoryItem[] =
		stats?.referrerCategories || [];
	const utmCampaigns: UtmCampaignItem[] = stats?.utmCampaigns || [];

	const totalClicks = getTotalClicks(referrers);

	return (
		<div className="links-sources-tab">
			{/* Device & Browser Statistics */}
			<DeviceStats
				devices={devices}
				browsers={browsers}
				os={operatingSystems}
				isLoading={isLoading}
			/>

			{/* Referrer Categories Overview */}
			{referrerCategories.length > 0 && (
				<div className="links-drawer-section">
					<h3 className="links-drawer-section-title mb-4">
						{__("Traffic by Category")}
					</h3>
					<div className="links-sources-category-grid">
						{referrerCategories.map(
							(cat: ReferrerCategoryItem, index: number) => {
								const categoryInfo = getCategoryInfo(
									cat.category
								);
								const Icon = categoryInfo.icon;
								const percentage =
									totalClicks > 0
										? (
												(cat.count / totalClicks) *
												100
											).toFixed(1)
										: 0;

								return (
									<div
										key={index}
										className="links-sources-category-card"
									>
										<div
											className={`links-sources-category-icon ${categoryInfo.bg}`}
										>
											<Icon
												className={`w-5 h-5 ${categoryInfo.color}`}
											/>
										</div>
										<div className="links-sources-category-copy">
											<p className="text-sm font-medium text-heading truncate">
												{getCategoryLabel(cat.category)}
											</p>
											<p className="text-xs text-text-muted">
												{cat.count} {__("clicks")} (
												{percentage}%)
											</p>
										</div>
									</div>
								);
							}
						)}
					</div>
				</div>
			)}

			{/* Detailed Referrer Data */}
			<div className="links-drawer-section">
				<h3 className="links-drawer-section-title mb-4">
					{__("Referrer Sources")}
				</h3>
				{isLoading ? (
					<div className="links-drawer-empty-panel links-drawer-empty-panel-large animate-pulse">
						<p className="links-drawer-empty-copy">
							{__("Loading referrers...")}
						</p>
					</div>
				) : referrers.length > 0 ? (
					<div className="links-sources-referrer-list">
						{referrers.map((ref: ReferrerItem, index: number) => {
							const categoryInfo = getCategoryInfo(
								ref.category || "Unknown"
							);
							const Icon = categoryInfo.icon;
							const percentage =
								totalClicks > 0
									? ((ref.count / totalClicks) * 100).toFixed(
											1
										)
									: 0;

							return (
								<div
									key={index}
									className="links-sources-referrer-item"
								>
									<div className="links-sources-referrer-main">
										<div
											className={`links-sources-referrer-icon ${categoryInfo.bg}`}
										>
											<Icon
												className={`w-4 h-4 ${categoryInfo.color}`}
											/>
										</div>
										<div className="links-sources-referrer-copy">
											<p className="text-sm font-medium text-heading truncate">
												{ref.name ||
													__("Direct / Unknown")}
											</p>
											{ref.domain &&
												ref.domain !==
													ref.name?.toLowerCase() && (
													<p className="text-xs text-text-muted truncate">
														{ref.domain}
													</p>
												)}
										</div>
									</div>
									<div className="links-sources-referrer-meta">
										<div className="links-sources-referrer-bar">
											<div
												className={`h-full ${categoryInfo.bg.replace(
													"/10",
													"/40"
												)} rounded-full`}
												style={{
													width: `${percentage}%`,
												}}
											/>
										</div>
										<div className="links-sources-referrer-count">
											<span className="text-sm font-medium text-heading">
												{ref.count}
											</span>
											<span className="hidden text-xs text-text-muted sm:inline">
												{" "}
												({percentage}%)
											</span>
										</div>
									</div>
								</div>
							);
						})}
					</div>
				) : (
					<div className="links-drawer-empty-panel links-drawer-empty-panel-large">
						<div className="text-center">
							<ExternalLink className="links-drawer-empty-icon" />
							<p className="links-drawer-empty-copy">
								{__("No referrer data available yet")}
							</p>
							<p className="mt-1 text-xs text-text-muted">
								{__(
									"Referrer data will appear when visitors come from external sources"
								)}
							</p>
						</div>
					</div>
				)}
			</div>

			{/* UTM Campaign Tracking */}
			{utmCampaigns && utmCampaigns.length > 0 && (
				<div className="links-drawer-section">
					<h3 className="links-drawer-section-title mb-4">
						{__("UTM Campaign Tracking")}
					</h3>
					<div className="links-sources-utm-list">
						{utmCampaigns.map(
							(campaign: UtmCampaignItem, index: number) => (
								<div
									key={index}
									className="links-sources-utm-item"
								>
									<div className="links-sources-utm-copy">
										<p className="text-sm font-medium text-heading truncate">
											{campaign.campaign}
										</p>
										<div className="links-sources-utm-tags">
											{campaign.source && (
												<span className="links-sources-utm-tag">
													{__("source:")}{" "}
													{campaign.source}
												</span>
											)}
											{campaign.medium && (
												<span className="links-sources-utm-tag">
													{__("medium:")}{" "}
													{campaign.medium}
												</span>
											)}
										</div>
									</div>
									<span className="text-sm font-medium text-heading shrink-0">
										{campaign.count} {__("clicks")}
									</span>
								</div>
							)
						)}
					</div>
				</div>
			)}
		</div>
	);
}

export default TrafficSourcesTab;
