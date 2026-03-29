// @ts-nocheck
'use client';

import { Fragment } from 'react';
import { Dialog, DialogPanel, Transition } from '@headlessui/react';
import { Button } from './Button';

/**
 * Reusable confirmation dialog using Headless UI Dialog.
 */
export function ConfirmDialog({
	open,
	onClose,
	title,
	description,
	children,
	confirmText = 'Confirm',
	cancelText = 'Cancel',
	onConfirm,
	confirmVariant = 'primary',
	loading = false,
}) {
	return (
		<Transition appear show={open} as={Fragment}>
			<Dialog as="div" className="relative z-50" onClose={onClose}>
				<Transition.Child
					as={Fragment}
					enter="ease-out duration-200"
					enterFrom="opacity-0"
					enterTo="opacity-100"
					leave="ease-in duration-150"
					leaveFrom="opacity-100"
					leaveTo="opacity-0"
				>
					<div className="fixed inset-0 bg-black/30" />
				</Transition.Child>

				<div className="fixed inset-0 overflow-y-auto">
					<div className="flex min-h-full items-center justify-center p-4 text-center">
						<Transition.Child
							as={Fragment}
							enter="ease-out duration-200"
							enterFrom="opacity-0 scale-95"
							enterTo="opacity-100 scale-100"
							leave="ease-in duration-150"
							leaveFrom="opacity-100 scale-100"
							leaveTo="opacity-0 scale-95"
						>
							<DialogPanel className="w-full max-w-lg transform overflow-hidden rounded-2xl bg-surface p-6 text-left shadow-xl transition-all border border-stroke">
								<Dialog.Title className="text-lg font-semibold text-heading mb-2">
									{title}
								</Dialog.Title>
								{description && (
									<p className="text-sm text-text-muted mb-4 whitespace-pre-line">
										{description}
									</p>
								)}
								{children}
								<div className="flex justify-end gap-2 mt-6">
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
										{loading ? 'Working...' : confirmText}
									</Button>
								</div>
							</DialogPanel>
						</Transition.Child>
					</div>
				</div>
			</Dialog>
		</Transition>
	);
}

export default ConfirmDialog;
