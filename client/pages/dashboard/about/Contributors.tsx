import { ArrowUpRight, Heart } from "lucide-react";
import { __ } from "@/i18n";
import { SPONSOR_URL } from "@/constants";

const authorLinks = [
	{ label: "Website", href: "https://go.peakurl.org/author" },
	{ label: "GitHub", href: "https://go.peakurl.org/author-github" },
	{ label: "X", href: "https://go.peakurl.org/author-x" },
	{ label: "LinkedIn", href: "https://go.peakurl.org/author-linkedin" },
];
const supporters = [
	{ name: "Abdellah Chelli", href: "https://go.peakurl.org/bmc1" },
	{ name: "Sapperlott", href: "https://go.peakurl.org/bmc2" },
];

export function Contributors() {
	return (
		<section className="about-contributors">
			<h2>{__("The people behind PeakURL")}</h2>
			<p className="about-section-intro">
				{__(
					"An independent project, made possible by the people who write code, report issues, and support its development."
				)}
			</p>
			<div className="about-author">
				<img
					src="https://static.peakurl.org/assets/sponsors/author.svg"
					alt="Abd Ur Rehman"
					className="about-author-logo"
					width={64}
					height={64}
				/>
				<div>
					<h3>Abd Ur Rehman</h3>
					<p>{__("Founder & Maintainer")}</p>
					<div className="about-author-links">
						{authorLinks.map(({ label, href }) => (
							<a
								key={href}
								href={href}
								target="_blank"
								rel="noopener noreferrer"
							>
								{label}
								<ArrowUpRight size={12} aria-hidden="true" />
							</a>
						))}
					</div>
				</div>
			</div>
			<section className="about-supporters">
				<h3>{__("Community supporters")}</h3>
				<p>
					{__("Thank you for helping keep the project maintained.")}
				</p>
				<ul>
					{supporters.map(({ name, href }) => (
						<li key={href}>
							<a
								href={href}
								target="_blank"
								rel="noopener noreferrer"
							>
								{name}
								<ArrowUpRight size={14} aria-hidden="true" />
							</a>
						</li>
					))}
				</ul>
			</section>
			<div className="about-contribute">
				<Heart size={20} aria-hidden="true" />
				<div>
					<h3>{__("Help keep PeakURL going")}</h3>
					<p>
						{__(
							"Contribute a fix, share an idea, or help cover development costs."
						)}
					</p>
					<a
						href={SPONSOR_URL}
						target="_blank"
						rel="noopener noreferrer"
						className="about-text-link"
					>
						{__("Support the project")}
						<ArrowUpRight size={14} aria-hidden="true" />
					</a>
				</div>
			</div>
		</section>
	);
}
