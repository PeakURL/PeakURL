import { Heart } from "lucide-react";
import { __ } from "@/i18n";

const PROJECT_START_YEAR = 2025;

export function AboutFooter() {
	const currentYear = new Date().getFullYear();
	const copyrightYears =
		currentYear === PROJECT_START_YEAR
			? String(PROJECT_START_YEAR)
			: `${PROJECT_START_YEAR}–${currentYear}`;

	return (
		<footer className="about-footer">
			<span>© {copyrightYears} PeakURL</span>
			<span className="about-footer-credit">
				<span>{__("Made with")}</span>
				<Heart
					className="about-footer-heart"
					size={14}
					role="img"
					aria-label={__("love")}
				/>
				<span>{__("by")}</span>
				<a
					href="https://go.peakurl.org/author"
					target="_blank"
					rel="noopener noreferrer"
				>
					Abd Ur Rehman
				</a>
			</span>
		</footer>
	);
}
