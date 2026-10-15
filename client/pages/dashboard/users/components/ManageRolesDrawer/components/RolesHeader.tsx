import { DialogTitle } from "@headlessui/react";
import { Shield, X } from "lucide-react";

import { __ } from "@/i18n";

export interface RolesHeaderProps {
	onClose: () => void;
	disabled?: boolean;
}

export function RolesHeader({ onClose, disabled }: RolesHeaderProps) {
	return (
		<div className="drawer-header users-roles-drawer-header">
			<div className="drawer-heading-group">
				<div className="drawer-title-icon users-roles-drawer-title-icon">
					<Shield className="w-5 h-5 text-accent" />
				</div>
				<div className="min-w-0 flex-1 text-start">
					<DialogTitle
						as="h2"
						className="drawer-title users-roles-drawer-title"
					>
						{__("Manage Roles & Capabilities")}
					</DialogTitle>
					<p className="users-roles-drawer-subtitle">
						{__(
							"Configure permitted capabilities for site roles. Changes take effect immediately."
						)}
					</p>
				</div>
			</div>

			<button
				type="button"
				onClick={onClose}
				className="drawer-icon-button users-roles-drawer-close"
				aria-label={__("Close roles drawer")}
				title={__("Close roles drawer")}
				disabled={disabled}
			>
				<X size={18} />
			</button>
		</div>
	);
}
