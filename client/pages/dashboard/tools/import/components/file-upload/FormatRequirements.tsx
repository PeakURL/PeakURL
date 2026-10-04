import { Download } from "lucide-react";

import { Button } from "@/components";
import { __ } from "@/i18n";
import { downloadBrowserFile } from "@/shared/browser";

import type { SampleFormat } from "./types";

function FormatRequirements() {
	const handleDownloadSample = (format: SampleFormat) => {
		let content = "";
		let filename = "";
		let type = "";

		if (format === "csv") {
			content = `destinationUrl,alias,title,status,password,expiresAt,socialTitle,socialDescription,socialImageUrl,utmSource,utmMedium,utmCampaign,utmTerm,utmContent\nhttps://example.com,ex1,${__(
				"Example page"
			)},active,,2026-12-31,${__("Custom Social Title")},${__(
				"Custom Social Description"
			)},https://example.com/preview.png,newsletter,email,summer_sale,discount,banner`;
			filename = "sample.csv";
			type = "text/csv";
		} else if (format === "json") {
			content = JSON.stringify(
				[
					{
						destinationUrl: "https://example.com",
						alias: "ex1",
						title: __("Example page"),
						status: "active",
						password: "",
						expiresAt: "2026-12-31",
						socialTitle: __("Custom Social Title"),
						socialDescription: __("Custom Social Description"),
						socialImageUrl: "https://example.com/preview.png",
						utmSource: "newsletter",
						utmMedium: "email",
						utmCampaign: "summer_sale",
						utmTerm: "discount",
						utmContent: "banner",
					},
				],
				null,
				2
			);
			filename = "sample.json";
			type = "application/json";
		} else if (format === "xml") {
			content = `<urls>
  <url>
    <destinationUrl>https://example.com</destinationUrl>
    <alias>ex1</alias>
    <title>${__("Example page")}</title>
    <status>active</status>
    <password></password>
    <expiresAt>2026-12-31</expiresAt>
    <socialTitle>${__("Custom Social Title")}</socialTitle>
    <socialDescription>${__("Custom Social Description")}</socialDescription>
    <socialImageUrl>https://example.com/preview.png</socialImageUrl>
    <utmSource>newsletter</utmSource>
    <utmMedium>email</utmMedium>
    <utmCampaign>summer_sale</utmCampaign>
    <utmTerm>discount</utmTerm>
    <utmContent>banner</utmContent>
  </url>
</urls>`;
			filename = "sample.xml";
			type = "text/xml";
		}

		downloadBrowserFile(content, filename, type);
	};

	return (
		<div className="import-panel import-format-panel">
			<h3 className="import-panel-title import-format-title">
				{__("File Format Requirements")}
			</h3>
			<div className="import-format-sections">
				<div className="import-format-section">
					<h4 className="import-format-section-title">
						{__("Required Fields")}
					</h4>
					<ul className="import-format-list">
						<li className="import-format-item">
							•{" "}
							<code className="import-inline-code">
								destinationUrl
							</code>{" "}
							{__(" - Destination URL")}
						</li>
					</ul>
				</div>
				<div className="import-format-section">
					<h4 className="import-format-section-title">
						{__("Optional Fields")}
					</h4>
					<ul className="import-format-list">
						<li className="import-format-item">
							• <code className="import-inline-code">alias</code>{" "}
							{__(" - Custom alias")}
						</li>
						<li className="import-format-item">
							• <code className="import-inline-code">title</code>{" "}
							{__(" - Link title")}
						</li>
						<li className="import-format-item">
							• <code className="import-inline-code">status</code>{" "}
							{__(
								" - Link status (active, inactive, paused, archived)"
							)}
						</li>
						<li className="import-format-item">
							•{" "}
							<code className="import-inline-code">password</code>{" "}
							{__(" - Protection password")}
						</li>
						<li className="import-format-item">
							•{" "}
							<code className="import-inline-code">
								expiresAt
							</code>{" "}
							{__(" - Expiration date (YYYY-MM-DD)")}
						</li>
						<li className="import-format-item">
							•{" "}
							<code className="import-inline-code">
								socialTitle
							</code>
							,{" "}
							<code className="import-inline-code">
								socialDescription
							</code>
							,{" "}
							<code className="import-inline-code">
								socialImageUrl
							</code>{" "}
							{__(" - Social preview meta tags")}
						</li>
						<li className="import-format-item">
							•{" "}
							<code className="import-inline-code">
								utmSource
							</code>
							,{" "}
							<code className="import-inline-code">
								utmMedium
							</code>
							,{" "}
							<code className="import-inline-code">
								utmCampaign
							</code>
							,{" "}
							<code className="import-inline-code">utmTerm</code>,{" "}
							<code className="import-inline-code">
								utmContent
							</code>{" "}
							{__(" - UTM campaign tracking parameters")}
						</li>
					</ul>
				</div>
			</div>
			<div className="import-format-actions">
				<Button
					variant="secondary"
					size="sm"
					onClick={() => handleDownloadSample("csv")}
				>
					<Download className="import-format-button-icon" />
					CSV
				</Button>
				<Button
					variant="secondary"
					size="sm"
					onClick={() => handleDownloadSample("json")}
				>
					<Download className="import-format-button-icon" />
					JSON
				</Button>
				<Button
					variant="secondary"
					size="sm"
					onClick={() => handleDownloadSample("xml")}
				>
					<Download className="import-format-button-icon" />
					XML
				</Button>
			</div>
		</div>
	);
}

export default FormatRequirements;
