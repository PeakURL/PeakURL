import { ChevronLeft, ChevronRight } from "lucide-react";

import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";

import type { PaginationProps } from "../types";

const Pagination = ({
	currentPage,
	totalPages,
	onPageChange,
	startItem,
	endItem,
	totalItems,
}: PaginationProps) => {
	const isRtl = isDocumentRtl();
	const PreviousIcon = isRtl ? ChevronRight : ChevronLeft;
	const NextIcon = isRtl ? ChevronLeft : ChevronRight;

	return (
		<div className="links-pagination">
			<div className="links-pagination-inner">
				{/* Results Info */}
				<div className="links-pagination-summary">
					{sprintf(__("Showing %1$s–%2$s of %3$s links"), [
						String(startItem),
						String(endItem),
						String(totalItems),
					])}
				</div>

				{/* Pagination Controls */}
				<div className="links-pagination-controls">
					<button
						onClick={() => onPageChange(currentPage - 1)}
						disabled={currentPage === 1}
						className="links-pagination-nav"
					>
						<PreviousIcon className="h-3 w-3" />
						{__("Previous")}
					</button>

					<div className="links-pagination-pages">
						{(() => {
							const maxVisiblePages = 5;
							const visiblePages = Math.min(
								totalPages,
								maxVisiblePages
							);
							const halfWindow = Math.floor(visiblePages / 2);

							const startPage = Math.max(
								1,
								Math.min(
									currentPage - halfWindow,
									totalPages - visiblePages + 1
								)
							);

							return Array.from(
								{ length: visiblePages },
								(_, i) => startPage + i
							).map((pageNum) => (
								<button
									key={pageNum}
									onClick={() => onPageChange(pageNum)}
									className={`links-pagination-page ${
										currentPage === pageNum
											? "links-pagination-page-current"
											: ""
									}`}
								>
									{pageNum}
								</button>
							));
						})()}
					</div>

					<button
						onClick={() => onPageChange(currentPage + 1)}
						disabled={currentPage === totalPages}
						className="links-pagination-nav"
					>
						{__("Next")}
						<NextIcon className="h-3 w-3" />
					</button>
				</div>
			</div>
		</div>
	);
};

export default Pagination;
