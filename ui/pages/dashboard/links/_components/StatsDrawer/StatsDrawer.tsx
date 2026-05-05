import {
	Dialog,
	DialogPanel,
	DialogTitle,
	Tab,
	TabGroup,
	TabList,
	TabPanel,
	TabPanels,
} from "@headlessui/react";
import { X, Link2, BarChart3, Globe, Share2, ExternalLink } from "lucide-react";
import { useCallback, useMemo, useState, type SetStateAction } from "react";
import { useGetLinkStatsQuery } from "@/store/slices/api";
import { isDocumentRtl } from "@/i18n/direction";
import { getLocalDateValue, getShortUrl } from "@/utils";
import { __ } from "@/i18n";
import {
	BestDay,
	ClickHistory,
	HistoricalStats,
	QuickInsights,
	ShareTab,
	StatCards,
	TrafficHistory,
	TrafficLocationTab,
	TrafficSourcesTab,
} from "./sections";
import type {
	StatsCustomDateRange,
	StatsDrawerProps,
	StatsTimeRange,
} from "./types";

const CUSTOM_RANGE_FALLBACK_DAYS = 30;
const DAY_MS = 24 * 60 * 60 * 1000;

/**
 * Build the first custom chart range for a link.
 */
function getDefaultCustomDateRange(
	createdAt?: string | null
): StatsCustomDateRange {
	const today = getLocalDateValue(new Date());
	const createdDate = createdAt ? new Date(createdAt) : null;
	const fromDate =
		createdDate && !Number.isNaN(createdDate.getTime())
			? createdDate
			: new Date(Date.now() - CUSTOM_RANGE_FALLBACK_DAYS * DAY_MS);
	const from = getLocalDateValue(fromDate);

	return from > today ? { from: today, to: today } : { from, to: today };
}

export default function StatsDrawer({ open, setOpen, link }: StatsDrawerProps) {
	const [selectedTab, setSelectedTab] = useState(0);
	const [timeRange, setTimeRange] = useState<StatsTimeRange>("7d");
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const linkId = link?.id || "";
	const defaultCustomDateRange = useMemo(
		() => getDefaultCustomDateRange(link?.createdAt),
		[link?.createdAt]
	);
	const [customDateRangeState, setCustomDateRangeState] = useState<{
		linkId: string;
		range: StatsCustomDateRange;
	} | null>(null);
	const customDateRange =
		customDateRangeState?.linkId === linkId
			? customDateRangeState.range
			: defaultCustomDateRange;
	const setCustomDateRange = useCallback(
		(nextRange: SetStateAction<StatsCustomDateRange>) => {
			setCustomDateRangeState((currentState) => {
				const currentRange =
					currentState?.linkId === linkId
						? currentState.range
						: defaultCustomDateRange;
				const range =
					"function" === typeof nextRange
						? nextRange(currentRange)
						: nextRange;

				return { linkId, range };
			});
		},
		[defaultCustomDateRange, linkId]
	);
	const statsQueryArgs =
		"custom" === timeRange
			? {
					id: linkId,
					range: "custom" as const,
					from: customDateRange.from,
					to: customDateRange.to,
				}
			: {
					id: linkId,
					range: timeRange,
				};

	const { data: statsData, isLoading } = useGetLinkStatsQuery(
		statsQueryArgs,
		{
			skip:
				!link?.id ||
				!open ||
				![0, 2].includes(selectedTab) ||
				("custom" === timeRange &&
					(!customDateRange.from || !customDateRange.to)),
		}
	);

	if (!link) return null;

	const shortUrl = getShortUrl(link);
	const statsPayload = statsData?.data;

	const tabs = [
		{ name: __("Traffic Statistics"), icon: BarChart3 },
		{ name: __("Traffic Location"), icon: Globe },
		{ name: __("Traffic Sources"), icon: ExternalLink },
		{ name: __("Share"), icon: Share2 },
	];

	return (
		<Dialog open={open} onClose={setOpen} className="relative z-50">
			<div className="links-modal-backdrop" aria-hidden="true" />

			<div className="fixed inset-0 overflow-hidden">
				<div className="absolute inset-0 overflow-hidden">
					<div
						className={`links-drawer-shell ${
							isRtl
								? "links-drawer-shell-rtl"
								: "links-drawer-shell-ltr"
						}`}
					>
						<DialogPanel
							dir={direction}
							transition
							className={`links-drawer-panel ${
								isRtl
									? "data-closed:-translate-x-full"
									: "data-closed:translate-x-full"
							}`}
						>
							<div className="flex h-full flex-col overflow-y-auto bg-surface shadow-xl">
								{/* Header */}
								<div className="links-drawer-header">
									<div className="links-drawer-header-inner">
										<div className="links-drawer-header-copy">
											<DialogTitle className="links-drawer-title">
												<div className="links-drawer-title-icon">
													<Link2 className="w-4 h-4 text-accent" />
												</div>
												{__("Link Analytics")}
											</DialogTitle>
											<p className="links-drawer-description">
												{__(
													"Detailed statistics and performance metrics"
												)}
											</p>
										</div>
										<button
											type="button"
											onClick={() => setOpen(false)}
											className="links-drawer-close"
										>
											<span className="sr-only">
												{__("Close panel")}
											</span>
											<X className="w-5 h-5" />
										</button>
									</div>
								</div>

								{/* Content */}
								<div className="links-drawer-content">
									{/* Tabs */}
									<TabGroup
										selectedIndex={selectedTab}
										onChange={setSelectedTab}
									>
										<TabList className="links-drawer-tabs">
											{tabs.map((tab) => {
												const Icon = tab.icon;
												return (
													<Tab
														key={tab.name}
														className={({
															selected,
														}) =>
															`links-drawer-tab ${
																selected
																	? "links-drawer-tab-active"
																	: "links-drawer-tab-inactive"
															}`
														}
													>
														<Icon className="links-drawer-tab-icon" />
														{tab.name}
													</Tab>
												);
											})}
										</TabList>

										<TabPanels>
											{/* Traffic Statistics Tab */}
											<TabPanel className="links-drawer-panel-stack">
												{/* Stats Cards at the top */}
												<StatCards
													link={link}
													stats={statsPayload}
													isLoading={isLoading}
												/>

												{/* Quick Insights */}
												<QuickInsights
													link={link}
													stats={statsPayload}
													isLoading={isLoading}
													timeRange={timeRange}
												/>

												{/* Traffic History */}
												<TrafficHistory
													link={link}
													stats={statsPayload}
													isLoading={isLoading}
													timeRange={timeRange}
													setTimeRange={setTimeRange}
													customDateRange={
														customDateRange
													}
													setCustomDateRange={
														setCustomDateRange
													}
												/>

												{/* Historical Stats */}
												<HistoricalStats
													link={link}
													stats={statsPayload}
													isLoading={isLoading}
												/>

												{/* Best Day */}
												<BestDay
													link={link}
													stats={statsPayload}
													isLoading={isLoading}
												/>

												{/* Click History */}
												<ClickHistory
													link={link}
													stats={statsPayload}
													isLoading={isLoading}
												/>
											</TabPanel>

											{/* Traffic Location Tab */}
											<TabPanel>
												<TrafficLocationTab
													link={link}
													selectedTab={selectedTab}
													open={open}
												/>
											</TabPanel>

											{/* Traffic Sources Tab */}
											<TabPanel>
												<TrafficSourcesTab
													link={link}
													stats={statsPayload}
													isLoading={isLoading}
												/>
											</TabPanel>

											{/* Share Tab */}
											<TabPanel>
												<ShareTab
													link={link}
													shortUrl={shortUrl}
												/>
											</TabPanel>
										</TabPanels>
									</TabGroup>
								</div>

								{/* Footer */}
								<div className="links-drawer-footer">
									<div className="links-drawer-footer-actions">
										<button
											type="button"
											onClick={() => setOpen(false)}
											className="links-drawer-footer-button"
										>
											{__("Close")}
										</button>
									</div>
								</div>
							</div>
						</DialogPanel>
					</div>
				</div>
			</div>
		</Dialog>
	);
}
