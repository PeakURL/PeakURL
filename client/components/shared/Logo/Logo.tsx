import { Link } from "react-router";
import { peakurlLogoUrl } from "@/assets/logo";
import { PEAKURL_NAME } from "@/constants";
import { cn } from "@/shared/formatting";
import type { LogoProps } from "../types";

export type { LogoProps, LogoSize, LogoTone } from "../types";

/** Shared artwork and optional wordmark, with an accessible name in either form. */
export function Logo({
	size = "md",
	tone = "light",
	showName = true,
	to,
	href,
	className,
}: LogoProps) {
	const classes = cn("logo", `logo-${size}`, `logo-${tone}`, className);
	const label = showName ? undefined : PEAKURL_NAME;
	const artwork = (
		<>
			<img
				src={peakurlLogoUrl}
				alt=""
				aria-hidden="true"
				width="72"
				height="72"
				className="logo-mark"
			/>
			{showName && <span className="logo-name">{PEAKURL_NAME}</span>}
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
	if (to)
		return (
			<Link to={to} className={classes} aria-label={label}>
				{artwork}
			</Link>
		);
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
