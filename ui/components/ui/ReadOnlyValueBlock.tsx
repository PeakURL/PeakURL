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
			className={`readonly-value-block ${className}`}
		>
			<div className="readonly-value-block-value">
				<span
					className={`readonly-value-block-text preserve-ltr-value ${
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
					className={`readonly-value-block-copy ${copyButtonStyles}`}
				>
					{copyButtonContent || <Copy size={14} />}
				</button>
			) : null}
		</div>
	);
}
