import { BarChart3, Link2, ShieldCheck, Terminal } from "lucide-react";
import { __ } from "@/i18n";

export function Overview() {
	const capabilities = [
		{
			icon: Link2,
			title: __("Make links your own"),
			description: __(
				"Choose short aliases, create QR codes, and set passwords or expiration dates."
			),
		},
		{
			icon: BarChart3,
			title: __("See where clicks come from"),
			description: __(
				"Review traffic, referrers, devices, and locations without sending your analytics to another service."
			),
		},
		{
			icon: ShieldCheck,
			title: __("Control who has access"),
			description: __(
				"Manage user permissions, protect accounts with two-factor authentication, and review active sessions."
			),
		},
		{
			icon: Terminal,
			title: __("Work with the tools you use"),
			description: __(
				"Publish through the REST API, connect webhooks, or use an official integration."
			),
		},
	];

	return (
		<section className="about-overview">
			<h2>{__("About PeakURL")}</h2>
			<p className="about-section-intro">
				{__(
					"PeakURL brings short links and click analytics into one dashboard. Install it on your own hosting and keep control of the addresses you share and the data behind them."
				)}
			</p>
			<ul className="about-capabilities">
				{capabilities.map(({ icon: Icon, title, description }) => (
					<li key={title}>
						<Icon
							size={20}
							className="about-capability-icon"
							aria-hidden="true"
						/>
						<div>
							<h3>{title}</h3>
							<p>{description}</p>
						</div>
					</li>
				))}
			</ul>
			<div className="about-license">
				<h3>{__("MIT License")}</h3>
				<p>
					{__(
						"PeakURL is released under the MIT License. You can use it for personal or commercial work, read the source, and make changes to suit your installation."
					)}
				</p>
				<a
					href="https://go.peakurl.org/license"
					target="_blank"
					rel="noopener noreferrer"
					className="about-text-link"
				>
					{__("Read the license")}
				</a>
			</div>
		</section>
	);
}
