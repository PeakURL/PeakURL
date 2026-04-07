import type { SubmitEvent } from 'react';
import { useDeferredValue, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useAdminAccess } from './useAdminAccess';
import { useGetAllUsersQuery, useGetUrlsQuery } from '@/store/slices/api';
import { __ } from '@/i18n';
import {
	buildLinkStatsPath,
	buildLinksSearchPath,
	findDashboardRouteMatches,
	findDashboardUserMatches,
	getDashboardSearchValueFromLocation,
	getLinkDisplayTitle,
	resolveDashboardSearchPath,
	buildShortUrl,
} from '@/utils';
import type { ClearSearchOptions } from './types';

export const useDashboardSearch = () => {
	const location = useLocation();
	const navigate = useNavigate();
	const capabilities = useAdminAccess();
	const [query, setQuery] = useState(() =>
		getDashboardSearchValueFromLocation(location)
	);
	const [isOpen, setIsOpen] = useState(false);
	const trimmedQuery = query.trim();
	const deferredQuery = useDeferredValue(trimmedQuery);
	const searchCapabilities = useMemo(
		() => ({
			canManageUsers: capabilities.canManageUsers,
			canManageApiKeys: capabilities.canManageApiKeys,
			canManageWebhooks: capabilities.canManageWebhooks,
			canManageMailDelivery: capabilities.canManageMailDelivery,
			canManageLocationData: capabilities.canManageLocationData,
			canManageUpdates: capabilities.canManageUpdates,
			canExportLinks:
				capabilities.capabilities.viewAllLinks ||
				capabilities.capabilities.viewOwnLinks,
		}),
		[
			capabilities.canManageUsers,
			capabilities.canManageApiKeys,
			capabilities.canManageWebhooks,
			capabilities.canManageMailDelivery,
			capabilities.canManageLocationData,
			capabilities.canManageUpdates,
			capabilities.capabilities.viewAllLinks,
			capabilities.capabilities.viewOwnLinks,
		]
	);
	const routeMatches = useMemo(
		() => findDashboardRouteMatches(deferredQuery, searchCapabilities, 5),
		[deferredQuery, searchCapabilities]
	);
	const { data: usersData, isFetching: isFetchingUsers } =
		useGetAllUsersQuery(undefined, {
			skip:
				!searchCapabilities.canManageUsers || deferredQuery.length < 2,
		});
	const { data: linksData, isFetching: isFetchingLinks } = useGetUrlsQuery(
		{
			page: 1,
			limit: 5,
			sortBy: 'updatedAt',
			sortOrder: 'desc',
			search: deferredQuery,
		},
		{
			skip: deferredQuery.length < 2,
		}
	);
	const linkMatches = useMemo(() => {
		const items = linksData?.data?.items || [];

		return items.map((link) => {
			const shortCode = link.alias || link.shortCode || '';
			const shortUrl = buildShortUrl(link);

			return {
				id: link.id,
				title: getLinkDisplayTitle(
					link.title,
					shortCode || link.destinationUrl || __('Untitled Link')
				),
				description: shortUrl,
				meta: link.destinationUrl || '',
				href: shortCode
					? buildLinkStatsPath(shortCode)
					: buildLinksSearchPath(deferredQuery),
			};
		});
	}, [linksData, deferredQuery]);
	const userMatches = useMemo(
		() => findDashboardUserMatches(deferredQuery, usersData?.data || [], 5),
		[deferredQuery, usersData]
	);

	const handleSubmit = (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		const normalizedPath = location.pathname.replace(/\/+$/, '') || '/';

		if (!trimmedQuery) {
			if (
				'/dashboard/links' === normalizedPath &&
				new URLSearchParams(location.search).has('search')
			) {
				navigate(buildLinksSearchPath(''));
			}

			setIsOpen(false);
			return;
		}

		handleSelect(
			resolveDashboardSearchPath(trimmedQuery, searchCapabilities)
		);
	};

	const handleSelect = (href: string) => {
		setQuery('');
		setIsOpen(false);
		navigate(href);
	};

	const handleChange = (value: string) => {
		setQuery(value);
		setIsOpen(Boolean(value.trim()));
	};

	const handleFocus = () => {
		if (trimmedQuery) {
			setIsOpen(true);
		}
	};

	const clearSearch = (options: ClearSearchOptions = {}) => {
		const shouldResetLinksSearch = Boolean(options.resetLinksSearch);

		setQuery('');
		setIsOpen(false);

		if (!shouldResetLinksSearch) {
			return;
		}

		const normalizedPath = location.pathname.replace(/\/+$/, '') || '/';
		const params = new URLSearchParams(location.search);

		if ('/dashboard/links' === normalizedPath && params.has('search')) {
			navigate(buildLinksSearchPath(''));
		}
	};

	return {
		query,
		isOpen: isOpen && Boolean(trimmedQuery),
		routeMatches,
		userMatches,
		linkMatches,
		isFetchingLinks,
		isFetchingUsers,
		allLinksHref: buildLinksSearchPath(trimmedQuery),
		handleChange,
		handleFocus,
		handleSelect,
		clearSearch,
		handleSubmit,
	};
};
