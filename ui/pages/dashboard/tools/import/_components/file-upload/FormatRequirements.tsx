import { Button } from '@/components';
import { Download } from 'lucide-react';
import { __ } from '@/i18n';
import type { SampleFormat } from './types';

function FormatRequirements() {
	const handleDownloadSample = (format: SampleFormat) => {
		let content = '';
		let filename = '';
		let type = '';

		if (format === 'csv') {
			content =
				'url,alias,title,password,expires\nhttps://example.com,ex1,' +
				__('Example page') +
				',,2025-12-31';
			filename = 'sample.csv';
			type = 'text/csv';
		} else if (format === 'json') {
			content = JSON.stringify(
				[
					{
						url: 'https://example.com',
						alias: 'ex1',
						title: __('Example page'),
						password: '',
						expires: '2025-12-31',
					},
				],
				null,
				2
			);
			filename = 'sample.json';
			type = 'application/json';
		} else if (format === 'xml') {
			content = `<urls>
  <url>
    <destinationUrl>https://example.com</destinationUrl>
    <alias>ex1</alias>
    <title>${__('Example page')}</title>
    <password></password>
    <expiresAt>2025-12-31</expiresAt>
  </url>
</urls>`;
			filename = 'sample.xml';
			type = 'text/xml';
		}

		const blob = new Blob([content], { type });
		const url = window.URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = filename;
		a.click();
	};

	return (
		<div className="import-panel import-format-panel">
			<h3 className="import-panel-title import-format-title">
				{__('File Format Requirements')}
			</h3>
			<div className="import-format-sections">
				<div className="import-format-section">
					<h4 className="import-format-section-title">
						{__('Required Fields')}
					</h4>
					<ul className="import-format-list">
						<li className="import-format-item">
							•{' '}
							<code className="import-inline-code">
								url
							</code>{' '}
							{__(
								' - The destination URL (e.g. destinationUrl in JSON/XML)'
							)}
						</li>
					</ul>
				</div>
				<div className="import-format-section">
					<h4 className="import-format-section-title">
						{__('Optional Fields')}
					</h4>
					<ul className="import-format-list">
						<li className="import-format-item">
							•{' '}
							<code className="import-inline-code">
								alias
							</code>{' '}
							{__(' - Custom alias')}
						</li>
						<li className="import-format-item">
							•{' '}
							<code className="import-inline-code">
								title
							</code>{' '}
							{__(' - Link title')}
						</li>
						<li className="import-format-item">
							•{' '}
							<code className="import-inline-code">
								password
							</code>{' '}
							{__(' - Protection password')}
						</li>
						<li className="import-format-item">
							•{' '}
							<code className="import-inline-code">
								expires
							</code>{' '}
							{__(' - Date (YYYY-MM-DD)')}
						</li>
						<li className="import-format-item">
							{__(
								'• Additional columns are ignored during import, so PeakURL exports can be imported again later.'
							)}
						</li>
					</ul>
				</div>
			</div>
			<div className="import-format-actions">
				<Button
					variant="secondary"
					size="sm"
					onClick={() => handleDownloadSample('csv')}
				>
					<Download className="import-format-button-icon" />
					CSV
				</Button>
				<Button
					variant="secondary"
					size="sm"
					onClick={() => handleDownloadSample('json')}
				>
					<Download className="import-format-button-icon" />
					JSON
				</Button>
				<Button
					variant="secondary"
					size="sm"
					onClick={() => handleDownloadSample('xml')}
				>
					<Download className="import-format-button-icon" />
					XML
				</Button>
			</div>
		</div>
	);
}

export default FormatRequirements;
