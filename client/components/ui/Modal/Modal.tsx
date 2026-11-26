import {
	Dialog,
	DialogBackdrop,
	DialogPanel,
	DialogTitle,
	Transition,
} from "@headlessui/react";
import { X } from "lucide-react";

import { __ } from "@/i18n";
import { getDocumentDirection } from "@/i18n/direction";
import { cn } from "@/shared/formatting";

import type { ModalProps, ModalSize } from "../types";

export type { ModalProps, ModalSize } from "../types";

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
	size = "md",
	panelClassName,
	afterLeave,
}: ModalProps) {
	const direction = getDocumentDirection();

	const sizes: Record<ModalSize, string> = {
		sm: "modal-panel-sm",
		md: "modal-panel-md",
		lg: "modal-panel-lg",
		xl: "modal-panel-xl",
	};

	return (
		<Transition appear show={isOpen} afterLeave={afterLeave}>
			<Dialog onClose={onClose} className="modal-root">
				<DialogBackdrop transition className="dialog-backdrop" />

				<div className="modal-position">
					<DialogPanel
						transition
						inert={!isOpen}
						dir={direction}
						className={cn(
							"modal-panel dialog-transition",
							sizes[size],
							panelClassName
						)}
					>
						{title && (
							<div className="modal-header">
								<DialogTitle className="modal-title">
									{title}
								</DialogTitle>
								<button
									type="button"
									onClick={onClose}
									className="modal-close"
									aria-label={__("Close modal")}
								>
									<X size={20} />
								</button>
							</div>
						)}
						{title ? (
							<div className="modal-content">{children}</div>
						) : (
							children
						)}
					</DialogPanel>
				</div>
			</Dialog>
		</Transition>
	);
}
