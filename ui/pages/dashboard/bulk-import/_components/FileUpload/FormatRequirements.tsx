// @ts-nocheck
import { Button } from '@/components/ui';
import { Download } from 'lucide-react';
import { __ } from '@/i18n';

function FormatRequirements() {
	const handleDownloadSample = (format) => {
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
		<div className="bg-surface border border-stroke rounded-lg p-5">
			<h3 className="text-base font-semibold text-heading mb-4">
				{__('File Format Requirements')}
			</h3>
			<div className="space-y-4">
				<div>
					<h4 className="font-medium text-sm text-heading mb-2">
						{__('Required Fields')}
					</h4>
					<ul className="text-sm text-text-muted space-y-1">
						<li>
							•{' '}
							<code className="px-1.5 py-0.5 bg-surface-alt rounded text-xs">
								url
							</code>{' '}
							{__(
								' - The destination URL (e.g. destinationUrl in JSON/XML)'
							)}
						</li>
					</ul>
				</div>
				<div>
					<h4 className="font-medium text-sm text-heading mb-2">
						{__('Optional Fields')}
					</h4>
					<ul className="text-sm text-text-muted space-y-1">
						<li>
							•{' '}
							<code className="px-1.5 py-0.5 bg-surface-alt rounded text-xs">
								alias
							</code>{' '}
							{__(' - Custom alias')}
						</li>
						<li>
							•{' '}
							<code className="px-1.5 py-0.5 bg-surface-alt rounded text-xs">
								title
							</code>{' '}
							{__(' - Link title')}
						</li>
						<li>
							•{' '}
							<code className="px-1.5 py-0.5 bg-surface-alt rounded text-xs">
								password
							</code>{' '}
							{__(' - Protection password')}
						</li>
						<li>
							•{' '}
							<code className="px-1.5 py-0.5 bg-surface-alt rounded text-xs">
								expires
							</code>{' '}
							{__(' - Date (YYYY-MM-DD)')}
						</li>
						<li>
							{__(
								'• Additional columns are ignored during import, so PeakURL exports can be imported again later.'
							)}
						</li>
					</ul>
				</div>
			</div>
			<div className="mt-4 flex flex-wrap gap-2">
				<Button
					variant="secondary"
					size="sm"
					onClick={() => handleDownloadSample('csv')}
				>
					<Download className="mr-2 h-4 w-4" />
					CSV
				</Button>
				<Button
					variant="secondary"
					size="sm"
					onClick={() => handleDownloadSample('json')}
				>
					<Download className="mr-2 h-4 w-4" />
					JSON
				</Button>
				<Button
					variant="secondary"
					size="sm"
					onClick={() => handleDownloadSample('xml')}
				>
					<Download className="mr-2 h-4 w-4" />
					XML
				</Button>
			</div>
		</div>
	);
}

export default FormatRequirements;
