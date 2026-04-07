import { serializeCsv } from './csv';
import { buildShortUrl } from './linkHelpers';
import type {
	LinkExportFile,
	LinkExportFormat,
	LinkExportItem,
	LinkExportSourceLink,
} from './types';

const LINK_EXPORT_HEADERS: Array<keyof LinkExportItem> = [
	'url',
	'alias',
	'title',
	'password',
	'expires',
	'short_url',
	'clicks',
	'unique_clicks',
	'created_at',
];

function escapeXml(value: unknown): string {
	return String(value ?? '')
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&apos;');
}

/**
 * Maps link records into the normalized export row shape shared by all formats.
 */
export function buildLinkExportItems(
	links: Array<LinkExportSourceLink> = []
): LinkExportItem[] {
	return links.map((link) => {
		const alias = link.alias || link.shortCode || '';

		return {
			url: link.destinationUrl || '',
			alias,
			title: link.title || '',
			password: '',
			expires: link.expiresAt || '',
			short_url: buildShortUrl(link),
			clicks: link.clicks ?? '',
			unique_clicks: link.uniqueClicks ?? '',
			created_at: link.createdAt || '',
		};
	});
}

/**
 * Serializes export rows into CSV, JSON, or XML content.
 */
export function serializeLinkExport(
	format: LinkExportFormat = 'csv',
	items: Array<LinkExportItem> = []
): string {
	if ('json' === format) {
		return JSON.stringify(items, null, 2);
	}

	if ('xml' === format) {
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
			.join('\n');

		return `<urls>\n${itemXml}\n</urls>`;
	}

	const rows = items.map((item) =>
		LINK_EXPORT_HEADERS.map((header) => item[header])
	);

	return serializeCsv(LINK_EXPORT_HEADERS, rows);
}

/**
 * Returns the default filename and MIME type for a link export format.
 */
export function getLinkExportFile(
	format: LinkExportFormat = 'csv'
): LinkExportFile {
	switch (format) {
		case 'json':
			return {
				filename: 'peakurl-links.json',
				type: 'application/json;charset=utf-8;',
			};
		case 'xml':
			return {
				filename: 'peakurl-links.xml',
				type: 'application/xml;charset=utf-8;',
			};
		default:
			return {
				filename: 'peakurl-links.csv',
				type: 'text/csv;charset=utf-8;',
			};
	}
}

/**
 * Downloads a browser export file and returns the normalized exported rows.
 */
export function downloadLinkExport(
	links: Array<LinkExportSourceLink> = [],
	format: LinkExportFormat = 'csv'
): LinkExportItem[] {
	const items = buildLinkExportItems(links);
	const content = serializeLinkExport(format, items);
	const file = getLinkExportFile(format);
	const blob = new Blob([content], { type: file.type });
	const blobUrl = window.URL.createObjectURL(blob);
	const link = document.createElement('a');

	link.setAttribute('href', blobUrl);
	link.setAttribute('download', file.filename);
	document.body.appendChild(link);
	link.click();
	document.body.removeChild(link);
	window.URL.revokeObjectURL(blobUrl);

	return items;
}
