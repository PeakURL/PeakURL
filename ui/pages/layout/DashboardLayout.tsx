// @ts-nocheck
'use client';

import { useState } from 'react';
import { useLocation } from 'react-router-dom';
import { PEAKURL_VERSION } from '@constants';
import DashboardSidebar from './DashboardSidebar';
import { DashboardAppBar } from './DashboardAppBar';
import { AdminNotices } from './AdminNotices';

export const DashboardLayout = ({ children }) => {
	const [isMobileSidebarOpen, setIsMobileSidebarOpen] = useState(false);
	const [basePath, setBasePath] = useState('/dashboard');
	const location = useLocation();
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

			<div className="lg:ml-64 min-h-screen">
				<DashboardAppBar
					onMobileMenuToggle={() => setIsMobileSidebarOpen(true)}
				/>

				<div className="flex min-h-[calc(100vh-4rem)] flex-col">
					<main className="flex-1 flex flex-col p-4 sm:p-6">
						{showAdminNotices ? <AdminNotices /> : null}
						{children}
					</main>

					<footer className="px-4 py-4 text-xs text-text-muted/80 sm:px-6">
						<div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
							<p className="italic">
								Thank you for choosing{' '}
								<a
									href={footerLink}
									target="_blank"
									rel="noreferrer"
									className="text-text-muted/80 transition-colors hover:text-accent"
								>
									PeakURL
								</a>
								.
							</p>
							<a
								href={footerLink}
								target="_blank"
								rel="noreferrer"
								className="text-text-muted/80 transition-colors hover:text-accent"
							>
								Version {PEAKURL_VERSION}
							</a>
						</div>
					</footer>
				</div>
			</div>
		</div>
	);
};

export default DashboardLayout;
