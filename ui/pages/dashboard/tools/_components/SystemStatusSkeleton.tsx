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
		<div className="overflow-hidden rounded-lg border border-stroke bg-surface">
			<div className="flex items-center justify-between gap-4 px-4 py-3.5">
				<SkeletonLoader className={`h-4 ${titleWidthClassName}`} />
				<SkeletonLoader className="h-4 w-4" />
			</div>

			{isOpen ? (
				<div className="overflow-hidden border-t border-stroke">
					<table className="min-w-full">
						<tbody>
							{Array.from({ length: rowCount }).map(
								(_, index) => (
									<tr
										key={index}
										className={
											index > 0
												? 'border-t border-stroke'
												: ''
										}
									>
										<th className="w-[34%] min-w-45 bg-surface-alt px-4 py-3 text-left align-top">
											<SkeletonLoader className="h-4 w-24" />
										</th>
										<td className="px-4 py-3 align-top">
											<div className="space-y-1.5">
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
		<div className="space-y-4">
			<div className="overflow-hidden rounded-lg border border-stroke bg-surface shadow-sm">
				<div className="border-b border-stroke bg-surface px-5 pt-8 sm:px-8">
					<div className="mx-auto max-w-2xl text-center">
						<SkeletonLoader className="mx-auto h-9 w-64" />

						<div className="mt-4 flex items-center justify-center gap-2">
							<SkeletonLoader className="h-5 w-5 rounded-full" />
							<SkeletonLoader className="h-5 w-12" />
						</div>

						<SkeletonLoader className="mx-auto mt-3 h-4 w-48" />
					</div>

					<div className="mt-6 flex items-center justify-center gap-6">
						<div className="border-b-2 border-transparent px-1 pb-3">
							<SkeletonLoader className="h-4 w-12 rounded-sm" />
						</div>
						<div className="border-b-2 border-accent px-1 pb-3">
							<SkeletonLoader className="h-4 w-8 rounded-sm" />
						</div>
					</div>
				</div>

				<div className="space-y-6 bg-bg px-5 py-6 sm:px-8">
					<section className="space-y-3">
						<SkeletonLoader className="h-8 w-48" />
						<div className="space-y-2">
							<SkeletonLoader className="h-4 max-w-2xl" />
							<SkeletonLoader className="h-4 max-w-xl" />
						</div>
					</section>

					<div>
						<SkeletonLoader className="h-9 w-55 rounded-lg" />
					</div>

					<div className="space-y-3">
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
