import { useRef } from "react";
import { ArrowLeft, ArrowRight, Gauge } from "lucide-react";
import { useNavigate } from "react-router";

import { Button, PageIllustration } from "@/components";
import { useFloatingArtwork, useRevealSequence } from "@/hooks";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";

const NOT_FOUND_REVEAL_GROUPS = [
	{
		selector:
			".dashboard-not-found-label, .dashboard-not-found-title, .dashboard-not-found-description, .dashboard-not-found-actions",
		to: { duration: 0.36, stagger: 0.05, delay: 0.06 },
	},
];

const NotFoundPage = () => {
	const sectionRef = useRef<HTMLElement>(null);
	const navigate = useNavigate();
	const BackArrow = isDocumentRtl() ? ArrowRight : ArrowLeft;

	useRevealSequence(sectionRef, NOT_FOUND_REVEAL_GROUPS);
	useFloatingArtwork(
		sectionRef,
		".dashboard-not-found-artwork",
		"missing-page"
	);

	return (
		<section
			ref={sectionRef}
			className="dashboard-not-found"
			aria-labelledby="page-heading"
		>
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
