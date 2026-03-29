// @ts-nocheck
/**
 * Global PeakURL logo component.
 *
 * Uses the same link-chain icon used on the login page branding panel,
 * rendered inside a gradient pill.  Accepts a `size` prop so the same
 * mark can appear in the sidebar, header, footer, and elsewhere.
 */

const sizeMap = {
	xs: { box: 'w-7 h-7', icon: 'w-3.5 h-3.5', radius: 'rounded-lg' },
	sm: { box: 'w-8 h-8', icon: 'w-4 h-4', radius: 'rounded-lg' },
	md: { box: 'w-9 h-9', icon: 'w-[18px] h-[18px]', radius: 'rounded-xl' },
	lg: { box: 'w-10 h-10', icon: 'w-5 h-5', radius: 'rounded-xl' },
};

export function Logo({ size = 'lg', className = '' }) {
	const s = sizeMap[size] || sizeMap.lg;

	return (
		<div
			className={`${s.box} ${s.radius} bg-linear-to-br from-primary-600 to-purple-600 flex items-center justify-center shadow-sm ${className}`}
		>
			<svg
				xmlns="http://www.w3.org/2000/svg"
				viewBox="0 0 24 24"
				fill="none"
				stroke="currentColor"
				strokeWidth="2.5"
				strokeLinecap="round"
				strokeLinejoin="round"
				className={`${s.icon} text-white`}
			>
				<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
				<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
			</svg>
		</div>
	);
}
