import { ChevronLeft, ChevronRight } from 'lucide-react';
import { __, sprintf } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import type { PaginationProps } from './types';

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
		<div className="bg-surface rounded-lg border border-stroke px-4 py-3">
			<div className="flex items-center justify-between">
				{/* Results Info */}
				<div className="text-sm text-text-muted">
					{sprintf(__('Showing %1$s–%2$s of %3$s links'), [
						String(startItem),
						String(endItem),
						String(totalItems),
					])}
				</div>

				{/* Pagination Controls */}
				<div className="flex items-center gap-1">
					<button
						onClick={() => onPageChange(currentPage - 1)}
						disabled={currentPage === 1}
						className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium transition-all disabled:opacity-40 disabled:cursor-not-allowed hover:bg-surface-alt text-text-muted hover:text-heading"
					>
						<PreviousIcon className="h-3 w-3" />
						{__('Previous')}
					</button>

					<div className="flex items-center gap-1 mx-2">
						{/* Simplified pagination for now - just showing current page surroundings could be better but sticking to simple list or range */}
						{[...Array(Math.min(totalPages, 5))].map((_, i) => {
							// Logic to show pages around current page
							// For simplicity, if totalPages <= 5, show all.
							// If > 5, this logic needs improvement but I'll stick to a simple window or just standard implementation.
							// The original code just showed first 5 pages.
							// I'll implement a smarter one or keep simple.
							// Let's keep simple first 5 if pages are many, or we can improve it.
							// Actually, let's just show up to 5 pages.
							const pageNum = i + 1;
							if (pageNum > totalPages) return null;

							return (
								<button
									key={pageNum}
									onClick={() => onPageChange(pageNum)}
									className={`
                                        w-8 h-8 rounded-lg text-sm font-medium transition-all
                                        ${
											currentPage === pageNum
												? 'bg-accent text-white'
												: 'text-text-muted hover:text-heading hover:bg-surface-alt'
										}
                                    `}
								>
									{pageNum}
								</button>
							);
						})}
					</div>

					<button
						onClick={() => onPageChange(currentPage + 1)}
						disabled={currentPage === totalPages}
						className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium transition-all disabled:opacity-40 disabled:cursor-not-allowed hover:bg-surface-alt text-text-muted hover:text-heading"
					>
						{__('Next')}
						<NextIcon className="h-3 w-3" />
					</button>
				</div>
			</div>
		</div>
	);
};

export default Pagination;
