import { ArrowLeft, ArrowRight, Gauge } from "lucide-react";
import { Link, useNavigate } from "react-router";

import { Button } from "@/components";
import { StatusLayout } from "@/components/shared/StatusLayout";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";

const NotFoundPage = () => {
	const navigate = useNavigate();
	const BackArrow = isDocumentRtl() ? ArrowRight : ArrowLeft;

	return (
		<StatusLayout
			embedded
			label={__("404 · Missing page")}
			title={__("Page not found")}
			description={__(
				"We couldn’t find the dashboard page you’re looking for. Check the address, or return to your dashboard to keep going."
			)}
			actions={
				<>
					<Button icon={Gauge} onClick={() => navigate("/dashboard")}>
						{__("Go to Dashboard")}
					</Button>
					<Button
						variant="secondary"
						icon={BackArrow}
						onClick={() => navigate(-1)}
					>
						{__("Go Back")}
					</Button>
				</>
			}
		>
			<nav className="status-support" aria-label={__("Helpful links")}>
				<h2 className="status-support-title">
					{__("Pick up where you left off")}
				</h2>
				<div className="status-support-links">
					<Link to="/dashboard/links">{__("All Links")}</Link>
					<Link to="/dashboard/settings/general">
						{__("General Settings")}
					</Link>
				</div>
			</nav>
		</StatusLayout>
	);
};

export default NotFoundPage;
