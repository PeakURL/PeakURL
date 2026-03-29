// @ts-nocheck
'use client';
import { useEffect, useState } from 'react';
import {
	CircleCheckBig,
	Link2,
	MousePointerClick,
	Users,
} from 'lucide-react';
// LocalStorage keys for persistence (defined outside component to satisfy hook deps)
const LS_KEYS = {
	sortBy: 'admin_links_sortBy',
	sortOrder: 'admin_links_sortOrder',
	limit: 'admin_links_limit',
};
import {
	Header,
	UrlShorteningForm,
	LinksTable,
	TableFooter,
	Pagination,
} from './_components';
import { useGetUrlsQuery } from '@/store/slices/api/urls';
import { useSearchParams } from 'react-router-dom';
import { buildShortUrl, getDefaultShortUrlOrigin } from '@/utils';

function LinksPage() {
	// State for Sorting and Pagination
	const [sortBy, setSortBy] = useState(() =>
		typeof window !== 'undefined'
			? localStorage.getItem(LS_KEYS.sortBy) || 'createdAt'
			: 'createdAt'
	);
	const [sortOrder, setSortOrder] = useState(() =>
		typeof window !== 'undefined'
			? localStorage.getItem(LS_KEYS.sortOrder) || 'desc'
			: 'desc'
	);
	const [limit, setLimit] = useState(() => {
		if (typeof window !== 'undefined') {
			const saved = localStorage.getItem(LS_KEYS.limit);
			const num = Number(saved);
			return !isNaN(num) && num > 0 ? num : 25;
		}
		return 25;
	});
	const [currentPage, setCurrentPage] = useState(1);

	// No need for a load effect since initial state derives from localStorage

	// Persist settings
	useEffect(() => {
		try {
			localStorage.setItem(LS_KEYS.sortBy, sortBy);
			localStorage.setItem(LS_KEYS.sortOrder, sortOrder);
			localStorage.setItem(LS_KEYS.limit, String(limit));
		} catch {}
	}, [sortBy, sortOrder, limit]);

	const {
		data: urlsRes,
		refetch: refetchUrls,
	} = useGetUrlsQuery({
		page: currentPage,
		limit,
		sortBy,
		sortOrder,
	});

	const apiItems = urlsRes?.data?.items ?? [];
	const apiMeta = urlsRes?.data?.meta ?? {
		page: currentPage,
		limit,
		totalItems: apiItems.length,
		totalPages: 1,
	};
	const [searchParams] = useSearchParams();
	const statsShortId = searchParams.get('stats');
	const searchQuery = searchParams.get('search')?.toLowerCase() || '';

	const {
		data: statsLookupRes,
		refetch: refetchStatsLookup,
	} = useGetUrlsQuery(
		statsShortId ? { page: 1, limit: 10, search: statsShortId } : undefined,
		{ skip: !statsShortId }
	);
	const statsLookupItems = statsLookupRes?.data?.items ?? [];
	const statsLink =
		statsShortId && statsLookupItems.length
			? statsLookupItems.find(
					(l) =>
						l.shortCode === statsShortId || l.alias === statsShortId
				) || null
			: null;
	const shortUrlOrigin = getDefaultShortUrlOrigin();
	const [isRefreshing, setIsRefreshing] = useState(false);
	const handleRefresh = async () => {
		if (isRefreshing) {
			return;
		}

		setIsRefreshing(true);
		const startedAt = Date.now();

		try {
			await Promise.all([
				refetchUrls(),
				statsShortId ? refetchStatsLookup() : Promise.resolve(),
			]);
		} finally {
			const remaining = 700 - (Date.now() - startedAt);

			if (remaining > 0) {
				window.setTimeout(() => setIsRefreshing(false), remaining);
			} else {
				setIsRefreshing(false);
			}
		}
	};

	// Filter
	const filteredLinks = apiItems.filter((link) => {
		if (!searchQuery) return true;
		const shortCode = link.alias || link.shortCode || '';
		const shortUrl = buildShortUrl(link, shortUrlOrigin);
		return (
			link.destinationUrl?.toLowerCase().includes(searchQuery) ||
			shortCode.toLowerCase().includes(searchQuery) ||
			link.title?.toLowerCase().includes(searchQuery) ||
			link.alias?.toLowerCase().includes(searchQuery) ||
			shortUrl.toLowerCase().includes(searchQuery)
		);
	});

	// Sort
	// Sorting is handled server-side
	const sortedLinks = filteredLinks;

	// Pagination
	const totalItems = apiMeta.totalItems;
	const totalPages = apiMeta.totalPages;
	const startItem = (apiMeta.page - 1) * apiMeta.limit + 1;
	const endItem = Math.min(apiMeta.page * apiMeta.limit, totalItems);
	const paginatedLinks = sortedLinks;

	// Calculate quick stats (based on filtered links or all links? usually filtered)
	const linksForStats = filteredLinks;
	const totalClicks = linksForStats.reduce(
		(sum, link) => sum + (link.clicks || 0),
		0
	);
	const totalUniqueClicks = linksForStats.reduce(
		(sum, link) => sum + (link.uniqueClicks || 0),
		0
	);
	const activeLinks = linksForStats.filter(
		(link) => link.status === 'active'
	).length;

	const handleExport = () => {
		// Simple CSV export
		const headers = [
			'Short URL',
			'Destination URL',
			'Clicks',
			'Unique Clicks',
			'Created At',
		];
		const rows = sortedLinks.map((link) => {
			const shortUrl = buildShortUrl(link, shortUrlOrigin);
			return [
				shortUrl,
				link.destinationUrl,
				link.clicks,
				link.uniqueClicks,
				link.createdAt,
			];
		});

		const csvContent =
			'data:text/csv;charset=utf-8,' +
			[headers.join(','), ...rows.map((e) => e.join(','))].join('\n');

		const encodedUri = encodeURI(csvContent);
		const link = document.createElement('a');
		link.setAttribute('href', encodedUri);
		link.setAttribute('download', 'links_export.csv');
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
	};

	return (
		<div className="space-y-5 pb-8">
			<Header
				onRefresh={handleRefresh}
				isRefreshing={isRefreshing}
			/>

			{/* Quick Stats - Compact Row */}
			<div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
				<div className="bg-surface border border-(--color-stroke) rounded-lg p-3.5">
					<div className="flex items-center gap-2.5 mb-2">
						<div className="w-8 h-8 rounded-lg bg-primary-500/10 flex items-center justify-center shrink-0">
							<Link2 className="h-4 w-4 text-primary-600 dark:text-primary-400" />
						</div>
						<span className="text-xs font-medium text-success">
							+12%
						</span>
					</div>
					<div className="text-xl font-bold text-heading">
						{linksForStats.length}
					</div>
					<div className="text-xs text-muted mt-0.5">Total Links</div>
				</div>

				<div className="bg-surface border border-(--color-stroke) rounded-lg p-3.5">
					<div className="flex items-center gap-2.5 mb-2">
						<div className="w-8 h-8 rounded-lg bg-success/10 flex items-center justify-center shrink-0">
							<CircleCheckBig className="h-4 w-4 text-success" />
						</div>
						<span className="text-xs font-medium text-success">
							Active
						</span>
					</div>
					<div className="text-xl font-bold text-heading">
						{activeLinks}
					</div>
					<div className="text-xs text-muted mt-0.5">
						Active Links
					</div>
				</div>

				<div className="bg-surface border border-(--color-stroke) rounded-lg p-3.5">
					<div className="flex items-center gap-2.5 mb-2">
						<div className="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center shrink-0">
							<MousePointerClick className="h-4 w-4 text-blue-600 dark:text-blue-400" />
						</div>
						<span className="text-xs font-medium text-success">
							+28%
						</span>
					</div>
					<div className="text-xl font-bold text-heading">
						{totalClicks.toLocaleString()}
					</div>
					<div className="text-xs text-muted mt-0.5">
						Total Clicks
					</div>
				</div>

				<div className="bg-surface border border-(--color-stroke) rounded-lg p-3.5">
					<div className="flex items-center gap-2.5 mb-2">
						<div className="w-8 h-8 rounded-lg bg-purple-500/10 flex items-center justify-center shrink-0">
							<Users className="h-4 w-4 text-purple-600 dark:text-purple-400" />
						</div>
						<span className="text-xs font-medium text-success">
							+35%
						</span>
					</div>
					<div className="text-xl font-bold text-heading">
						{totalUniqueClicks.toLocaleString()}
					</div>
					<div className="text-xs text-muted mt-0.5">
						Unique Visitors
					</div>
				</div>
			</div>

			<UrlShorteningForm />

			<LinksTable
				links={paginatedLinks}
				statsShortId={statsShortId}
				statsLink={statsLink}
			/>

			<TableFooter
				totalLinks={filteredLinks.length}
				totalClicks={totalClicks}
				sortBy={sortBy}
				setSortBy={setSortBy}
				sortOrder={sortOrder}
				setSortOrder={setSortOrder}
				limit={limit}
				setLimit={setLimit}
				onExport={handleExport}
			/>

			{totalPages > 1 && (
				<Pagination
					currentPage={currentPage}
					totalPages={totalPages}
					onPageChange={setCurrentPage}
					startItem={startItem}
					endItem={endItem}
					totalItems={totalItems}
				/>
			)}
		</div>
	);
}

export default LinksPage;
