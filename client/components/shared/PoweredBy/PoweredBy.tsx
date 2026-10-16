import { peakurlLogoUrl } from "@/assets/logo";
import { PEAKURL_NAME } from "@/constants";
import { __ } from "@/i18n";

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
				<img src={peakurlLogoUrl} alt="" width="22" height="22" />
				{PEAKURL_NAME}
			</span>
		</a>
	);
}
