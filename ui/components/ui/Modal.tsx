// @ts-nocheck
import { X } from 'lucide-react';

/**
 * Modal Component
 * A customizable modal dialog with backdrop.
 * @param {Object} props
 * @param {boolean} props.isOpen - Whether the modal is visible
 * @param {Function} props.onClose - Callback to close the modal
 * @param {string} props.title - Modal title
 * @param {React.ReactNode} props.children - Modal content
 * @param {('sm'|'md'|'lg'|'xl')} [props.size='md'] - Modal width
 */
export function Modal({ isOpen, onClose, title, children, size = 'md' }) {
	if (!isOpen) return null;

	const sizes = {
		sm: 'max-w-md',
		md: 'max-w-2xl',
		lg: 'max-w-4xl',
		xl: 'max-w-6xl',
	};

	return (
		<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
			{/* Backdrop */}
			<div className="absolute inset-0 bg-black/50" onClick={onClose} />

			{/* Modal */}
			<div
				className={`relative bg-surface rounded-2xl shadow-2xl w-full ${sizes[size]} max-h-[90vh] overflow-hidden border border-stroke`}
			>
				{title && (
					<div className="px-6 py-4 border-b border-stroke flex items-center justify-between">
						<h3 className="text-xl font-bold text-heading">
							{title}
						</h3>
						<button
							type="button"
							onClick={onClose}
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
