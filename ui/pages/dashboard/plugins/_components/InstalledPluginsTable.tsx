import { MoreHorizontal, Lock, Info } from 'lucide-react';
import { Button } from '@/components/ui';
import { __ } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import { cn } from '@/utils';
import type {
	InstalledPluginsTableProps,
	PluginPreviewSkeletonProps,
	PluginStatusPillProps,
} from './types';

/* Faint shimmer skeleton bar */
function Skeleton({ className = '' }: PluginPreviewSkeletonProps) {
	return <div className={`plugins-skeleton ${className}`} />;
}

function StatusPill({ active }: PluginStatusPillProps) {
	const statusPillClassName = cn(
		'plugins-status-pill',
		active ? 'plugins-status-pill-active' : 'plugins-status-pill-inactive'
	);

	return active ? (
		<span className={statusPillClassName}>
			<span className="plugins-status-pill-dot" />
			{__('Active')}
		</span>
	) : (
		<span className={statusPillClassName}>
			{__('Inactive')}
		</span>
	);
}

function InstalledPluginsTable({ plugins }: InstalledPluginsTableProps) {
	const direction = isDocumentRtl() ? 'rtl' : 'ltr';

	if (plugins.length === 0) {
		return (
			<div className="plugins-table-empty">
				<div className="plugins-table-empty-icon">
					<Info size={22} />
				</div>
				<h3 className="plugins-table-empty-title">
					{__('No installed plugins')}
				</h3>
				<p className="plugins-table-empty-copy">
					{__(
						'Plugins you install will appear here once the feature is live.'
					)}
				</p>
			</div>
		);
	}

	return (
		<div className="plugins-table">
			<div className="plugins-table-wrap">
				<table className="plugins-table-grid">
					<thead>
						<tr className="plugins-table-head-row">
							<th className="plugins-table-head-cell">
								{__('Plugin')}
							</th>
							<th className="plugins-table-head-cell hidden md:table-cell">
								{__('Version')}
							</th>
							<th className="plugins-table-head-cell hidden sm:table-cell">
								{__('Status')}
							</th>
							<th className="plugins-table-head-cell hidden lg:table-cell">
								{__('Author')}
							</th>
							<th className="plugins-table-head-cell plugins-table-head-cell-end">
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
									className="plugins-table-row"
								>
									{/* Plugin name + desc (blurred) */}
									<td className="plugins-table-cell">
										<div
											dir={direction}
											className="plugins-table-main"
										>
											<div
												className={`plugins-table-icon bg-gradient-to-br ${plugin.gradient}`}
											>
												<div className="plugins-table-icon-fill" />
											</div>
											<div className="plugins-table-copy">
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
									<td className="plugins-table-cell hidden md:table-cell">
										<Skeleton className="h-3.5 w-10" />
									</td>
									{/* Status */}
									<td className="plugins-table-cell hidden sm:table-cell">
										<StatusPill active={isActive} />
									</td>
									{/* Author (blurred) */}
									<td className="plugins-table-cell hidden lg:table-cell">
										<Skeleton className="h-3.5 w-16" />
									</td>
									{/* Actions (disabled) */}
									<td className="plugins-table-cell">
										<div className="plugins-table-actions">
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
												className="plugins-table-more"
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
