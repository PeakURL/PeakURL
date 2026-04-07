import { Link } from 'react-router-dom';
import { PEAKURL_NAME } from '@constants';
import type { BrandLockupProps } from './types';
export type {
	BrandLockupProps,
	BrandLockupSize,
	BrandLockupTone,
} from './types';

const sizeMap = {
	sm: {
		container: 'gap-2',
		box: 'h-7 w-7 rounded-md',
		iconClass: 'h-3.5 w-3.5',
		text: 'text-sm',
	},
	md: {
		container: 'gap-2.5',
		box: 'h-8 w-8 rounded-lg',
		iconClass: 'h-4 w-4',
		text: 'text-base',
	},
	lg: {
		container: 'gap-3',
		box: 'h-10 w-10 rounded-xl',
		iconClass: 'h-[18px] w-[18px]',
		text: 'text-[17px]',
	},
} as const;

const toneMap = {
	light: {
		box: 'bg-accent text-white shadow-sm dark:bg-white/10 dark:ring-1 dark:ring-white/10',
		text: 'text-slate-900 dark:text-white',
	},
	dark: {
		box: 'bg-white/10 text-white ring-1 ring-white/10',
		text: 'text-white',
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
				className={`flex items-center justify-center ${sizing.box} ${colors.box}`}
			>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					strokeWidth="2.5"
					strokeLinecap="round"
					strokeLinejoin="round"
					className={`${sizing.iconClass} shrink-0`}
				>
					<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
					<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
				</svg>
			</div>
			<span
				className={`font-semibold tracking-tight ${sizing.text} ${colors.text} ${textClassName}`}
			>
				{PEAKURL_NAME}
			</span>
		</>
	);

	const sharedClassName = `inline-flex items-center ${sizing.container} ${className}`;

	if (to) {
		return (
			<Link to={to} className={sharedClassName}>
				{content}
			</Link>
		);
	}

	return <div className={sharedClassName}>{content}</div>;
}
