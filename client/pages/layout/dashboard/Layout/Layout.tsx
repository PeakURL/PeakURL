import { useRef, useState, useSyncExternalStore } from "react";
import { useHref, useLocation } from "react-router";

import { PEAKURL_NAME } from "@/constants";
import { getPeakURLData } from "@/data";
import { usePageTransition } from "@/hooks";
import { isDocumentRtl } from "@/i18n/direction";
import { __, sprintf } from "@/i18n";
import { cn } from "@/shared/formatting";

import Sidebar from "../Sidebar";
import { Header } from "../Header";
import { AdminNotices } from "../AdminNotices";
import type { LayoutProps } from "../types";

const SIDEBAR_COLLAPSED_STORAGE_KEY = "peakurl_sidebar_collapsed";
const DESKTOP_SIDEBAR_QUERY = "(min-width: 1024px)";

function subscribeToDesktopSidebar(callback: () => void) {
	const mediaQuery = window.matchMedia(DESKTOP_SIDEBAR_QUERY);
	mediaQuery.addEventListener("change", callback);
	return () => mediaQuery.removeEventListener("change", callback);
}

function getDesktopSidebar() {
	return window.matchMedia(DESKTOP_SIDEBAR_QUERY).matches;
}

export const Layout = ({ children }: LayoutProps) => {
	const [isMobileSidebarOpen, setIsMobileSidebarOpen] = useState(false);
	const isDesktop = useSyncExternalStore(
		subscribeToDesktopSidebar,
		getDesktopSidebar
	);
	if (isDesktop && isMobileSidebarOpen) setIsMobileSidebarOpen(false);
	const mobileMenuOpen = !isDesktop && isMobileSidebarOpen;
	const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(() => {
		if (typeof window !== "undefined") {
			try {
				return (
					localStorage.getItem(SIDEBAR_COLLAPSED_STORAGE_KEY) ===
					"true"
				);
			} catch {
				return false;
			}
		}
		return false;
	});

	const handleToggleSidebarCollapse = () => {
		setIsSidebarCollapsed((prev) => {
			const nextState = !prev;
			try {
				localStorage.setItem(
					SIDEBAR_COLLAPSED_STORAGE_KEY,
					String(nextState)
				);
			} catch {
				// Ignore storage write exceptions
			}
			return nextState;
		});
	};

	const isRtl = isDocumentRtl();
	const basePath = "/dashboard";
	const location = useLocation();
	const skipHref = useHref({
		search: location.search,
		hash: "#dashboard-main",
	});
	const { version } = getPeakURLData();
	const footerLink =
		"https://peakurl.org?utm_source=peakurl_dashboard&utm_medium=dashboard_footer&utm_campaign=app_footer";
	const normalizedPath = location.pathname.replace(/\/+$/, "") || "/";
	const pageContent = useRef<HTMLDivElement>(null);
	usePageTransition(pageContent, normalizedPath);
	const isAboutPage = normalizedPath === "/dashboard/about";
	const footerCopy = sprintf(
		__("Thank you for choosing %s."),
		"__PEAKURL_NAME__"
	);
	const [footerCopyBeforeLink, footerCopyAfterLink = ""] =
		footerCopy.split("__PEAKURL_NAME__");

	return (
		<div
			className={cn(
				"dashboard-layout",
				isSidebarCollapsed && "dashboard-layout-collapsed"
			)}
		>
			<a href={skipHref} className="dashboard-skip-link">
				{__("Skip to content")}
			</a>
			<Sidebar
				basePath={basePath}
				isDesktop={isDesktop}
				isMobileOpen={mobileMenuOpen}
				onMobileClose={() => setIsMobileSidebarOpen(false)}
				isCollapsed={isSidebarCollapsed}
				onToggleCollapse={handleToggleSidebarCollapse}
			/>

			<div className="dashboard-layout-wrapper">
				<Header
					mobileMenuOpen={mobileMenuOpen}
					onMobileMenuToggle={() => setIsMobileSidebarOpen(true)}
				/>

				<div className="dashboard-layout-content">
					<main
						id="dashboard-main"
						className="dashboard-layout-main"
						tabIndex={-1}
					>
						{!isAboutPage && <AdminNotices />}
						<div
							ref={pageContent}
							className="dashboard-page-content"
						>
							{children}
						</div>
					</main>

					{!isAboutPage && (
						<footer className="dashboard-layout-footer">
							<div className="dashboard-layout-footer-inner">
								<p
									dir={isRtl ? "rtl" : "ltr"}
									className="dashboard-layout-footer-copy"
								>
									{footerCopyBeforeLink}
									<a
										href={footerLink}
										target="_blank"
										rel="noreferrer"
										className="dashboard-layout-footer-link"
									>
										{PEAKURL_NAME}
									</a>
									{footerCopyAfterLink}
								</p>
								<p
									dir={isRtl ? "rtl" : "ltr"}
									className="dashboard-layout-footer-version"
								>
									{__("Version")}{" "}
									<bdi className="dashboard-layout-footer-version-value">
										{version}
									</bdi>
								</p>
							</div>
						</footer>
					)}
				</div>
			</div>
		</div>
	);
};

export default Layout;
