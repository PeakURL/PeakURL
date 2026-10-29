import { useState } from "react";
import {
	BadgeCheck,
	Download,
	ExternalLink,
	Package,
	ShieldCheck,
	Tag,
} from "lucide-react";

import { __ } from "@/i18n";
import type { PublishedPluginListing } from "../../types";

export interface DirectoryCardProps {
	plugin: PublishedPluginListing;
}

/**
 * Safely parse and validate an HTTP/HTTPS URL, rejecting control chars and whitespace.
 * Optionally resolves relative paths against a trusted base origin.
 */
function sanitizeUrl(
	rawUrl?: string | null,
	trustedBaseOrigin?: string
): string | null {
	if (!rawUrl || typeof rawUrl !== "string") {
		return null;
	}
	const trimmed = rawUrl.trim();
	if (!trimmed) {
		return null;
	}

	// Reject whitespace and ASCII control characters (0x00-0x20, 0x7F)
	for (let i = 0; i < trimmed.length; i++) {
		const code = trimmed.charCodeAt(i);
		if (code <= 0x20 || code === 0x7f) {
			return null;
		}
	}

	// If absolute URL
	if (/^https?:\/\//i.test(trimmed)) {
		try {
			const parsed = new URL(trimmed);
			if (parsed.protocol === "https:" || parsed.protocol === "http:") {
				return parsed.href;
			}
		} catch {
			return null;
		}
	}

	// If relative path and trusted origin provided
	if (trustedBaseOrigin && trimmed.startsWith("/")) {
		try {
			const parsed = new URL(trimmed, trustedBaseOrigin);
			if (parsed.protocol === "https:" || parsed.protocol === "http:") {
				return parsed.href;
			}
		} catch {
			return null;
		}
	}

	return null;
}

export function DirectoryCard({ plugin }: DirectoryCardProps) {
	const [imageFailed, setImageFailed] = useState(false);
	const rawIcon = plugin.media?.icon128 || plugin.media?.icon256;
	const iconUrl = sanitizeUrl(rawIcon, "https://peakurl.org");
	const isOfficial = Boolean(plugin.directoryTrust?.isOfficial);
	const isVerifiedAuthor = Boolean(plugin.directoryTrust?.isVerifiedAuthor);
	const safeSlug = encodeURIComponent(plugin.slug.trim());
	const detailUrl = `https://peakurl.org/plugin/${safeSlug}`;
	const downloadUrl = sanitizeUrl(
		plugin.distribution?.downloadUrl,
		"https://api.peakurl.org"
	);
	const authorUrl = sanitizeUrl(
		plugin.author?.profileUrl,
		"https://peakurl.org"
	);

	return (
		<article
			className="plugins-directory-card flex h-full flex-col justify-between rounded-xl border border-stroke bg-surface p-5 shadow-xs transition-all hover:border-stroke-strong hover:shadow-sm"
			data-plugin-slug={plugin.slug}
		>
			<div>
				{/* Top Header: Icon + Name/Author + Version */}
				<div className="flex items-start justify-between gap-3">
					<div className="flex min-w-0 items-center gap-3">
						<div
							className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-stroke bg-surface-alt overflow-hidden text-accent"
							aria-hidden="true"
						>
							{iconUrl && !imageFailed ? (
								<img
									src={iconUrl}
									alt=""
									className="h-full w-full object-cover"
									onError={() => setImageFailed(true)}
								/>
							) : (
								<Package size={22} />
							)}
						</div>
						<div className="min-w-0 flex-1">
							<h3 className="truncate text-base font-semibold text-heading">
								<a
									href={detailUrl}
									target="_blank"
									rel="noopener noreferrer"
									className="hover:text-accent focus-visible:underline"
								>
									{plugin.name}
								</a>
							</h3>
							{plugin.author && (
								<p className="truncate text-xs text-text-muted mt-0.5">
									{__("By")}{" "}
									{authorUrl ? (
										<a
											href={authorUrl}
											target="_blank"
											rel="noopener noreferrer"
											className="font-medium text-heading hover:text-accent transition-colors"
										>
											{plugin.author.name}
										</a>
									) : (
										<span className="font-medium text-heading">
											{plugin.author.name}
										</span>
									)}
								</p>
							)}
						</div>
					</div>
					<span className="shrink-0 rounded-md border border-stroke bg-surface-alt px-2 py-0.5 font-mono text-xs font-medium text-heading">
						v{plugin.version}
					</span>
				</div>

				{/* Trust Badges */}
				{(isOfficial || isVerifiedAuthor) && (
					<div className="mt-3 flex flex-wrap items-center gap-2 text-xs">
						{isOfficial && (
							<span className="inline-flex items-center gap-1 rounded-full border border-emerald-500/25 bg-emerald-500/10 px-2 py-0.5 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
								<ShieldCheck size={12} aria-hidden="true" />
								<span>{__("Official")}</span>
							</span>
						)}
						{isVerifiedAuthor && !isOfficial && (
							<span className="inline-flex items-center gap-1 rounded-full border border-sky-500/25 bg-sky-500/10 px-2 py-0.5 text-[11px] font-medium text-sky-600 dark:text-sky-400">
								<BadgeCheck size={12} aria-hidden="true" />
								<span>{__("Verified Author")}</span>
							</span>
						)}
					</div>
				)}

				{/* Summary */}
				<p className="mt-3 line-clamp-3 text-xs text-text-muted leading-relaxed">
					{plugin.summary}
				</p>

				{/* Requirements */}
				<div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-text-muted font-mono">
					{plugin.requires && <span>PeakURL {plugin.requires}+</span>}
					{plugin.php && (
						<>
							<span>•</span>
							<span>PHP {plugin.php}+</span>
						</>
					)}
					{plugin.license && (
						<>
							<span>•</span>
							<span>{plugin.license}</span>
						</>
					)}
				</div>
			</div>

			{/* Footer: Tags and Actions */}
			<div className="mt-5 border-t border-stroke/60 pt-3.5">
				<div className="flex items-center justify-between gap-2">
					{/* Category/Tags */}
					<div className="flex flex-wrap items-center gap-1.5 min-w-0">
						{plugin.tags && plugin.tags.length > 0 ? (
							plugin.tags.slice(0, 2).map((t) => (
								<span
									key={t.slug}
									className="inline-flex items-center gap-1 rounded-md border border-stroke/60 bg-surface-alt px-2 py-0.5 text-[11px] font-medium text-text-muted"
								>
									<Tag size={10} className="text-accent" />
									<span className="truncate max-w-[80px]">
										{t.label}
									</span>
								</span>
							))
						) : (
							<span className="text-xs text-text-muted">—</span>
						)}
					</div>

					{/* Actions */}
					<div className="flex items-center gap-2.5 shrink-0">
						{downloadUrl && (
							<a
								href={downloadUrl}
								target="_blank"
								rel="noopener noreferrer"
								className="inline-flex items-center gap-1 text-xs text-text-muted hover:text-heading transition-colors"
								title={__("Download ZIP package")}
							>
								<Download size={13} />
								<span className="hidden sm:inline">
									{__("Download")}
								</span>
							</a>
						)}
						<a
							href={detailUrl}
							target="_blank"
							rel="noopener noreferrer"
							className="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
						>
							<span>{__("View Details")}</span>
							<ExternalLink size={12} />
						</a>
					</div>
				</div>
			</div>
		</article>
	);
}

export default DirectoryCard;
