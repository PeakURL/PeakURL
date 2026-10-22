import { ExternalLink } from "lucide-react";

import { PageHeader } from "@/components";
import { dashboardPageIcons } from "@/pages/dashboard/navigation";
import { __ } from "@/i18n";

export default function Header() {
	return (
		<PageHeader
			icon={dashboardPageIcons["tools-import"]}
			title={__("Import")}
			description={__(
				"Bulk import short links into PeakURL from CSV, JSON, or XML files, pasted URL lists, or automated API payloads."
			)}
			docsLink={
				<a
					href="https://go.peakurl.org/979434"
					target="_blank"
					rel="noopener noreferrer"
					className="page-header-docs-link"
				>
					{__("Read documentation")}{" "}
					<ExternalLink size={14} aria-hidden="true" />
				</a>
			}
		/>
	);
}
