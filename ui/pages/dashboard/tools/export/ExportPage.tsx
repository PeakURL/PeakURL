import { useState } from "react";
import {
	Braces,
	Download,
	ExternalLink,
	FileCode2,
	FileSpreadsheet,
	FileText,
	Link2,
} from "lucide-react";

import { API_ROUTES } from "@/api";
import { Button, useNotification } from "@/components";
import { API_SERVER_BASE_URL } from "@/constants";
import { __, sprintf } from "@/i18n";
import { useGetUrlsQuery, useLazyGetUrlsExportQuery } from "@/store/slices/api";
import { downloadLinkExport, formatCount, getErrorMessage } from "@/utils";

import type { ExportCardProps, ExportFormat, ExportOption } from "../types";

interface ExtendedExportCardProps extends ExportCardProps {
	tone?: string;
}

function ExportCard({
	title,
	description,
	formatLabel,
	icon: Icon,
	tone = "accent",
	isLoading,
	isDisabled,
	onExport,
}: ExtendedExportCardProps) {
	return (
		<div className="export-card">
			<div className="export-card-header">
				<div className={`export-card-icon export-card-icon-${tone}`}>
					<Icon className="export-card-icon-glyph" />
				</div>
				<div className="export-card-copy">
					<h3 className="export-card-title">{title}</h3>
					<p className="export-card-summary">{description}</p>
				</div>
			</div>

			<div className="export-card-footer">
				<span className="export-card-format">{formatLabel}</span>
				<Button
					type="button"
					size="sm"
					onClick={onExport}
					disabled={isDisabled || isLoading}
					icon={Download}
				>
					{isLoading ? __("Preparing...") : __("Export")}
				</Button>
			</div>
		</div>
	);
}

function ExportPage() {
	const notification = useNotification();
	const [activeFormat, setActiveFormat] = useState<ExportFormat | "">("");
	const { data: urlsResponse, isLoading: isCountLoading } = useGetUrlsQuery({
		page: 1,
		limit: 1,
	});
	const [triggerExport, { isFetching: isExporting }] =
		useLazyGetUrlsExportQuery();
	const totalLinks = urlsResponse?.data?.meta?.totalItems ?? null;

	const exportOptions: (ExportOption & { tone: string })[] = [
		{
			id: "csv",
			title: __("CSV Export"),
			description: __(
				"Download a spreadsheet-friendly file that can also be imported back into PeakURL later."
			),
			formatLabel: __("Comma-separated values (.csv)"),
			icon: FileSpreadsheet,
			tone: "csv",
		},
		{
			id: "json",
			title: __("JSON Export"),
			description: __(
				"Download a structured snapshot for scripts, integrations, or backups."
			),
			formatLabel: __("JavaScript Object Notation (.json)"),
			icon: Braces,
			tone: "json",
		},
		{
			id: "xml",
			title: __("XML Export"),
			description: __(
				"Download a portable XML feed with the full link dataset and analytics totals."
			),
			formatLabel: __("Extensible Markup Language (.xml)"),
			icon: FileCode2,
			tone: "xml",
		},
	];

	const handleExport = async (format: ExportFormat) => {
		setActiveFormat(format);

		try {
			const response = await triggerExport(undefined).unwrap();
			const links = response?.data?.items || [];

			if (!links.length) {
				notification?.info(
					__("Nothing to export"),
					__("No links are available for export yet.")
				);
				return;
			}

			downloadLinkExport(links, format);
			notification?.success(
				__("Export downloaded"),
				sprintf(
					__("Downloaded %1$s links as %2$s."),
					formatCount(links.length),
					format.toUpperCase()
				)
			);
		} catch (error) {
			notification?.error(
				__("Export failed"),
				getErrorMessage(
					error,
					__("PeakURL could not prepare the export right now.")
				)
			);
		} finally {
			setActiveFormat("");
		}
	};

	return (
		<div className="export-page">
			<div className="export-page-hero">
				<div className="export-page-hero-copy">
					<div className="export-page-hero-badge">
						<Download size={13} />
						<span>{__("Data Export")}</span>
					</div>
					<h1 className="export-page-title">{__("Export")}</h1>
					<p className="export-page-summary">
						{__(
							"Export your short links dataset in CSV, JSON, or XML formats for offline analysis, migration, or programmatic backups."
						)}
					</p>
				</div>
			</div>

			<div className="export-page-intro">
				<div className="export-page-intro-layout">
					<div className="export-page-intro-copy">
						<div className="export-page-intro-heading">
							<div className="export-page-intro-icon-wrapper">
								<Link2 size={16} />
							</div>
							<h2 className="export-page-intro-title">
								{__("Bulk Link Export")}
							</h2>
						</div>
						<p className="export-page-intro-text">
							{__(
								"Each export includes destination URLs, custom aliases, titles, short URLs, total clicks, unique visitors, and creation dates."
							)}
						</p>
						<p className="export-page-intro-text text-text-muted/80">
							{__(
								"Exports follow your current role permissions. Administrators can export all site links, while Editors export links created by their account."
							)}
						</p>
					</div>

					<div className="export-page-intro-count">
						<div className="export-page-intro-count-label">
							{__("Exportable Links")}
						</div>
						<div className="export-page-intro-count-value">
							{isCountLoading || null === totalLinks
								? "..."
								: formatCount(totalLinks)}
						</div>
					</div>
				</div>
			</div>

			<div className="export-page-grid">
				{exportOptions.map((option) => (
					<ExportCard
						key={option.id}
						title={option.title}
						description={option.description}
						formatLabel={option.formatLabel}
						icon={option.icon}
						tone={option.tone}
						isLoading={isExporting && activeFormat === option.id}
						isDisabled={0 === totalLinks}
						onExport={() => handleExport(option.id)}
					/>
				))}
			</div>

			<div className="export-page-api">
				<div className="export-page-api-header">
					<div className="export-page-api-header-main">
						<div className="export-page-api-icon-wrapper">
							<FileText size={16} />
						</div>
						<div>
							<h2 className="export-page-api-title">
								{__("API Export")}
							</h2>
							<p className="export-page-api-copy">
								{__(
									"Use the API export endpoint to back up short links or integrate with automated workflows without opening the dashboard."
								)}
							</p>
						</div>
					</div>
					<a
						href="https://peakurl.org/docs/import-and-export#api-export"
						target="_blank"
						rel="noreferrer"
						className="shrink-0"
					>
						<Button size="sm" icon={ExternalLink}>
							{__("Read Export Guide")}
						</Button>
					</a>
				</div>

				<div className="export-page-api-grid">
					<div className="export-page-api-column">
						<h3 className="export-page-api-heading">
							<span>{__("Example Request")}</span>
							<span className="text-[11px] font-mono lowercase opacity-70">
								cURL / HTTP
							</span>
						</h3>
						<pre className="export-page-api-code-block">
							<code>{`GET ${API_SERVER_BASE_URL}/${API_ROUTES.urls.export}?sortBy=createdAt&sortOrder=desc
Authorization: Bearer YOUR_API_KEY
Accept: application/json`}</code>
						</pre>
					</div>

					<div className="export-page-api-column">
						<h3 className="export-page-api-heading">
							<span>{__("Response")}</span>
							<span className="text-[11px] font-mono lowercase opacity-70">
								application/json
							</span>
						</h3>
						<pre className="export-page-api-code-block">
							<code>{`{
  "success": true,
  "message": "URLs export loaded.",
  "data": {
    "items": [
      {
        "id": "URL_ID",
        "shortUrl": "https://example.com/docs",
        "destinationUrl": "https://docs.example.com",
        "alias": "docs",
        "clicks": 42,
        "uniqueClicks": 31
      }
    ],
    "meta": {
      "totalItems": 1
    }
  }
}`}</code>
						</pre>
					</div>
				</div>
			</div>
		</div>
	);
}

export default ExportPage;
