import { useState } from 'react';
import {
	Braces,
	Download,
	FileCode2,
	FileSpreadsheet,
	Link2,
} from 'lucide-react';
import { Button, useNotification } from '@/components';
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
		</div>
	);
}

export default ExportPage;
