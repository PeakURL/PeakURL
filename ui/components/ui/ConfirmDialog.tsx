import { Fragment } from 'react';
import {
	Dialog,
	DialogPanel,
	DialogTitle,
	Transition,
	TransitionChild,
} from '@headlessui/react';
import { __ } from '@/i18n';
import { getDocumentDirection } from '@/i18n/direction';
import { Button } from './Button';
import type { ConfirmDialogProps } from './types';
export type { ConfirmDialogProps, ConfirmVariant } from './types';

/**
 * Reusable confirmation dialog using Headless UI.
 *
 * @param props Dialog props
 * @param props.open Whether the dialog is visible
 * @param props.onClose Callback used to close the dialog
 * @param props.onConfirm Confirmation callback
 */
export function ConfirmDialog({
	open,
	onClose,
	title,
	description,
	children,
	confirmText = __('Confirm'),
	cancelText = __('Cancel'),
	onConfirm,
	confirmVariant = 'primary',
	loading = false,
}: ConfirmDialogProps) {
	const direction = getDocumentDirection();

	return (
		<Transition appear show={open} as={Fragment}>
			<Dialog as="div" className="relative z-50" onClose={onClose}>
				<TransitionChild
					as={Fragment}
					enter="ease-out duration-200"
					enterFrom="opacity-0"
					enterTo="opacity-100"
					leave="ease-in duration-150"
					leaveFrom="opacity-100"
					leaveTo="opacity-0"
				>
					<div className="fixed inset-0 bg-black/30" />
				</TransitionChild>

				<div className="fixed inset-0 overflow-y-auto">
					<div className="flex min-h-full items-center justify-center p-4 text-center">
						<TransitionChild
							as={Fragment}
							enter="ease-out duration-200"
							enterFrom="opacity-0 scale-95"
							enterTo="opacity-100 scale-100"
							leave="ease-in duration-150"
							leaveFrom="opacity-100 scale-100"
							leaveTo="opacity-0 scale-95"
						>
							<DialogPanel
								dir={direction}
								className="text-inline-start w-full max-w-lg transform overflow-hidden rounded-2xl border border-stroke bg-surface p-6 shadow-xl transition-all"
							>
								<DialogTitle className="mb-2 text-lg font-semibold text-heading">
									{title}
								</DialogTitle>
								{description && (
									<p className="mb-4 whitespace-pre-line text-sm text-text-muted">
										{description}
									</p>
								)}
								{children}
								<div dir={direction} className="mt-6 flex justify-end gap-2">
									<Button
										variant="secondary"
										onClick={onClose}
										disabled={loading}
									>
										{cancelText}
									</Button>
									<Button
										variant={confirmVariant}
										onClick={onConfirm}
										disabled={loading}
									>
										{loading
											? __('Working...')
											: confirmText}
									</Button>
								</div>
							</DialogPanel>
						</TransitionChild>
					</div>
				</div>
			</Dialog>
		</Transition>
	);
}

export default ConfirmDialog;
