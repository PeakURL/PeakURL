// @ts-nocheck
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { ChevronDown, Download, Link2, MousePointerClick } from 'lucide-react';

const TableFooter = ({
	totalLinks = 0,
	totalClicks = 0,
	sortBy = 'createdAt',
	setSortBy,
	sortOrder = 'desc',
	setSortOrder,
	limit = 15,
	setLimit,
	onExport,
}) => {
	const pageSizeOptions = [25, 50, 100, 150];
	const isCustom = !pageSizeOptions.includes(Number(limit));

	return (
		<div className="bg-surface rounded-lg border border-stroke px-4 py-3 mb-4">
			<div className="flex items-center justify-between gap-4">
				{/* Stats */}
				<div className="flex items-center gap-4 text-sm">
					<div className="flex items-center gap-1.5">
						<Link2 className="h-3 w-3 text-accent" />
						<span className="text-text-muted">Total Links:</span>
						<span className="font-semibold text-heading">
							{totalLinks}
						</span>
					</div>
					<div className="w-px h-4 bg-stroke"></div>
					<div className="flex items-center gap-1.5">
						<MousePointerClick className="h-3 w-3 text-accent" />
						<span className="text-text-muted">Total Clicks:</span>
						<span className="font-semibold text-heading">
							{totalClicks.toLocaleString()}
						</span>
					</div>
				</div>

				{/* Filters */}
				<div className="flex items-center gap-2">
					<select
						value={sortBy}
						onChange={(e) => setSortBy(e.target.value)}
						className="bg-surface-alt border border-stroke rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-accent focus:border-accent outline-none cursor-pointer"
					>
						<option value="createdAt">Sort: Date Created</option>
						<option value="clicks">Sort: Most Clicks</option>
						<option value="alias">Sort: Alias</option>
					</select>

					<select
						value={sortOrder}
						onChange={(e) => setSortOrder(e.target.value)}
						className="bg-surface-alt border border-stroke rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-accent focus:border-accent outline-none cursor-pointer"
					>
						<option value="desc">Descending</option>
						<option value="asc">Ascending</option>
					</select>

					<select
						value={isCustom ? 'custom' : String(limit)}
						onChange={(e) => {
							const val = e.target.value;
							if (val === 'custom') return; // keep input active
							setLimit(Number(val));
						}}
						className="bg-surface-alt border border-stroke rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-accent focus:border-accent outline-none cursor-pointer"
					>
						{pageSizeOptions.map((opt) => (
							<option key={opt} value={String(opt)}>
								Show: {opt} rows
							</option>
						))}
						<option value="custom">Custom…</option>
					</select>

					{isCustom && (
						<input
							type="number"
							min={1}
							value={limit}
							onChange={(e) => {
								const num = Number(e.target.value);
								if (!isNaN(num) && num > 0) setLimit(num);
							}}
							placeholder="Custom page size"
							className="w-28 bg-surface-alt border border-stroke rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-accent focus:border-accent outline-none"
						/>
					)}

					<Menu as="div" className="relative">
						<MenuButton className="px-3 py-1.5 text-sm font-medium text-accent hover:bg-accent/10 rounded-lg transition-colors flex items-center gap-1.5">
							<Download className="h-3 w-3" />
							Export
							<ChevronDown className="h-3 w-3" />
						</MenuButton>

						<MenuItems className="absolute right-0 z-20 mt-2 min-w-40 overflow-hidden rounded-xl border border-stroke bg-surface shadow-xl focus:outline-none">
							<div className="p-1">
								{[
									{
										format: 'csv',
										label: 'Export CSV',
										description: 'Import ready',
									},
									{
										format: 'json',
										label: 'Export JSON',
										description: 'Structured data',
									},
									{
										format: 'xml',
										label: 'Export XML',
										description: 'Portable feed',
									},
								].map((option) => (
									<MenuItem key={option.format}>
										{({ focus }) => (
											<button
												type="button"
												onClick={() => onExport(option.format)}
												className={`flex w-full items-start justify-between rounded-lg px-3 py-2 text-left transition-colors ${
													focus
														? 'bg-surface-alt'
														: ''
												}`}
											>
												<div>
													<div className="text-sm font-medium text-heading">
														{option.label}
													</div>
													<div className="text-xs text-text-muted">
														{option.description}
													</div>
												</div>
											</button>
										)}
									</MenuItem>
								))}
							</div>
						</MenuItems>
					</Menu>
				</div>
			</div>
		</div>
	);
};

export default TableFooter;
