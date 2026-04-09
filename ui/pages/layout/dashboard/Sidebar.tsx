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
import { cn } from '@/utils';
import type { NavItem, SidebarProps } from './types';

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

const getSectionToggleClassName = (isActive: boolean): string =>
	cn(
		'dashboard-sidebar-section-toggle',
		isActive && 'dashboard-sidebar-section-toggle-active'
	);

const getSectionIconClassName = (isActive: boolean): string =>
	cn(
		'dashboard-sidebar-section-icon',
		isActive && 'dashboard-sidebar-section-icon-active'
	);

const getSectionCaretClassName = (isOpen: boolean): string =>
	cn(
		'dashboard-sidebar-section-caret',
		isOpen && 'dashboard-sidebar-section-caret-open'
	);

const getSubmenuClassName = (isOpen: boolean): string =>
	cn('dashboard-sidebar-submenu', isOpen && 'dashboard-sidebar-submenu-open');

const getSubmenuLinkClassName = (isActive: boolean): string =>
	cn(
		'dashboard-sidebar-submenu-link',
		isActive && 'dashboard-sidebar-submenu-link-active'
	);

const getLinkClassName = (isActive: boolean): string =>
	cn('dashboard-sidebar-link', isActive && 'dashboard-sidebar-link-active');

const getLinkIconClassName = (isActive: boolean): string =>
	cn(
		'dashboard-sidebar-link-icon',
		isActive && 'dashboard-sidebar-link-icon-active'
	);

const getLinkBadgeClassName = (isActive: boolean): string =>
	cn(
		'dashboard-sidebar-link-badge',
		isActive && 'dashboard-sidebar-link-badge-active'
	);

const getAboutPanelClassName = (isOpen: boolean): string =>
	cn(
		'dashboard-sidebar-about-panel',
		isOpen && 'dashboard-sidebar-about-panel-open'
	);

const getAboutLinkClassName = (isActive: boolean): string =>
	cn(
		'dashboard-sidebar-about-link',
		isActive && 'dashboard-sidebar-about-link-active'
	);

export const Sidebar = ({
	basePath = '',
	isMobileOpen,
	onMobileClose,
}: SidebarProps) => {
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
					className="dashboard-sidebar-overlay"
					onClick={onMobileClose}
				/>
			)}

			<aside
				className={cn(
					'dashboard-sidebar',
					isRtl && 'dashboard-sidebar-rtl',
					isMobileOpen && 'dashboard-sidebar-open'
				)}
			>
				<div className="dashboard-sidebar-header">
					<BrandLockup
						to={base || '/dashboard'}
						size="md"
						className="dashboard-sidebar-brand"
					/>
					<button
						onClick={onMobileClose}
						className="dashboard-sidebar-close"
					>
						<X size={20} />
					</button>
				</div>

				<nav className="dashboard-sidebar-nav">
					<div className="dashboard-sidebar-list">
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
									<div
										key={item.name}
										className="dashboard-sidebar-section"
									>
										<button
											type="button"
											onClick={() =>
												setOpenSections((current) => ({
													...current,
													[item.name]:
														!current[item.name],
												}))
											}
											className={getSectionToggleClassName(
												isSectionActive
											)}
											dir={direction}
										>
											<IconComponent
												size={18}
												className={getSectionIconClassName(
													isSectionActive
												)}
											/>
											<span className="dashboard-sidebar-section-label">
												{item.name}
											</span>
											<ChevronDown
												size={16}
												className={getSectionCaretClassName(
													isOpen
												)}
											/>
										</button>

										<div className={getSubmenuClassName(isOpen)}>
											<div className="dashboard-sidebar-submenu-list">
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
															className={getSubmenuLinkClassName(
																isChildLinkActive
															)}
														>
															{child.name}
														</Link>
													);
												})}
											</div>
										</div>
									</div>
								);
							}

							return (
								<Link
									key={item.name}
									to={item.href || base || '/dashboard'}
									onClick={onMobileClose}
									className={getLinkClassName(isActive)}
									dir={direction}
								>
									<IconComponent
										size={18}
										className={getLinkIconClassName(isActive)}
									/>
									<span className="dashboard-sidebar-link-label">
										{item.name}
									</span>
									{item.name === __('All Links') &&
										links.length > 0 && (
											<span
												className={getLinkBadgeClassName(
													isActive
												)}
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
					className="dashboard-sidebar-footer"
				>
					<div className={getAboutPanelClassName(isAboutOpen)}>
						<div className="dashboard-sidebar-about-panel-list">
							<Link
								to={`${base || '/dashboard'}/about`}
								onClick={onMobileClose}
								className={getAboutLinkClassName(
									pathname === `${base || '/dashboard'}/about`
								)}
							>
								<Info
									size={15}
									className="dashboard-sidebar-about-link-icon"
								/>
								<span className="dashboard-sidebar-about-link-label">
									{__('About PeakURL')}
								</span>
							</Link>
							<a
								href="https://peakurl.org/sponsor"
								target="_blank"
								rel="noreferrer"
								className="dashboard-sidebar-about-link"
								dir={direction}
							>
								<Heart
									size={15}
									className="dashboard-sidebar-about-link-icon"
								/>
								<span className="dashboard-sidebar-about-link-label">
									{__('Sponsor')}
								</span>
								<ExternalLink
									size={12}
									className="dashboard-sidebar-about-link-meta"
								/>
							</a>
							<a
								href="https://buymeacoffee.com/PeakURL"
								target="_blank"
								rel="noreferrer"
								className="dashboard-sidebar-about-link"
								dir={direction}
							>
								<Coffee
									size={15}
									className="dashboard-sidebar-about-link-icon"
								/>
								<span className="dashboard-sidebar-about-link-label">
									{__('Buy Me a Coffee')}
								</span>
								<ExternalLink
									size={12}
									className="dashboard-sidebar-about-link-meta"
								/>
							</a>
						</div>
					</div>

					<button
						type="button"
						onClick={() => setIsAboutOpen((prev) => !prev)}
						className={getSectionToggleClassName(isAboutOpen)}
						dir={direction}
					>
						<Info
							size={18}
							className={getSectionIconClassName(isAboutOpen)}
						/>
						<span className="dashboard-sidebar-section-label">
							{__('About PeakURL')}
						</span>
						<ChevronDown
							size={16}
							className={getSectionCaretClassName(isAboutOpen)}
						/>
					</button>
				</div>
			</aside>
		</>
	);
};

export default Sidebar;
