import { useEffect } from "react";
import { DialogBackdrop, DialogPanel } from "@headlessui/react";

import { DrawerDialog } from "@/components";
import { isDocumentRtl } from "@/i18n/direction";

import { useAdminAccess } from "@/hooks";
import { useGetUrlQuery } from "@/state/slices/api";

import type { EditLinkDrawerProps } from "../types";
import Form from "./components/Form";
import { getEditLinkDrawerKey } from "./components/helpers";

function EditLinkDrawer({ open, setOpen, link }: EditLinkDrawerProps) {
	const { canEditLinks, canEditAnyLink, isAdmin, user } = useAdminAccess();
	const selectedLinkId = link?.id || "";
	const isInitialOwner = Boolean(user?.id && link?.userId === user.id);
	const canInitiallyEdit = Boolean(
		canEditLinks && (isInitialOwner || canEditAnyLink || isAdmin)
	);

	const { data: linkDetailsRes } = useGetUrlQuery(selectedLinkId, {
		skip: !open || !selectedLinkId || !canInitiallyEdit,
		refetchOnMountOrArgChange: true,
	});
	const activeLink = linkDetailsRes?.data || link;

	const isOwner = Boolean(user?.id && activeLink?.userId === user.id);
	const canEdit = Boolean(
		canEditLinks && (isOwner || canEditAnyLink || isAdmin)
	);

	useEffect(() => {
		if (open && activeLink && !canEdit) {
			setOpen(false);
		}
	}, [open, activeLink, canEdit, setOpen]);

	if (!activeLink || !canEdit) return null;

	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";

	return (
		<DrawerDialog open={open} onClose={() => setOpen(false)}>
			<DialogBackdrop transition className="dialog-backdrop" />
			<div className="fixed inset-0 overflow-hidden">
				<div className="absolute inset-0 overflow-hidden">
					<div
						className={`links-edit-drawer-layout ${isRtl ? "links-edit-drawer-layout-rtl" : "links-edit-drawer-layout-ltr"}`}
					>
						<DialogPanel
							dir={direction}
							transition
							className={`drawer-panel drawer-transition links-edit-drawer-panel ${isRtl ? "data-closed:-translate-x-full" : "data-closed:translate-x-full"}`}
						>
							<Form
								key={getEditLinkDrawerKey(activeLink)}
								setOpen={setOpen}
								link={activeLink}
							/>
						</DialogPanel>
					</div>
				</div>
			</div>
		</DrawerDialog>
	);
}

export default EditLinkDrawer;
