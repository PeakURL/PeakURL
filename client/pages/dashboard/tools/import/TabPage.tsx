import { useState } from "react";
import { useLocation } from "react-router";

import { __ } from "@/i18n";

import { ApiImport, PasteImport, FileUpload } from "./components";
import type { ImportStatus, SampleRow } from "./components/types";

function TabPage() {
	const location = useLocation();
	const activeTab =
		location.pathname.split("/").filter(Boolean).pop() || "file";

	const [importStatus, setImportStatus] = useState<ImportStatus>("idle");

	const sampleData: SampleRow[] = [
		{
			destinationUrl: "https://example.com/page1",
			alias: "page1",
			title: __("Product launch"),
			status: "active",
			utmSource: "newsletter",
		},
		{
			destinationUrl: "https://example.com/page2",
			alias: "page2",
			title: __("Help docs"),
			status: "active",
			utmSource: "docs",
		},
		{
			destinationUrl: "https://example.com/page3",
			alias: "page3",
			title: __("Newsletter"),
			status: "inactive",
			utmSource: "twitter",
		},
	];

	return (
		<div className="import-page">
			{activeTab === "file" && (
				<FileUpload
					importStatus={importStatus}
					setImportStatus={setImportStatus}
					sampleData={sampleData}
				/>
			)}

			{activeTab === "api" && <ApiImport />}

			{activeTab === "paste" && <PasteImport />}
		</div>
	);
}

export default TabPage;
