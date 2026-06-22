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
import {
	X,
	Link2,
	BarChart3,
	Globe,
	Share2,
	ExternalLink,
	Copy,
	Check,
} from "lucide-react";
import { useCallback, useMemo, useState, type SetStateAction } from "react";

import { useGetLinkStatsQuery } from "@/store/slices/api";
import { isDocumentRtl } from "@/i18n/direction";
import {
	getLocalDateValue,
	getShortUrl,
	getLinkDisplayTitle,
	copyToClipboard,
} from "@/utils";
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
	const [copiedKey, setCopiedKey] = useState<"short" | "destination" | null>(
		null
	);

	const handleCopy = useCallback(
		async (url: string, key: "short" | "destination") => {
			try {
				await copyToClipboard(url);
				setCopiedKey(key);
				setTimeout(() => setCopiedKey(null), 2000);
			} catch (err) {
				console.error("Failed to copy:", err);
			}
		},
		[]
	);

	const handleOpen = useCallback((url: string) => {
		window.open(url, "_blank", "noopener,noreferrer");
	}, []);
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
					typeof nextRange === "function"
						? nextRange(currentRange)
						: nextRange;

				return { linkId, range };
			});
		},
		[defaultCustomDateRange, linkId]
	);

	const tabs = [
		{ name: __("Traffic Statistics"), icon: BarChart3, usesStatsQuery: true },
		{ name: __("Traffic Location"), icon: Globe, usesStatsQuery: false },
		{ name: __("Traffic Sources"), icon: ExternalLink, usesStatsQuery: true },
		{ name: __("Share"), icon: Share2, usesStatsQuery: false },
	];
	const selectedTabUsesStatsQuery =
		tabs[selectedTab]?.usesStatsQuery === true;

	const statsQueryArgs =
		timeRange === "custom"
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
				!selectedTabUsesStatsQuery ||
				(timeRange === "custom" &&
					(!customDateRange.from || !customDateRange.to)),
		}
	);

	if (!link) return null;

	const shortUrl = getShortUrl(link);
	const statsPayload = statsData?.data;

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
										<div className="links-drawer-header-copy min-w-0 flex-1">
											<DialogTitle className="links-drawer-title">
												<div className="links-drawer-title-icon">
													<Link2 className="w-4 h-4 text-accent" />
												</div>
												{__("Link Analytics")}
											</DialogTitle>
											<div className="links-drawer-description flex flex-wrap items-center gap-x-2 gap-y-1.5 mt-2.5 text-xs text-text-muted">
												<span
													className="font-semibold text-heading truncate max-w-37.5 sm:max-w-62.5"
													title={
														link.title ||
														__("Untitled Link")
													}
												>
													{getLinkDisplayTitle(
														link.title,
														__("Untitled Link")
													)}
												</span>
												<span className="text-stroke/60">
													&bull;
												</span>
												<span className="inline-flex items-center gap-1 font-mono text-accent font-semibold preserve-ltr-value shrink-0">
													<span>
														/
														{link.alias ||
															link.shortCode}
													</span>
													<button
														onClick={() =>
															handleCopy(
																shortUrl,
																"short"
															)
														}
														className="text-text-muted hover:text-accent transition-colors cursor-pointer"
														title={
															copiedKey ===
															"short"
																? __("Copied!")
																: __(
																		"Copy short URL"
																	)
														}
													>
														{copiedKey ===
														"short" ? (
															<Check className="h-3 w-3 text-success" />
														) : (
															<Copy className="h-3 w-3" />
														)}
													</button>
													<button
														onClick={() =>
															handleOpen(shortUrl)
														}
														className="text-text-muted hover:text-accent transition-colors cursor-pointer"
														title={__(
															"Open short URL"
														)}
													>
														<ExternalLink className="h-3 w-3" />
													</button>
												</span>
												<span className="text-stroke/60">
													&bull;
												</span>
												<span className="inline-flex items-center gap-1 min-w-0 text-text-muted preserve-ltr-value">
													<span
														className="truncate max-w-37.5 sm:max-w-75"
														title={
															link.destinationUrl
														}
													>
														{link.destinationUrl}
													</span>
													<button
														onClick={() =>
															handleCopy(
																link.destinationUrl ||
																	"",
																"destination"
															)
														}
														className="text-text-muted hover:text-heading transition-colors cursor-pointer shrink-0"
														title={
															copiedKey ===
															"destination"
																? __("Copied!")
																: __(
																		"Copy destination URL"
																	)
														}
													>
														{copiedKey ===
														"destination" ? (
															<Check className="h-3 w-3 text-success" />
														) : (
															<Copy className="h-3 w-3" />
														)}
													</button>
													<button
														onClick={() =>
															handleOpen(
																link.destinationUrl ||
																	""
															)
														}
														className="text-text-muted hover:text-heading transition-colors cursor-pointer shrink-0"
														title={__(
															"Open destination URL"
														)}
													>
														<ExternalLink className="h-3 w-3" />
													</button>
												</span>
											</div>
										</div>
										<button
											type="button"
											onClick={() => setOpen(false)}
											className="links-drawer-close shrink-0 ml-4"
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
													timeRange={timeRange}
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
