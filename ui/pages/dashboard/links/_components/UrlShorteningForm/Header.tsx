import { Scissors } from "lucide-react";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";

const Header = () => {
	const direction = isDocumentRtl() ? "rtl" : "ltr";

	return (
		<div dir={direction} className="links-form-header">
			<div className="links-form-header-icon">
				<Scissors className="text-white" size={20} />
			</div>
			<div className="links-form-header-copy">
				<h3 className="links-form-header-title">
					{__("Create Short Link")}
				</h3>
				<p className="links-form-header-description">
					{__("Transform your long URL into a short, shareable link")}
				</p>
			</div>
		</div>
	);
};

export default Header;
