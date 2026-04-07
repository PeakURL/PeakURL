import { Star, Download, Bell, ExternalLink } from 'lucide-react';
import { __ } from '@/i18n';
import { PLUGINS_WAITLIST_URL } from '@constants';
import type { PluginCardProps, PluginPreviewSkeletonProps } from './types';

/* Faint shimmer skeleton bar */
function Skeleton({ className = '' }: PluginPreviewSkeletonProps) {
	return (
		<div
			className={`animate-pulse rounded-md bg-stroke/60 dark:bg-stroke/40 ${className}`}
		/>
	);
}

function PluginCard({ plugin }: PluginCardProps) {
	return (
		<div className="group relative flex flex-col overflow-hidden rounded-xl border border-stroke bg-surface shadow-sm transition-all duration-200 hover:shadow-md hover:border-stroke-strong">
			{/* ── Banner ── */}
			<div
				className={`relative h-28 overflow-hidden bg-gradient-to-br ${plugin.gradient}`}
			>
				{/* Decorative blurred circles inside the banner */}
				<div className="absolute -right-6 -top-6 h-20 w-20 rounded-full bg-white/10 blur-xl" />
				<div className="absolute -bottom-4 -left-4 h-16 w-16 rounded-full bg-black/10 blur-lg" />

				{/* Plugin icon placeholder */}
				<div className="absolute -bottom-5 left-4">
					<div className="flex h-12 w-12 items-center justify-center rounded-xl border-2 border-surface bg-surface shadow-md">
						<div className="h-6 w-6 rounded-lg bg-gradient-to-br from-stroke to-stroke-strong opacity-60" />
					</div>
				</div>
			</div>

			{/* ── Content area ── */}
			<div className="flex flex-1 flex-col px-4 pb-4 pt-8">
				{/* Blurred plugin name */}
				<div className="mb-2 flex items-center justify-between gap-2">
					<Skeleton className={`h-4 ${plugin.barWidths[0]}`} />
					<Skeleton className="h-3.5 w-10 shrink-0" />
				</div>

				{/* Blurred author */}
				<Skeleton className="mb-3 h-3 w-20" />

				{/* Blurred description lines */}
				<div className="mb-4 flex-1 space-y-2">
					<Skeleton className={`h-3 ${plugin.barWidths[1]}`} />
					<Skeleton className={`h-3 ${plugin.barWidths[2]}`} />
					<Skeleton className="h-3 w-1/3" />
				</div>

				{/* Rating / installs placeholder row */}
				<div className="mb-3 flex items-center justify-between border-t border-stroke pt-3">
					<div className="flex items-center gap-0.5">
						{[1, 2, 3, 4, 5].map((s) => (
							<Star
								key={s}
								size={11}
								className="text-stroke-strong dark:text-stroke"
								fill="currentColor"
							/>
						))}
						<Skeleton className="ml-1.5 h-3 w-6" />
					</div>
					<div className="flex items-center gap-1 text-stroke-strong dark:text-stroke">
						<Download size={11} />
						<Skeleton className="h-3 w-8" />
					</div>
				</div>

				{/* Waitlist button */}
				<a
					href={PLUGINS_WAITLIST_URL}
					target="_blank"
					rel="noreferrer"
					className="group/btn flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-accent to-accent/80 px-3 py-2.5 text-xs font-semibold text-white shadow-sm transition-all hover:shadow-md hover:from-accent/95 hover:to-accent/75 hover:-translate-y-px active:translate-y-0"
				>
					<Bell
						size={13}
						className="transition-transform group-hover/btn:rotate-12"
					/>
					{__('Join the Waitlist')}
					<ExternalLink size={11} className="ml-auto opacity-60" />
				</a>
			</div>

			<a
				href={PLUGINS_WAITLIST_URL}
				target="_blank"
				rel="noreferrer"
				className="absolute inset-0 flex items-center justify-center opacity-0 transition-opacity duration-200 group-hover:opacity-100"
			>
				<span className="inline-flex items-center gap-2 rounded-full border border-white/20 bg-black/60 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-widest text-white shadow-lg backdrop-blur-md">
					<Bell size={11} />
					{__('Join the Waitlist')}
				</span>
			</a>
		</div>
	);
}

export default PluginCard;
