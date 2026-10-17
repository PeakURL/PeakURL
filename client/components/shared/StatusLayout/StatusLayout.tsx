import type { ReactNode } from "react";

import type { StatusIllustrationName } from "@/assets/status-illustrations";
import { __ } from "@/i18n";
import { getBrandTagline } from "@/shared/branding";

import { Logo } from "../Logo";
import { PageIllustration } from "../PageIllustration";
import { PoweredBy } from "../PoweredBy";

interface StatusLayoutProps {
	title: string;
	description: string;
	label: string;
	actions: ReactNode;
	note?: string;
	children?: ReactNode;
	illustration?: StatusIllustrationName;
}

/** Standalone branded recovery layout, independent of the dashboard layout. */
export function StatusLayout({
	title,
	description,
	label,
	actions,
	note,
	children,
	illustration = "missing-page",
}: StatusLayoutProps) {
	return (
		<div className="status-page">
			<div className="status-container">
				<header className="status-header">
					<Logo size="lg" />
					<span className="status-tagline">{getBrandTagline()}</span>
				</header>
				<main className="status-main">
					<section
						className="status-hero"
						aria-labelledby="page-heading"
					>
						<div className="status-copy">
							<p className="status-eyebrow">{label}</p>
							<h1 id="page-heading" className="status-title">
								{title}
							</h1>
							<p className="status-description">{description}</p>
							<div className="status-actions">{actions}</div>
							{note && <p className="status-note">{note}</p>}
						</div>
						<PageIllustration
							className="status-artwork"
							name={illustration}
						/>
					</section>
					{children}
				</main>
				<footer className="status-footer">
					<PoweredBy />
				</footer>
			</div>
		</div>
	);
}
