import { isRelativeUrl, sanitizeUrl } from "@/utils";
import { ExternalLink } from "lucide-react";
import type { DetailRowProps } from "../types";
import DirectionalValue from "./DirectionalValue";

/**
 * Renders a label/value row with optional external-link behavior.
 */
function DetailRow({
	direction,
	label,
	value,
	icon: Icon,
	href,
	valueDirection = "auto",
}: DetailRowProps) {
	const safeHref = sanitizeUrl(href);
	const valueNode = (
		<DirectionalValue direction={valueDirection}>{value}</DirectionalValue>
	);

	return (
		<div dir={direction} className="settings-updates-detail-row">
			<div className="settings-updates-detail-label">
				{Icon ? <Icon size={15} /> : null}
				<span>{label}</span>
			</div>
			{safeHref && !isRelativeUrl(safeHref) ? (
				<a
					href={safeHref}
					target="_blank"
					rel="noreferrer"
					dir={direction}
					className="settings-updates-detail-link"
				>
					{valueNode}
					<ExternalLink size={14} />
				</a>
			) : (
				<span className="settings-updates-detail-value">
					{valueNode}
				</span>
			)}
		</div>
	);
}

export default DetailRow;
