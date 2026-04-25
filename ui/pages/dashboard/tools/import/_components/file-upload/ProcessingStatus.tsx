import { Cog, LoaderCircle } from "lucide-react";
import { __ } from "@/i18n";
import type { ProcessingStatusProps } from "./types";

function ProcessingStatus({ status, progress }: ProcessingStatusProps) {
	if (status === "uploading") {
		return (
			<div className="import-processing import-processing-upload">
				<LoaderCircle className="import-processing-upload-icon" />
				<h3 className="import-processing-title">
					{__("Uploading file...")}
				</h3>
				<p className="import-processing-copy">
					{__("Please wait while we process your file")}
				</p>
				{progress ? (
					<p className="import-processing-progress-copy">
						{`${progress}%`}
					</p>
				) : null}
			</div>
		);
	}
	if (status === "processing") {
		return (
			<div className="import-processing">
				<div className="import-processing-header">
					<Cog className="import-processing-icon" />
					<h3 className="import-processing-title">
						{__("Processing URLs")}
					</h3>
					<p className="import-processing-copy">
						{__("Creating short links...")}
					</p>
				</div>
				<div className="import-processing-bar">
					<div className="import-processing-bar-fill" />
				</div>
			</div>
		);
	}
	return null;
}

export default ProcessingStatus;
