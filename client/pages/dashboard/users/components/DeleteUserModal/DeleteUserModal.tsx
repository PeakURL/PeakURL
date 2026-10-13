import { ConfirmDialog } from "@/components";
import { __, sprintf } from "@/i18n";

import { getUserDisplayName } from "../../lib";
import type { DeleteUserModalProps } from "../../types";

export function DeleteUserModal({
	user,
	currentUser,
	onClose,
	onConfirm,
	isDeleting,
}: DeleteUserModalProps) {
	const displayName = getUserDisplayName(user);
	const isSelf = user?.id === currentUser?.id;

	return (
		<ConfirmDialog
			open={Boolean(user) && !isSelf}
			onClose={onClose}
			title={__("Delete user")}
			description={
				user
					? sprintf(
							__(
								'Are you sure you want to delete "%s"? Their short links, clicks, and analytics will be transferred to your administrator account. Active sessions, API keys, and personal webhooks will be permanently deleted.'
							),
							displayName
						)
					: ""
			}
			confirmText={__("Delete user")}
			confirmVariant="danger"
			onConfirm={onConfirm}
			loading={isDeleting}
		/>
	);
}
