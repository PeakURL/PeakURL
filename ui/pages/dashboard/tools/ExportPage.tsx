import { useState } from 'react';
import {
	Braces,
	Download,
	FileCode2,
	FileSpreadsheet,
	Link2,
} from 'lucide-react';
import { useNotification } from '@/components';
import { Button } from '@/components/ui';
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
} from './types';

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
		<div className="flex h-full flex-col rounded-xl border border-stroke bg-surface p-5">
			<div className="mb-4 flex flex-1 items-start gap-3">
				<div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-surface-alt text-accent">
					<Icon size={20} />
				</div>
				<div className="min-w-0 space-y-1">
					<h2 className="text-lg font-semibold text-heading">
						{title}
					</h2>
					<p className="text-sm leading-6 text-text-muted">
						{description}
					</p>
				</div>
			</div>

			<div className="mt-auto flex items-center justify-between gap-3 border-t border-stroke pt-4">
				<span className="text-sm font-medium text-text-muted">
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
		<div className="space-y-5 pb-8">
			<div className="space-y-1">
				<h1 className="text-2xl font-bold text-heading">
					{__('Export')}
				</h1>
				<p className="text-sm text-text-muted">
					{__(
						'Export all links you can access from one place in CSV, JSON, or XML.'
					)}
				</p>
			</div>

			<div className="rounded-xl border border-stroke bg-surface p-6">
				<div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
					<div className="max-w-2xl space-y-2">
						<div className="flex items-center gap-2 text-heading">
							<Link2 size={18} className="text-accent" />
							<h2 className="text-lg font-semibold">
								{__('Bulk Link Export')}
							</h2>
						</div>
						<p className="text-sm leading-6 text-text-muted">
							{__(
								'Each export includes destination URLs, aliases, titles, short URLs, click totals, unique visitor totals, and created dates.'
							)}
						</p>
						<p className="text-sm leading-6 text-text-muted">
							{__(
								'Exports follow your current permissions. Admins can export all site links, while editors can export only their own links.'
							)}
						</p>
					</div>

					<div className="text-page-end rounded-lg border border-stroke bg-surface-alt px-4 py-3">
						<div className="text-xs font-semibold uppercase tracking-[0.2em] text-text-muted">
							{__('Exportable Links')}
						</div>
						<div className="mt-1 text-2xl font-bold text-heading">
							{isCountLoading || null === totalLinks
								? '...'
								: formatCount(totalLinks)}
						</div>
					</div>
				</div>
			</div>

			<div className="grid gap-4 lg:grid-cols-3">
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
