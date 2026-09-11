import { ChevronLeft, ChevronRight } from "lucide-react";

import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { cn, formatCount } from "@/shared/formatting";

import { getVisiblePages } from "../../lib";
import type { ActivityPaginationMeta } from "../../types";

interface ActivityPaginationProps {
	meta: ActivityPaginationMeta;
	onPageChange: (page: number) => void;
}

export function ActivityPagination({
	meta,
	onPageChange,
}: ActivityPaginationProps) {
	const totalItems = meta.totalItems ?? 0;
	const totalPages = meta.totalPages ?? 1;
	const currentPage = meta.page ?? 1;
	const limit = meta.limit ?? 25;

	if (totalItems <= 0) {
		return null;
	}

	const isRtl = isDocumentRtl();
	const PreviousIcon = isRtl ? ChevronRight : ChevronLeft;
	const NextIcon = isRtl ? ChevronLeft : ChevronRight;

	const startItem = (currentPage - 1) * limit + 1;
	const endItem = Math.min(currentPage * limit, totalItems);
	const visiblePages = getVisiblePages(currentPage, totalPages);

	return (
		<div className="activity-page-pagination">
			<div className="activity-page-pagination-summary-group">
				<p className="activity-page-pagination-summary">
					{sprintf(__("Showing %1$s-%2$s of %3$s events"), [
						formatCount(startItem),
						formatCount(endItem),
						formatCount(totalItems),
					])}
				</p>
				<p className="activity-page-pagination-page-note">
					{sprintf(__("Page %1$s of %2$s"), [
						String(currentPage),
						String(totalPages),
					])}
				</p>
			</div>

			{totalPages > 1 ? (
				<div className="activity-page-pagination-controls">
					<button
						type="button"
						onClick={() => onPageChange(currentPage - 1)}
						disabled={currentPage === 1}
						className="activity-page-pagination-nav"
					>
						<PreviousIcon size={14} />
						{__("Previous")}
					</button>

					<div className="activity-page-pagination-pages">
						{visiblePages.map((page) => (
							<button
								key={page}
								type="button"
								onClick={() => onPageChange(page)}
								className={cn(
									"activity-page-pagination-page",
									page === currentPage &&
										"activity-page-pagination-page-current"
								)}
							>
								{page}
							</button>
						))}
					</div>

					<button
						type="button"
						onClick={() => onPageChange(currentPage + 1)}
						disabled={currentPage === totalPages}
						className="activity-page-pagination-nav"
					>
						{__("Next")}
						<NextIcon size={14} />
					</button>
				</div>
			) : null}
		</div>
	);
}
