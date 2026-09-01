import { Upload } from "lucide-react";
import { __ } from "@/i18n";

const Header = () => {
	return (
		<div className="import-layout-header">
			<div className="import-layout-header-copy">
				<div className="import-layout-header-badge">
					<Upload size={13} />
					<span>{__("Data Import")}</span>
				</div>
				<h1 className="import-layout-title">{__("Import")}</h1>
				<p className="import-layout-copy">
					{__(
						"Bulk import short links into PeakURL from CSV, JSON, or XML files, pasted URL lists, or automated API payloads."
					)}
				</p>
			</div>
		</div>
	);
};

export default Header;
