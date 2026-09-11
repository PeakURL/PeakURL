import { useState, useRef } from "react";

import { useNotification } from "@/components";
import { useBulkCreateUrlMutation } from "@/store/slices/api";
import {
	getShortUrl,
	extractAliasFromShortUrl,
	getErrorMessage,
	normalizeCsvHeader,
	parseCsvRows,
} from "@/utils";
import { __ } from "@/i18n";

import FileUploadArea from "./FileUploadArea";
import ProcessingStatus from "./ProcessingStatus";
import { ImportDetails, ImportSummary } from "../results";
import FormatRequirements from "./FormatRequirements";
import SampleData from "./SampleData";
import type { ImportResult } from "../types";
import type { FileUploadProps, ImportRecord } from "./types";

const FileUpload = ({
	importStatus,
	setImportStatus,
	sampleData,
}: FileUploadProps) => {
	const notification = useNotification();
	const fileInputRef = useRef<HTMLInputElement | null>(null);
	const [progress, setProgress] = useState<number>(0);
	const [importResults, setImportResults] = useState<ImportResult[]>([]);
	const [bulkCreateUrl] = useBulkCreateUrlMutation();

	const handleFileSelect = (file: File) => {
		if (!file) {
			return;
		}

		parseFile(file);
	};

	const parseFile = (file: File) => {
		const reader = new FileReader();
		reader.onload = (e) => {
			try {
				const text = (e.target?.result as string) || "";
				let data: ImportRecord[] = [];

				if (file.name.endsWith(".csv")) {
					data = parseCsv(text);
				} else if (file.name.endsWith(".json")) {
					data = (JSON.parse(text) as Array<Record<string, unknown>>)
						.map((item) => ({
							...item,
							destinationUrl: item.destinationUrl || item.url,
						}))
						.filter(
							(item): item is ImportRecord =>
								"string" === typeof item.destinationUrl &&
								Boolean(item.destinationUrl)
						);
				} else if (file.name.endsWith(".xml")) {
					data = parseXml(text);
				} else {
					notification.error(__("Unsupported file format"));
					return;
				}

				if (data.length > 0) {
					processImport(data);
				} else {
					notification.error(__("No valid data found in file"));
				}
			} catch (err) {
				console.error("Parsing error", err);
				notification.error(
					__("Failed to parse file"),
					getErrorMessage(err, __("Unknown error"))
				);
			}
		};
		reader.readAsText(file);
	};

	const parseCsv = (text: string): ImportRecord[] => {
		const rows = parseCsvRows(text);
		if (rows.length < 2) return [];

		const firstRow = rows[0];
		if (!firstRow) return [];

		const headers = firstRow.map((header: string) =>
			normalizeCsvHeader(header)
		);
		const data: ImportRecord[] = [];

		for (let i = 1; i < rows.length; i++) {
			const values = rows[i];
			if (!values) continue;
			const entry: Partial<ImportRecord> = {};

			headers.forEach((header: string, index: number) => {
				const value = values[index]?.trim();

				if (value) {
					if (
						header === "url" ||
						header === "destinationurl" ||
						header === "destination"
					) {
						entry.destinationUrl = value;
					} else if (header === "alias" || header === "shortcode") {
						entry.alias = value;
					} else if (
						header === "shorturl" ||
						header === "shortlink"
					) {
						entry.alias =
							entry.alias || extractAliasFromShortUrl(value);
					} else if (header === "password") {
						entry.password = value;
					} else if (header === "expires" || header === "expiresat") {
						entry.expiresAt = value;
					} else if (header === "title") {
						entry.title = value;
					}
				}
			});

			if (entry.destinationUrl) {
				data.push(entry as ImportRecord);
			}
		}
		return data;
	};

	const parseXml = (text: string): ImportRecord[] => {
		const parser = new DOMParser();
		const xmlDoc = parser.parseFromString(text, "text/xml");
		const urls = xmlDoc.getElementsByTagName("url"); // Assumes <url> item tag
		// If not <url>, try <item>
		const items =
			urls.length > 0 ? urls : xmlDoc.getElementsByTagName("item");

		const data: ImportRecord[] = [];

		for (let i = 0; i < items.length; i++) {
			const node = items[i];
			if (!node) continue;
			const getVal = (tag: string): string | undefined =>
				node.getElementsByTagName(tag)[0]?.textContent || undefined;

			const destinationUrl = getVal("destinationUrl") || getVal("url");

			if (destinationUrl) {
				data.push({
					destinationUrl,
					alias: getVal("alias") || getVal("shortCode"),
					password: getVal("password"),
					expiresAt: getVal("expiresAt") || getVal("expires"),
					title: getVal("title"),
				});
			}
		}
		return data;
	};

	const processImport = async (data: ImportRecord[]) => {
		setImportStatus("processing");
		setProgress(0);
		try {
			const batchSize = 25;
			const totalItems = data.length;
			const results: ImportResult[] = [];
			let processedCount = 0;

			for (let i = 0; i < totalItems; i += batchSize) {
				const chunk = data.slice(i, i + batchSize);
				const result = await bulkCreateUrl({
					urls: chunk,
				}).unwrap();

				if (result.data) {
					(result.data.results || []).forEach((item) => {
						results.push({
							url: item.destinationUrl,
							alias:
								item.alias ||
								item.shortCode ||
								extractAliasFromShortUrl(item.shortUrl || "") ||
								__("Auto-generated"),
							status: "success",
							shortUrl: getShortUrl(item),
						});
					});

					(result.data.errors || []).forEach((item) => {
						results.push({
							url: item.destinationUrl,
							alias: item.alias || "N/A",
							status: "error",
							error: item.error,
						});
					});
				}

				processedCount += chunk.length;
				setProgress(
					Math.min(
						100,
						Math.round((processedCount / totalItems) * 100)
					)
				);
			}

			setImportResults(results);
			setImportStatus("completed");
		} catch (err) {
			console.error("Import failed", err);
			setImportStatus("idle");
			notification.error(
				__("Import failed"),
				getErrorMessage(err, __("Unknown error"))
			);
		}
	};

	return (
		<div className="import-file-grid">
			<div className="import-file-main">
				<div className="import-panel import-file-panel">
					<h2 className="import-panel-title">{__("Upload File")}</h2>
					<p className="import-panel-copy">
						{__(
							"Upload a CSV, JSON, or XML file containing URLs and their metadata."
						)}
					</p>

					{importStatus === "idle" && (
						<FileUploadArea
							fileInputRef={fileInputRef}
							onFileSelected={handleFileSelect}
						/>
					)}

					{(importStatus === "uploading" ||
						importStatus === "processing") && (
						<ProcessingStatus
							status={importStatus}
							progress={progress}
						/>
					)}

					{importStatus === "completed" && (
						<ImportSummary
							results={importResults}
							onReset={() => {
								setImportStatus("idle");
								setImportResults([]);
							}}
						/>
					)}
				</div>

				<FormatRequirements />
			</div>

			<div className="import-file-sidebar">
				{importStatus === "completed" ? (
					<ImportDetails results={importResults} />
				) : (
					<SampleData sampleData={sampleData} />
				)}
			</div>
		</div>
	);
};

export default FileUpload;
