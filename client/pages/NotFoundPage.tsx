import { ArrowLeft, ArrowRight, Gauge } from "lucide-react";
import { useNavigate } from "react-router";

import { Button, PageIllustration } from "@/components";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";

const NotFoundPage = () => {
	const navigate = useNavigate();
	const BackArrow = isDocumentRtl() ? ArrowRight : ArrowLeft;

	return (
		<section className="dashboard-not-found" aria-labelledby="page-heading">
			<div className="dashboard-not-found-content">
				<PageIllustration
					name="missing-page"
					className="dashboard-not-found-artwork"
				/>
				<p className="dashboard-not-found-label">404</p>
				<h1 id="page-heading" className="dashboard-not-found-title">
					{__("Page not found")}
				</h1>
				<p className="dashboard-not-found-description">
					{__(
						"This dashboard page doesn’t exist. Check the address, or return to your dashboard."
					)}
				</p>
				<div className="dashboard-not-found-actions">
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
				</div>
			</div>
		</section>
	);
};

export default NotFoundPage;
