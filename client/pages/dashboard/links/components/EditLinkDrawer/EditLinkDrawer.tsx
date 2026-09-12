import { useGetUrlQuery } from "@/state/slices/api";

import type { EditLinkDrawerProps } from "../types";
import Form from "./components/Form";
import { getEditLinkDrawerKey } from "./components/helpers";

function EditLinkDrawer({ open, setOpen, link }: EditLinkDrawerProps) {
	const selectedLinkId = link?.id || "";
	const { data: linkDetailsRes } = useGetUrlQuery(selectedLinkId, {
		skip: !open || !selectedLinkId,
		refetchOnMountOrArgChange: true,
	});
	const activeLink = linkDetailsRes?.data || link;

	if (!activeLink) return null;

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
