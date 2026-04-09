import { useState } from 'react';
import { useLocation } from 'react-router-dom';
import { PEAKURL_NAME, PEAKURL_VERSION } from '@constants';
import { isDocumentRtl } from '@/i18n/direction';
import { __, sprintf } from '@/i18n';
import { cn } from '@/utils';
import Sidebar from './Sidebar';
import { Header } from './Header';
import { AdminNotices } from './AdminNotices';
import type { LayoutProps } from './types';

export const Layout = ({ children }: LayoutProps) => {
	const [isMobileSidebarOpen, setIsMobileSidebarOpen] = useState(false);
	const isRtl = isDocumentRtl();
	const basePath = '/dashboard';
	const location = useLocation();
	const headerKey = `${location.pathname}${location.search}`;
	const footerLink =
		'https://peakurl.org?utm_source=peakurl_dashboard&utm_medium=dashboard_footer&utm_campaign=app_footer';
	const normalizedPath = location.pathname.replace(/\/+$/, '') || '/';
	const showAdminNotices = normalizedPath !== '/dashboard/about';

	return (
		<div className="dashboard-layout">
			<Sidebar
				basePath={basePath}
				isMobileOpen={isMobileSidebarOpen}
				onMobileClose={() => setIsMobileSidebarOpen(false)}
			/>

			<div
				className={cn(
					'dashboard-layout-wrapper',
					isRtl && 'dashboard-layout-wrapper-rtl'
				)}
			>
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
							<a
								href={footerLink}
								target="_blank"
								rel="noreferrer"
								dir={isRtl ? 'rtl' : 'ltr'}
								className="dashboard-layout-footer-link"
							>
								{sprintf(
									__('Thank you for choosing %s.'),
									PEAKURL_NAME
								)}
							</a>
							<a
								href={footerLink}
								target="_blank"
								rel="noreferrer"
								className="dashboard-layout-footer-link dashboard-layout-footer-link-version"
							>
								{__('Version')} {PEAKURL_VERSION}
							</a>
						</div>
					</footer>
				</div>
			</div>
		</div>
	);
};

export default Layout;
