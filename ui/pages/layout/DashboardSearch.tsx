import { useEffect, useRef } from 'react';
import {
	ArrowLeft,
	ArrowRight,
	Link2,
	LoaderCircle,
	Search,
	Sparkles,
	User,
	Wrench,
	X,
} from 'lucide-react';
import { useDashboardSearch } from '@/hooks';
import { getDocumentDirection, isDocumentRtl } from '@/i18n/direction';
import { __, sprintf } from '@/i18n';
import type { ResultButtonProps, ResultSectionProps } from './types';

function ResultButton({
	icon: Icon,
	title,
	description,
	meta,
	onClick,
}: ResultButtonProps) {
	const isRtl = isDocumentRtl();
	const DirectionArrow = isRtl ? ArrowLeft : ArrowRight;

	return (
		<button
			type="button"
			onMouseDown={(event) => event.preventDefault()}
			onClick={onClick}
			className="logical-text-start flex w-full items-start gap-3 rounded-lg px-3 py-3 transition-colors hover:bg-surface-alt"
		>
			<div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-stroke bg-surface text-text-muted">
				<Icon size={16} />
			</div>
			<div className="min-w-0 flex-1">
				<p className="truncate text-sm font-medium text-heading">
					{title}
				</p>
				{description ? (
					<p className="truncate text-xs text-text-muted">
						{description}
					</p>
				) : null}
				{meta ? (
					<p className="truncate text-xs text-text-muted/80">
						{meta}
					</p>
				) : null}
			</div>
			<DirectionArrow
				size={15}
				className="mt-1 shrink-0 text-text-muted"
			/>
		</button>
	);
}

function ResultSection({ title, children }: ResultSectionProps) {
	return (
		<div className="space-y-1">
			<p className="px-3 pt-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">
				{title}
			</p>
			<div className="space-y-1">{children}</div>
		</div>
	);
}

export const DashboardSearch = () => {
	const direction = getDocumentDirection();
	const isRtl = isDocumentRtl();
	const containerRef = useRef<HTMLDivElement | null>(null);
	const {
		query,
		isOpen,
		routeMatches,
		userMatches,
		linkMatches,
		isFetchingLinks,
		isFetchingUsers,
		allLinksHref,
		handleChange,
		handleFocus,
		handleSelect,
		clearSearch,
		handleSubmit,
	} = useDashboardSearch();

	useEffect(() => {
		const handlePointerDown = (event: PointerEvent) => {
			const container = containerRef.current;

			if (
				container &&
				event.target instanceof Node &&
				!container.contains(event.target)
			) {
				clearSearch();
			}
		};

		document.addEventListener('pointerdown', handlePointerDown);

		return () => {
			document.removeEventListener('pointerdown', handlePointerDown);
		};
	}, [clearSearch]);

	const toolMatches = routeMatches.filter((item) => item.section === 'tools');
	const pageMatches = routeMatches.filter((item) => item.section !== 'tools');
	const hasResults =
		pageMatches.length > 0 ||
		toolMatches.length > 0 ||
		userMatches.length > 0 ||
		linkMatches.length > 0;

	return (
		<div ref={containerRef} className="relative min-w-0 flex-1 max-w-xl">
			<form onSubmit={handleSubmit}>
				<div className="relative">
					<Search
						size={18}
						className="logical-inset-inline-start-3 absolute top-1/2 -translate-y-1/2 text-text-muted"
					/>
					<input
						type="text"
						dir={query ? 'auto' : direction}
						value={query}
						onFocus={handleFocus}
						onChange={(event) => handleChange(event.target.value)}
						onKeyDown={(event) => {
							if ('Escape' === event.key) {
								clearSearch({
									resetLinksSearch: false,
								});
							}
						}}
						placeholder={__('Search links, settings...')}
						aria-label={__('Search the dashboard')}
						className={`w-full rounded-lg border border-stroke bg-bg px-10 py-2 text-sm text-heading placeholder:text-text-muted focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 ${
							isRtl ? 'text-right' : 'text-left'
						}`}
					/>
					{query ? (
						<button
							type="button"
							onClick={() =>
								clearSearch({
									resetLinksSearch: true,
								})
							}
							className="logical-inset-inline-end-3 absolute top-1/2 -translate-y-1/2 rounded-full p-1 text-text-muted transition-colors hover:bg-surface-alt hover:text-heading"
							aria-label={__('Clear search')}
						>
							<X size={15} />
						</button>
					) : null}
				</div>
			</form>

			{isOpen ? (
				<div className="absolute left-0 right-0 top-full z-40 mt-2 overflow-hidden rounded-xl border border-stroke bg-surface shadow-xl">
					<div className="max-h-112 overflow-y-auto p-2">
						{pageMatches.length > 0 ? (
							<ResultSection title={__('Pages')}>
								{pageMatches.map((item) => (
									<ResultButton
										key={item.id}
										icon={Sparkles}
										title={item.label}
										description={item.description}
										onClick={() => handleSelect(item.href)}
									/>
								))}
							</ResultSection>
						) : null}

						{toolMatches.length > 0 ? (
							<div
								className={pageMatches.length > 0 ? 'mt-3' : ''}
							>
								<ResultSection title={__('Tools')}>
									{toolMatches.map((item) => (
										<ResultButton
											key={item.id}
											icon={Wrench}
											title={item.label}
											description={item.description}
											onClick={() =>
												handleSelect(item.href)
											}
										/>
									))}
								</ResultSection>
							</div>
						) : null}

						{query.trim().length >= 2 ? (
							<div
								className={
									pageMatches.length > 0 ||
									toolMatches.length > 0
										? 'mt-3'
										: ''
								}
							>
								<ResultSection title={__('Users')}>
									{isFetchingUsers ? (
										<div className="flex items-center gap-2 px-3 py-3 text-sm text-text-muted">
											<LoaderCircle
												size={16}
												className="animate-spin"
											/>
											{__('Searching users...')}
										</div>
									) : userMatches.length > 0 ? (
										userMatches.map((item) => (
											<ResultButton
												key={item.id}
												icon={User}
												title={item.title}
												description={item.description}
												meta={item.meta}
												onClick={() =>
													handleSelect(item.href)
												}
											/>
										))
									) : (
										<p className="px-3 py-3 text-sm text-text-muted">
											{__('No matching users found.')}
										</p>
									)}
								</ResultSection>
							</div>
						) : null}

						{query.trim().length >= 2 ? (
							<div
								className={
									pageMatches.length > 0 ||
									toolMatches.length > 0 ||
									userMatches.length > 0
										? 'mt-3'
										: ''
								}
							>
								<ResultSection title={__('Links')}>
									{isFetchingLinks ? (
										<div className="flex items-center gap-2 px-3 py-3 text-sm text-text-muted">
											<LoaderCircle
												size={16}
												className="animate-spin"
											/>
											{__('Searching links...')}
										</div>
									) : linkMatches.length > 0 ? (
										linkMatches.map((item) => (
											<ResultButton
												key={item.id}
												icon={Link2}
												title={item.title}
												description={item.description}
												meta={item.meta}
												onClick={() =>
													handleSelect(item.href)
												}
											/>
										))
									) : (
										<p className="px-3 py-3 text-sm text-text-muted">
											{__('No matching links found.')}
										</p>
									)}
								</ResultSection>
							</div>
						) : null}

						<div
							className={
								pageMatches.length > 0 ||
								toolMatches.length > 0 ||
								userMatches.length > 0 ||
								query.trim().length >= 2
									? 'mt-3 border-t border-stroke pt-2'
									: ''
							}
						>
							<ResultButton
								icon={Search}
								title={sprintf(
									__('Search all links for "%s"'),
									query.trim()
								)}
								description={__(
									'Open the links page with this search applied'
								)}
								onClick={() => handleSelect(allLinksHref)}
							/>
						</div>

						{!hasResults && query.trim().length < 2 ? (
							<p className="px-3 pb-2 pt-1 text-xs text-text-muted">
								{__(
									'Keep typing to search links, or jump straight to a dashboard page.'
								)}
							</p>
						) : null}
					</div>
				</div>
			) : null}
		</div>
	);
};

export default DashboardSearch;
