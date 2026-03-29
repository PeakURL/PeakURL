// @ts-nocheck
'use client';
import { useState } from 'react';
import { Button } from '@/components/ui';
import { useBulkCreateUrlMutation } from '@/store/slices/api/urls';
import ImportSummary from './ImportSummary';
import ImportDetails from './ImportDetails';
import { Lightbulb, LoaderCircle, WandSparkles } from 'lucide-react';

const PasteImport = () => {
	const [text, setText] = useState('');
	const [status, setStatus] = useState('idle');
	const [results, setResults] = useState([]);
	const [bulkCreateUrl] = useBulkCreateUrlMutation();

	const handleImport = async () => {
		if (!text.trim()) return;

		setStatus('processing');
		try {
			const lines = text.split(/\r\n|\n/).filter((line) => line.trim());
			const data = lines.map((line) => {
				// Split by comma or space to find alias
				// But URL might contain comma (less likely) or spaces (encoded).
				// Let's assume: URL [comma or space] Alias
				// If comma exists, split by comma. Else split by space.

				let destinationUrl = line.trim();
				let alias = undefined;

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

			if (data.length === 0) throw new Error('No URLs found');

			const result = await bulkCreateUrl({ urls: data }).unwrap();

			const transformResults = [];
			if (result.data) {
				result.data.results.forEach((item) => {
					transformResults.push({
						url: item.destinationUrl,
						alias: item.alias || item.shortCode,
						status: 'success',
						shortUrl: `${window.location.origin}/${
							item.alias || item.shortCode
						}`,
					});
				});
				result.data.errors.forEach((item) => {
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
			alert('Import failed: ' + (err.message || 'Unknown error'));
			setStatus('idle');
		}
	};

	return (
		<div className="space-y-5">
			<div className="bg-surface border border-stroke rounded-lg p-5">
				<h2 className="text-base font-semibold text-heading mb-3">
					Paste URLs
				</h2>
				<p className="text-sm text-text-muted mb-5">
					Paste a list of URLs (one per line) to quickly create
					multiple short links. You can optionally add a custom alias
					separated by a comma or space.
				</p>

				{status === 'idle' && (
					<div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
						<div>
							<label className="block text-sm font-medium text-heading mb-2">
								URLs (one per line)
							</label>
							<textarea
								className="w-full h-64 bg-surface-alt border border-stroke rounded-lg px-4 py-3 text-sm text-heading placeholder-text-text-muted focus:ring-2 focus:ring-accent focus:border-accent outline-none resize-none font-mono transition-colors"
								placeholder={`https://example.com/page1
https://example.com/page2, my-alias
https://example.com/page3 custom-alias`}
								value={text}
								onChange={(e) => setText(e.target.value)}
							/>
							<div className="mt-4 flex items-end">
								<Button
									size="sm"
									onClick={handleImport}
									disabled={!text.trim()}
								>
									<WandSparkles className="mr-2 h-4 w-4" />
									Create Links
								</Button>
							</div>
						</div>
						<div>
							<h3 className="font-medium text-sm text-heading mb-3">
								Tips
							</h3>
							<div className="bg-amber-500/10 dark:bg-amber-500/20 border border-amber-500/20 dark:border-amber-500/30 rounded-lg p-4">
								<ul className="text-sm text-heading space-y-2.5">
									<li className="flex items-start gap-2">
										<Lightbulb className="mt-0.5 h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0" />
										<span>
											Format: <code>URL [alias]</code> or{' '}
											<code>URL, alias</code>
										</span>
									</li>
									<li className="flex items-start gap-2">
										<Lightbulb className="mt-0.5 h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0" />
										<span>
											Each entry should be on a separate
											line
										</span>
									</li>
									<li className="flex items-start gap-2">
										<Lightbulb className="mt-0.5 h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0" />
										<span>
											URLs must include http:// or
											https://
										</span>
									</li>
									<li className="flex items-start gap-2">
										<Lightbulb className="mt-0.5 h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0" />
										<span>
											If alias is omitted, one will be
											auto-generated
										</span>
									</li>
								</ul>
							</div>
						</div>
					</div>
				)}

				{status === 'processing' && (
					<div className="py-8 text-center">
						<LoaderCircle className="mx-auto mb-3 h-8 w-8 animate-spin text-accent" />
						<p className="text-sm text-text-muted">
							Processing URLs...
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
