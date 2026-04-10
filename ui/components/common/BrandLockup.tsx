import { Link } from 'react-router-dom';
import { PEAKURL_NAME } from '@constants';
import type { BrandLockupProps } from './types';
import { cn } from '@/utils';
export type {
	BrandLockupProps,
	BrandLockupSize,
	BrandLockupTone,
} from './types';

const sizeMap = {
	sm: {
		container: 'brand-lockup-sm',
		box: 'brand-lockup-mark-sm',
		iconClass: 'brand-lockup-icon-sm',
		text: 'brand-lockup-text-sm',
	},
	md: {
		container: 'brand-lockup-md',
		box: 'brand-lockup-mark-md',
		iconClass: 'brand-lockup-icon-md',
		text: 'brand-lockup-text-md',
	},
	lg: {
		container: 'brand-lockup-lg',
		box: 'brand-lockup-mark-lg',
		iconClass: 'brand-lockup-icon-lg',
		text: 'brand-lockup-text-lg',
	},
} as const;

const toneMap = {
	light: {
		box: 'brand-lockup-mark-light',
		text: 'brand-lockup-text-light',
	},
	dark: {
		box: 'brand-lockup-mark-dark',
		text: 'brand-lockup-text-dark',
	},
} as const;

export function BrandLockup({
	size = 'md',
	tone = 'light',
	to,
	className = '',
	textClassName = '',
}: BrandLockupProps) {
	const sizing = sizeMap[size];
	const colors = toneMap[tone];

	const content = (
		<>
			<div
				className={cn('brand-lockup-mark', sizing.box, colors.box)}
			>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					strokeWidth="2.5"
					strokeLinecap="round"
					strokeLinejoin="round"
					className={cn('brand-lockup-icon', sizing.iconClass)}
				>
					<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
					<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
				</svg>
			</div>
			<span
				className={cn(
					'brand-lockup-text',
					sizing.text,
					colors.text,
					textClassName
				)}
			>
				{PEAKURL_NAME}
			</span>
		</>
	);

	const sharedClassName = cn('brand-lockup', sizing.container, className);

	if (to) {
		return (
			<Link to={to} className={sharedClassName}>
				{content}
			</Link>
		);
	}

	return <div className={sharedClassName}>{content}</div>;
}
