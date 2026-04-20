import { useState } from 'react';
import {
	Braces,
	Download,
	ExternalLink,
	FileCode2,
	FileSpreadsheet,
	Link2,
} from 'lucide-react';
import { Button, useNotification } from '@/components';
import { API_SERVER_BASE_URL } from '@/constants';
import { __, sprintf } from '@/i18n';
import {
	useGetUrlsQuery,
	useLazyGetUrlsExportQuery,
} from '@/store/slices/api';
import { downloadLinkExport, formatCount, getErrorMessage } from '@/utils';
import type {
	ExportCardProps,
	ExportFormat,
	ExportOption,
} from '../types';

function ExportCard({
	title,
	description,
	formatLabel,
	icon: Icon,
	isLoading,
	isDisabled,
	onExport,
}: ExportCardProps) {
	return (
		<div className="export-card">
			<div className="export-card-header">
				<div className="export-card-icon">
					<Icon size={20} />
				</div>
				<div className="export-card-copy">
					<h2 className="export-card-title">
						{title}
					</h2>
					<p className="export-card-summary">
						{description}
					</p>
				</div>
			</div>

			<div className="export-card-footer">
				<span className="export-card-format">
					{formatLabel}
				</span>
				<Button
					type="button"
					size="sm"
					onClick={onExport}
					disabled={isDisabled || isLoading}
				>
					<Download size={16} />
					{isLoading ? __('Preparing...') : __('Export')}
				</Button>
			</div>
		</div>
	);
}

function ExportPage() {
	const notification = useNotification();
	const [activeFormat, setActiveFormat] = useState<ExportFormat | ''>('');
	const { data: urlsResponse, isLoading: isCountLoading } = useGetUrlsQuery({
		page: 1,
		limit: 1,
	});
	const [triggerExport, { isFetching: isExporting }] =
		useLazyGetUrlsExportQuery();
	const totalLinks = urlsResponse?.data?.meta?.totalItems ?? null;

	const exportOptions: ExportOption[] = [
		{
			id: 'csv',
			title: __('CSV Export'),
			description: __(
				'Download a spreadsheet-friendly file that can also be imported back into PeakURL later.'
			),
			formatLabel: __('Comma-separated values'),
			icon: FileSpreadsheet,
		},
		{
			id: 'json',
			title: __('JSON Export'),
			description: __(
				'Download a structured snapshot for scripts, integrations, or backups.'
			),
			formatLabel: __('JavaScript Object Notation'),
			icon: Braces,
		},
		{
			id: 'xml',
			title: __('XML Export'),
			description: __(
				'Download a portable XML feed with the full link dataset and analytics totals.'
			),
			formatLabel: __('Extensible Markup Language'),
			icon: FileCode2,
		},
	];

	const handleExport = async (format: ExportFormat) => {
		setActiveFormat(format);

		try {
			const response = await triggerExport(undefined).unwrap();
			const links = response?.data?.items || [];

			if (!links.length) {
				notification?.info(
					__('Nothing to export'),
					__('No links are available for export yet.')
				);
				return;
			}

			downloadLinkExport(links, format);
			notification?.success(
				__('Export downloaded'),
				sprintf(
					__('Downloaded %1$s links as %2$s.'),
					formatCount(links.length),
					format.toUpperCase()
				)
			);
		} catch (error) {
			notification?.error(
				__('Export failed'),
				getErrorMessage(
					error,
					__('PeakURL could not prepare the export right now.')
				)
			);
		} finally {
			setActiveFormat('');
		}
	};

	return (
		<div className="export-page">
			<div className="export-page-header">
				<h1 className="export-page-title">
					{__('Export')}
				</h1>
				<p className="export-page-summary">
					{__(
						'Export all links you can access from one place in CSV, JSON, or XML.'
					)}
				</p>
			</div>

			<div className="export-page-intro">
				<div className="export-page-intro-layout">
					<div className="export-page-intro-copy">
						<div className="export-page-intro-heading">
							<Link2
								size={18}
								className="export-page-intro-icon"
							/>
							<h2 className="export-page-intro-title">
								{__('Bulk Link Export')}
							</h2>
						</div>
						<p className="export-page-intro-text">
							{__(
								'Each export includes destination URLs, aliases, titles, short URLs, click totals, unique visitor totals, and created dates.'
							)}
						</p>
						<p className="export-page-intro-text">
							{__(
								'Exports follow your current permissions. Admins can export all site links, while editors can export only their own links.'
							)}
						</p>
					</div>

					<div className="export-page-intro-count">
						<div className="export-page-intro-count-label">
							{__('Exportable Links')}
						</div>
						<div className="export-page-intro-count-value">
							{isCountLoading || null === totalLinks
								? '...'
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
						isLoading={isExporting && activeFormat === option.id}
						isDisabled={0 === totalLinks}
						onExport={() => handleExport(option.id)}
					/>
				))}
			</div>

			<div className="export-page-api">
				<h2 className="export-page-api-title">
					{__('API Export')}
				</h2>
				<p className="export-page-api-copy">
					{__(
						'Use the export route when you want to back up links or feed another tool without opening the dashboard.'
					)}
				</p>

				<div className="export-page-api-grid">
					<div className="export-page-api-column">
						<h3 className="export-page-api-heading">
							{__('Example Request')}
						</h3>
						<pre className="export-page-api-code-block">
							<code>{`GET ${API_SERVER_BASE_URL}/urls/export?sortBy=createdAt&sortOrder=desc
Authorization: Bearer YOUR_API_KEY
Accept: application/json`}</code>
						</pre>
					</div>

					<div className="export-page-api-column">
						<h3 className="export-page-api-heading">
							{__('Response')}
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

				<div className="export-page-api-actions">
					<a
						href="https://peakurl.org/docs/import-and-export#api-export"
						target="_blank"
						rel="noreferrer"
					>
						<Button size="sm">
							<ExternalLink className="export-page-api-button-icon" />
							{__('Read Export Guide')}
						</Button>
					</a>
				</div>
			</div>
		</div>
	);
}

export default ExportPage;
