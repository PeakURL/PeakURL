import { useEffect } from "react";

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

	if (!open || !activeLink || !canEdit) return null;

	return (
		<Form
			key={getEditLinkDrawerKey(activeLink)}
			open={open}
			setOpen={setOpen}
			link={activeLink}
		/>
	);
}

export default EditLinkDrawer;
