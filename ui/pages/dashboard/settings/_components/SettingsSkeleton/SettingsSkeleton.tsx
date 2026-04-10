import { Skeleton } from '@/components';

const SettingRowSkeleton = () => (
	<div className="settings-skeleton-row">
		<div className="settings-skeleton-row-copy">
			<Skeleton className="settings-skeleton-row-title" />
			<Skeleton className="settings-skeleton-row-description" />
			<Skeleton className="settings-skeleton-row-description-mobile" />
		</div>
		<div className="settings-skeleton-row-control">
			<Skeleton className="settings-skeleton-row-input" />
		</div>
	</div>
);

export const SettingsSkeleton = () => {
	return (
		<div className="settings-skeleton">
			<div className="settings-skeleton-header">
				<Skeleton className="settings-skeleton-title" />
				<Skeleton className="settings-skeleton-copy" />
			</div>

			<div className="skeleton-form settings-skeleton-panel">
				{[1, 2, 3, 4].map((i) => (
					<SettingRowSkeleton key={i} />
				))}
			</div>
		</div>
	);
};

export default SettingsSkeleton;
