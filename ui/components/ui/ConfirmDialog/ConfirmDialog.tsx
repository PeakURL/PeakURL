import { Fragment } from "react";
import {
	Dialog,
	DialogPanel,
	DialogTitle,
	Transition,
	TransitionChild,
} from "@headlessui/react";
import { __ } from "@/i18n";
import { getDocumentDirection } from "@/i18n/direction";
import { Button } from "../Button";
import type { ConfirmDialogProps } from "../types";
export type { ConfirmDialogProps, ConfirmVariant } from "../types";

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
	confirmText = __("Confirm"),
	cancelText = __("Cancel"),
	onConfirm,
	confirmVariant = "primary",
	loading = false,
	hideActions = false,
}: ConfirmDialogProps) {
	const direction = getDocumentDirection();

	return (
		<Transition appear show={open} as={Fragment}>
			<Dialog as="div" className="confirm-dialog-root" onClose={onClose}>
				<TransitionChild
					as={Fragment}
					enter="ease-out duration-200"
					enterFrom="opacity-0"
					enterTo="opacity-100"
					leave="ease-in duration-150"
					leaveFrom="opacity-100"
					leaveTo="opacity-0"
				>
					<div className="confirm-dialog-backdrop" />
				</TransitionChild>

				<div className="confirm-dialog-viewport">
					<div className="confirm-dialog-shell">
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
								className="confirm-dialog-panel"
							>
								{title ? (
									<DialogTitle className="confirm-dialog-title">
										{title}
									</DialogTitle>
								) : null}
								{description && (
									<p className="confirm-dialog-description">
										{description}
									</p>
								)}
								{children}
								{!hideActions ? (
									<div
										dir={direction}
										className="confirm-dialog-actions"
									>
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
												? __("Working...")
												: confirmText}
										</Button>
									</div>
								) : null}
							</DialogPanel>
						</TransitionChild>
					</div>
				</div>
			</Dialog>
		</Transition>
	);
}

export default ConfirmDialog;
