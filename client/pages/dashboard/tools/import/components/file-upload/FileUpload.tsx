import { useState, useRef } from "react";

import { useNotification } from "@/components";
import { useBulkCreateUrlMutation } from "@/state/slices/api";
import { getErrorMessage } from "@/shared/errors";
import {
	extractAliasFromShortUrl,
	normalizeCsvHeader,
	parseCsvRows,
} from "@/shared/csv";
import { getShortUrl } from "@/shared/links";
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
					const parsed = JSON.parse(text);
					const rawList: Array<Record<string, unknown>> =
						Array.isArray(parsed)
							? parsed
							: Array.isArray(parsed?.urls)
								? parsed.urls
								: Array.isArray(parsed?.items)
									? parsed.items
									: [];

					data = rawList
						.map((item): ImportRecord | null => {
							const destinationUrl = String(
								item.destinationUrl || ""
							).trim();

							if (!destinationUrl) {
								return null;
							}

							const alias = String(item.alias || "").trim();
							const title =
								String(item.title || "").trim() || undefined;
							const password =
								String(item.password || "").trim() || undefined;
							const expiresAt =
								String(item.expiresAt || "").trim() ||
								undefined;
							const status =
								String(item.status || "").trim() || undefined;

							const socialPreviewObj =
								typeof item.socialPreview === "object" &&
								item.socialPreview !== null
									? (item.socialPreview as Record<
											string,
											unknown
										>)
									: null;

							const socialTitle =
								String(
									item.socialTitle ||
										socialPreviewObj?.title ||
										""
								).trim() || undefined;

							const socialDescription =
								String(
									item.socialDescription ||
										socialPreviewObj?.description ||
										""
								).trim() || undefined;

							const socialImageUrl =
								String(
									item.socialImageUrl ||
										socialPreviewObj?.imageUrl ||
										socialPreviewObj?.externalImageUrl ||
										""
								).trim() || undefined;

							const utmSource =
								String(item.utmSource || "").trim() ||
								undefined;
							const utmMedium =
								String(item.utmMedium || "").trim() ||
								undefined;
							const utmCampaign =
								String(item.utmCampaign || "").trim() ||
								undefined;
							const utmTerm =
								String(item.utmTerm || "").trim() || undefined;
							const utmContent =
								String(item.utmContent || "").trim() ||
								undefined;

							return {
								destinationUrl,
								alias: alias || undefined,
								title,
								password,
								expiresAt,
								status,
								socialTitle,
								socialDescription,
								socialImageUrl,
								utmSource,
								utmMedium,
								utmCampaign,
								utmTerm,
								utmContent,
							};
						})
						.filter((item): item is ImportRecord => item !== null);
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
					if (header === "destinationurl") {
						entry.destinationUrl = value;
					} else if (header === "alias") {
						entry.alias = value;
					} else if (header === "title") {
						entry.title = value;
					} else if (header === "password") {
						entry.password = value;
					} else if (header === "expiresat") {
						entry.expiresAt = value;
					} else if (header === "status") {
						entry.status = value;
					} else if (header === "socialtitle") {
						entry.socialTitle = value;
					} else if (header === "socialdescription") {
						entry.socialDescription = value;
					} else if (header === "socialimageurl") {
						entry.socialImageUrl = value;
					} else if (header === "utmsource") {
						entry.utmSource = value;
					} else if (header === "utmmedium") {
						entry.utmMedium = value;
					} else if (header === "utmcampaign") {
						entry.utmCampaign = value;
					} else if (header === "utmterm") {
						entry.utmTerm = value;
					} else if (header === "utmcontent") {
						entry.utmContent = value;
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
		const urls = xmlDoc.getElementsByTagName("url");
		const items =
			urls.length > 0 ? urls : xmlDoc.getElementsByTagName("item");

		const data: ImportRecord[] = [];

		for (let i = 0; i < items.length; i++) {
			const node = items[i];
			if (!node) continue;
			const getVal = (...tags: string[]): string | undefined => {
				for (const tag of tags) {
					const val = node
						.getElementsByTagName(tag)[0]
						?.textContent?.trim();
					if (val) return val;
				}
				return undefined;
			};

			const destinationUrl = getVal("destinationUrl");

			if (destinationUrl) {
				const alias = getVal("alias");

				data.push({
					destinationUrl,
					alias: alias || undefined,
					title: getVal("title"),
					password: getVal("password"),
					expiresAt: getVal("expiresAt"),
					status: getVal("status"),
					socialTitle: getVal("socialTitle"),
					socialDescription: getVal("socialDescription"),
					socialImageUrl: getVal("socialImageUrl"),
					utmSource: getVal("utmSource"),
					utmMedium: getVal("utmMedium"),
					utmCampaign: getVal("utmCampaign"),
					utmTerm: getVal("utmTerm"),
					utmContent: getVal("utmContent"),
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
					const createdList = result.data.results || [];
					const errorList = result.data.errors || [];

					createdList.forEach((item) => {
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

					errorList.forEach((item) => {
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
