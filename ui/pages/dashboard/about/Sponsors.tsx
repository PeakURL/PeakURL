import { Globe } from "lucide-react";
import { __ } from "@/i18n";

const socialIconPaths: Record<string, string> = {
	github: "M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z",
	x: "M18.901 1.153h3.68l-8.04 9.19L24 22.847h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.153h7.594l5.243 6.932 6.064-6.932Zm-1.293 19.493h2.039L6.486 3.24H4.298l13.31 17.406Z",
	linkedin:
		"M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.85-3.037-1.853 0-2.136 1.447-2.136 2.942v5.664H9.352V9h3.414v1.561h.049c.476-.9 1.637-1.85 3.37-1.85 3.602 0 4.268 2.371 4.268 5.455v6.286ZM5.337 7.433a2.062 2.062 0 1 1 0-4.124 2.062 2.062 0 0 1 0 4.124ZM7.114 20.452H3.558V9h3.556v11.452ZM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003Z",
};

const SocialIcon = ({
	name,
	className,
}: {
	name: string;
	className?: string;
}) => (
	<svg
		className={className}
		viewBox="0 0 24 24"
		fill="currentColor"
		aria-hidden="true"
	>
		<path d={socialIconPaths[name]} />
	</svg>
);

export type SponsorSocial = {
	platform: "website" | "github" | "x" | "linkedin";
	url: string;
};

export type Sponsor = {
	name: string;
	role?: string;
	badge?: string;
	socials?: SponsorSocial[];
};

const communitySupporters: Sponsor[] = [
	{
		name: "Abdellah Chelli",
		socials: [
			{
				platform: "github",
				url: "https://github.com/sneetsher",
			},
		],
	},
];

export const Sponsors = () => {
	return (
		<div className="about-page-sponsors">
			<div className="about-page-section-heading">
				<h2 className="about-page-section-title">
					{__("Our amazing supporters")}
				</h2>
				<p className="about-page-section-summary">
					{__(
						"A huge thank you to everyone who has contributed to keeping PeakURL running."
					)}
				</p>
			</div>

			{/* Creator & Maintainer */}
			<div className="about-page-sponsor-creator">
				<div className="about-page-sponsor-creator-info">
					<h3 className="about-page-sponsor-creator-title">
						{__("Creator & Maintainer")}
					</h3>
					<p className="about-page-sponsor-creator-desc">
						{__(
							"PeakURL is a solo project. All development, hosting, and infrastructure costs are currently self-funded by the creator. If PeakURL brings value to your workflow, consider pitching in!"
						)}
					</p>
				</div>
				<div className="about-page-sponsor-creator-profile">
					<img
						src="https://static.peakurl.org/assets/sponsors/author.svg"
						alt="Abd Ur Rehman"
						className="about-page-sponsor-author-logo"
						width={72}
						height={72}
					/>
					<div className="about-page-sponsor-creator-details">
						<p className="about-page-sponsor-creator-name">
							Abd Ur Rehman
						</p>
						<p className="about-page-sponsor-creator-badge">
							{__("Independent Solo Maintainer")}
						</p>
						<div className="about-page-sponsor-creator-socials">
							<a
								href="https://go.peakurl.org/author"
								target="_blank"
								rel="noopener noreferrer"
								aria-label={__("Website")}
							>
								<Globe size={16} />
							</a>
							<a
								href="https://go.peakurl.org/author-github"
								target="_blank"
								rel="noopener noreferrer"
								aria-label={__("GitHub")}
							>
								<SocialIcon name="github" className="h-4 w-4" />
							</a>
							<a
								href="https://go.peakurl.org/author-x"
								target="_blank"
								rel="noopener noreferrer"
								aria-label={__("X (Twitter)")}
							>
								<SocialIcon name="x" className="h-4 w-4" />
							</a>
							<a
								href="https://go.peakurl.org/author-linkedin"
								target="_blank"
								rel="noopener noreferrer"
								aria-label={__("LinkedIn")}
							>
								<SocialIcon
									name="linkedin"
									className="h-4 w-4"
								/>
							</a>
						</div>
					</div>
				</div>
			</div>

			{/* Corporate Sponsors */}
			<div className="about-page-sponsor-section">
				<div className="about-page-sponsor-section-header">
					<h3 className="about-page-sponsor-section-title">
						{__("Company Sponsors")}
					</h3>
					<p className="about-page-sponsor-section-desc">
						{__(
							"Claim your spot on the homepage. Get visibility for your brand while supporting open-source development."
						)}
					</p>
				</div>
				<div className="about-page-sponsor-companies">
					{[1, 2, 3, 4].map((i) => (
						<a
							key={i}
							href="https://peakurl.org/sponsor"
							target="_blank"
							rel="noopener noreferrer"
							className="about-page-sponsor-company-slot group"
						>
							<div className="about-page-sponsor-company-slot-bg" />
							<div className="about-page-sponsor-company-slot-content">
								<svg
									fill="none"
									viewBox="0 0 24 24"
									stroke="currentColor"
									strokeWidth="2"
								>
									<path
										strokeLinecap="round"
										strokeLinejoin="round"
										d="M12 4v16m8-8H4"
									/>
								</svg>
								<span>{__("Your Logo")}</span>
							</div>
						</a>
					))}
				</div>
			</div>

			{/* Community Supporters */}
			<div className="about-page-sponsor-section">
				<div className="about-page-sponsor-section-header">
					<h3 className="about-page-sponsor-section-title">
						{__("Community Supporters")}
					</h3>
					<p className="about-page-sponsor-section-desc">
						{__(
							"A huge thanks to the individuals who back PeakURL's development and help keep the project actively maintained."
						)}
					</p>
					<a
						href="https://buymeacoffee.com/PeakURL"
						target="_blank"
						rel="noopener noreferrer"
						className="about-page-sponsor-btn"
					>
						{__("Support the Project")}
					</a>
				</div>

				<div className="about-page-sponsor-community">
					{communitySupporters.map((supporter) => (
						<div
							key={supporter.name}
							className="about-page-sponsor-supporter group"
						>
							<div className="about-page-sponsor-supporter-avatar">
								<img
									src="https://static.peakurl.org/assets/sponsors/bmc-logo.svg"
									alt="Buy Me a Coffee"
									width={32}
									height={32}
									className="object-contain"
								/>
							</div>
							<div className="about-page-sponsor-supporter-info">
								<p className="about-page-sponsor-supporter-name">
									{supporter.name}
								</p>
								{supporter.socials &&
									supporter.socials.length > 0 && (
										<div className="about-page-sponsor-supporter-socials">
											{supporter.socials.map((social) => (
												<a
													key={social.platform}
													href={social.url}
													target="_blank"
													rel="noopener noreferrer"
													aria-label={`${supporter.name} on ${social.platform}`}
												>
													{social.platform ===
													"website" ? (
														<Globe size={14} />
													) : (
														<SocialIcon
															name={
																social.platform
															}
															className="h-3.5 w-3.5"
														/>
													)}
												</a>
											))}
										</div>
									)}
							</div>
						</div>
					))}
				</div>
			</div>
		</div>
	);
};
