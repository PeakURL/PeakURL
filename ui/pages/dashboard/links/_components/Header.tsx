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
			className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-stroke bg-surface text-text-muted transition-colors hover:border-accent/30 hover:text-accent disabled:cursor-wait"
			aria-label={__('Refresh links')}
			title={__('Refresh links')}
		>
			<RefreshCw
				className={`h-5 w-5 ${isRefreshing ? 'animate-spin' : ''}`}
			/>
		</button>
	);

	return (
		<div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
			<div className="flex items-start justify-between gap-3 lg:justify-start">
				<div className="flex min-w-0 items-start gap-3 sm:gap-4">
					<div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-primary-600 sm:h-12 sm:w-12">
						<Link2 className="h-5 w-5 text-white" />
					</div>
					<div className="min-w-0">
						<h1 className="text-xl font-bold text-heading sm:text-2xl">
							{__('Links')}
						</h1>
						<p className="mt-1 max-w-2xl text-sm text-text-muted">
							{__('Manage and track your shortened URLs')}
						</p>
					</div>
				</div>

				<div className="sm:hidden">{refreshButton}</div>
			</div>

			<div className="hidden items-center gap-3 sm:flex lg:w-auto lg:max-w-[26rem]">
				{refreshButton}

				<div className="min-w-0 flex-1 rounded-2xl border border-stroke bg-surface px-4 py-3 text-left sm:flex-none sm:min-w-[15rem]">
					<div className="text-[11px] font-semibold uppercase tracking-[0.14em] text-text-muted">
						{__('Short links domain')}
					</div>
					<div className="mt-1 break-all text-sm font-semibold text-heading sm:break-normal sm:text-base">
						{PEAKURL_DOMAIN}
					</div>
				</div>
			</div>
		</div>
	);
};

export default Header;
