import { PEAKURL_NAME } from "@/constants";
import { __ } from "@/i18n";
import { LogoIcon, Wordmark } from "../Logo";

interface PoweredByProps {
	href?: string;
	inverse?: boolean;
}

/** Compact attribution badge shared by auth and recovery pages. */
export function PoweredBy({
	inverse = false,
	href = "https://peakurl.org?utm_source=peakurl_status&utm_medium=public_page&utm_campaign=powered_by",
}: PoweredByProps) {
	return (
		<a
			className={`powered-by${inverse ? " powered-by-inverse" : ""}`}
			href={href}
			target="_blank"
			rel="noopener noreferrer"
		>
			<span>{__("Powered by")}</span>
			<span className="powered-by-brand">
				<LogoIcon size={20} className="powered-by-mark" />
				<Wordmark className="powered-by-wordmark" />
				<span className="sr-only">{PEAKURL_NAME}</span>
			</span>
		</a>
	);
}
