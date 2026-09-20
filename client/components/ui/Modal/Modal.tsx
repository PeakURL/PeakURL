import { Dialog, DialogPanel, DialogTitle } from "@headlessui/react";
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
}: ModalProps) {
	const direction = getDocumentDirection();

	const sizes: Record<ModalSize, string> = {
		sm: "modal-panel-sm",
		md: "modal-panel-md",
		lg: "modal-panel-lg",
		xl: "modal-panel-xl",
	};

	return (
		<Dialog open={isOpen} onClose={onClose} className="modal-root">
			<div className="modal-backdrop" aria-hidden="true" />

			<div className="modal-shell">
				<DialogPanel
					dir={direction}
					className={cn("modal-panel", sizes[size])}
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
					<div className="modal-content">{children}</div>
				</DialogPanel>
			</div>
		</Dialog>
	);
}
