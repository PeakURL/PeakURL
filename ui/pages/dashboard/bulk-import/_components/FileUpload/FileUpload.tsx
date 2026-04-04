// @ts-nocheck
import { useState, useRef } from 'react';
import { useBulkCreateUrlMutation } from '@/store/slices/api/urls';
import {
	extractAliasFromShortUrl,
	normalizeCsvHeader,
	parseCsvRows,
} from '@/utils';
import { buildShortUrl } from '@/utils/linkHelpers';
import FileUploadArea from './FileUploadArea';
import ProcessingStatus from './ProcessingStatus';
import ImportSummary from '../ImportSummary';
import ImportDetails from '../ImportDetails';
import FormatRequirements from './FormatRequirements';
import SampleData from './SampleData';
import { __, sprintf } from '@/i18n';

const FileUpload = ({
	importStatus,
	setImportStatus,
	importProgress,
	sampleData,
}) => {
	const fileInputRef = useRef(null);
	const [importResults, setImportResults] = useState([]);
	const [bulkCreateUrl] = useBulkCreateUrlMutation();

	const handleFileSelect = (file) => {
		if (!file) {
			return;
		}

		parseFile(file);
	};

	const parseFile = (file) => {
		const reader = new FileReader();
		reader.onload = (e) => {
			const text = e.target.result;
			let data = [];

			try {
				if (file.name.endsWith('.csv')) {
					data = parseCsv(text);
				} else if (file.name.endsWith('.json')) {
					data = JSON.parse(text);
					// Normalize JSON data if needed (e.g. map 'url' to 'destinationUrl')
					data = data
						.map((item) => ({
							...item,
							destinationUrl: item.destinationUrl || item.url,
						}))
						.filter((item) => item.destinationUrl);
				} else if (file.name.endsWith('.xml')) {
					data = parseXml(text);
				} else {
					alert(__('Unsupported file format'));
					return;
				}

				if (data.length > 0) {
					processImport(data);
				} else {
					alert(__('No valid data found in file'));
				}
			} catch (err) {
				console.error('Parsing error', err);
				alert(sprintf(__('Failed to parse file: %s'), err.message));
			}
		};
		reader.readAsText(file);
	};

	const parseCsv = (text) => {
		const rows = parseCsvRows(text);
		if (rows.length < 2) return [];

		const headers = rows[0].map((header) => normalizeCsvHeader(header));
		const data = [];

		for (let i = 1; i < rows.length; i++) {
			const values = rows[i];
			const entry = {};

			headers.forEach((header, index) => {
				const value = values[index]?.trim();

				if (value) {
					if (
						header === 'url' ||
						header === 'destinationurl' ||
						header === 'destination'
					) {
						entry.destinationUrl = value;
					} else if (
						header === 'alias' ||
						header === 'shortcode'
					) {
						entry.alias = value;
					} else if (
						header === 'shorturl' ||
						header === 'shortlink'
					) {
						entry.alias =
							entry.alias || extractAliasFromShortUrl(value);
					} else if (header === 'password') {
						entry.password = value;
					} else if (
						header === 'expires' ||
						header === 'expiresat'
					) {
						entry.expiresAt = value;
					} else if (header === 'title') {
						entry.title = value;
					}
				}
			});

			if (entry.destinationUrl) {
				data.push(entry);
			}
		}
		return data;
	};

	const parseXml = (text) => {
		const parser = new DOMParser();
		const xmlDoc = parser.parseFromString(text, 'text/xml');
		const urls = xmlDoc.getElementsByTagName('url'); // Assumes <url> item tag
		// If not <url>, try <item>
		const items =
			urls.length > 0 ? urls : xmlDoc.getElementsByTagName('item');

		const data = [];

		for (let i = 0; i < items.length; i++) {
			const node = items[i];
			const getVal = (tag) =>
				node.getElementsByTagName(tag)[0]?.textContent;

			const destinationUrl = getVal('destinationUrl') || getVal('url');

			if (destinationUrl) {
				data.push({
					destinationUrl,
					alias: getVal('alias') || getVal('shortCode'),
					password: getVal('password'),
					expiresAt: getVal('expiresAt') || getVal('expires'),
					title: getVal('title'),
				});
			}
		}
		return data;
	};

	const processImport = async (data) => {
		setImportStatus('processing');
		try {
			const result = await bulkCreateUrl({ urls: data }).unwrap();

			const results = [];

			if (result.data) {
				result.data.results.forEach((item) => {
					results.push({
						url: item.destinationUrl,
						alias: item.alias || item.shortCode,
						status: 'success',
						shortUrl: buildShortUrl(item),
					});
				});

				result.data.errors.forEach((item) => {
					results.push({
						url: item.destinationUrl,
						alias: item.alias || 'N/A',
						status: 'error',
						error: item.error,
					});
				});
			}

			setImportResults(results);
			setImportStatus('completed');
		} catch (err) {
			console.error('Import failed', err);
			setImportStatus('idle');
			alert(
				sprintf(
					__('Import failed: %s'),
					err?.data?.message || err.message
				)
			);
		}
	};

	return (
		<div className="grid grid-cols-1 xl:grid-cols-2 gap-5">
			<div className="space-y-5">
				<div className="bg-surface border border-stroke rounded-lg p-5">
					<h2 className="text-base font-semibold text-heading mb-3">
						{__('Upload File')}
					</h2>
					<p className="text-sm text-text-muted mb-5">
						{__(
							'Upload a CSV, JSON, or XML file containing URLs and their metadata.'
						)}
					</p>

					{importStatus === 'idle' && (
						<FileUploadArea
							fileInputRef={fileInputRef}
							onFileSelected={handleFileSelect}
						/>
					)}

					{(importStatus === 'uploading' ||
						importStatus === 'processing') && (
						<ProcessingStatus status={importStatus} />
					)}

					{importStatus === 'completed' && (
						<ImportSummary
							results={importResults}
							onReset={() => {
								setImportStatus('idle');
								setImportResults([]);
							}}
						/>
					)}
				</div>

				<FormatRequirements />
			</div>

			<div className="space-y-5">
				{importStatus === 'completed' ? (
					<ImportDetails results={importResults} />
				) : (
					<SampleData sampleData={sampleData} />
				)}
			</div>
		</div>
	);
};

export default FileUpload;
