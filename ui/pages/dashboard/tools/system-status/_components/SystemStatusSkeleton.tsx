import { SkeletonLoader } from '@/components/ui';

type InfoSectionSkeletonProps = {
	isOpen?: boolean;
	rowCount?: number;
	titleWidthClassName?: string;
};

function InfoSectionSkeleton({
	isOpen = false,
	rowCount = 4,
	titleWidthClassName = 'w-40',
}: InfoSectionSkeletonProps) {
	return (
		<div className="system-status-skeleton-info">
			<div className="system-status-skeleton-info-header">
				<SkeletonLoader className={`h-4 ${titleWidthClassName}`} />
				<SkeletonLoader className="h-4 w-4" />
			</div>

			{isOpen ? (
				<div className="system-status-skeleton-info-table">
					<table className="system-status-skeleton-table">
						<tbody>
							{Array.from({ length: rowCount }).map(
								(_, index) => (
									<tr
										key={index}
										className={
											index > 0
												? 'system-status-skeleton-row-bordered'
												: ''
										}
									>
										<th className="system-status-skeleton-heading">
											<SkeletonLoader className="h-4 w-24" />
										</th>
										<td className="system-status-skeleton-value">
											<div className="system-status-skeleton-value-copy">
												<SkeletonLoader className="h-4 w-32" />
												{index % 2 === 0 && (
													<SkeletonLoader className="h-3 w-20 opacity-60" />
												)}
											</div>
										</td>
									</tr>
								)
							)}
						</tbody>
					</table>
				</div>
			) : null}
		</div>
	);
}

export function SystemStatusSkeleton() {
	return (
		<div className="system-status-skeleton">
			<div className="system-status-skeleton-panel">
				<div className="system-status-skeleton-header">
					<div className="system-status-skeleton-summary">
						<SkeletonLoader className="mx-auto h-9 w-64" />

						<div className="system-status-skeleton-status">
							<SkeletonLoader className="h-5 w-5 rounded-full" />
							<SkeletonLoader className="h-5 w-12" />
						</div>

						<SkeletonLoader className="mx-auto mt-3 h-4 w-48" />
					</div>

					<div className="system-status-skeleton-tabs">
						<div className="system-status-skeleton-tab system-status-skeleton-tab-inactive">
							<SkeletonLoader className="h-4 w-12 rounded-sm" />
						</div>
						<div className="system-status-skeleton-tab system-status-skeleton-tab-active">
							<SkeletonLoader className="h-4 w-8 rounded-sm" />
						</div>
					</div>
				</div>

				<div className="system-status-skeleton-content">
					<section className="system-status-skeleton-section">
						<SkeletonLoader className="h-8 w-48" />
						<div className="system-status-skeleton-lines">
							<SkeletonLoader className="h-4 max-w-2xl" />
							<SkeletonLoader className="h-4 max-w-xl" />
						</div>
					</section>

					<div className="system-status-skeleton-copy-row">
						<SkeletonLoader className="system-status-skeleton-copy-button" />
					</div>

					<div className="system-status-skeleton-list">
						<InfoSectionSkeleton
							isOpen
							rowCount={7}
							titleWidthClassName="w-20"
						/>
						<InfoSectionSkeleton titleWidthClassName="w-36" />
						<InfoSectionSkeleton titleWidthClassName="w-16" />
						<InfoSectionSkeleton titleWidthClassName="w-24" />
						<InfoSectionSkeleton titleWidthClassName="w-20" />
					</div>
				</div>
			</div>
		</div>
	);
}

export default SystemStatusSkeleton;
