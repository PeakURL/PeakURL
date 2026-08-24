import { useState } from "react";
import {
	CircleAlert,
	CircleCheckBig,
	Download,
	LoaderCircle,
} from "lucide-react";

import { Button, useNotification } from "@/components";
import { __, sprintf } from "@/i18n";
import { cn, downloadBrowserFile, serializeCsv } from "@/utils";

import type { ImportDetailsProps } from "../types";

function ImportDetails({ results }: ImportDetailsProps) {
	const notification = useNotification();
	const [isExporting, setIsExporting] = useState(false);

	const successCount = results.filter((r) => r.status === "success").length;
	const errorCount = results.filter((r) => r.status === "error").length;

	const handleExport = () => {
		if (results.length === 0 || isExporting) {
			return;
		}

		setIsExporting(true);
		try {
			const headers = [
				__("Original URL"),
				__("Alias"),
				__("Status"),
				__("Short URL"),
				__("Error"),
			];

			const rows = results.map((result) => [
				result.url || "",
				result.alias || "",
				result.status || "",
				result.shortUrl || "",
				result.error || "",
			]);

			const content = serializeCsv(headers, rows);
			const today = new Date().toISOString().slice(0, 10);
			const filename = `peakurl-import-results-${today}.csv`;

			downloadBrowserFile(content, filename, "text/csv;charset=utf-8;");

			notification.success(
				__("Results exported"),
				__("Import results have been downloaded successfully.")
			);
		} catch {
			notification.error(
				__("Export failed"),
				__("Unable to export import results right now.")
			);
		} finally {
			setIsExporting(false);
		}
	};

	return (
		<div className="import-panel import-results-panel">
			<h3 className="import-panel-title import-results-title">
				{__("Import Results")}
			</h3>
			<div className="import-results-list">
				{results.map((result, index) => (
					<div
						key={index}
						className={cn(
							"import-results-item",
							result.status === "success"
								? "import-results-item-success"
								: "import-results-item-error"
						)}
					>
						{result.status === "success" ? (
							<CircleCheckBig className="import-results-item-icon import-results-item-icon-success" />
						) : (
							<CircleAlert className="import-results-item-icon import-results-item-icon-error" />
						)}
						<div className="import-results-item-body">
							<div className="import-results-item-url">
								<bdi>{result.url}</bdi>
							</div>
							{result.status === "success" ? (
								<div className="import-results-item-short-url">
									<bdi>{result.shortUrl}</bdi>
								</div>
							) : (
								<div className="import-results-item-error-text">
									{result.error}
								</div>
							)}
						</div>
					</div>
				))}
			</div>
			<div className="import-results-footer">
				<span className="import-results-footer-summary">
					{sprintf(
						/* translators: 1: success count, 2: error count */
						__("%1$s successful, %2$s failed"),
						[String(successCount), String(errorCount)]
					)}
				</span>
				<Button
					variant="ghost"
					size="sm"
					onClick={handleExport}
					disabled={results.length === 0 || isExporting}
				>
					{isExporting ? (
						<LoaderCircle className="import-results-footer-icon animate-spin" />
					) : (
						<Download className="import-results-footer-icon" />
					)}
					{__("Export Results")}
				</Button>
			</div>
		</div>
	);
}

export default ImportDetails;
