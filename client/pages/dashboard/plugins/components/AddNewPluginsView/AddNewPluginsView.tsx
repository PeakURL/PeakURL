import { useEffect, useRef, useState } from "react";
import {
	AlertCircle,
	FileArchive,
	Globe,
	RefreshCw,
	Search,
	Upload,
	X,
} from "lucide-react";
import { useNavigate } from "react-router";

import { Button, useNotification } from "@/components";
import { __, sprintf } from "@/i18n";
import { cn, formatByteSize } from "@/shared/formatting";
import {
	useGetInstalledPluginsQuery,
	useGetPublishedPluginsQuery,
	useUploadPluginMutation,
} from "@/state/slices/api/system";

import DirectoryCard from "../DirectoryCard";
import type { PluginSortOption, PluginUploadLimit } from "../../types";

export interface AddNewPluginsViewProps {
	/** Whether the upload plugin panel is visible. */
	showUpload?: boolean;

	/** Callback to toggle upload panel visibility. */
	onToggleUpload?: (show: boolean) => void;

	/** Optional dynamic upload limit configuration passed from parent. */
	uploadLimit?: PluginUploadLimit;
}

export function AddNewPluginsView({
	showUpload = false,
	onToggleUpload,
	uploadLimit,
}: AddNewPluginsViewProps) {
	const navigate = useNavigate();
	const notification = useNotification();
	const fileInputRef = useRef<HTMLInputElement>(null);

	const [searchQuery, setSearchQuery] = useState("");
	const [debouncedSearch, setDebouncedSearch] = useState("");
	const [activeSort, setActiveSort] = useState<PluginSortOption>("featured");
	const [currentPage, setCurrentPage] = useState(1);

	// Upload form state
	const [selectedFile, setSelectedFile] = useState<File | null>(null);
	const [isDragging, setIsDragging] = useState(false);
	const [uploadPlugin, { isLoading: isUploading }] =
		useUploadPluginMutation();

	// Dynamic upload limits from query or parent prop
	const { data: installedResponse } = useGetInstalledPluginsQuery();
	const effectiveLimit = uploadLimit ?? installedResponse?.data?.uploadLimit;
	const maxUploadSizeFormatted = effectiveLimit?.formatted ?? "2 MB";

	// Debounce search queries by 300ms
	useEffect(() => {
		const timer = setTimeout(() => {
			setDebouncedSearch(searchQuery.trim());
		}, 300);
		return () => clearTimeout(timer);
	}, [searchQuery]);

	const {
		data: response,
		isLoading,
		isFetching,
		isError,
		refetch,
	} = useGetPublishedPluginsQuery({
		q: debouncedSearch || undefined,
		sort: activeSort,
		page: currentPage,
		limit: 12,
	});

	const directoryData = response?.data;
	const plugins = directoryData?.items ?? [];
	const pagination = directoryData?.pagination ?? {
		total: 0,
		page: 1,
		limit: 12,
		totalPages: 1,
	};

	const handleSearchChange = (val: string) => {
		setSearchQuery(val);
		setCurrentPage(1);
	};

	const handleClearSearch = () => {
		setSearchQuery("");
		setDebouncedSearch("");
		setCurrentPage(1);
	};

	const handleSortChange = (sort: PluginSortOption) => {
		setActiveSort(sort);
		setCurrentPage(1);
	};

	const validateAndSetFile = (file: File) => {
		if (!file.name.toLowerCase().endsWith(".zip")) {
			notification.error(__("Please select a valid .zip archive file."));
			return false;
		}
		setSelectedFile(file);
		return true;
	};

	const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
		const file = e.target.files?.[0];
		if (file) {
			validateAndSetFile(file);
		}
	};

	const handleClearFile = (e?: React.MouseEvent) => {
		if (e) {
			e.stopPropagation();
		}
		setSelectedFile(null);
		if (fileInputRef.current) {
			fileInputRef.current.value = "";
		}
	};

	const handleDragOver = (e: React.DragEvent) => {
		e.preventDefault();
		e.stopPropagation();
		if (!isDragging) {
			setIsDragging(true);
		}
	};

	const handleDragLeave = (e: React.DragEvent) => {
		e.preventDefault();
		e.stopPropagation();
		if (
			e.currentTarget &&
			e.relatedTarget &&
			e.currentTarget.contains(e.relatedTarget as Node)
		) {
			return;
		}
		setIsDragging(false);
	};

	const handleDrop = (e: React.DragEvent) => {
		e.preventDefault();
		e.stopPropagation();
		setIsDragging(false);
		const file = e.dataTransfer.files?.[0];
		if (file) {
			validateAndSetFile(file);
		}
	};

	const handleInstallZip = async (e: React.FormEvent) => {
		e.preventDefault();
		if (!selectedFile) {
			notification.error(__("Please select a .zip file to upload."));
			return;
		}

		const formData = new FormData();
		formData.append("plugin_zip", selectedFile);

		try {
			await uploadPlugin(formData).unwrap();
			notification.success(
				__("Plugin installed successfully. It is ready for activation.")
			);
			setSelectedFile(null);
			if (onToggleUpload) {
				onToggleUpload(false);
			}
			navigate("/dashboard/plugins");
		} catch (err: unknown) {
			const apiErr = err as { data?: { message?: string } };
			notification.error(
				apiErr?.data?.message ??
					__("Failed to install plugin from archive.")
			);
		}
	};

	return (
		<div className="plugins-add-new-view space-y-6">
			{/* Upload Plugin Section */}
			{showUpload && (
				<section
					aria-label={__("Install from ZIP")}
					className="plugins-upload-panel pb-6 border-b border-stroke/70 space-y-3"
				>
					<div>
						<h3 className="text-sm font-semibold text-heading">
							{__("Install from ZIP")}
						</h3>
						<p className="mt-1 text-xs text-text-muted leading-relaxed">
							{__(
								"Upload a plugin ZIP file to install it on this server."
							)}
						</p>
					</div>

					<form
						onSubmit={handleInstallZip}
						className="space-y-2 pt-1"
					>
						{/* Hidden accessible file input */}
						<input
							ref={fileInputRef}
							id="plugin-zip-file-input"
							type="file"
							accept=".zip"
							onChange={handleFileSelect}
							className="sr-only"
							aria-label={__("Choose plugin ZIP file")}
							disabled={isUploading}
						/>

						{/* Selection Area + Install Now Action */}
						<div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
							{/* Purposeful File Selection Surface */}
							<div
								onDragOver={handleDragOver}
								onDragEnter={handleDragOver}
								onDragLeave={handleDragLeave}
								onDrop={handleDrop}
								className="min-w-0 flex-1 max-w-xl"
							>
								{!selectedFile ? (
									/* Empty Selection Surface */
									<div
										role="button"
										tabIndex={0}
										onClick={() =>
											fileInputRef.current?.click()
										}
										onKeyDown={(e) => {
											if (
												e.key === "Enter" ||
												e.key === " "
											) {
												e.preventDefault();
												fileInputRef.current?.click();
											}
										}}
										className={cn(
											"flex items-center justify-between gap-3 p-3 rounded-lg border border-dashed transition-all cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-accent select-none",
											isDragging
												? "border-accent bg-accent/[0.06] text-heading"
												: "border-stroke/80 bg-surface-alt/40 hover:bg-surface-alt hover:border-stroke"
										)}
										aria-label={__(
											"Select plugin ZIP archive. Click or drag and drop a file here."
										)}
									>
										<div className="flex items-center gap-3 min-w-0">
											<div
												className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-stroke/70 bg-surface text-text-muted"
												aria-hidden="true"
											>
												<FileArchive size={18} />
											</div>
											<div className="min-w-0 text-left">
												<div className="text-xs font-medium text-heading">
													{isDragging
														? __(
																"Drop archive to select"
															)
														: __(
																"Select plugin archive"
															)}
												</div>
												<div className="text-[11px] text-text-muted truncate">
													{__(
														"Drag and drop a .zip file or click to browse"
													)}
												</div>
											</div>
										</div>
										<Button
											type="button"
											variant="secondary"
											size="sm"
											className="shrink-0 pointer-events-none"
											tabIndex={-1}
										>
											{__("Choose File")}
										</Button>
									</div>
								) : (
									/* Populated Selection State */
									<div className="flex items-center justify-between gap-3 p-2.5 sm:p-3 rounded-lg border border-stroke bg-surface-alt/60">
										<div className="flex items-center gap-3 min-w-0 flex-1">
											<div
												className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-accent/30 bg-accent/10 text-accent"
												aria-hidden="true"
											>
												<FileArchive size={18} />
											</div>
											<div className="min-w-0 flex-1 text-left">
												<div
													className="text-xs font-semibold text-heading truncate"
													title={selectedFile.name}
												>
													{selectedFile.name}
												</div>
												<div className="text-[11px] text-text-muted">
													{formatByteSize(
														selectedFile.size
													)}
												</div>
											</div>
										</div>
										<div className="flex items-center gap-1.5 shrink-0">
											<Button
												type="button"
												variant="ghost"
												size="sm"
												onClick={() =>
													fileInputRef.current?.click()
												}
												disabled={isUploading}
												className="text-xs text-text-muted hover:text-heading h-8 px-2.5"
											>
												{__("Replace")}
											</Button>
											<Button
												type="button"
												variant="ghost"
												size="sm"
												onClick={handleClearFile}
												disabled={isUploading}
												aria-label={__(
													"Clear selected file"
												)}
												className="text-xs text-text-muted hover:text-red-500 hover:bg-red-500/10 h-8 w-8 p-0 inline-flex items-center justify-center"
											>
												<X size={15} />
											</Button>
										</div>
									</div>
								)}
							</div>

							{/* Install Now Button */}
							<Button
								type="submit"
								disabled={!selectedFile || isUploading}
								className="inline-flex items-center justify-center gap-1.5 shrink-0 self-stretch sm:self-auto h-[48px] px-4 font-medium"
							>
								{isUploading ? (
									<>
										<RefreshCw
											size={15}
											className="animate-spin"
										/>
										<span>{__("Installing…")}</span>
									</>
								) : (
									<>
										<Upload size={15} />
										<span>{__("Install Now")}</span>
									</>
								)}
							</Button>
						</div>

						{/* Dynamic Limit Status Line */}
						<p className="text-xs text-text-muted">
							{sprintf(
								__("Maximum upload size: %s"),
								maxUploadSizeFormatted
							)}
						</p>
					</form>
				</section>
			)}

			{/* Search & Sort Controls */}
			<div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
				{/* Search Field */}
				<div className="relative flex-1 max-w-md">
					<Search
						size={15}
						className="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted pointer-events-none"
					/>
					<input
						type="search"
						value={searchQuery}
						onChange={(e) => handleSearchChange(e.target.value)}
						placeholder={__(
							"Search plugins by name, publisher, or capability…"
						)}
						className="w-full rounded-lg border border-stroke bg-surface py-2 pl-9 pr-9 text-xs text-heading placeholder:text-text-muted focus:border-accent focus:outline-none"
					/>
					{searchQuery && (
						<button
							type="button"
							onClick={handleClearSearch}
							className="absolute right-2.5 top-1/2 -translate-y-1/2 text-text-muted hover:text-heading cursor-pointer"
							aria-label={__("Clear search")}
						>
							<X size={14} />
						</button>
					)}
				</div>

				{/* Sorting Tabs */}
				<div className="flex items-center gap-2 shrink-0 self-start sm:self-auto">
					<div
						role="radiogroup"
						aria-label={__("Sort plugins")}
						className="inline-flex items-center rounded-lg border border-stroke bg-surface-alt p-1 text-xs"
					>
						<button
							type="button"
							role="radio"
							aria-checked={activeSort === "featured"}
							onClick={() => handleSortChange("featured")}
							className={`inline-flex items-center rounded-md px-3 py-1 font-medium transition-colors cursor-pointer ${
								activeSort === "featured"
									? "bg-surface text-heading shadow-xs"
									: "text-text-muted hover:text-heading"
							}`}
						>
							<span>{__("Featured")}</span>
						</button>
						<button
							type="button"
							role="radio"
							aria-checked={activeSort === "newest"}
							onClick={() => handleSortChange("newest")}
							className={`inline-flex items-center rounded-md px-3 py-1 font-medium transition-colors cursor-pointer ${
								activeSort === "newest"
									? "bg-surface text-heading shadow-xs"
									: "text-text-muted hover:text-heading"
							}`}
						>
							<span>{__("Newest")}</span>
						</button>
					</div>
				</div>
			</div>

			{/* Active filter counter and refreshing indicator */}
			<div className="flex items-center justify-between text-xs text-text-muted px-1">
				<div>
					{isLoading ? (
						<span>{__("Loading directory…")}</span>
					) : plugins.length > 0 ? (
						<span>
							{pagination.total === 1
								? __("1 published plugin available")
								: `${pagination.total} ${__(
										"published plugins available"
									)}`}
						</span>
					) : (
						<span>{__("0 published plugins")}</span>
					)}
				</div>
				{isFetching && !isLoading && (
					<span className="inline-flex items-center gap-1.5 text-accent">
						<RefreshCw size={11} className="animate-spin" />
						<span>{__("Refreshing…")}</span>
					</span>
				)}
			</div>

			{/* Content Area */}
			{isLoading ? (
				/* Loading Skeletons in 2-column grid */
				<div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
					{Array.from({ length: 4 }).map((_, idx) => (
						<div
							key={idx}
							className="rounded-xl border border-stroke bg-surface p-5 space-y-4 animate-pulse"
						>
							<div className="flex items-start justify-between">
								<div className="flex items-center gap-3">
									<div className="h-11 w-11 rounded-xl bg-surface-alt" />
									<div className="space-y-1.5">
										<div className="h-4 w-32 rounded bg-surface-alt" />
										<div className="h-3 w-20 rounded bg-surface-alt" />
									</div>
								</div>
								<div className="h-5 w-12 rounded bg-surface-alt" />
							</div>
							<div className="space-y-2 pt-2">
								<div className="h-3 w-full rounded bg-surface-alt" />
								<div className="h-3 w-4/5 rounded bg-surface-alt" />
							</div>
						</div>
					))}
				</div>
			) : isError ? (
				/* Error State - Clean flat centered */
				<div className="py-16 text-center max-w-md mx-auto">
					<div
						className="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-surface-alt text-red-500 border border-stroke/60"
						aria-hidden="true"
					>
						<AlertCircle size={18} />
					</div>
					<h3 className="text-sm font-semibold text-heading">
						{__("Unable to connect to the PeakURL.org directory.")}
					</h3>
					<p className="mt-1 text-xs text-text-muted leading-relaxed">
						{__(
							"Could not retrieve published plugins from the official directory service. Please check your internet connection."
						)}
					</p>
					<div className="mt-4">
						<Button
							variant="secondary"
							size="sm"
							onClick={() => refetch()}
						>
							<RefreshCw size={14} className="mr-1.5" />
							{__("Retry")}
						</Button>
					</div>
				</div>
			) : plugins.length === 0 ? (
				/* Empty State - Clean flat centered */
				searchQuery ? (
					/* 0 search matches */
					<div className="py-16 text-center max-w-md mx-auto">
						<div
							className="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-surface-alt text-text-muted border border-stroke/60"
							aria-hidden="true"
						>
							<Search size={18} />
						</div>
						<h3 className="text-sm font-semibold text-heading">
							{__("No matching plugins")}
						</h3>
						<p className="mt-1 text-xs text-text-muted leading-relaxed">
							{__("Try a different search term.")}
						</p>
						<div className="mt-4">
							<Button
								variant="secondary"
								size="sm"
								onClick={handleClearSearch}
							>
								{__("Clear Search")}
							</Button>
						</div>
					</div>
				) : (
					/* 0 published plugins in official catalog */
					<div className="py-16 text-center max-w-md mx-auto">
						<div
							className="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-surface-alt text-text-muted border border-stroke/60"
							aria-hidden="true"
						>
							<Globe size={18} />
						</div>
						<h3 className="text-sm font-semibold text-heading">
							{__("No plugins available yet")}
						</h3>
						<p className="mt-1 text-xs text-text-muted leading-relaxed">
							{showUpload
								? __(
										"Published plugins will appear here once available in the directory."
									)
								: __(
										"Published plugins will appear here. You can also install a plugin by uploading a ZIP file."
									)}
						</p>
						{onToggleUpload && !showUpload && (
							<div className="mt-4">
								<Button
									variant="secondary"
									size="sm"
									onClick={() => onToggleUpload(true)}
								>
									<Upload size={14} className="mr-1.5" />
									{__("Upload Plugin")}
								</Button>
							</div>
						)}
					</div>
				)
			) : (
				/* Cards Grid (2 columns on desktop) */
				<div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
					{plugins.map((plugin) => (
						<DirectoryCard key={plugin.id} plugin={plugin} />
					))}
				</div>
			)}

			{/* Pagination Controls */}
			{pagination.totalPages > 1 && (
				<div className="flex items-center justify-between border-t border-stroke/60 pt-4 text-xs text-text-muted">
					<Button
						variant="secondary"
						size="sm"
						disabled={currentPage <= 1}
						onClick={() =>
							setCurrentPage((p) => Math.max(1, p - 1))
						}
					>
						{__("Previous")}
					</Button>
					<span>
						{__("Page")} {pagination.page} {__("of")}{" "}
						{pagination.totalPages}
					</span>
					<Button
						variant="secondary"
						size="sm"
						disabled={currentPage >= pagination.totalPages}
						onClick={() =>
							setCurrentPage((p) =>
								Math.min(pagination.totalPages, p + 1)
							)
						}
					>
						{__("Next")}
					</Button>
				</div>
			)}
		</div>
	);
}

export default AddNewPluginsView;
