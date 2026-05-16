import { serializeCsv } from "./csv";
import { downloadBrowserFile } from "./dom";
import { getShortUrl } from "./linkHelpers";
import type {
	LinkExportFile,
	LinkExportFormat,
	LinkExportItem,
	LinkExportSourceLink,
} from "./types";

/**
 * Ordered list of headers for CSV exports.
 */
const LINK_EXPORT_HEADERS: Array<keyof LinkExportItem> = [
	"url",
	"alias",
	"title",
	"password",
	"expires",
	"short_url",
	"clicks",
	"unique_clicks",
	"created_at",
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

		return {
			url: link.destinationUrl || "",
			alias,
			title: link.title || "",
			/* Password values are intentionally excluded for security reasons. */
			password: "",
			expires: link.expiresAt || "",
			short_url: getShortUrl(link),
			clicks: link.clicks ?? 0,
			unique_clicks: link.uniqueClicks ?? 0,
			created_at: link.createdAt || "",
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
    <destinationUrl>${escapeXml(item.url)}</destinationUrl>
    <alias>${escapeXml(item.alias)}</alias>
    <title>${escapeXml(item.title)}</title>
    <password>${escapeXml(item.password)}</password>
    <expiresAt>${escapeXml(item.expires)}</expiresAt>
    <shortUrl>${escapeXml(item.short_url)}</shortUrl>
    <clicks>${escapeXml(item.clicks)}</clicks>
    <uniqueClicks>${escapeXml(item.unique_clicks)}</uniqueClicks>
    <createdAt>${escapeXml(item.created_at)}</createdAt>
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
