import { ArrowUpRight } from "lucide-react";
import { Logo } from "@/components";
import { getPeakURLData } from "@/data";
import { __ } from "@/i18n";

export function AboutHeader() {
	const { version } = getPeakURLData();
	return (
		<header className="about-header">
			<div className="about-identity">
				<Logo size="xl" showName={false} />
				<div className="about-identity-copy">
					<h1>PeakURL</h1>
					<p>{__("Shorten, track, and own every link.")}</p>
					<span className="about-version">
						{__("Version")} <bdi>{version}</bdi>
					</span>
				</div>
				<a
					href="https://peakurl.org"
					target="_blank"
					rel="noopener noreferrer"
					className="about-website"
				>
					peakurl.org <ArrowUpRight size={15} aria-hidden="true" />
				</a>
			</div>
		</header>
	);
}
