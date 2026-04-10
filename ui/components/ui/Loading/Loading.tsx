import { Loader2, Zap } from 'lucide-react';
import { __ } from '@/i18n';
import { cn } from '@/utils';
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
} from '../types';
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
} from '../types';

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
		<div className={cn('loading-spinner', className)}>
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
			className={cn('skeleton-loader', className)}
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
					<SkeletonLoader className="card-skeleton-label" />
					<SkeletonLoader className="card-skeleton-value" />
					<SkeletonLoader className="card-skeleton-meta" />
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
					<SkeletonLoader className="table-row-skeleton-line" />
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
		green: 'pulse-dot-color-green',
		red: 'pulse-dot-color-red',
		yellow: 'pulse-dot-color-yellow',
		blue: 'pulse-dot-color-blue',
		purple: 'pulse-dot-color-purple',
	};

	const sizes: Record<PulseDotSize, string> = {
		xs: 'pulse-dot-size-xs',
		sm: 'pulse-dot-size-sm',
		md: 'pulse-dot-size-md',
		lg: 'pulse-dot-size-lg',
	};

	return (
		<span className="pulse-dot">
			<span
				className={cn('pulse-dot-core', sizes[size], colors[color])}
			></span>
			{animated && (
				<span
					className={cn('pulse-dot-ping', sizes[size], colors[color])}
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
		blue: 'progress-bar-fill-blue',
		green: 'progress-bar-fill-green',
		red: 'progress-bar-fill-red',
		yellow: 'progress-bar-fill-yellow',
		purple: 'progress-bar-fill-purple',
		accent: 'progress-bar-fill-accent',
	};

	const normalizedProgress = Math.min(100, Math.max(0, progress));

	return (
		<div className={className}>
			<div className="progress-bar-track">
				<div
					className={cn('progress-bar-fill', colors[color])}
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
	return <Loader2 size={16} className={cn('inline-loader', className)} />;
}
