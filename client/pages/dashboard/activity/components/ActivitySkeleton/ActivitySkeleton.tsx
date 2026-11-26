import { __ } from "@/i18n";
import { SearchInput, Skeleton } from "@/components";
import { cn } from "@/shared/formatting";

interface ActivitySkeletonProps {
	isAdmin?: boolean;
}

export function ActivitySkeleton({ isAdmin = false }: ActivitySkeletonProps) {
	return (
		<div className="activity-page-panel" aria-busy="true">
			<div className="activity-page-panel-header">
				<h2 className="activity-page-panel-title">
					{__("Activity history")}
				</h2>
				<div className="w-full sm:w-64">
					<SearchInput
						value=""
						onChange={() => {}}
						placeholder={__("Search activity...")}
						aria-label={__("Search activity history")}
						disabled
					/>
				</div>
			</div>
			<div className="activity-page-table" aria-hidden="true">
				<div className="activity-page-table-scroll">
					<div className="activity-page-table-element">
						<div className="activity-page-events">
							{Array.from({ length: 6 }, (_, index) => (
								<div
									key={index}
									className={cn(
										"activity-page-event",
										isAdmin && "activity-page-event-admin"
									)}
								>
									<div className="activity-page-event-identity">
										{isAdmin ? (
											<Skeleton className="h-4 w-4 rounded shrink-0" />
										) : null}
										<Skeleton className="activity-page-skeleton-icon shrink-0" />
									</div>
									<div className="activity-page-event-primary">
										<Skeleton className="activity-page-skeleton-title" />
									</div>
									<div className="activity-page-event-context">
										<div className="activity-page-skeleton-details">
											<Skeleton className="activity-page-skeleton-chip" />
											<Skeleton className="activity-page-skeleton-chip" />
										</div>
									</div>
									<div className="activity-page-event-time">
										<Skeleton className="activity-page-skeleton-time" />
										<Skeleton className="activity-page-skeleton-time-secondary" />
									</div>
									{isAdmin ? (
										<div className="activity-page-event-actions">
											<Skeleton className="h-7.5 w-7.5 rounded-lg shrink-0" />
										</div>
									) : null}
								</div>
							))}
						</div>
					</div>
				</div>
			</div>
		</div>
	);
}

export default ActivitySkeleton;
