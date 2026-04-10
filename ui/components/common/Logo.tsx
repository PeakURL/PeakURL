/**
 * Global PeakURL logo component.
 *
 * Uses the same link-chain icon used on the login page branding panel,
 * rendered inside a gradient pill.
 *
 * @param props Logo props
 * @param props.size Visual size preset
 * @param props.className Additional container classes
 */
import type { LogoProps } from './types';
import { cn } from '@/utils';
export type { LogoProps, LogoSize } from './types';

const sizeMap = {
	xs: { box: 'logo-mark-xs', icon: 'logo-mark-icon-xs' },
	sm: { box: 'logo-mark-sm', icon: 'logo-mark-icon-sm' },
	md: { box: 'logo-mark-md', icon: 'logo-mark-icon-md' },
	lg: { box: 'logo-mark-lg', icon: 'logo-mark-icon-lg' },
} as const;

export function Logo({ size = 'lg', className = '' }: LogoProps) {
	const sizing = sizeMap[size];

	return (
		<div
			className={cn('logo-mark', sizing.box, className)}
		>
			<svg
				xmlns="http://www.w3.org/2000/svg"
				viewBox="0 0 24 24"
				fill="none"
				stroke="currentColor"
				strokeWidth="2.5"
				strokeLinecap="round"
				strokeLinejoin="round"
				className={cn('logo-mark-icon', sizing.icon)}
			>
				<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
				<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
			</svg>
		</div>
	);
}
