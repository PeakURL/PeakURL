import { Link2 } from "lucide-react";
import { __ } from "@/i18n";

function EmptyState() {
	return (
		<div className="links-empty-state">
			<div className="links-empty-state-icon-wrap">
				<Link2 className="h-8 w-8 text-accent" />
			</div>
			<h3 className="links-empty-state-title">{__("No links yet")}</h3>
			<p className="links-empty-state-description">
				{__("Create your first shortened link to get started")}
			</p>
		</div>
	);
}

export default EmptyState;
