import type { ReactNode } from "react";
import { Copy } from "lucide-react";
import { getDocumentDirection } from "@/i18n/direction";
import { cn } from "@/utils";

interface ReadOnlyValueBlockProps {
	value?: string | null;
	className?: string;
	valueClassName?: string;
	monospace?: boolean;
	onCopy?: () => void;
	copyButtonLabel?: string;
	copyButtonClassName?: string;
	copyButtonContent?: ReactNode;
	extraActions?: ReactNode;
}

export function ReadOnlyValueBlock({
	value,
	className = "",
	valueClassName = "",
	monospace = true,
	onCopy,
	copyButtonLabel,
	copyButtonClassName = "",
	copyButtonContent,
	extraActions,
}: ReadOnlyValueBlockProps) {
	const direction = getDocumentDirection();
	const copyButtonStyles =
		copyButtonClassName || "readonly-value-block-copy-default";

	return (
		<div dir={direction} className={cn("readonly-value-block", className)}>
			<div className="readonly-value-block-value">
				<span
					className={cn(
						"readonly-value-block-text",
						"preserve-ltr-value",
						monospace && "font-mono",
						valueClassName
					)}
				>
					{value || ""}
				</span>
			</div>
			<div className="readonly-value-block-actions">
				{onCopy ? (
					<button
						type="button"
						onClick={onCopy}
						aria-label={copyButtonLabel}
						title={copyButtonLabel}
						className={cn(
							"readonly-value-block-copy",
							copyButtonStyles
						)}
					>
						{copyButtonContent || <Copy size={14} />}
					</button>
				) : null}
				{extraActions}
			</div>
		</div>
	);
}
