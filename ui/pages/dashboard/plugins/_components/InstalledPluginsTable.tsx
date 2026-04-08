import { MoreHorizontal, Lock, Info } from 'lucide-react';
import { Button } from '@/components/ui';
import { __ } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import type {
	InstalledPluginsTableProps,
	PluginPreviewSkeletonProps,
	PluginStatusPillProps,
} from './types';

/* Faint shimmer skeleton bar */
function Skeleton({ className = '' }: PluginPreviewSkeletonProps) {
	return (
		<div
			className={`animate-pulse rounded-md bg-stroke/60 dark:bg-stroke/40 ${className}`}
		/>
	);
}

function StatusPill({ active }: PluginStatusPillProps) {
	return active ? (
		<span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-1 text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">
			<span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
			{__('Active')}
		</span>
	) : (
		<span className="inline-flex items-center rounded-full border border-gray-500/20 bg-gray-500/10 px-2.5 py-1 text-[11px] font-semibold text-gray-600 dark:text-gray-400">
			{__('Inactive')}
		</span>
	);
}

function InstalledPluginsTable({ plugins }: InstalledPluginsTableProps) {
	const direction = isDocumentRtl() ? 'rtl' : 'ltr';

	if (plugins.length === 0) {
		return (
			<div className="rounded-xl border border-dashed border-stroke bg-surface-alt/30 px-6 py-12 text-center">
				<div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-surface-alt text-text-muted">
					<Info size={22} />
				</div>
				<h3 className="text-sm font-semibold text-heading">
					{__('No installed plugins')}
				</h3>
				<p className="mt-1 text-sm text-text-muted">
					{__(
						'Plugins you install will appear here once the feature is live.'
					)}
				</p>
			</div>
		);
	}

	return (
		<div className="overflow-hidden rounded-xl border border-stroke bg-surface shadow-sm">
			<div className="overflow-x-auto">
				<table className="min-w-full">
					<thead>
						<tr className="text-inline-start border-b border-stroke bg-surface-alt/60">
							<th className="px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-text-muted">
								{__('Plugin')}
							</th>
							<th className="hidden px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-text-muted md:table-cell">
								{__('Version')}
							</th>
							<th className="hidden px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-text-muted sm:table-cell">
								{__('Status')}
							</th>
							<th className="hidden px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-text-muted lg:table-cell">
								{__('Author')}
							</th>
							<th className="text-inline-end px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-text-muted">
								{__('Actions')}
							</th>
						</tr>
					</thead>
					<tbody className="divide-y divide-stroke">
						{plugins.map((plugin, idx) => {
							const isActive = idx === 0; // first one looks "active"
							return (
								<tr
									key={plugin.id}
									className="group transition-colors hover:bg-surface-alt/40"
								>
									{/* Plugin name + desc (blurred) */}
									<td className="text-inline-start px-5 py-4">
										<div
											dir={direction}
											className="flex items-center justify-start gap-3"
										>
											<div
												className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br ${plugin.gradient} opacity-80`}
											>
												<div className="h-5 w-5 rounded bg-white/30" />
											</div>
											<div className="text-inline-start min-w-0 flex-1 space-y-1.5">
												<Skeleton
													className={`h-4 ${plugin.barWidths[0]}`}
												/>
												<Skeleton
													className={`h-3 ${plugin.barWidths[1]}`}
												/>
												{/* Mobile-only status */}
												<div className="mt-1.5 sm:hidden">
													<StatusPill
														active={isActive}
													/>
												</div>
											</div>
										</div>
									</td>
									{/* Version (blurred) */}
									<td className="text-inline-start hidden px-5 py-4 md:table-cell">
										<Skeleton className="h-3.5 w-10" />
									</td>
									{/* Status */}
									<td className="text-inline-start hidden px-5 py-4 sm:table-cell">
										<StatusPill active={isActive} />
									</td>
									{/* Author (blurred) */}
									<td className="text-inline-start hidden px-5 py-4 lg:table-cell">
										<Skeleton className="h-3.5 w-16" />
									</td>
									{/* Actions (disabled) */}
									<td className="px-5 py-4">
										<div className="flex items-center justify-end gap-1">
											<Button
												variant="secondary"
												size="xs"
												className="text-xs"
												disabled
											>
												<span className="inline-flex items-center gap-1.5 text-text-muted">
													<Lock size={11} />
													<span className="hidden sm:inline">
														{isActive
															? __('Deactivate')
															: __('Activate')}
													</span>
												</span>
											</Button>
											<button
												disabled
												className="flex h-8 w-8 items-center justify-center rounded-lg text-text-muted opacity-50"
											>
												<MoreHorizontal size={16} />
											</button>
										</div>
									</td>
								</tr>
							);
						})}
					</tbody>
				</table>
			</div>
		</div>
	);
}

export default InstalledPluginsTable;
