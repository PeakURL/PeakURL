import { Loader2, Zap } from 'lucide-react';
import { __ } from '@/i18n';
import type {
	InlineLoaderProps,
	LoadingSize,
	LoadingSpinnerProps,
	ProgressBarProps,
	ProgressColor,
	PulseDotColor,
	PulseDotProps,
	PulseDotSize,
	SkeletonLoaderProps,
	TableRowSkeletonProps,
} from './types';
export type {
	InlineLoaderProps,
	LoadingSize,
	LoadingSpinnerProps,
	ProgressBarProps,
	ProgressColor,
	PulseDotColor,
	PulseDotProps,
	PulseDotSize,
	SkeletonLoaderProps,
	TableRowSkeletonProps,
} from './types';

/**
 * Modern Loading Spinner
 * @param {Object} props
 * @param {('xs'|'sm'|'md'|'lg'|'xl')} [props.size='md'] - Spinner size
 * @param {string} [props.className=''] - Additional class names
 */
export function LoadingSpinner({
	size = 'md',
	className = '',
}: LoadingSpinnerProps) {
	const sizes: Record<LoadingSize, number> = {
		xs: 12,
		sm: 16,
		md: 24,
		lg: 32,
		xl: 48,
	};

	return (
		<div className={`loading-spinner ${className}`}>
			<Loader2 size={sizes[size]} className="loading-spinner-icon" />
		</div>
	);
}

/**
 * PageLoader Component
 * A full-screen loading overlay with a modern spinner and text.
 */
export function PageLoader() {
	return (
		<div className="page-loader">
			<div className="page-loader-content">
				<div className="page-loader-visual">
					<div className="page-loader-ring"></div>
					<div className="page-loader-ring-active"></div>
					<div className="page-loader-icon">
						<Zap size={24} />
					</div>
				</div>
				<p className="page-loader-label">
					{__('Loading…')}
				</p>
			</div>
		</div>
	);
}

/**
 * SkeletonLoader Component
 * A basic animated placeholder for content that is loading.
 * @param {Object} props
 * @param {string} [props.className=''] - Additional class names
 */
export function SkeletonLoader({
	className = '',
	...props
}: SkeletonLoaderProps) {
	return (
		<div
			className={`skeleton-loader ${className}`}
			{...props}
		/>
	);
}

/**
 * CardSkeleton Component
 * A skeleton loader representing a card-like element.
 */
export function CardSkeleton() {
	return (
		<div className="card-skeleton">
			<div className="card-skeleton-header">
				<div className="card-skeleton-body">
					<SkeletonLoader className="h-4 w-24 mb-3" />
					<SkeletonLoader className="h-8 w-16 mb-3" />
					<SkeletonLoader className="h-5 w-20" />
				</div>
				<SkeletonLoader className="card-skeleton-icon" />
			</div>
			<SkeletonLoader className="card-skeleton-progress" />
		</div>
	);
}

/**
 * TableRowSkeleton Component
 * A skeleton loader representing a table row.
 * @param {Object} props
 * @param {number} [props.columns=4] - Number of columns to render
 */
export function TableRowSkeleton({ columns = 4 }: TableRowSkeletonProps) {
	return (
		<tr className="table-row-skeleton">
			{Array.from({ length: columns }).map((_, index) => (
				<td key={index} className="table-row-skeleton-cell">
					<SkeletonLoader className="h-4 w-full" />
				</td>
			))}
		</tr>
	);
}

/**
 * PulseDot Component
 * An animated dot for status indicators.
 * @param {Object} props
 * @param {('green'|'red'|'yellow'|'blue'|'purple')} [props.color='green'] - Dot color
 * @param {('xs'|'sm'|'md'|'lg')} [props.size='sm'] - Dot size
 * @param {boolean} [props.animated=true] - Whether to show the pulsing animation
 */
export function PulseDot({
	color = 'green',
	size = 'sm',
	animated = true,
}: PulseDotProps) {
	const colors: Record<PulseDotColor, string> = {
		green: 'bg-emerald-500',
		red: 'bg-red-500',
		yellow: 'bg-amber-500',
		blue: 'bg-blue-500',
		purple: 'bg-purple-500',
	};

	const sizes: Record<PulseDotSize, string> = {
		xs: 'w-1.5 h-1.5',
		sm: 'w-2 h-2',
		md: 'w-3 h-3',
		lg: 'w-4 h-4',
	};

	return (
		<span className="pulse-dot">
			<span
				className={`pulse-dot-core ${sizes[size]} ${colors[color]}`}
			></span>
			{animated && (
				<span
					className={`pulse-dot-ping ${sizes[size]} ${colors[color]}`}
				></span>
			)}
		</span>
	);
}

/**
 * ProgressBar Component
 * An animated progress bar.
 * @param {Object} props
 * @param {number} [props.progress=0] - Progress percentage (0-100)
 * @param {('blue'|'green'|'red'|'yellow'|'purple'|'accent')} [props.color='blue'] - Bar color
 * @param {string} [props.className=''] - Additional class names for the container
 * @param {boolean} [props.showLabel=false] - Whether to show the percentage label
 */
export function ProgressBar({
	progress = 0,
	color = 'blue',
	className = '',
	showLabel = false,
}: ProgressBarProps) {
	const colors: Record<ProgressColor, string> = {
		blue: 'bg-linear-to-r from-blue-500 to-blue-600',
		green: 'bg-linear-to-r from-emerald-500 to-emerald-600',
		red: 'bg-linear-to-r from-red-500 to-red-600',
		yellow: 'bg-linear-to-r from-amber-500 to-orange-600',
		purple: 'bg-linear-to-r from-purple-500 to-pink-600',
		accent: 'bg-linear-to-r from-accent to-primary-600',
	};

	const normalizedProgress = Math.min(100, Math.max(0, progress));

	return (
		<div className={className}>
			<div className="progress-bar-track">
				<div
					className={`progress-bar-fill ${colors[color]}`}
					style={{ width: `${normalizedProgress}%` }}
				></div>
			</div>
			{showLabel && (
				<div className="progress-bar-label">
					{normalizedProgress}%
				</div>
			)}
		</div>
	);
}

/**
 * InlineLoader Component
 * A small, inline spinner for buttons and other elements.
 * @param {Object} props
 * @param {string} [props.className=''] - Additional class names
 */
export function InlineLoader({ className = '' }: InlineLoaderProps) {
	return <Loader2 size={16} className={`inline-loader ${className}`} />;
}
