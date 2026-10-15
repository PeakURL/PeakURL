import { ArrowRight, CheckCircle2 } from "lucide-react";
import { Link } from "react-router";
import { getPeakURLData } from "@/data";
import { __, sprintf } from "@/i18n";
import type { AboutSource } from "./types";

/** Completion status stays separate from the product identity and only offers relevant next steps. */
export function CompletionNotice({ source }: { source: AboutSource }) {
	const { version } = getPeakURLData();
	const messages = {
		install: {
			title: __("Your installation is ready."),
			description: __(
				"Your account is set up. Create a short link to get started, or review your site settings."
			),
		},
		update: {
			title: __("Update complete."),
			description: sprintf(
				__(
					"You're running PeakURL %s. Your links and settings are still in place."
				),
				version || __("Unknown")
			),
		},
		reinstall: {
			title: __("Reinstallation complete."),
			description: __(
				"The application files have been refreshed. Your links, settings, and uploaded files are still in place."
			),
		},
	};
	const message = messages[source];

	return (
		<section
			className="about-completion"
			aria-labelledby="about-completion-title"
		>
			<CheckCircle2
				className="about-completion-icon"
				size={22}
				aria-hidden="true"
			/>
			<div className="about-completion-copy">
				<h2 id="about-completion-title">{message.title}</h2>
				<p>{message.description}</p>
				<div className="about-completion-actions">
					{source === "install" ? (
						<>
							<Link
								to="/dashboard/links"
								className="about-primary-link"
							>
								{__("Create your first short link")}
								<ArrowRight size={14} aria-hidden="true" />
							</Link>
							<Link
								to="/dashboard/settings/general"
								className="about-text-link"
							>
								{__("Review general settings")}
							</Link>
						</>
					) : (
						<>
							<Link
								to="/dashboard"
								className="about-primary-link"
							>
								{__("Go to Dashboard")}
								<ArrowRight size={14} aria-hidden="true" />
							</Link>
							<Link
								to="/dashboard/tools/system-status"
								className="about-text-link"
							>
								{__("System Status")}
							</Link>
						</>
					)}
				</div>
			</div>
		</section>
	);
}
