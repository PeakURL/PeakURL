import { useRef } from "react";
import { ArrowLeft, ArrowRight } from "lucide-react";
import { Link } from "react-router";

import { DEFAULT_AUTH_LOGO_URL } from "@constants";
import { Logo, PageIllustration, PoweredBy, ThemeToggle } from "@/components";
import { useFloatingArtwork, useRevealSequence } from "@/hooks";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";

import type { AuthLayoutProps } from "./types";

const AUTH_REVEAL_GROUPS = [
	{
		selector: ".auth-page-layout",
		from: { y: 14, scale: 0.992, opacity: 0 },
		to: { scale: 1, duration: 0.42, ease: "power3.out" },
	},
	{
		selector: ".auth-page-showcase-header, .auth-page-showcase-footer",
		to: { duration: 0.36, stagger: 0.06, delay: 0.08 },
	},
	{
		selector:
			".auth-page-card-header, .auth-page-card-title, .auth-page-card-copy, .auth-page-form, .auth-page-status, .auth-page-alert, .auth-page-status-actions",
		to: { duration: 0.34, stagger: 0.04, delay: 0.07 },
	},
	{
		selector: ".auth-page-powered",
		from: { y: 6, opacity: 0 },
		to: { duration: 0.32, delay: 0.2 },
	},
];

function AuthLayout({
	backLabel = __("Back to login"),
	backTo = "/login",
	badgeIcon: BadgeIcon,
	badgeLabel,
	brandHref = DEFAULT_AUTH_LOGO_URL,
	cardCopy,
	cardTitle,
	children,
	illustration,
	noteCopy,
	noteTitle,
	poweredByHref,
	showcaseCopy,
	showcaseFooter,
	showcaseTitle,
}: AuthLayoutProps) {
	const containerRef = useRef<HTMLDivElement>(null);
	const isRtl = isDocumentRtl();
	const BackArrow = isRtl ? ArrowRight : ArrowLeft;

	useRevealSequence(containerRef, AUTH_REVEAL_GROUPS);
	useFloatingArtwork(containerRef, ".auth-page-illustration", illustration);

	return (
		<main id="page-container" className="auth-page">
			<div ref={containerRef} className="auth-page-container">
				<div className="auth-page-topbar">
					<ThemeToggle />
				</div>
				<div className="auth-page-layout">
					<aside className="auth-page-showcase" aria-hidden="true">
						<div className="auth-page-showcase-header">
							{badgeLabel && (
								<div className="auth-page-badge">
									{BadgeIcon && <BadgeIcon size={14} />}
									{badgeLabel}
								</div>
							)}
							<p className="auth-page-showcase-title">
								{showcaseTitle}
							</p>
							<p className="auth-page-showcase-copy">
								{showcaseCopy}
							</p>
						</div>

						<PageIllustration
							name={illustration}
							className="auth-page-illustration"
						/>

						<div className="auth-page-showcase-footer">
							{showcaseFooter ?? (
								<div className="auth-page-note">
									<p className="auth-page-note-title">
										{noteTitle}
									</p>
									<p className="auth-page-note-copy">
										{noteCopy}
									</p>
								</div>
							)}
						</div>
					</aside>

					<section
						className="auth-page-card"
						aria-labelledby="page-heading"
					>
						<header className="auth-page-card-header">
							<Logo
								size="md"
								href={brandHref}
								className="auth-page-logo"
							/>
							{backTo && (
								<Link
									to={backTo}
									className="auth-page-back-link"
								>
									<BackArrow size={16} />
									{backLabel}
								</Link>
							)}
						</header>

						<h1 id="page-heading" className="auth-page-card-title">
							{cardTitle}
						</h1>
						<p className="auth-page-card-copy">{cardCopy}</p>

						{children}
					</section>
				</div>
				<footer className="auth-page-powered">
					<PoweredBy href={poweredByHref} />
				</footer>
			</div>
		</main>
	);
}

export default AuthLayout;
