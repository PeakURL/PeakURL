export interface LogoIconProps {
	size?: number;
	className?: string;
	loop?: boolean;
}

/** Canonical Compression symbol bound to theme-aware CSS mark tokens. */
export function LogoIcon({
	size = 36,
	className,
	loop = false,
}: LogoIconProps) {
	return (
		<svg
			xmlns="http://www.w3.org/2000/svg"
			width={size}
			height={size}
			viewBox="12 12.5 72 72"
			fill="none"
			className={className}
			data-logo-icon=""
			data-motion={loop ? "loop" : undefined}
			aria-hidden="true"
			focusable="false"
		>
			<g fill="var(--logo-foreground, #126e68)">
				<rect x="18.5" y="25.5" width="59" height="11" rx="5.5" />
				<rect
					className="logo-icon-middle"
					x="18.5"
					y="42.5"
					width="44"
					height="11"
					rx="5.5"
				/>
				<rect
					className="logo-icon-bottom"
					x="18.5"
					y="59.5"
					width="29"
					height="11"
					rx="5.5"
				/>
			</g>
			<circle
				className="logo-icon-dot"
				cx="65"
				cy="65"
				r="6.5"
				fill="var(--logo-dot, #17373b)"
			/>
		</svg>
	);
}
