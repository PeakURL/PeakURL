import { ExternalLink, Upload } from "lucide-react";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";

const Header = () => {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";

	return (
		<div className="import-layout-header">
			<div className="import-layout-header-copy">
				<div className="import-layout-header-badge">
					<Upload size={13} />
					<span>{__("Data Import")}</span>
				</div>
				<h1 className="import-layout-title">{__("Import")}</h1>
				<p className="import-layout-copy">
					{__(
						"Bulk import short links into PeakURL from CSV, JSON, or XML files, pasted URL lists, or automated API payloads."
					)}
				</p>
				<div className="mt-2">
					<a
						href="https://go.peakurl.org/979434"
						target="_blank"
						rel="noopener noreferrer"
						dir={direction}
						className="import-layout-docs-link"
					>
						{__("Read documentation")}
						<ExternalLink size={13} className="shrink-0" />
					</a>
				</div>
			</div>
		</div>
	);
};

export default Header;
