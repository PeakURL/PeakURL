import { useState } from 'react';
import { useLocation } from 'react-router-dom';
import { PEAKURL_NAME, PEAKURL_VERSION } from '@constants';
import { isDocumentRtl } from '@/i18n/direction';
import { __, sprintf } from '@/i18n';
import DashboardSidebar from './DashboardSidebar';
import { DashboardAppBar } from './DashboardAppBar';
import { AdminNotices } from './AdminNotices';
import type { DashboardLayoutProps } from './types';

export const DashboardLayout = ({ children }: DashboardLayoutProps) => {
	const [isMobileSidebarOpen, setIsMobileSidebarOpen] = useState(false);
	const isRtl = isDocumentRtl();
	const basePath = '/dashboard';
	const location = useLocation();
	const appBarKey = `${location.pathname}${location.search}`;
	const footerLink =
		'https://peakurl.org?utm_source=peakurl_dashboard&utm_medium=dashboard_footer&utm_campaign=app_footer';
	const normalizedPath = location.pathname.replace(/\/+$/, '') || '/';
	const showAdminNotices = normalizedPath !== '/dashboard/about';

	return (
		<div className="min-h-screen bg-bg">
			<DashboardSidebar
				basePath={basePath}
				isMobileOpen={isMobileSidebarOpen}
				onMobileClose={() => setIsMobileSidebarOpen(false)}
			/>

			<div className={`${isRtl ? 'lg:mr-64' : 'lg:ml-64'} min-h-screen`}>
				<DashboardAppBar
					key={appBarKey}
					onMobileMenuToggle={() => setIsMobileSidebarOpen(true)}
				/>

				<div className="flex min-h-[calc(100vh-4rem)] flex-col">
					<main className="flex-1 flex flex-col p-4 sm:p-6">
						{showAdminNotices ? <AdminNotices /> : null}
						{children}
					</main>

					<footer className="px-4 py-4 text-xs text-text-muted/80 sm:px-6">
						<div className="flex items-center justify-between gap-3">
							<a
								href={footerLink}
								target="_blank"
								rel="noreferrer"
								dir={isRtl ? 'rtl' : 'ltr'}
								className="text-inline-start min-w-0 italic text-text-muted/80 transition-colors hover:text-accent"
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
								className="text-inline-end shrink-0 text-text-muted/80 transition-colors hover:text-accent"
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

export default DashboardLayout;
