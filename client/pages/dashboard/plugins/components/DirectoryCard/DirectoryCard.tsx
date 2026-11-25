import { useState } from "react";
import {
	BadgeCheck,
	ExternalLink,
	Package,
	ShieldCheck,
	Tag,
} from "lucide-react";

import { Button, useNotification } from "@/components";
import { __ } from "@/i18n";
import { getErrorMessage } from "@/shared/errors";
import { formatCount } from "@/shared/formatting";
import {
	useActivatePluginMutation,
	useInstallDirectoryPluginMutation,
} from "@/state/slices/api/system";

import type { InstalledPlugin, PublishedPluginListing } from "../../types";

export interface DirectoryCardProps {
	plugin: PublishedPluginListing;
	installedPlugin?: InstalledPlugin;
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

export function DirectoryCard({ plugin, installedPlugin }: DirectoryCardProps) {
	const [imageFailed, setImageFailed] = useState(false);
	const notification = useNotification();
	const [installPlugin, { data: installation, isLoading: isInstalling }] =
		useInstallDirectoryPluginMutation();
	const [activatePlugin, { data: activation, isLoading: isActivating }] =
		useActivatePluginMutation();
	const installed = installedPlugin ?? installation?.data;
	const isActive =
		installed?.active ||
		(activation?.data?.active && activation.data.id === installed?.id);
	const canActivate =
		installed?.type === "regular" &&
		installed.valid &&
		installed.compatible;
	const rawIcon = plugin.media?.icon128 || plugin.media?.icon256;
	const iconUrl = sanitizeUrl(rawIcon, "https://peakurl.org");
	const isOfficial = Boolean(plugin.directoryTrust?.isOfficial);
	const isVerifiedAuthor = Boolean(plugin.directoryTrust?.isVerifiedAuthor);
	const tags = plugin.tags?.slice(0, 2) ?? [];
	const safeSlug = encodeURIComponent(plugin.slug.trim());
	const detailUrl = `https://peakurl.org/plugin/${safeSlug}`;
	const authorUrl = sanitizeUrl(
		plugin.author?.profileUrl,
		"https://peakurl.org"
	);

	const handleInstall = async () => {
		try {
			await installPlugin({
				slug: plugin.slug,
				version: plugin.version,
			}).unwrap();
			notification.success(
				__("Plugin installed successfully. It is ready for activation.")
			);
		} catch (error: unknown) {
			notification.error(
				getErrorMessage(error, __("Failed to install plugin."))
			);
		}
	};

	const handleActivate = async (id: string) => {
		try {
			await activatePlugin({ id }).unwrap();
			notification.success(__("Plugin activated."));
		} catch (error: unknown) {
			notification.error(
				getErrorMessage(error, __("Failed to activate plugin."))
			);
		}
	};

	return (
		<article
			className="plugins-directory-card flex h-full min-w-0 flex-col justify-between rounded-xl border border-stroke bg-surface p-4 shadow-xs transition-all hover:border-stroke-strong hover:shadow-sm"
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

				{plugin.summary && plugin.summary !== plugin.name && (
					<p className="mt-3 line-clamp-3 text-xs text-text-muted leading-relaxed">
						{plugin.summary}
					</p>
				)}

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

			<dl className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-text-muted">
				<div className="flex items-baseline gap-1.5">
					<dt>{__("Active installs")}</dt>
					<dd className="font-semibold tabular-nums text-heading">
						{formatCount(
							plugin.installationStats?.activeInstalls ?? 0
						)}
					</dd>
				</div>
				{plugin.downloadCount !== undefined && (
					<div className="flex items-baseline gap-1.5">
						<dt>{__("Downloads")}</dt>
						<dd className="font-semibold tabular-nums text-heading">
							{formatCount(plugin.downloadCount)}
						</dd>
					</div>
				)}
			</dl>

			{/* Footer: Tags and Actions */}
			<div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-stroke/60 pt-3">
				{tags.length > 0 && (
					<div className="flex min-w-0 flex-wrap items-center gap-1.5">
						{tags.map((tag) => (
							<span
								key={tag.slug}
								className="inline-flex items-center gap-1 rounded-md border border-stroke/60 bg-surface-alt px-2 py-0.5 text-[11px] font-medium text-text-muted"
							>
								<Tag size={10} className="text-accent" />
								<span className="truncate max-w-[80px]">
									{tag.label}
								</span>
							</span>
						))}
					</div>
				)}
				<div className="ms-auto flex flex-wrap items-center gap-2.5">
					<a
						href={detailUrl}
						target="_blank"
						rel="noopener noreferrer"
						className="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
					>
						<span>{__("View Details")}</span>
						<ExternalLink size={12} />
					</a>
					{installed ? (
						<Button
							size="xs"
							disabled={isActive || !canActivate}
							loading={isActivating}
							onClick={() => handleActivate(installed.id)}
						>
							{isActive
								? __("Active")
								: isActivating
									? __("Activating…")
									: __("Activate")}
						</Button>
					) : (
						plugin.distribution?.sha256 && (
							<Button
								size="xs"
								loading={isInstalling}
								onClick={handleInstall}
							>
								{isInstalling
									? __("Installing…")
									: __("Install Now")}
							</Button>
						)
					)}
				</div>
			</div>
		</article>
	);
}

export default DirectoryCard;
