import { DialogTitle } from "@headlessui/react";
import { Link2, X } from "lucide-react";
import { __ } from "@/i18n";

interface HeaderProps {
	onClose: () => void;
}

function Header({ onClose }: HeaderProps) {
	return (
		<div className="links-edit-drawer-header">
			<div className="links-edit-drawer-header-copy">
				<DialogTitle className="links-edit-drawer-title">
					<span className="links-edit-drawer-title-icon">
						<Link2 className="h-4 w-4" />
					</span>
					{__("Edit Link")}
				</DialogTitle>
				<p className="links-edit-drawer-description">
					{__("Update link settings and sharing details.")}
				</p>
			</div>
			<button
				type="button"
				onClick={onClose}
				className="links-edit-drawer-close"
			>
				<span className="sr-only">{__("Close panel")}</span>
				<X className="h-5 w-5" />
			</button>
		</div>
	);
}

export default Header;
