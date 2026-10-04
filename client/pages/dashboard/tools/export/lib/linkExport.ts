import { downloadBrowserFile } from "@/shared/browser";
import { serializeCsv } from "@/shared/csv";
import { getShortUrl } from "@/shared/links";
import type {
	LinkExportFile,
	LinkExportFormat,
	LinkExportItem,
	LinkExportSourceLink,
} from "../types";

/**
 * Ordered list of headers for CSV exports.
 */
const LINK_EXPORT_HEADERS: Array<keyof LinkExportItem> = [
	"destinationUrl",
	"alias",
	"title",
	"status",
	"password",
	"expiresAt",
	"socialTitle",
	"socialDescription",
	"socialImageUrl",
	"utmSource",
	"utmMedium",
	"utmCampaign",
	"utmTerm",
	"utmContent",
	"shortUrl",
	"clicks",
	"uniqueClicks",
	"createdAt",
];

/**
 * Escape values for safe inclusion in XML documents.
 *
 * @param value - The value to escape.
 * @return The XML-safe string.
 */
function escapeXml(value: unknown): string {
	return String(value ?? "")
		.replace(/&/g, "&amp;")
		.replace(/</g, "&lt;")
		.replace(/>/g, "&gt;")
		.replace(/"/g, "&quot;")
		.replace(/'/g, "&apos;");
}

/**
 * Map link records into the normalized export row shape shared by all formats.
 *
 * @param links - The source link records.
 * @return The formatted export items.
 */
export function formatLinkExportItems(
	links: Array<LinkExportSourceLink> = []
): LinkExportItem[] {
	return links.map((link) => {
		const alias = link.alias || link.shortCode || "";
		const socialImageUrl =
			link.socialPreview?.imageUrl ||
			link.socialPreview?.externalImageUrl ||
			"";

		return {
			destinationUrl: link.destinationUrl || "",
			alias,
			title: link.title || "",
			status: link.status || "active",
			/* Password values are intentionally excluded for security reasons. */
			password: "",
			expiresAt: link.expiresAt || "",
			socialTitle: link.socialPreview?.title || "",
			socialDescription: link.socialPreview?.description || "",
			socialImageUrl,
			utmSource: link.utmSource || "",
			utmMedium: link.utmMedium || "",
			utmCampaign: link.utmCampaign || "",
			utmTerm: link.utmTerm || "",
			utmContent: link.utmContent || "",
			shortUrl: getShortUrl(link),
			clicks: link.clicks ?? 0,
			uniqueClicks: link.uniqueClicks ?? 0,
			createdAt: link.createdAt || "",
		};
	});
}

/**
 * Serialize export rows into CSV, JSON, or XML content.
 *
 * @param format - The target export format.
 * @param items  - The items to serialize.
 * @return The serialized string content.
 */
export function serializeLinkExport(
	format: LinkExportFormat = "csv",
	items: Array<LinkExportItem> = []
): string {
	if (format === "json") {
		return JSON.stringify(items, null, 2);
	}

	if (format === "xml") {
		const itemXml = items
			.map(
				(item) => `  <url>
    <destinationUrl>${escapeXml(item.destinationUrl)}</destinationUrl>
    <alias>${escapeXml(item.alias)}</alias>
    <title>${escapeXml(item.title)}</title>
    <status>${escapeXml(item.status)}</status>
    <password>${escapeXml(item.password)}</password>
    <expiresAt>${escapeXml(item.expiresAt)}</expiresAt>
    <socialTitle>${escapeXml(item.socialTitle)}</socialTitle>
    <socialDescription>${escapeXml(item.socialDescription)}</socialDescription>
    <socialImageUrl>${escapeXml(item.socialImageUrl)}</socialImageUrl>
    <utmSource>${escapeXml(item.utmSource)}</utmSource>
    <utmMedium>${escapeXml(item.utmMedium)}</utmMedium>
    <utmCampaign>${escapeXml(item.utmCampaign)}</utmCampaign>
    <utmTerm>${escapeXml(item.utmTerm)}</utmTerm>
    <utmContent>${escapeXml(item.utmContent)}</utmContent>
    <shortUrl>${escapeXml(item.shortUrl)}</shortUrl>
    <clicks>${escapeXml(item.clicks)}</clicks>
    <uniqueClicks>${escapeXml(item.uniqueClicks)}</uniqueClicks>
    <createdAt>${escapeXml(item.createdAt)}</createdAt>
  </url>`
			)
			.join("\n");

		return `<urls>\n${itemXml}\n</urls>`;
	}

	const rows = items.map((item) =>
		LINK_EXPORT_HEADERS.map((header) => item[header] ?? "")
	);

	return serializeCsv(LINK_EXPORT_HEADERS, rows);
}

/**
 * Return the default filename and MIME type for a link export format.
 *
 * @param format - The export format.
 * @return The file configuration object.
 */
export function createLinkExportFile(
	format: LinkExportFormat = "csv"
): LinkExportFile {
	switch (format) {
		case "json":
			return {
				filename: "peakurl-links.json",
				type: "application/json;charset=utf-8;",
			};
		case "xml":
			return {
				filename: "peakurl-links.xml",
				type: "application/xml;charset=utf-8;",
			};
		default:
			return {
				filename: "peakurl-links.csv",
				type: "text/csv;charset=utf-8;",
			};
	}
}

/**
 * Download a browser export file and return the normalized exported rows.
 *
 * @param links  - The source link records.
 * @param format - The target export format.
 * @return The normalized exported items.
 */
export function downloadLinkExport(
	links: Array<LinkExportSourceLink> = [],
	format: LinkExportFormat = "csv"
): LinkExportItem[] {
	const items = formatLinkExportItems(links);
	const content = serializeLinkExport(format, items);
	const file = createLinkExportFile(format);

	/* Trigger a browser download for the serialized content. */
	downloadBrowserFile(content, file.filename, file.type);

	return items;
}
