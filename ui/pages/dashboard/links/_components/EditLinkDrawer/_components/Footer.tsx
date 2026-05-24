import { Save } from "lucide-react";

import { __ } from "@/i18n";

interface FooterProps {
	isLoading: boolean;
	onCancel: () => void;
}

function Footer({ isLoading, onCancel }: FooterProps) {
	return (
		<div className="links-edit-drawer-footer">
			<button
				type="button"
				onClick={onCancel}
				className="links-edit-drawer-footer-button links-edit-drawer-footer-button-secondary"
			>
				{__("Cancel")}
			</button>
			<button
				type="submit"
				disabled={isLoading}
				className="links-edit-drawer-footer-button links-edit-drawer-footer-button-primary"
			>
				{isLoading ? (
					<span className="links-modal-button-content">
						<span className="links-modal-spinner" />
						{__("Saving...")}
					</span>
				) : (
					<span className="links-modal-button-content">
						<Save className="links-modal-button-icon" />
						{__("Save Changes")}
					</span>
				)}
			</button>
		</div>
	);
}

export default Footer;
