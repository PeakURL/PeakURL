import { PEAKURL_DOMAIN } from '@constants';
import { Link2, RefreshCw } from 'lucide-react';
import { __ } from '@/i18n';
import type { LinksHeaderProps } from './types';

const Header = ({ onRefresh, isRefreshing = false }: LinksHeaderProps) => {
	const refreshButton = (
		<button
			type="button"
			onClick={onRefresh}
			disabled={isRefreshing}
			className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-stroke bg-surface text-text-muted transition-colors hover:border-accent/30 hover:text-accent disabled:cursor-wait"
			aria-label={__('Refresh links')}
			title={__('Refresh links')}
		>
			<RefreshCw
				className={`h-5 w-5 ${isRefreshing ? 'animate-spin' : ''}`}
			/>
		</button>
	);

	return (
		<div className="flex items-start justify-between gap-4">
			<div className="flex min-w-0 items-center gap-3">
				<div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-600">
					<Link2 className="h-5 w-5 text-white" />
				</div>
				<div className="min-w-0">
					<h1 className="text-xl font-bold text-heading">
						{__('Links')}
					</h1>
					<p className="text-xs text-text-muted">
						{__('Manage and track your shortened URLs')}
					</p>
				</div>
			</div>

			<div className="flex shrink-0 items-center gap-2">
				<div className="sm:hidden">{refreshButton}</div>
				<div className="hidden items-center gap-2 sm:flex">
					{refreshButton}
					<p className="rounded-full border border-stroke bg-surface px-4 py-3 text-xs font-medium text-text-muted">
						{__('Short links domain')}{' '}
						<span className="text-heading">{PEAKURL_DOMAIN}</span>
					</p>
				</div>
			</div>
		</div>
	);
};

export default Header;
