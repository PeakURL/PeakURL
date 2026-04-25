import { Star, Download, Bell, ExternalLink } from "lucide-react";
import { __ } from "@/i18n";
import { PLUGINS_WAITLIST_URL } from "@constants";
import PluginPreviewSkeleton from "../PluginPreviewSkeleton";
import type { PluginCardProps } from "../types";

function PluginCard({ plugin }: PluginCardProps) {
	return (
		<div className="plugins-card group">
			{/* ── Banner ── */}
			<div
				className={`plugins-card-banner bg-linear-to-br ${plugin.gradient}`}
			>
				{/* Decorative blurred circles inside the banner */}
				<div className="plugins-card-banner-glow-end" />
				<div className="plugins-card-banner-glow-start" />

				{/* Plugin icon placeholder */}
				<div className="plugins-card-banner-icon-wrap">
					<div className="plugins-card-banner-icon">
						<div className="plugins-card-banner-icon-placeholder" />
					</div>
				</div>
			</div>

			{/* ── Content area ── */}
			<div className="plugins-card-body">
				{/* Blurred plugin name */}
				<div className="plugins-card-header">
					<PluginPreviewSkeleton
						className={`h-4 ${plugin.barWidths[0]}`}
					/>
					<PluginPreviewSkeleton className="plugins-preview-skeleton-label" />
				</div>

				{/* Blurred author */}
				<PluginPreviewSkeleton className="plugins-card-author" />

				{/* Blurred description lines */}
				<div className="plugins-card-copy">
					<PluginPreviewSkeleton
						className={`h-3 ${plugin.barWidths[1]}`}
					/>
					<PluginPreviewSkeleton
						className={`h-3 ${plugin.barWidths[2]}`}
					/>
					<PluginPreviewSkeleton className="h-3 w-1/3" />
				</div>

				{/* Rating / installs placeholder row */}
				<div className="plugins-card-meta">
					<div className="plugins-card-meta-stars">
						{[1, 2, 3, 4, 5].map((s) => (
							<Star
								key={s}
								size={11}
								className="plugins-preview-meta-icon"
								fill="currentColor"
							/>
						))}
						<PluginPreviewSkeleton className="plugins-preview-skeleton-stat" />
					</div>
					<div className="plugins-card-meta-downloads">
						<Download size={11} />
						<PluginPreviewSkeleton className="plugins-preview-skeleton-stat-wide" />
					</div>
				</div>

				{/* Waitlist button */}
				<a
					href={PLUGINS_WAITLIST_URL}
					target="_blank"
					rel="noreferrer"
					className="plugins-card-button group/btn"
				>
					<Bell
						size={13}
						className="plugins-card-button-icon group-hover/btn:rotate-12"
					/>
					{__("Join the Waitlist")}
					<ExternalLink
						size={11}
						className="plugins-preview-link-icon"
					/>
				</a>
			</div>

			<a
				href={PLUGINS_WAITLIST_URL}
				target="_blank"
				rel="noreferrer"
				className="plugins-card-overlay group-hover:opacity-100"
			>
				<span className="plugins-card-overlay-pill">
					<Bell size={11} />
					{__("Join the Waitlist")}
				</span>
			</a>
		</div>
	);
}

export default PluginCard;
