import { useEffect, useState } from 'react';
import { CircleCheckBig, Link2, MousePointerClick, Users } from 'lucide-react';
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
import { useGetUrlsQuery } from '@/store/slices/api';
import { useSearchParams } from 'react-router-dom';
import { __ } from '@/i18n';
import type {
	LinkRecord,
	LinksMeta,
	LinksSortBy,
	LinksSortOrder,
} from './_components/types';
import type { GetUrlsResponse } from './types';

function LinksPage() {
	// State for Sorting and Pagination
	const [sortBy, setSortBy] = useState<LinksSortBy>(() =>
		typeof window !== 'undefined'
			? (localStorage.getItem(LS_KEYS.sortBy) as LinksSortBy) ||
				'createdAt'
			: 'createdAt'
	);
	const [sortOrder, setSortOrder] = useState<LinksSortOrder>(() =>
		typeof window !== 'undefined'
			? (localStorage.getItem(LS_KEYS.sortOrder) as LinksSortOrder) ||
				'desc'
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
	const [searchParams] = useSearchParams();
	const statsShortId = searchParams.get('stats');
	const searchQuery = searchParams.get('search')?.trim() || '';

	// No need for a load effect since initial state derives from localStorage

	// Persist settings
	useEffect(() => {
		try {
			localStorage.setItem(LS_KEYS.sortBy, sortBy);
			localStorage.setItem(LS_KEYS.sortOrder, sortOrder);
			localStorage.setItem(LS_KEYS.limit, String(limit));
		} catch {}
	}, [sortBy, sortOrder, limit]);

	const { data: urlsRes, refetch: refetchUrls } = useGetUrlsQuery({
		page: currentPage,
		limit,
		sortBy,
		sortOrder,
		search: searchQuery,
	});
	const typedUrlsRes = urlsRes as GetUrlsResponse | undefined;

	const apiItems: LinkRecord[] = typedUrlsRes?.data?.items ?? [];
	const apiMeta: LinksMeta = typedUrlsRes?.data?.meta ?? {
		page: currentPage,
		limit,
		totalItems: apiItems.length,
		totalPages: 1,
	};
	const { data: statsLookupRes, refetch: refetchStatsLookup } =
		useGetUrlsQuery(
			statsShortId
				? { page: 1, limit: 10, search: statsShortId }
				: undefined,
			{ skip: !statsShortId }
		);
	const typedStatsLookupRes = statsLookupRes as GetUrlsResponse | undefined;
	const statsLookupItems: LinkRecord[] =
		typedStatsLookupRes?.data?.items ?? [];
	const statsLink =
		statsShortId && statsLookupItems.length
			? statsLookupItems.find(
					(link: LinkRecord) =>
						link.shortCode === statsShortId ||
						link.alias === statsShortId
				) || null
			: null;
	const [isRefreshing, setIsRefreshing] = useState(false);

	useEffect(() => {
		setCurrentPage(1);
	}, [searchQuery]);

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
	const filteredLinks = apiItems;

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
		(sum: number, link: LinkRecord) => sum + (link.clicks || 0),
		0
	);
	const totalUniqueClicks = linksForStats.reduce(
		(sum: number, link: LinkRecord) => sum + (link.uniqueClicks || 0),
		0
	);
	const activeLinks = linksForStats.filter(
		(link: LinkRecord) => link.status === 'active'
	).length;

	return (
		<div className="space-y-5 pb-8">
			<Header onRefresh={handleRefresh} isRefreshing={isRefreshing} />

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
					<div className="text-xs text-muted mt-0.5">
						{__('Total Links')}
					</div>
				</div>

				<div className="bg-surface border border-(--color-stroke) rounded-lg p-3.5">
					<div className="flex items-center gap-2.5 mb-2">
						<div className="w-8 h-8 rounded-lg bg-success/10 flex items-center justify-center shrink-0">
							<CircleCheckBig className="h-4 w-4 text-success" />
						</div>
						<span className="text-xs font-medium text-success">
							{__('Active')}
						</span>
					</div>
					<div className="text-xl font-bold text-heading">
						{activeLinks}
					</div>
					<div className="text-xs text-muted mt-0.5">
						{__('Active Links')}
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
						{__('Total Clicks')}
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
						{__('Unique Visitors')}
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
