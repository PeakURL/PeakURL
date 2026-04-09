import type { ReactNode } from 'react';
import { Copy } from 'lucide-react';
import { getDocumentDirection } from '@/i18n/direction';

interface ReadOnlyValueBlockProps {
	value?: string | null;
	className?: string;
	valueClassName?: string;
	monospace?: boolean;
	onCopy?: () => void;
	copyButtonLabel?: string;
	copyButtonClassName?: string;
	copyButtonContent?: ReactNode;
}

export function ReadOnlyValueBlock({
	value,
	className = '',
	valueClassName = '',
	monospace = true,
	onCopy,
	copyButtonLabel,
	copyButtonClassName = '',
	copyButtonContent,
}: ReadOnlyValueBlockProps) {
	const direction = getDocumentDirection();
	const copyButtonStyles =
		copyButtonClassName ||
		'bg-surface text-text-muted shadow-sm hover:text-heading hover:shadow';

	return (
		<div
			dir={direction}
			className={`flex items-start gap-2 rounded-lg border border-stroke bg-surface-alt px-3 py-3 ${className}`}
		>
			<div className="text-inline-start min-w-0 flex-1">
				<span
					className={`preserve-ltr-value inline-block max-w-full break-all text-sm text-heading ${
						monospace ? 'font-mono' : ''
					} ${valueClassName}`}
				>
					{value || ''}
				</span>
			</div>
			{onCopy ? (
				<button
					type="button"
					onClick={onCopy}
					aria-label={copyButtonLabel}
					title={copyButtonLabel}
					className={`shrink-0 rounded p-1.5 transition-all ${copyButtonStyles}`}
				>
					{copyButtonContent || <Copy size={14} />}
				</button>
			) : null}
		</div>
	);
}
