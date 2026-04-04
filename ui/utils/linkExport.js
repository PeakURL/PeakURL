import { serializeCsv } from './csv';
import { buildShortUrl } from './linkHelpers';

function escapeXml(value) {
	return String(value ?? '')
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&apos;');
}

export function buildLinkExportItems(links = []) {
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

export function serializeLinkExport(format = 'csv', items = []) {
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

	const headers = [
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
	const rows = items.map((item) => headers.map((header) => item[header]));

	return serializeCsv(headers, rows);
}

export function getLinkExportFile(format = 'csv') {
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

export function downloadLinkExport(links = [], format = 'csv') {
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
