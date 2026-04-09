import { useEffect, useRef } from 'react';
import {
	ArrowLeft,
	ArrowRight,
	Link2,
	LoaderCircle,
	Search as SearchIcon,
	Sparkles,
	User,
	Wrench,
	X,
} from 'lucide-react';
import { useDashboardSearch } from '@/hooks';
import { getDocumentDirection, isDocumentRtl } from '@/i18n/direction';
import { __, sprintf } from '@/i18n';
import { cn } from '@/utils';
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
			className="dashboard-search-result"
		>
			<div className="dashboard-search-result-icon">
				<Icon size={16} />
			</div>
			<div className="dashboard-search-result-content">
				<p className="dashboard-search-result-title">
					{title}
				</p>
				{description ? (
					<p className="dashboard-search-result-description">
						{description}
					</p>
				) : null}
				{meta ? (
					<p className="dashboard-search-result-meta">
						{meta}
					</p>
				) : null}
			</div>
			<DirectionArrow
				size={15}
				className="dashboard-search-result-arrow"
			/>
		</button>
	);
}

function ResultSection({ title, children }: ResultSectionProps) {
	return (
		<div className="dashboard-search-section">
			<p className="dashboard-search-section-title">{title}</p>
			<div className="dashboard-search-section-body">{children}</div>
		</div>
	);
}

export const Search = () => {
	const direction = getDocumentDirection();
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
	const getSectionWrapperClassName = (isSpaced: boolean) =>
		cn(
			'dashboard-search-section-wrapper',
			isSpaced && 'dashboard-search-section-wrapper-spaced'
		);
	const hasFooterBorder =
		pageMatches.length > 0 ||
		toolMatches.length > 0 ||
		userMatches.length > 0 ||
		query.trim().length >= 2;

	return (
		<div ref={containerRef} className="dashboard-search">
			<form onSubmit={handleSubmit}>
				<div className="dashboard-search-field">
					<SearchIcon
						size={18}
						className="dashboard-search-field-icon"
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
						className="dashboard-search-input"
					/>
					{query ? (
						<button
							type="button"
							onClick={() =>
								clearSearch({
									resetLinksSearch: true,
								})
							}
							className="dashboard-search-clear"
							aria-label={__('Clear search')}
						>
							<X size={15} />
						</button>
					) : null}
				</div>
			</form>

			{isOpen ? (
				<div className="dashboard-search-panel">
					<div className="dashboard-search-panel-body">
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
								className={getSectionWrapperClassName(
									pageMatches.length > 0
								)}
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
								className={getSectionWrapperClassName(
									pageMatches.length > 0 ||
										toolMatches.length > 0
								)}
							>
								<ResultSection title={__('Users')}>
									{isFetchingUsers ? (
										<div className="dashboard-search-status">
											<LoaderCircle
												size={16}
												className="dashboard-search-status-icon"
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
										<p className="dashboard-search-empty">
											{__('No matching users found.')}
										</p>
									)}
								</ResultSection>
							</div>
						) : null}

						{query.trim().length >= 2 ? (
							<div
								className={getSectionWrapperClassName(
									pageMatches.length > 0 ||
										toolMatches.length > 0 ||
										userMatches.length > 0
								)}
							>
								<ResultSection title={__('Links')}>
									{isFetchingLinks ? (
										<div className="dashboard-search-status">
											<LoaderCircle
												size={16}
												className="dashboard-search-status-icon"
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
										<p className="dashboard-search-empty">
											{__('No matching links found.')}
										</p>
									)}
								</ResultSection>
							</div>
						) : null}

						<div
							className={cn(
								'dashboard-search-footer',
								hasFooterBorder &&
									'dashboard-search-footer-bordered'
							)}
						>
							<ResultButton
								icon={SearchIcon}
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
							<p className="dashboard-search-helper">
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

export default Search;
