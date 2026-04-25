import { Button } from "@/components";
import { CircleAlert, CircleCheckBig, Download } from "lucide-react";
import { __, sprintf } from "@/i18n";
import { cn } from "@/utils";
import type { ImportDetailsProps } from "../types";

function ImportDetails({ results }: ImportDetailsProps) {
	const successCount = results.filter((r) => r.status === "success").length;
	const errorCount = results.filter((r) => r.status === "error").length;

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
				<Button variant="ghost" size="sm">
					<Download className="import-results-footer-icon" />
					{__("Export Results")}
				</Button>
			</div>
		</div>
	);
}

export default ImportDetails;
