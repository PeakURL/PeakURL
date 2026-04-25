import {
	Gauge,
	ArrowLeft,
	ArrowRight,
	Link2 as Link2Icon,
	Wrench,
	Settings,
	FileX,
} from "lucide-react";
import { Button } from "@/components";
import { Link, useNavigate } from "react-router-dom";
import { isDocumentRtl } from "@/i18n/direction";
import { __ } from "@/i18n";

const NotFoundPage = () => {
	const navigate = useNavigate();
	const isRtl = isDocumentRtl();
	const BackArrow = isRtl ? ArrowRight : ArrowLeft;

	return (
		<div className="not-found-page">
			<div className="not-found-page-inner">
				<div className="not-found-page-layout">
					<div className="not-found-page-graphic">
						<FileX
							size={40}
							className="not-found-page-graphic-icon"
							strokeWidth={2}
						/>
					</div>

					<h1 className="not-found-page-title">
						{__("Page Not Found")}
					</h1>

					<p className="not-found-page-summary">
						{__(
							"The admin page you're looking for doesn't exist. It might have been moved, deleted, or you may not have permission."
						)}
					</p>

					<div className="not-found-page-actions">
						<Button
							variant="secondary"
							onClick={() => navigate(-1)}
							className="not-found-page-action"
						>
							<BackArrow size={16} />
							{__("Go Back")}
						</Button>
						<Button
							variant="primary"
							onClick={() => navigate("/dashboard")}
							className="not-found-page-action"
						>
							<Gauge size={16} />
							{__("Go to Dashboard")}
						</Button>
					</div>

					<div className="not-found-page-links">
						<p className="not-found-page-links-title">
							{__("Popular Destinations")}
						</p>
						<div className="not-found-page-link-grid">
							<Link
								to="/dashboard/links"
								className="not-found-page-link"
							>
								<Link2Icon
									size={16}
									className="not-found-page-link-icon"
								/>
								{__("All Links")}
							</Link>
							<Link
								to="/dashboard/tools/import/file"
								className="not-found-page-link"
							>
								<Wrench
									size={16}
									className="not-found-page-link-icon"
								/>
								{__("Import")}
							</Link>
							<Link
								to="/dashboard/settings/general"
								className="not-found-page-link"
							>
								<Settings
									size={16}
									className="not-found-page-link-icon"
								/>
								{__("Settings")}
							</Link>
						</div>
					</div>
				</div>
			</div>
		</div>
	);
};

export default NotFoundPage;
