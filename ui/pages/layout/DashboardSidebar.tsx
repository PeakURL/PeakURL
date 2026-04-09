import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import {
	PieChart,
	Link2,
	Plug,
	Wrench,
	Users,
	Settings,
	ChevronDown,
	X,
	Info,
	Heart,
	Coffee,
	ExternalLink,
} from 'lucide-react';
import { useGetUrlsQuery } from '@/store/slices/api';
import { useAdminAccess } from '@/hooks';
import { BrandLockup } from '@/components';
import { isDocumentRtl } from '@/i18n/direction';
import { __ } from '@/i18n';
import type { DashboardSidebarProps, NavItem } from './types';

const buildNav = (
	basePath = '/dashboard',
	canManageUsers = false
): NavItem[] => {
	const base =
		basePath === '/'
			? ''
			: basePath.endsWith('/') && basePath.length > 1
				? basePath.slice(0, -1)
				: basePath || '/dashboard';
	const navigation = [
		{
			name: __('Overview'),
			href: base || '/',
			icon: PieChart,
		},
		{
			name: __('All Links'),
			href: `${base || ''}/links`,
			icon: Link2,
		},
		{
			name: __('Users'),
			href: `${base || ''}/users`,
			icon: Users,
			adminOnly: true,
		},
		{
			name: __('Plugins'),
			href: `${base || ''}/plugins`,
			icon: Plug,
			adminOnly: true,
		},
		{
			name: __('Tools'),
			icon: Wrench,
			adminOnly: true,
			children: [
				{
					name: __('Import'),
					href: `${base || ''}/tools/import/file`,
					adminOnly: true,
				},
				{
					name: __('Export'),
					href: `${base || ''}/tools/export`,
				},
				{
					name: __('System Status'),
					href: `${base || ''}/tools/system-status`,
					adminOnly: true,
				},
			],
		},
		{
			name: __('Settings'),
			href: `${base || ''}/settings`,
			icon: Settings,
		},
	];

	return navigation
		.map((item) => {
			if (!item.children?.length) {
				return item;
			}

			return {
				...item,
				children: item.children.filter(
					(child) => !child.adminOnly || canManageUsers
				),
			};
		})
		.filter((item) => {
			if (item.adminOnly && !canManageUsers) {
				return false;
			}

			return !item.children || item.children.length > 0;
		});
};

export const DashboardSidebar = ({
	basePath = '',
	isMobileOpen,
	onMobileClose,
}: DashboardSidebarProps) => {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? 'rtl' : 'ltr';
	const location = useLocation();
	const pathname = location.pathname;
	const { data: urlsRes } = useGetUrlsQuery(undefined);
	const { canManageUsers } = useAdminAccess();
	const links = Array.isArray(urlsRes?.data?.items) ? urlsRes.data.items : [];

	const navigation = useMemo(
		() => buildNav(basePath, canManageUsers),
		[basePath, canManageUsers]
	);
	const base =
		basePath === '/'
			? ''
			: basePath.endsWith('/') && basePath.length > 1
				? basePath.slice(0, -1)
				: basePath || '';
	const [openSections, setOpenSections] = useState<Record<string, boolean>>(
		{}
	);
	const [isAboutOpen, setIsAboutOpen] = useState(false);
	const aboutRef = useRef<HTMLDivElement | null>(null);

	// Close when clicking anywhere outside the about panel
	useEffect(() => {
		if (!isAboutOpen) return;
		const handler = (event: MouseEvent) => {
			if (
				aboutRef.current &&
				event.target instanceof Node &&
				!aboutRef.current.contains(event.target)
			) {
				setIsAboutOpen(false);
			}
		};
		document.addEventListener('mousedown', handler);
		return () => document.removeEventListener('mousedown', handler);
	}, [isAboutOpen]);

	const getSectionBasePath = (href?: string) =>
		href ? href.replace(/\/[^/]+$/, '') : '';

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
                    fixed inset-y-0 w-64 bg-surface border-stroke
                    ${isRtl ? 'right-0 border-l' : 'left-0 border-r'}
                    transform ${
						isMobileOpen
							? 'translate-x-0'
							: isRtl
								? 'translate-x-full'
								: '-translate-x-full'
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
						{navigation.map((item) => {
							const IconComponent = item.icon;
							const childHrefBase = getSectionBasePath(
								item.children?.[0]?.href
							);
							const isChildActive = item.children?.some(
								(child) =>
									pathname === child.href ||
									pathname.startsWith(`${child.href}/`)
							);
							const isSectionActive =
								Boolean(isChildActive) ||
								(Boolean(childHrefBase) &&
									(pathname === childHrefBase ||
										pathname.startsWith(
											`${childHrefBase}/`
										)));
							const isOpen =
								isSectionActive ||
								Boolean(openSections[item.name]);
							const isActive =
								pathname === item.href ||
								(pathname.startsWith(`${item.href}/`) &&
									item.href !== base);

							if (item.children?.length) {
								return (
									<div key={item.name} className="space-y-1">
										<button
											type="button"
											onClick={() =>
												setOpenSections((current) => ({
													...current,
													[item.name]:
														!current[item.name],
												}))
											}
											className={`
												w-full flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-lg transition-colors
												cursor-pointer
												${
													isSectionActive
														? 'text-heading bg-surface-alt'
														: 'text-text-muted hover:text-accent hover:bg-surface-alt'
												}
											`}
											dir={direction}
										>
											<IconComponent
												size={18}
												className={`shrink-0 ${
													isSectionActive
														? 'text-accent'
														: 'text-text-muted'
												}`}
											/>
											<span className="text-inline-start flex-1">
												{item.name}
											</span>
											<ChevronDown
												size={16}
												className={`transition-transform ${
													isOpen ? 'rotate-180' : ''
												}`}
											/>
										</button>

										{isOpen && (
											<div className="sidebar-submenu-rail space-y-1">
												{item.children.map((child) => {
													const isChildLinkActive =
														pathname ===
															child.href ||
														pathname.startsWith(
															`${child.href}/`
														);

													return (
														<Link
															key={child.name}
															to={child.href}
															onClick={
																onMobileClose
															}
													className={`
																text-inline-start block rounded-lg px-3 py-2 text-sm font-medium transition-colors
																${
																	isChildLinkActive
																		? 'bg-accent text-white shadow-sm'
																		: 'text-text-muted hover:bg-surface-alt hover:text-accent'
																}
															`}
														>
															{child.name}
														</Link>
													);
												})}
											</div>
										)}
									</div>
								);
							}

							return (
								<Link
									key={item.name}
									to={item.href || base || '/dashboard'}
									onClick={onMobileClose}
									className={`
                                        relative w-full flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-lg transition-colors
                                        cursor-pointer
                                        ${
											isActive
												? 'text-white bg-accent shadow-sm'
												: 'text-text-muted hover:text-accent hover:bg-surface-alt'
										}
                                    `}
									dir={direction}
								>
									<IconComponent
										size={18}
										className={`shrink-0 ${
											isActive
												? 'text-white'
												: 'text-text-muted'
										}`}
									/>
									<span className="text-inline-start flex-1">
										{item.name}
									</span>
									{item.name === __('All Links') &&
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

				<div
					ref={aboutRef}
					className="shrink-0 border-t border-stroke px-3 py-3 space-y-0.5"
				>
					{/* Sub-items sit above the trigger so they animate upward into view */}
					<div
						className={`overflow-hidden transition-[max-height,opacity] duration-200 ease-in-out ${
							isAboutOpen
								? 'max-h-40 opacity-100'
								: 'max-h-0 opacity-0'
						}`}
					>
						<div className="pb-1 space-y-0.5">
							<Link
								to={`${base || '/dashboard'}/about`}
								onClick={onMobileClose}
								className={`flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
									pathname === `${base || '/dashboard'}/about`
										? 'bg-accent text-white shadow-sm'
										: 'text-text-muted hover:bg-surface-alt hover:text-accent'
								}`}
							>
								<Info size={15} className="shrink-0" />
								<span className="flex-1">
									{__('About PeakURL')}
								</span>
							</Link>
							<a
								href="https://peakurl.org/sponsor"
								target="_blank"
								rel="noreferrer"
								className="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-text-muted hover:bg-surface-alt hover:text-accent transition-colors"
								dir={direction}
							>
								<Heart size={15} className="shrink-0" />
								<span className="flex-1">{__('Sponsor')}</span>
								<ExternalLink
									size={12}
									className="shrink-0 opacity-40"
								/>
							</a>
							<a
								href="https://buymeacoffee.com/PeakURL"
								target="_blank"
								rel="noreferrer"
								className="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-text-muted hover:bg-surface-alt hover:text-accent transition-colors"
								dir={direction}
							>
								<Coffee size={15} className="shrink-0" />
								<span className="flex-1">
									{__('Buy Me a Coffee')}
								</span>
								<ExternalLink
									size={12}
									className="shrink-0 opacity-40"
								/>
							</a>
						</div>
					</div>

					{/* Trigger */}
					<button
						type="button"
						onClick={() => setIsAboutOpen((prev) => !prev)}
						className={`w-full flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-lg transition-colors cursor-pointer ${
							isAboutOpen
								? 'text-heading bg-surface-alt'
								: 'text-text-muted hover:text-accent hover:bg-surface-alt'
						}`}
						dir={direction}
					>
						<Info
							size={18}
							className={`shrink-0 ${isAboutOpen ? 'text-accent' : 'text-text-muted'}`}
						/>
						<span className="text-inline-start flex-1">
							{__('About PeakURL')}
						</span>
						<ChevronDown
							size={16}
							className={`shrink-0 transition-transform duration-200 ${
								isAboutOpen ? 'rotate-180' : ''
							}`}
						/>
					</button>
				</div>
			</aside>
		</>
	);
};

export default DashboardSidebar;
