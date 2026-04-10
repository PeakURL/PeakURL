import { useState } from 'react';
import { Button, TextArea } from '@/components/ui';
import { useBulkCreateUrlMutation } from '@/store/slices/api';
import { buildShortUrl, getErrorMessage } from '@/utils';
import { ImportDetails, ImportSummary } from '../results';
import { Lightbulb, LoaderCircle, WandSparkles } from 'lucide-react';
import { __, sprintf } from '@/i18n';
import type {
	ImportResult,
	ImportStatus,
	PasteImportRequestItem,
} from '../types';

const PasteImport = () => {
	const [text, setText] = useState('');
	const [status, setStatus] = useState<ImportStatus>('idle');
	const [results, setResults] = useState<ImportResult[]>([]);
	const [bulkCreateUrl] = useBulkCreateUrlMutation();

	const handleImport = async () => {
		if (!text.trim()) return;

		setStatus('processing');
		try {
			const lines = text.split(/\r\n|\n/).filter((line) => line.trim());
			const data: PasteImportRequestItem[] = lines.map((line) => {
				// Split by comma or space to find alias
				// But URL might contain comma (less likely) or spaces (encoded).
				// Let's assume: URL [comma or space] Alias
				// If comma exists, split by comma. Else split by space.

				let destinationUrl = line.trim();
				let alias: string | undefined;

				if (line.includes(',')) {
					const parts = line.split(',');
					destinationUrl = parts[0].trim();
					if (parts[1]) alias = parts[1].trim();
				} else if (line.includes(' ')) {
					const parts = line.trim().split(/\s+/);
					destinationUrl = parts[0];
					if (parts[1]) alias = parts[1];
				}

				return { destinationUrl, alias };
			});

			if (data.length === 0) throw new Error(__('No URLs found'));

			const result = await bulkCreateUrl({
				urls: data,
			}).unwrap();

			const transformResults: ImportResult[] = [];
			if (result.data) {
				(result.data.results || []).forEach((item) => {
					transformResults.push({
						url: item.destinationUrl,
						alias:
							item.alias ||
							item.shortCode ||
							__('Auto-generated'),
						status: 'success',
						shortUrl: buildShortUrl(item),
					});
				});
				(result.data.errors || []).forEach((item) => {
					transformResults.push({
						url: item.destinationUrl,
						alias: item.alias || 'N/A',
						status: 'error',
						error: item.error,
					});
				});
			}

			setResults(transformResults);
			setStatus('completed');
		} catch (err) {
			console.error(err);
			alert(
				sprintf(
					__('Import failed: %s'),
					getErrorMessage(err, __('Unknown error'))
				)
			);
			setStatus('idle');
		}
	};

	return (
		<div className="import-paste">
			<div className="import-panel import-paste-panel">
				<h2 className="import-panel-title">
					{__('Paste URLs')}
				</h2>
				<p className="import-panel-copy">
					{__(
						'Paste a list of URLs (one per line) to quickly create multiple short links. You can optionally add a custom alias separated by a comma or space.'
					)}
				</p>

				{status === 'idle' && (
					<div className="import-paste-grid">
						<div>
							<label className="import-field-label">
								{__('URLs (one per line)')}
							</label>
							<TextArea
								valueDirection="ltr"
								className="form-control-surface-alt form-control-roomy form-control-strong-focus import-paste-textarea font-mono"
								placeholder={`https://example.com/page1
https://example.com/page2, my-alias
https://example.com/page3 custom-alias`}
								value={text}
								onChange={(event) => setText(event.target.value)}
							/>
							<div className="import-paste-actions">
								<Button
									size="sm"
									onClick={handleImport}
									disabled={!text.trim()}
								>
									<WandSparkles className="import-paste-action-icon" />
									{__('Create Links')}
								</Button>
							</div>
						</div>
						<div>
							<h3 className="import-section-title">
								{__('Tips')}
							</h3>
							<div className="import-paste-tips">
								<ul className="import-paste-tips-list">
									<li className="import-paste-tip">
										<Lightbulb className="import-paste-tip-icon" />
										<div className="import-paste-tip-content">
											<p>{__('Format:')}</p>
											<div className="import-paste-tip-format">
												<code>URL [alias]</code>
												<span>{__('or')}</span>
												<code>URL, alias</code>
											</div>
										</div>
									</li>
									<li className="import-paste-tip">
										<Lightbulb className="import-paste-tip-icon" />
										<span>
											{__(
												'Each entry should be on a separate line'
											)}
										</span>
									</li>
									<li className="import-paste-tip">
										<Lightbulb className="import-paste-tip-icon" />
										<span>
											{__(
												'URLs must include http:// or https://'
											)}
										</span>
									</li>
									<li className="import-paste-tip">
										<Lightbulb className="import-paste-tip-icon" />
										<span>
											{__(
												'If alias is omitted, one will be auto-generated'
											)}
										</span>
									</li>
								</ul>
							</div>
						</div>
					</div>
				)}

				{status === 'processing' && (
					<div className="import-status-panel">
						<LoaderCircle className="import-status-icon" />
						<p className="import-status-copy">
							{__('Processing URLs...')}
						</p>
					</div>
				)}

				{status === 'completed' && (
					<ImportSummary
						results={results}
						onReset={() => {
							setStatus('idle');
							setResults([]);
							setText('');
						}}
					/>
				)}
			</div>

			{status === 'completed' && <ImportDetails results={results} />}
		</div>
	);
};

export default PasteImport;
