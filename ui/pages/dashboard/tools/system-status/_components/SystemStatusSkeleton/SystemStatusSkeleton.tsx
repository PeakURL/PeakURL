import { SkeletonLoader } from "@/components";

type InfoSectionSkeletonProps = {
	isOpen?: boolean;
	rowCount?: number;
	titleWidth?: "xs" | "sm" | "md" | "lg" | "xl";
};

function InfoSectionSkeleton({
	isOpen = false,
	rowCount = 4,
	titleWidth = "xl",
}: InfoSectionSkeletonProps) {
	const titleWidthClassNames = {
		xs: "system-status-skeleton-title-xs",
		sm: "system-status-skeleton-title-sm",
		md: "system-status-skeleton-title-md",
		lg: "system-status-skeleton-title-lg",
		xl: "system-status-skeleton-title-xl",
	} as const;

	return (
		<div className="system-status-skeleton-info">
			<div className="system-status-skeleton-info-header">
				<SkeletonLoader className={titleWidthClassNames[titleWidth]} />
				<SkeletonLoader className="system-status-skeleton-chevron" />
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
												? "system-status-skeleton-row-bordered"
												: ""
										}
									>
										<th className="system-status-skeleton-heading">
											<SkeletonLoader className="system-status-skeleton-heading-label" />
										</th>
										<td className="system-status-skeleton-value">
											<div className="system-status-skeleton-value-copy">
												<SkeletonLoader className="system-status-skeleton-value-line" />
												{index % 2 === 0 && (
													<SkeletonLoader className="system-status-skeleton-value-line-muted" />
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
						<SkeletonLoader className="system-status-skeleton-summary-title" />

						<div className="system-status-skeleton-status">
							<SkeletonLoader className="system-status-skeleton-status-dot" />
							<SkeletonLoader className="system-status-skeleton-status-label" />
						</div>

						<SkeletonLoader className="system-status-skeleton-summary-copy" />
					</div>

					<div className="system-status-skeleton-tabs">
						<div className="system-status-skeleton-tab system-status-skeleton-tab-inactive">
							<SkeletonLoader className="system-status-skeleton-tab-label" />
						</div>
						<div className="system-status-skeleton-tab system-status-skeleton-tab-active">
							<SkeletonLoader className="system-status-skeleton-tab-label-short" />
						</div>
					</div>
				</div>

				<div className="system-status-skeleton-content">
					<section className="system-status-skeleton-section">
						<SkeletonLoader className="system-status-skeleton-section-title" />
						<div className="system-status-skeleton-lines">
							<SkeletonLoader className="system-status-skeleton-line-long" />
							<SkeletonLoader className="system-status-skeleton-line-short" />
						</div>
					</section>

					<div className="system-status-skeleton-copy-row">
						<SkeletonLoader className="system-status-skeleton-copy-button" />
					</div>

					<div className="system-status-skeleton-list">
						<InfoSectionSkeleton
							isOpen
							rowCount={7}
							titleWidth="sm"
						/>
						<InfoSectionSkeleton titleWidth="lg" />
						<InfoSectionSkeleton titleWidth="xs" />
						<InfoSectionSkeleton titleWidth="md" />
						<InfoSectionSkeleton titleWidth="sm" />
					</div>
				</div>
			</div>
		</div>
	);
}

export default SystemStatusSkeleton;
