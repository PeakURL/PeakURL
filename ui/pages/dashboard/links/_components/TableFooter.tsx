import { Link2, MousePointerClick } from 'lucide-react';
import { Select } from '@/components/ui';
import { __, sprintf } from '@/i18n';
import { isDocumentRtl, resolveFieldDirection } from '@/i18n/direction';
import type { LinksSortBy, LinksSortOrder, TableFooterProps } from './types';
import type { SelectOption } from '@/components/ui';

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
	const isRtl = isDocumentRtl();
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
		<div className="bg-surface rounded-lg border border-stroke px-4 py-3 mb-4">
			<div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
				{/* Stats */}
				<div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
					<div className="flex items-center gap-1.5">
						<Link2 className="h-3 w-3 text-accent" />
						<span className="text-text-muted">
							{__('Total Links:')}
						</span>
						<span className="font-semibold text-heading">
							{totalLinks}
						</span>
					</div>
					<div className="hidden h-4 w-px bg-stroke sm:block"></div>
					<div className="flex items-center gap-1.5">
						<MousePointerClick className="h-3 w-3 text-accent" />
						<span className="text-text-muted">
							{__('Total Clicks:')}
						</span>
						<span className="font-semibold text-heading">
							{totalClicks.toLocaleString()}
						</span>
					</div>
				</div>

				{/* Filters */}
				<div
					className={`grid grid-cols-2 gap-2 sm:flex sm:flex-wrap ${
						isRtl ? 'sm:justify-start' : 'sm:justify-end'
					}`}
				>
					<Select
						value={sortBy}
						onChange={setSortBy}
						options={sortOptions}
						className="col-span-2 sm:col-span-1 sm:min-w-52"
						ariaLabel={__('Sort links')}
						buttonClassName="rounded-lg bg-surface-alt px-3 py-2"
					/>

					<Select
						value={sortOrder}
						onChange={setSortOrder}
						options={sortOrderOptions}
						className="sm:min-w-44"
						ariaLabel={__('Sort order')}
						buttonClassName="rounded-lg bg-surface-alt px-3 py-2"
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
						className="sm:min-w-44"
						ariaLabel={__('Rows per page')}
						buttonClassName="rounded-lg bg-surface-alt px-3 py-2"
					/>

					{isCustom && (
						<input
							type="number"
							dir={resolveFieldDirection({
								value: limit,
								fallbackDirection: isRtl ? 'rtl' : 'ltr',
								valueDirection: 'ltr',
							})}
							min={1}
							value={limit}
							onChange={(e) => {
								const num = Number(e.target.value);
								if (!isNaN(num) && num > 0) setLimit(num);
							}}
							placeholder={__('Custom page size')}
							className="col-span-2 w-full min-w-0 bg-surface-alt border border-stroke rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-accent focus:border-accent outline-none sm:col-span-1 sm:w-28"
							style={{ textAlign: isRtl ? 'right' : 'left' }}
						/>
					)}
				</div>
			</div>
		</div>
	);
};

export default TableFooter;
