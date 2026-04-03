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
import {
	buildShortUrl,
	getDefaultShortUrlOrigin,
	serializeCsv,
} from '@/utils';
import { __ } from '@/i18n';

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

	const {
		data: urlsRes,
		refetch: refetchUrls,
	} = useGetUrlsQuery({
		page: currentPage,
		limit,
		sortBy,
		sortOrder,
		search: searchQuery,
	});

	const apiItems = urlsRes?.data?.items ?? [];
	const apiMeta = urlsRes?.data?.meta ?? {
		page: currentPage,
		limit,
		totalItems: apiItems.length,
		totalPages: 1,
	};
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

	const getExportItems = () =>
		sortedLinks.map((link) => {
			const alias = link.alias || link.shortCode || '';

			return {
				url: link.destinationUrl || '',
				alias,
				title: link.title || '',
				password: '',
				expires: '',
				short_url: buildShortUrl(link, shortUrlOrigin),
				clicks: link.clicks ?? '',
				unique_clicks: link.uniqueClicks ?? '',
				created_at: link.createdAt || '',
			};
		});

	const buildCsvExport = (items) => {
		const headers = [
			'url',
			'alias',
			'title',
			'password',
			'expires',
			'short_url',
			'clicks',
			'unique_clicks',
			'created_at',
		];
		const rows = items.map((item) => headers.map((header) => item[header]));

		return serializeCsv(headers, rows);
	};

	const buildJsonExport = (items) => JSON.stringify(items, null, 2);

	const buildXmlExport = (items) => {
		const escapeXml = (value) =>
			String(value ?? '')
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&apos;');

		const itemXml = items
			.map(
				(item) => `  <url>
    <destinationUrl>${escapeXml(item.url)}</destinationUrl>
    <alias>${escapeXml(item.alias)}</alias>
    <title>${escapeXml(item.title)}</title>
    <password>${escapeXml(item.password)}</password>
    <expiresAt>${escapeXml(item.expires)}</expiresAt>
    <shortUrl>${escapeXml(item.short_url)}</shortUrl>
    <clicks>${escapeXml(item.clicks)}</clicks>
    <uniqueClicks>${escapeXml(item.unique_clicks)}</uniqueClicks>
    <createdAt>${escapeXml(item.created_at)}</createdAt>
  </url>`
			)
			.join('\n');

		return `<urls>\n${itemXml}\n</urls>`;
	};

	const handleExport = (format = 'csv') => {
		const exportItems = getExportItems();
		const exporters = {
			csv: {
				content: buildCsvExport(exportItems),
				type: 'text/csv;charset=utf-8;',
				filename: 'links_export.csv',
			},
			json: {
				content: buildJsonExport(exportItems),
				type: 'application/json;charset=utf-8;',
				filename: 'links_export.json',
			},
			xml: {
				content: buildXmlExport(exportItems),
				type: 'application/xml;charset=utf-8;',
				filename: 'links_export.xml',
			},
		};
		const selectedExport = exporters[format] || exporters.csv;
		const blob = new Blob([selectedExport.content], {
			type: selectedExport.type,
		});
		const blobUrl = window.URL.createObjectURL(blob);
		const link = document.createElement('a');
		link.setAttribute('href', blobUrl);
		link.setAttribute('download', selectedExport.filename);
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		window.URL.revokeObjectURL(blobUrl);
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
