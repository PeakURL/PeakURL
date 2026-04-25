import { useState } from "react";
import { useLocation } from "react-router-dom";
import { PEAKURL_NAME, PEAKURL_VERSION } from "@constants";
import { isDocumentRtl } from "@/i18n/direction";
import { __, sprintf } from "@/i18n";
import Sidebar from "../Sidebar";
import { Header } from "../Header";
import { AdminNotices } from "../AdminNotices";
import type { LayoutProps } from "../types";

export const Layout = ({ children }: LayoutProps) => {
	const [isMobileSidebarOpen, setIsMobileSidebarOpen] = useState(false);
	const isRtl = isDocumentRtl();
	const basePath = "/dashboard";
	const location = useLocation();
	const headerKey = `${location.pathname}${location.search}`;
	const footerLink =
		"https://peakurl.org?utm_source=peakurl_dashboard&utm_medium=dashboard_footer&utm_campaign=app_footer";
	const normalizedPath = location.pathname.replace(/\/+$/, "") || "/";
	const showAdminNotices = normalizedPath !== "/dashboard/about";
	const footerCopy = sprintf(
		__("Thank you for choosing %s."),
		"__PEAKURL_NAME__"
	);
	const [footerCopyBeforeLink, footerCopyAfterLink = ""] =
		footerCopy.split("__PEAKURL_NAME__");

	return (
		<div className="dashboard-layout">
			<Sidebar
				basePath={basePath}
				isMobileOpen={isMobileSidebarOpen}
				onMobileClose={() => setIsMobileSidebarOpen(false)}
			/>

			<div className="dashboard-layout-wrapper">
				<Header
					key={headerKey}
					onMobileMenuToggle={() => setIsMobileSidebarOpen(true)}
				/>

				<div className="dashboard-layout-content">
					<main className="dashboard-layout-main">
						{showAdminNotices ? <AdminNotices /> : null}
						{children}
					</main>

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
									{PEAKURL_VERSION}
								</bdi>
							</p>
						</div>
					</footer>
				</div>
			</div>
		</div>
	);
};

export default Layout;
