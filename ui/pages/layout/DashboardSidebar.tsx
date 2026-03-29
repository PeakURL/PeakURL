// @ts-nocheck
'use client';

import { Link, useLocation } from 'react-router-dom';
import {
	PieChart,
	Link2,
	Plug,
	Upload,
	Code,
	Users,
	Settings,
	X,
} from 'lucide-react';
import { useGetUrlsQuery } from '@/store/slices/api/urls';
import { useAdminAccess } from '@/hooks';
import { BrandLockup } from '@/components';

const buildNav = (basePath = '/dashboard') => {
	const base =
		basePath === '/'
			? ''
			: basePath.endsWith('/') && basePath.length > 1
				? basePath.slice(0, -1)
				: basePath || '/dashboard';
	return [
		{
			name: 'Overview',
			href: base || '/',
			icon: PieChart,
		},
		{
			name: 'All Links',
			href: `${base || ''}/links`,
			icon: Link2,
		},
		{
			name: 'Users',
			href: `${base || ''}/users`,
			icon: Users,
		},
		{
			name: 'Plugins',
			href: `${base || ''}/plugins`,
			icon: Plug,
		},
		{
			name: 'Bulk Import',
			href: `${base || ''}/bulk-import`,
			icon: Upload,
		},
		{
			name: 'API & Docs',
			href: `${base || ''}/api-docs`,
			icon: Code,
		},
		{
			name: 'Settings',
			href: `${base || ''}/settings`,
			icon: Settings,
		},
	];
};

export const DashboardSidebar = ({
	basePath = '',
	isMobileOpen,
	onMobileClose,
}) => {
	const location = useLocation();
	const pathname = location.pathname;
	const { data: urlsRes } = useGetUrlsQuery();
	const { canManageUsers } = useAdminAccess();
	const links = urlsRes?.data?.items ?? [];

	const navigation = buildNav(basePath);
	const base =
		basePath === '/'
			? ''
			: basePath.endsWith('/') && basePath.length > 1
				? basePath.slice(0, -1)
				: basePath || '';

	const filteredNavigation = navigation.filter((item) => {
		if (
			item.name === 'Plugins' ||
			item.name === 'Users' ||
			item.name === 'Bulk Import' ||
			item.name === 'API & Docs'
		) {
			return canManageUsers;
		}
		return true;
	});

	return (
		<>
			{isMobileOpen && (
				<div
					className="fixed inset-0 bg-black/50 z-40 lg:hidden"
					onClick={onMobileClose}
				/>
			)}

			<aside
				className={`
                    fixed inset-y-0 left-0 w-64 bg-surface border-r border-stroke
                    transform ${
						isMobileOpen ? 'translate-x-0' : '-translate-x-full'
					} lg:translate-x-0
                    transition-transform duration-300 ease-in-out z-50 lg:z-30
                    flex flex-col
                `}
			>
				<div className="h-16 flex items-center px-5 border-b border-stroke shrink-0">
					<BrandLockup
						to={base || '/dashboard'}
						size="md"
						className="flex-1"
					/>
					<button
						onClick={onMobileClose}
						className="lg:hidden p-2 text-text-muted hover:text-heading rounded-lg hover:bg-surface-alt transition-colors"
					>
						<X size={20} />
					</button>
				</div>

				<nav className="flex-1 py-4 px-3 overflow-y-auto">
					<div className="space-y-1">
						{filteredNavigation.map((item) => {
							const isActive =
								pathname === item.href ||
								(pathname.startsWith(`${item.href}/`) &&
									item.href !== base);
							const IconComponent = item.icon;
							return (
								<Link
									key={item.name}
									to={item.href}
									onClick={onMobileClose}
									className={`
                                        relative w-full flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors
                                        cursor-pointer
                                        ${
											isActive
												? 'text-white bg-accent shadow-sm'
												: 'text-text-muted hover:text-accent hover:bg-surface-alt'
										}
                                    `}
								>
									<IconComponent
										size={18}
										className={`mr-3 ${
											isActive
												? 'text-white'
												: 'text-text-muted'
										}`}
									/>
									<span className="flex-1 text-left">
										{item.name}
									</span>
									{item.name === 'All Links' &&
										links.length > 0 && (
											<span
												className={`
                                                text-xs font-semibold px-2 py-0.5 rounded-md
                                                ${
													isActive
														? 'bg-white/20 text-white'
														: 'bg-surface-alt text-text-muted'
												}
                                            `}
											>
												{links.length}
											</span>
										)}
								</Link>
							);
						})}
					</div>
				</nav>
			</aside>
		</>
	);
};

export default DashboardSidebar;
