// @ts-nocheck
'use client';

import { PEAKURL_DOMAIN } from '@constants';
import { Link2, RefreshCw } from 'lucide-react';

const Header = ({ onRefresh, isRefreshing = false }) => {
	return (
		<div className="flex items-center justify-between gap-4">
			<div className="flex items-center gap-3">
				<div className="w-10 h-10 rounded-xl bg-primary-600 flex items-center justify-center">
					<Link2 className="h-5 w-5 text-white" />
				</div>
				<div>
					<h1 className="text-xl font-bold text-heading">Links</h1>
					<p className="text-xs text-text-muted">
						Manage and track your shortened URLs
					</p>
				</div>
			</div>

			<div className="flex items-center gap-2">
				<button
					type="button"
					onClick={onRefresh}
					disabled={isRefreshing}
					className="inline-flex h-11 w-11 items-center justify-center rounded-full border border-stroke bg-surface text-text-muted transition-colors hover:border-accent/30 hover:text-accent disabled:cursor-wait"
					aria-label="Refresh links"
					title="Refresh links"
				>
					<RefreshCw
						className={`h-5 w-5 ${isRefreshing ? 'animate-spin' : ''}`}
					/>
				</button>

				<div className="rounded-full border border-stroke bg-surface px-4 py-3 text-xs font-medium text-text-muted">
					Short links domain{' '}
					<span className="text-heading">{PEAKURL_DOMAIN}</span>
				</div>
			</div>
		</div>
	);
};

export default Header;
