import { BookOpen, Code2, FileText, MessageSquare } from "lucide-react";
import { __ } from "@/i18n";
import { Integrations } from "./Integrations";

export function Resources() {
	const links = [
		{
			label: __("Documentation"),
			description: __("Installation and everyday use"),
			href: "https://go.peakurl.org/docs",
			icon: BookOpen,
		},
		{
			label: __("Source code"),
			description: __("Explore the project on GitHub"),
			href: "https://go.peakurl.org/repo",
			icon: Code2,
		},
		{
			label: __("Release history"),
			description: __("Changes across every version"),
			href: "https://go.peakurl.org/release-notes",
			icon: FileText,
		},
		{
			label: __("Report an issue"),
			description: __("Bugs, questions, and suggestions"),
			href: "https://go.peakurl.org/report-issue",
			icon: MessageSquare,
		},
	];

	return (
		<aside className="about-resources" aria-label={__("Project resources")}>
			<section>
				<h2>{__("Project resources")}</h2>
				<ul className="about-resource-links">
					{links.map(({ label, description, href, icon: Icon }) => (
						<li key={href}>
							<a
								href={href}
								target="_blank"
								rel="noopener noreferrer"
							>
								<Icon size={18} aria-hidden="true" />
								<span>
									<strong>{label}</strong>
									<span>{description}</span>
								</span>
							</a>
						</li>
					))}
				</ul>
			</section>
			<Integrations />
		</aside>
	);
}
