import { ChevronLeft, ChevronRight } from "lucide-react";

import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { cn, formatCount } from "@/shared/formatting";

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

	const maxVisiblePages = 5;
	const visiblePages = Math.min(totalPages, maxVisiblePages);
	const halfWindow = Math.floor(visiblePages / 2);

	const startPage = Math.max(
		1,
		Math.min(currentPage - halfWindow, totalPages - visiblePages + 1)
	);

	const pages = Array.from({ length: visiblePages }, (_, i) => startPage + i);

	// On mobile, show fewer page buttons (up to 3) around current page and hide the rest
	const getIsMobileVisible = (pageNum: number) => {
		if (visiblePages <= 3) {
			return true;
		}
		const halfMobile = 1;
		let mobileStart = Math.max(startPage, currentPage - halfMobile);
		let mobileEnd = mobileStart + 2;
		if (mobileEnd > startPage + visiblePages - 1) {
			mobileEnd = startPage + visiblePages - 1;
			mobileStart = Math.max(startPage, mobileEnd - 2);
		}
		return pageNum >= mobileStart && pageNum <= mobileEnd;
	};

	return (
		<div className="links-pagination">
			<div className="links-pagination-inner">
				{/* Results Info */}
				<div className="links-pagination-summary">
					{sprintf(__("Showing %1$s–%2$s of %3$s links"), [
						formatCount(startItem),
						formatCount(endItem),
						formatCount(totalItems),
					])}
				</div>

				{/* Pagination Controls */}
				<div className="links-pagination-controls">
					<button
						type="button"
						onClick={() => onPageChange(currentPage - 1)}
						disabled={currentPage === 1}
						className="links-pagination-nav"
						aria-label={__("Previous page")}
					>
						<PreviousIcon className="h-3 w-3" />
						<span>{__("Previous")}</span>
					</button>

					<div className="links-pagination-pages">
						{pages.map((pageNum) => {
							const isMobileVisible = getIsMobileVisible(pageNum);

							return (
								<button
									key={pageNum}
									type="button"
									onClick={() => onPageChange(pageNum)}
									className={cn(
										"links-pagination-page",
										currentPage === pageNum &&
											"links-pagination-page-current",
										!isMobileVisible &&
											"hidden sm:inline-flex"
									)}
									aria-label={sprintf(__("Page %d"), pageNum)}
									aria-current={
										currentPage === pageNum
											? "page"
											: undefined
									}
								>
									{pageNum}
								</button>
							);
						})}
					</div>

					<button
						type="button"
						onClick={() => onPageChange(currentPage + 1)}
						disabled={currentPage === totalPages}
						className="links-pagination-nav"
						aria-label={__("Next page")}
					>
						<span>{__("Next")}</span>
						<NextIcon className="h-3 w-3" />
					</button>
				</div>
			</div>
		</div>
	);
};

export default Pagination;
