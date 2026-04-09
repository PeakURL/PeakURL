import { X } from 'lucide-react';
import { getDocumentDirection } from '@/i18n/direction';
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
		sm: 'max-w-md',
		md: 'max-w-2xl',
		lg: 'max-w-4xl',
		xl: 'max-w-6xl',
	};

	return (
		<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
			<div
				className="absolute inset-0 bg-black/50"
				onClick={() => onClose()}
			/>

			<div
				dir={direction}
				className={`text-inline-start relative w-full overflow-hidden rounded-2xl border border-stroke bg-surface shadow-2xl ${sizes[size]} max-h-[90vh]`}
			>
				{title && (
					<div className="px-6 py-4 border-b border-stroke flex items-center justify-between">
						<h3 className="text-xl font-bold text-heading">
							{title}
						</h3>
						<button
							type="button"
							onClick={() => onClose()}
							className="p-2 text-text-muted hover:text-heading rounded-lg hover:bg-surface-alt transition-colors"
						>
							<X size={20} />
						</button>
					</div>
				)}
				<div className="overflow-y-auto max-h-[70vh] p-6">
					{children}
				</div>
			</div>
		</div>
	);
}
