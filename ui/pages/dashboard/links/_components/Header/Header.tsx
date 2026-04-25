import { PEAKURL_DOMAIN } from "@constants";
import { Link2, RefreshCw } from "lucide-react";
import { __ } from "@/i18n";
import type { LinksHeaderProps } from "../types";

const Header = ({ onRefresh, isRefreshing = false }: LinksHeaderProps) => {
	const refreshButton = (
		<button
			type="button"
			onClick={onRefresh}
			disabled={isRefreshing}
			className="links-header-refresh"
			aria-label={__("Refresh links")}
			title={__("Refresh links")}
		>
			<RefreshCw
				className={`links-header-refresh-icon ${
					isRefreshing ? "animate-spin" : ""
				}`}
			/>
		</button>
	);

	return (
		<div className="links-header">
			<div className="links-header-brand">
				<div className="links-header-brand-icon">
					<Link2 className="h-5 w-5 text-white" />
				</div>
				<div className="links-header-brand-copy">
					<h1 className="links-header-title">{__("Links")}</h1>
					<p className="links-header-description">
						{__("Manage and track your shortened URLs")}
					</p>
				</div>
			</div>

			<div className="links-header-actions">
				<div className="links-header-actions-mobile">
					{refreshButton}
				</div>
				<div className="links-header-actions-desktop">
					{refreshButton}
					<p className="links-header-domain">
						{__("Short links domain")}{" "}
						<span className="links-header-domain-value">
							{PEAKURL_DOMAIN}
						</span>
					</p>
				</div>
			</div>
		</div>
	);
};

export default Header;
