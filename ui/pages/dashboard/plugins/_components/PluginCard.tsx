import { Star, Download, Bell, ExternalLink } from 'lucide-react';
import { __ } from '@/i18n';
import { PLUGINS_WAITLIST_URL } from '@constants';
import type { PluginCardProps, PluginPreviewSkeletonProps } from './types';

/* Faint shimmer skeleton bar */
function Skeleton({ className = '' }: PluginPreviewSkeletonProps) {
	return (
		<div className={`plugins-skeleton ${className}`} />
	);
}

function PluginCard({ plugin }: PluginCardProps) {
	return (
		<div className="plugins-card group">
			{/* ── Banner ── */}
			<div className={`plugins-card-banner bg-gradient-to-br ${plugin.gradient}`}>
				{/* Decorative blurred circles inside the banner */}
				<div className="plugins-card-banner-glow-end" />
				<div className="plugins-card-banner-glow-start" />

				{/* Plugin icon placeholder */}
				<div className="plugins-card-banner-icon-wrap">
					<div className="plugins-card-banner-icon">
						<div className="plugins-card-banner-icon-placeholder" />
					</div>
				</div>
			</div>

			{/* ── Content area ── */}
			<div className="plugins-card-body">
				{/* Blurred plugin name */}
				<div className="plugins-card-header">
					<Skeleton className={`h-4 ${plugin.barWidths[0]}`} />
					<Skeleton className="h-3.5 w-10 shrink-0" />
				</div>

				{/* Blurred author */}
				<Skeleton className="plugins-card-author" />

				{/* Blurred description lines */}
				<div className="plugins-card-copy">
					<Skeleton className={`h-3 ${plugin.barWidths[1]}`} />
					<Skeleton className={`h-3 ${plugin.barWidths[2]}`} />
					<Skeleton className="h-3 w-1/3" />
				</div>

				{/* Rating / installs placeholder row */}
				<div className="plugins-card-meta">
					<div className="plugins-card-meta-stars">
						{[1, 2, 3, 4, 5].map((s) => (
							<Star
								key={s}
								size={11}
								className="text-stroke-strong dark:text-stroke"
								fill="currentColor"
							/>
						))}
						<Skeleton className="h-3 w-6" />
					</div>
					<div className="plugins-card-meta-downloads">
						<Download size={11} />
						<Skeleton className="h-3 w-8" />
					</div>
				</div>

				{/* Waitlist button */}
				<a
					href={PLUGINS_WAITLIST_URL}
					target="_blank"
					rel="noreferrer"
					className="plugins-card-button group/btn"
				>
					<Bell
						size={13}
						className="plugins-card-button-icon group-hover/btn:rotate-12"
					/>
					{__('Join the Waitlist')}
					<ExternalLink size={11} className="opacity-60" />
				</a>
			</div>

			<a
				href={PLUGINS_WAITLIST_URL}
				target="_blank"
				rel="noreferrer"
				className="plugins-card-overlay group-hover:opacity-100"
			>
				<span className="plugins-card-overlay-pill">
					<Bell size={11} />
					{__('Join the Waitlist')}
				</span>
			</a>
		</div>
	);
}

export default PluginCard;
