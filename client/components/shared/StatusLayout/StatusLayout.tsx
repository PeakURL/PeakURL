import { useRef, type ReactNode } from "react";

import type { StatusIllustrationName } from "@/assets/status-illustrations";
import { useFloatingArtwork, useRevealSequence } from "@/hooks";
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

const STATUS_REVEAL_GROUPS = [
	{
		selector: ".status-header",
		from: { y: -8, opacity: 0 },
		to: { duration: 0.34 },
	},
	{
		selector:
			".status-eyebrow, .status-title, .status-description, .status-actions, .status-note",
		to: { duration: 0.38, stagger: 0.05, delay: 0.06 },
	},
	{
		selector: ".status-main > section + *",
		from: { y: 14, opacity: 0 },
		to: { duration: 0.4, delay: 0.16 },
	},
];

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
	const pageRef = useRef<HTMLDivElement>(null);

	useRevealSequence(pageRef, STATUS_REVEAL_GROUPS);
	useFloatingArtwork(pageRef, ".status-artwork", illustration);

	return (
		<div ref={pageRef} className="status-page">
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
