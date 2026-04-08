import {
	Dialog,
	DialogPanel,
	DialogTitle,
	Tab,
	TabGroup,
	TabList,
	TabPanel,
	TabPanels,
} from '@headlessui/react';
import { X, Link2, BarChart3, Globe, Share2, ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { useGetLinkStatsQuery } from '@/store/slices/api';
import { isDocumentRtl } from '@/i18n/direction';
import { buildShortUrl } from '@/utils';
import { __ } from '@/i18n';
import StatCards from './StatCards';
import ClickChart from './ClickChart';
import HistoricalStats from './HistoricalStats';
import BestDay from './BestDay';
import ShareTab from './ShareTab';
import TrafficLocationTab from './TrafficLocationTab';
import TrafficSourcesTab from './TrafficSourcesTab';
import QuickInsights from './QuickInsights';
import type { StatsDrawerProps, StatsTimeRange } from './types';

export default function StatsDrawer({ open, setOpen, link }: StatsDrawerProps) {
	const [selectedTab, setSelectedTab] = useState(0);
	const [timeRange, setTimeRange] = useState<StatsTimeRange>('7d');
	const isRtl = isDocumentRtl();

	const getDaysFromRange = (range: StatsTimeRange): number => {
		switch (range) {
			case '24h':
				return 1;
			case '7d':
				return 7;
			case '30d':
				return 30;
			case 'all':
				return 90;
		}
	};

	const { data: statsData, isLoading } = useGetLinkStatsQuery(
		{
			id: link?.id || '',
			days: getDaysFromRange(timeRange),
		},
		{
			skip: !link?.id || !open || selectedTab !== 0,
		}
	);

	if (!link) return null;

	const shortUrl = buildShortUrl(link);
	const statsPayload = statsData?.data;

	const tabs = [
		{ name: __('Traffic Statistics'), icon: BarChart3 },
		{ name: __('Traffic Location'), icon: Globe },
		{ name: __('Traffic Sources'), icon: ExternalLink },
		{ name: __('Share'), icon: Share2 },
	];

	return (
		<Dialog open={open} onClose={setOpen} className="relative z-50">
			<div className="fixed inset-0 bg-black/30" aria-hidden="true" />

			<div className="fixed inset-0 overflow-hidden">
				<div className="absolute inset-0 overflow-hidden">
					<div
						className={`pointer-events-none fixed inset-y-0 flex max-w-full ${
							isRtl
								? 'left-0 pr-0 sm:pr-16'
								: 'right-0 pl-0 sm:pl-16'
						}`}
					>
						<DialogPanel
							transition
							className={`pointer-events-auto w-screen max-w-4xl transform transition duration-500 ease-in-out sm:duration-700 ${
								isRtl
									? 'data-closed:-translate-x-full'
									: 'data-closed:translate-x-full'
							}`}
						>
							<div className="flex h-full flex-col overflow-y-auto bg-surface shadow-xl">
								{/* Header */}
								<div className="bg-surface-alt px-4 py-6 sm:px-6">
									<div className="flex items-start justify-between">
										<div className="space-y-1">
											<DialogTitle className="text-lg font-semibold text-heading flex items-center gap-2">
												<div className="w-8 h-8 rounded-lg bg-accent/10 flex items-center justify-center">
													<Link2 className="w-4 h-4 text-accent" />
												</div>
												{__('Link Analytics')}
											</DialogTitle>
											<p className="text-sm text-text-muted">
												{__(
													'Detailed statistics and performance metrics'
												)}
											</p>
										</div>
										<button
											type="button"
											onClick={() => setOpen(false)}
											className="rounded-lg text-text-muted hover:text-heading hover:bg-surface-alt p-2 transition-all"
										>
											<span className="sr-only">
												{__('Close panel')}
											</span>
											<X className="w-5 h-5" />
										</button>
									</div>
								</div>

								{/* Content */}
								<div className="flex-1 px-4 py-6 sm:px-6">
									{/* Tabs */}
									<TabGroup
										selectedIndex={selectedTab}
										onChange={setSelectedTab}
									>
										<TabList className="flex gap-2 border-b border-stroke mb-6 overflow-x-auto no-scrollbar">
											{tabs.map((tab) => {
												const Icon = tab.icon;
												return (
													<Tab
														key={tab.name}
														className={({
															selected,
														}) =>
															`flex items-center gap-2 px-4 py-3 text-sm font-medium transition-all outline-none border-b-2 -mb-px whitespace-nowrap ${
																selected
																	? 'border-accent text-accent'
																	: 'border-transparent text-text-muted hover:text-heading'
															}`
														}
													>
														<Icon className="w-4 h-4 shrink-0" />
														{tab.name}
													</Tab>
												);
											})}
										</TabList>

										<TabPanels>
											{/* Traffic Statistics Tab */}
											<TabPanel className="space-y-4">
												{/* Stats Cards at the top */}
												<StatCards
													link={link}
													stats={statsPayload}
													isLoading={isLoading}
												/>

												{/* Quick Insights */}
												<QuickInsights link={link} />

												{/* Click Chart */}
												<ClickChart
													link={link}
													stats={statsPayload}
													isLoading={isLoading}
													timeRange={timeRange}
													setTimeRange={setTimeRange}
													selectedTab={selectedTab}
													open={open}
												/>

												{/* Historical Stats */}
												<HistoricalStats link={link} />

												{/* Best Day */}
												<BestDay link={link} />
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
								<div className="border-t border-stroke px-4 py-4 bg-surface-alt sm:px-6">
									<div
										className={`flex gap-3 ${
											isRtl ? 'justify-start' : 'justify-end'
										}`}
									>
										<button
											type="button"
											onClick={() => setOpen(false)}
											className="px-4 py-2 rounded-lg bg-surface border border-stroke text-heading hover:bg-surface-alt transition-all text-sm font-medium"
										>
											{__('Close')}
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
