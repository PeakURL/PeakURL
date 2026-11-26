import { Fragment } from "react";
import {
	Dialog,
	DialogBackdrop,
	DialogDescription,
	DialogPanel,
	DialogTitle,
	Transition,
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
				<DialogBackdrop transition className="dialog-backdrop" />

				<div className="confirm-dialog-viewport">
					<div className="confirm-dialog-shell">
						<DialogPanel
							transition
							inert={!open}
							dir={direction}
							className="confirm-dialog-panel dialog-transition"
						>
							{title ? (
								<DialogTitle className="confirm-dialog-title">
									{title}
								</DialogTitle>
							) : null}
							{description && (
								<DialogDescription className="confirm-dialog-description">
									{description}
								</DialogDescription>
							)}
							{children}
							{!hideActions ? (
								<div
									dir={direction}
									className="confirm-dialog-actions"
								>
									<Button
										data-autofocus
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
					</div>
				</div>
			</Dialog>
		</Transition>
	);
}

export default ConfirmDialog;
