import { Link2, MousePointerClick } from 'lucide-react';
import { Input, Select, type SelectOption } from '@/components';
import { __, sprintf } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import type {
	LinksSortBy,
	LinksSortOrder,
	TableFooterProps,
} from '../types';

const TableFooter = ({
	totalLinks = 0,
	totalClicks = 0,
	sortBy = 'createdAt',
	setSortBy,
	sortOrder = 'desc',
	setSortOrder,
	limit = 15,
	setLimit,
}: TableFooterProps) => {
	const pageDirection = isDocumentRtl() ? 'rtl' : 'ltr';
	const pageSizeOptions = [25, 50, 100, 150];
	const isCustom = !pageSizeOptions.includes(Number(limit));
	const sortOptions: SelectOption<LinksSortBy>[] = [
		{ value: 'createdAt', label: __('Sort: Date Created') },
		{ value: 'clicks', label: __('Sort: Most Clicks') },
		{ value: 'alias', label: __('Sort: Alias') },
	];
	const sortOrderOptions: SelectOption<LinksSortOrder>[] = [
		{ value: 'desc', label: __('Descending') },
		{ value: 'asc', label: __('Ascending') },
	];
	const pageSizeSelectOptions: SelectOption<string>[] = [
		...pageSizeOptions.map((opt) => ({
			value: String(opt),
			label: sprintf(__('Show: %s rows'), String(opt)),
		})),
		{ value: 'custom', label: __('Custom…') },
	];

	return (
		<div className="links-table-footer">
			<div className="links-table-footer-inner">
				{/* Stats */}
				<div className="links-table-footer-stats">
					<div className="links-table-footer-stat">
						<Link2 className="h-3 w-3 text-accent" />
						<span className="links-table-footer-label">
							{__('Total Links:')}
						</span>
						<span className="links-table-footer-value">
							{totalLinks}
						</span>
					</div>
					<div className="links-table-footer-divider"></div>
					<div className="links-table-footer-stat">
						<MousePointerClick className="h-3 w-3 text-accent" />
						<span className="links-table-footer-label">
							{__('Total Clicks:')}
						</span>
						<span className="links-table-footer-value">
							{totalClicks.toLocaleString()}
						</span>
					</div>
				</div>

				{/* Filters */}
				<div
					className={`links-table-footer-filters ${
						'rtl' === pageDirection
							? 'links-table-footer-filters-start'
							: 'links-table-footer-filters-end'
					}`}
				>
					<Select
						value={sortBy}
						onChange={setSortBy}
						options={sortOptions}
						className="links-table-footer-select-wide"
						ariaLabel={__('Sort links')}
						buttonClassName="form-control-surface-alt form-control-compact"
					/>

					<Select
						value={sortOrder}
						onChange={setSortOrder}
						options={sortOrderOptions}
						className="links-table-footer-select"
						ariaLabel={__('Sort order')}
						buttonClassName="form-control-surface-alt form-control-compact"
					/>

					<Select
						value={isCustom ? 'custom' : String(limit)}
						onChange={(value) => {
							if ('custom' === value) {
								return;
							}

							setLimit(Number(value));
						}}
						options={pageSizeSelectOptions}
						className="links-table-footer-select"
						ariaLabel={__('Rows per page')}
						buttonClassName="form-control-surface-alt form-control-compact"
					/>

					{isCustom && (
						<div className="links-table-footer-input">
							<Input
								type="number"
								min={1}
								valueDirection="ltr"
								value={limit}
								onChange={(event) => {
									const num = Number(event.target.value);
									if (!isNaN(num) && num > 0) setLimit(num);
								}}
								placeholder={__('Custom page size')}
								className="form-control-surface-alt form-control-compact form-control-strong-focus"
							/>
						</div>
					)}
				</div>
			</div>
		</div>
	);
};

export default TableFooter;
