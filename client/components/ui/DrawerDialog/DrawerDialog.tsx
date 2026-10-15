import { Dialog, Transition } from "@headlessui/react";
import { Fragment, type ReactNode } from "react";

interface DrawerDialogProps {
	open: boolean;
	onClose: () => void;
	children: ReactNode;
}

/** Animate the first opening and retain drawer content until its exit completes. */
export function DrawerDialog({ open, onClose, children }: DrawerDialogProps) {
	return (
		<Transition appear show={open} as={Fragment}>
			<Dialog onClose={onClose} className="drawer-dialog">
				{children}
			</Dialog>
		</Transition>
	);
}
