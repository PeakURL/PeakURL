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
		<div className={`inline-flex items-center justify-center ${className}`}>
			<Loader2 size={sizes[size]} className="text-accent animate-spin" />
		</div>
	);
}

/**
 * PageLoader Component
 * A full-screen loading overlay with a modern spinner and text.
 */
export function PageLoader() {
	return (
		<div className="fixed inset-0 bg-bg/80 z-50 flex items-center justify-center">
			<div className="text-center">
				<div className="relative mb-6">
					{/* Outer ring */}
					<div className="w-16 h-16 border-4 border-stroke rounded-full"></div>
					{/* Spinning gradient ring */}
					<div className="absolute top-0 left-0 w-16 h-16 border-4 border-transparent border-t-accent rounded-full animate-spin"></div>
					{/* Inner icon */}
					<div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2">
						<Zap size={24} className="text-accent" />
					</div>
				</div>
				<p className="text-sm font-medium text-text-muted">
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
			className={`animate-pulse bg-surface-alt rounded-lg ${className}`}
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
		<div className="bg-surface border border-stroke rounded-2xl p-6 animate-pulse">
			<div className="flex items-start justify-between mb-4">
				<div className="flex-1">
					<SkeletonLoader className="h-4 w-24 mb-3" />
					<SkeletonLoader className="h-8 w-16 mb-3" />
					<SkeletonLoader className="h-5 w-20" />
				</div>
				<SkeletonLoader className="w-14 h-14 rounded-2xl" />
			</div>
			<SkeletonLoader className="h-2 w-full rounded-full" />
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
		<tr className="animate-pulse">
			{Array.from({ length: columns }).map((_, index) => (
				<td key={index} className="px-6 py-4">
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
		<span className="relative inline-flex">
			<span
				className={`${sizes[size]} ${colors[color]} rounded-full`}
			></span>
			{animated && (
				<span
					className={`absolute inline-flex ${sizes[size]} ${colors[color]} rounded-full opacity-75 animate-ping`}
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
			<div className="w-full bg-surface-alt rounded-full h-2 overflow-hidden">
				<div
					className={`h-2 ${colors[color]} rounded-full transition-all duration-500 ease-out`}
					style={{ width: `${normalizedProgress}%` }}
				></div>
			</div>
			{showLabel && (
				<div className="mt-1 text-xs text-muted text-right">
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
	return <Loader2 size={16} className={`animate-spin ${className}`} />;
}
