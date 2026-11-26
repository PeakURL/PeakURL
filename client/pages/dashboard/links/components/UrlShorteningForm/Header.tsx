import { __ } from "@/i18n";

export default function Header() {
	return (
		<div className="links-form-header">
			<h2 className="links-form-header-title">
				{__("Create Short Link")}
			</h2>
			<p className="links-form-header-description">
				{__("Transform your long URL into a short, shareable link")}
			</p>
		</div>
	);
}
