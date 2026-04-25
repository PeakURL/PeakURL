import { __ } from "@/i18n";

const Header = () => {
	return (
		<div className="import-layout-header">
			<h1 className="import-layout-title">{__("Import")}</h1>
			<p className="import-layout-copy">
				{__("Import links from files, pasted URLs, or API payloads")}
			</p>
		</div>
	);
};

export default Header;
