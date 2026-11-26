import { useEffect, useMemo, useRef, useState } from "react";
import { Dialog, DialogBackdrop, DialogPanel } from "@headlessui/react";
import { Link, useLocation } from "react-router";
import {
	ChevronDown,
	X,
	Info,
	Heart,
	Coffee,
	ExternalLink,
	PanelLeftClose,
	PanelLeftOpen,
	PanelRightClose,
	PanelRightOpen,
} from "lucide-react";

import type { NavigationItem } from "@/pages/dashboard/navigation";
import {
	getDashboardBasePath,
	getNavigationGroups,
} from "@/pages/dashboard/navigation";
import { useGetUrlsQuery } from "@/state/slices/api";
import { useGetPluginExtensionsQuery } from "@/state/slices/api/system";
import { useActiveIndicator, useAdminAccess } from "@/hooks";
import { Logo } from "@/components";
import { isDocumentRtl } from "@/i18n/direction";
import { __ } from "@/i18n";
import { SPONSOR_URL } from "@/constants";
import { cn, formatCount } from "@/shared/formatting";

import type { SidebarProps } from "../types";

const getSectionToggleClassName = (isActive: boolean): string =>
	cn(
		"dashboard-sidebar-section-toggle",
		isActive && "dashboard-sidebar-section-toggle-active"
	);

const getSectionIconClassName = (isActive: boolean): string =>
	cn(
		"dashboard-sidebar-section-icon",
		isActive && "dashboard-sidebar-section-icon-active"
	);

const getSectionCaretClassName = (isOpen: boolean): string =>
	cn(
		"dashboard-sidebar-section-caret",
		isOpen && "dashboard-sidebar-section-caret-open"
	);

const getSubmenuClassName = (isOpen: boolean): string =>
	cn("dashboard-sidebar-submenu", isOpen && "dashboard-sidebar-submenu-open");

const getSubmenuLinkClassName = (isActive: boolean): string =>
	cn(
		"dashboard-sidebar-submenu-link",
		isActive && "dashboard-sidebar-submenu-link-active"
	);

const getLinkClassName = (isActive: boolean): string =>
	cn("dashboard-sidebar-link", isActive && "dashboard-sidebar-link-active");

const getLinkIconClassName = (isActive: boolean): string =>
	cn(
		"dashboard-sidebar-link-icon",
		isActive && "dashboard-sidebar-link-icon-active"
	);

const getLinkBadgeClassName = (isActive: boolean): string =>
	cn(
		"dashboard-sidebar-link-badge",
		isActive && "dashboard-sidebar-link-badge-active"
	);

const getAboutPanelClassName = (isOpen: boolean): string =>
	cn(
		"dashboard-sidebar-about-panel",
		isOpen && "dashboard-sidebar-about-panel-open"
	);

const getAboutLinkClassName = (isActive: boolean): string =>
	cn(
		"dashboard-sidebar-about-link",
		isActive &&
			"dashboard-sidebar-about-link-active navigation-active-indicator"
	);

function matchesNavigationPath(pathname: string, basePath: string | undefined) {
	return Boolean(
		basePath &&
		(pathname === basePath || pathname.startsWith(`${basePath}/`))
	);
}

interface SidebarSectionProps {
	item: NavigationItem;
	pathname: string;
	isRail: boolean;
	open?: boolean;
	onOpenChange: (open: boolean) => void;
	onNavigate: () => void;
}

function SidebarSection({
	item,
	pathname,
	isRail,
	open,
	onOpenChange,
	onNavigate,
}: SidebarSectionProps) {
	const toggleRef = useRef<HTMLButtonElement>(null);
	const children = item.children ?? [];
	const activeChild =
		children.find((child) => pathname === child.href) ??
		children.find((child) =>
			matchesNavigationPath(pathname, child.activeBasePath ?? child.href)
		);
	const active =
		Boolean(activeChild) || matchesNavigationPath(pathname, item.href);
	const isOpen = open ?? (!isRail && active);
	const indicatorOnToggle = active && (isRail || !isOpen || !activeChild);
	const Icon = item.icon;

	return (
		<div className="dashboard-sidebar-section">
			<button
				ref={toggleRef}
				type="button"
				onClick={() => onOpenChange(!isOpen)}
				aria-expanded={isOpen}
				aria-current={indicatorOnToggle ? "location" : undefined}
				data-sidebar-indicator-target={
					indicatorOnToggle ? "" : undefined
				}
				className={getSectionToggleClassName(active)}
				title={isRail ? item.name : undefined}
			>
				<Icon size={18} className={getSectionIconClassName(active)} />
				<span className="dashboard-sidebar-section-label">
					{item.name}
				</span>
				{item.badge && (
					<span
						className={cn(
							getLinkBadgeClassName(active),
							"dashboard-sidebar-link-badge-preview"
						)}
					>
						{item.badge}
					</span>
				)}
				<ChevronDown
					size={16}
					className={getSectionCaretClassName(isOpen)}
				/>
			</button>
			<div className={getSubmenuClassName(isOpen)} inert={!isOpen}>
				<div className="dashboard-sidebar-submenu-list">
					{children.map((child) => (
						<Link
							key={child.href}
							to={child.href}
							aria-current={
								child === activeChild ? "page" : undefined
							}
							data-sidebar-indicator-target={
								child === activeChild && !isRail && isOpen
									? ""
									: undefined
							}
							className={getSubmenuLinkClassName(
								child === activeChild
							)}
							onClick={() => {
								if (isRail) onOpenChange(false);
								onNavigate();
							}}
							onKeyDown={(event) => {
								if (event.key !== "Escape") return;
								event.preventDefault();
								onOpenChange(false);
								toggleRef.current?.focus();
							}}
						>
							{child.name}
						</Link>
					))}
				</div>
			</div>
		</div>
	);
}

export const Sidebar = ({
	basePath = "",
	isDesktop,
	isMobileOpen,
	onMobileClose,
	isCollapsed = false,
	onToggleCollapse,
}: SidebarProps) => {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const location = useLocation();
	const pathname = location.pathname.replace(/\/+$/, "") || "/";
	const closeButton = useRef<HTMLButtonElement>(null);
	const isRail = isDesktop && isCollapsed;
	const { data: urlsRes } = useGetUrlsQuery(undefined);
	const { canManageUsers, canManagePlugins, hasCapability, user } =
		useAdminAccess();
	const totalLinks = urlsRes?.data?.meta?.totalItems ?? 0;

	const CollapseIcon = isRtl
		? isCollapsed
			? PanelRightOpen
			: PanelRightClose
		: isCollapsed
			? PanelLeftOpen
			: PanelLeftClose;

	const { data: extensionsResponse } = useGetPluginExtensionsQuery(
		undefined,
		{
			skip: !user,
		}
	);

	const pluginPages = useMemo(() => {
		const rawPages = extensionsResponse?.data?.pages ?? [];
		return rawPages.filter(
			(page) => !page.capability || hasCapability(page.capability)
		);
	}, [extensionsResponse?.data?.pages, hasCapability]);

	const base = getDashboardBasePath(basePath);
	const navigation = getNavigationGroups(
		base,
		canManageUsers,
		canManagePlugins,
		pluginPages
	);
	const [openSections, setOpenSections] = useState<Record<string, boolean>>(
		{}
	);
	const [isAboutOpen, setIsAboutOpen] = useState(false);
	const aboutRef = useRef<HTMLDivElement>(null);
	const desktopTrack = useRef<HTMLDivElement>(null);
	const desktopIndicator = useRef<HTMLSpanElement>(null);
	const mobileTrack = useRef<HTMLDivElement>(null);
	const mobileIndicator = useRef<HTMLSpanElement>(null);
	const navigationTrack = isDesktop ? desktopTrack : mobileTrack;
	const activeIndicator = isDesktop ? desktopIndicator : mobileIndicator;
	useActiveIndicator(navigationTrack, activeIndicator, {
		duration: 0.26,
		enabled: isDesktop || isMobileOpen,
		selector: "[data-sidebar-indicator-target]",
	});

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
		document.addEventListener("mousedown", handler);
		return () => document.removeEventListener("mousedown", handler);
	}, [isAboutOpen]);

	const Panel = isDesktop ? "aside" : DialogPanel;
	const sidebar = (
		<Panel
			{...(!isDesktop && { as: "aside" as const })}
			inert={!isDesktop && !isMobileOpen}
			className={cn(
				"dashboard-sidebar",
				isRtl && "dashboard-sidebar-rtl",
				isMobileOpen && "dashboard-sidebar-open",
				isCollapsed && "dashboard-sidebar-collapsed"
			)}
		>
			<div className="dashboard-sidebar-header">
				<Logo to={base} size="lg" className="dashboard-sidebar-logo" />
				{onToggleCollapse && (
					<button
						type="button"
						onClick={onToggleCollapse}
						className="dashboard-sidebar-collapse-button"
						dir={direction}
						aria-expanded={!isCollapsed}
						title={
							isCollapsed
								? __("Expand sidebar")
								: __("Collapse sidebar")
						}
						aria-label={
							isCollapsed
								? __("Expand sidebar")
								: __("Collapse sidebar")
						}
					>
						<CollapseIcon size={17} strokeWidth={1.9} />
					</button>
				)}
				<button
					ref={closeButton}
					type="button"
					onClick={onMobileClose}
					aria-label={__("Close sidebar")}
					className="dashboard-sidebar-close"
				>
					<X size={20} />
				</button>
			</div>

			<nav className="dashboard-sidebar-nav" aria-label={__("Dashboard")}>
				<div ref={navigationTrack} className="dashboard-sidebar-list">
					<span
						ref={activeIndicator}
						className="dashboard-sidebar-indicator"
						aria-hidden="true"
					/>
					{navigation.map((group) => {
						const Group = group.name ? "section" : "div";
						return (
							<Group
								key={group.id}
								aria-label={group.name}
								className="dashboard-sidebar-group"
							>
								{group.name && (
									<p
										className="dashboard-sidebar-group-label"
										aria-hidden="true"
									>
										{group.name}
									</p>
								)}
								{group.items.map((item) => {
									const Icon = item.icon;
									const itemHref = item.href ?? base;
									if (item.children?.length) {
										return (
											<SidebarSection
												key={item.id}
												item={item}
												pathname={pathname}
												isRail={isRail}
												open={openSections[item.id]}
												onOpenChange={(open) =>
													setOpenSections(
														(current) => ({
															...current,
															[item.id]: open,
														})
													)
												}
												onNavigate={onMobileClose}
											/>
										);
									}

									const active =
										pathname === itemHref ||
										matchesNavigationPath(
											pathname,
											item.activeBasePath
										) ||
										(itemHref !== base &&
											matchesNavigationPath(
												pathname,
												itemHref
											));

									return (
										<Link
											key={itemHref}
											to={itemHref}
											onClick={onMobileClose}
											className={getLinkClassName(active)}
											data-sidebar-indicator-target={
												active ? "" : undefined
											}
											aria-current={
												active ? "page" : undefined
											}
											title={
												isRail ? item.name : undefined
											}
										>
											<Icon
												size={18}
												className={getLinkIconClassName(
													active
												)}
											/>
											<span className="dashboard-sidebar-link-label">
												{item.name}
											</span>
											{item.id === "links" &&
												totalLinks > 0 && (
													<span
														className={getLinkBadgeClassName(
															active
														)}
													>
														{formatCount(
															totalLinks
														)}
													</span>
												)}
											{item.badge && (
												<span
													className={cn(
														getLinkBadgeClassName(
															active
														),
														"dashboard-sidebar-link-badge-preview"
													)}
												>
													{item.badge}
												</span>
											)}
										</Link>
									);
								})}
							</Group>
						);
					})}
				</div>
			</nav>

			<div ref={aboutRef} className="dashboard-sidebar-footer">
				<div
					className={getAboutPanelClassName(isAboutOpen)}
					inert={!isAboutOpen}
				>
					<div className="dashboard-sidebar-about-panel-list">
						<Link
							to={`${base}/about`}
							aria-current={
								pathname === `${base}/about`
									? "page"
									: undefined
							}
							onClick={onMobileClose}
							className={getAboutLinkClassName(
								pathname === `${base}/about`
							)}
						>
							<Info
								size={15}
								className="dashboard-sidebar-about-link-icon"
							/>
							<span className="dashboard-sidebar-about-link-label">
								{__("About PeakURL")}
							</span>
						</Link>
						<a
							href={SPONSOR_URL}
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
								{__("Sponsor")}
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
								{__("Buy Me a Coffee")}
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
					aria-expanded={isAboutOpen}
					className={getSectionToggleClassName(isAboutOpen)}
					dir={direction}
					title={isRail ? __("About PeakURL") : undefined}
				>
					<Info
						size={18}
						className={getSectionIconClassName(isAboutOpen)}
					/>
					<span className="dashboard-sidebar-section-label">
						{__("About PeakURL")}
					</span>
					<ChevronDown
						size={16}
						className={getSectionCaretClassName(isAboutOpen)}
					/>
				</button>
			</div>
		</Panel>
	);
	if (isDesktop) return sidebar;

	return (
		<Dialog
			static
			open={isMobileOpen}
			onClose={onMobileClose}
			initialFocus={closeButton}
			aria-label={__("Dashboard")}
			aria-hidden={!isMobileOpen || undefined}
			className="drawer-dialog"
		>
			<DialogBackdrop
				className={cn(
					"dashboard-sidebar-overlay",
					isMobileOpen
						? "dashboard-sidebar-overlay-open"
						: "dashboard-sidebar-overlay-closed"
				)}
			/>
			{sidebar}
		</Dialog>
	);
};

export default Sidebar;
