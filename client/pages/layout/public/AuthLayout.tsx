import { ArrowLeft, ArrowRight } from "lucide-react";
import { Link } from "react-router";

import { DEFAULT_AUTH_LOGO_URL } from "@constants";
import { Logo, PageIllustration, PoweredBy } from "@/components";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";

import type { AuthLayoutProps } from "./types";

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
	const isRtl = isDocumentRtl();
	const BackArrow = isRtl ? ArrowRight : ArrowLeft;

	return (
		<main id="page-container" className="auth-page">
			<div className="auth-page-container">
				<div className="auth-page-layout">
					<aside className="auth-page-showcase" aria-hidden="true">
						<div className="auth-page-showcase-header">
							{badgeLabel && (
								<div className="auth-page-badge">
									{BadgeIcon && <BadgeIcon size={14} />}
									{badgeLabel}
								</div>
							)}
							<h1 className="auth-page-showcase-title">
								{showcaseTitle}
							</h1>
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

						<h2 id="page-heading" className="auth-page-card-title">
							{cardTitle}
						</h2>
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
