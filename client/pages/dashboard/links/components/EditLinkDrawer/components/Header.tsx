import { DialogTitle } from "@headlessui/react";
import { Link2, X } from "lucide-react";

import { __ } from "@/i18n";

interface HeaderProps {
	onClose: () => void;
}

function Header({ onClose }: HeaderProps) {
	return (
		<div className="drawer-header links-edit-drawer-header">
			<div className="drawer-heading-group">
				<span className="drawer-title-icon">
					<Link2 />
				</span>
				<div className="links-edit-drawer-header-copy">
					<DialogTitle className="drawer-title links-edit-drawer-title">
						{__("Edit Link")}
					</DialogTitle>
					<p className="links-edit-drawer-description">
						{__("Update link settings and sharing details.")}
					</p>
				</div>
			</div>
			<button
				type="button"
				onClick={onClose}
				className="drawer-icon-button links-edit-drawer-close"
			>
				<span className="sr-only">{__("Close panel")}</span>
				<X className="h-5 w-5" />
			</button>
		</div>
	);
}

export default Header;
