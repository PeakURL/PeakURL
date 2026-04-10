import { X } from 'lucide-react';
import { getDocumentDirection } from '@/i18n/direction';
import { cn } from '@/utils';
import type { ModalProps, ModalSize } from './types';
export type { ModalProps, ModalSize } from './types';

/**
 * Modal dialog with a backdrop and configurable width.
 *
 * @param props Modal props
 * @param props.isOpen Whether the modal is visible
 * @param props.onClose Callback used to close the modal
 * @param props.title Optional modal title
 * @param props.children Modal content
 * @param props.size Width preset
 */
export function Modal({
	isOpen,
	onClose,
	title,
	children,
	size = 'md',
}: ModalProps) {
	if (!isOpen) return null;
	const direction = getDocumentDirection();

	const sizes: Record<ModalSize, string> = {
		sm: 'modal-panel-sm',
		md: 'modal-panel-md',
		lg: 'modal-panel-lg',
		xl: 'modal-panel-xl',
	};

	return (
		<div className="modal-shell">
			<div
				className="modal-backdrop"
				onClick={() => onClose()}
			/>

			<div
				dir={direction}
				className={cn('modal-panel', sizes[size])}
			>
				{title && (
					<div className="modal-header">
						<h3 className="modal-title">
							{title}
						</h3>
						<button
							type="button"
							onClick={() => onClose()}
							className="modal-close"
						>
							<X size={20} />
						</button>
					</div>
				)}
				<div className="modal-content">
					{children}
				</div>
			</div>
		</div>
	);
}
