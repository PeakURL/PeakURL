import type { ReactNode } from "react";

import { __ } from "@/i18n";
import { getBrandTagline } from "@/shared/branding";

import { Logo } from "../Logo";
import { PoweredBy } from "../PoweredBy";

const statusArtworkUrl = new URL(
	"../../../../server/services/status-artwork.svg",
	import.meta.url
).href;

interface StatusLayoutProps {
	title: string;
	description: string;
	label: string;
	actions: ReactNode;
	note?: string;
	children?: ReactNode;
	embedded?: boolean;
}

/** Flat branded layout shared by dashboard recovery and missing-page states. */
export function StatusLayout({
	title,
	description,
	label,
	actions,
	note,
	children,
	embedded = false,
}: StatusLayoutProps) {
	const Content = embedded ? "div" : "main";
	return (
		<div
			className={`status-page${embedded ? " status-page-embedded" : ""}`}
		>
			<div className="status-container">
				<header className="status-header">
					<Logo size="lg" />
					<span className="status-tagline">{getBrandTagline()}</span>
				</header>
				<Content className="status-main">
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
						<img
							className="status-artwork"
							src={statusArtworkUrl}
							alt=""
							aria-hidden="true"
							width="360"
							height="280"
						/>
					</section>
					{children}
				</Content>
				<footer className="status-footer">
					<PoweredBy />
				</footer>
			</div>
		</div>
	);
}
