import { Link } from "react-router";
import { PEAKURL_NAME } from "@/constants";
import { cn } from "@/shared/formatting";
import type { LogoProps } from "../types";
import { LogoIcon } from "./LogoIcon";
import { Wordmark } from "./Wordmark";

/** Shared Compression mark and vector Plus Jakarta Sans Bold (700) wordmark. */
export function Logo({
	size = "md",
	tone = "light",
	showName = true,
	className,
	to,
	href,
}: LogoProps) {
	const classes = cn("logo", `logo-${size}`, `logo-${tone}`, className);
	const label = showName ? undefined : PEAKURL_NAME;
	const artwork = (
		<>
			<LogoIcon size={72} className="logo-mark" />
			{showName && (
				<span className="logo-name">
					<Wordmark />
					<span className="sr-only">{PEAKURL_NAME}</span>
				</span>
			)}
		</>
	);

	if (href) {
		const external = /^https?:\/\//.test(href);
		return (
			<a
				href={href}
				className={classes}
				aria-label={label}
				target={external ? "_blank" : undefined}
				rel={external ? "noopener noreferrer" : undefined}
			>
				{artwork}
			</a>
		);
	}
	if (to) {
		return (
			<Link to={to} className={classes} aria-label={label}>
				{artwork}
			</Link>
		);
	}
	return (
		<div
			className={classes}
			role={showName ? undefined : "img"}
			aria-label={label}
		>
			{artwork}
		</div>
	);
}
