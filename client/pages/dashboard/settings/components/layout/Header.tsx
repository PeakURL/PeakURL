import { PageHeader } from "@/components";
import { dashboardPageIcons } from "@/pages/dashboard/navigation";
import { __ } from "@/i18n";

export default function Header() {
	return (
		<PageHeader
			icon={dashboardPageIcons["settings"]}
			className="settings-page-header"
			title={__("Settings")}
			description={__(
				"Manage your account, integrations, and PeakURL installation."
			)}
		/>
	);
}
