import { CircleAlert, CircleCheckBig } from "lucide-react";
import type { StatusMessagesProps } from "../types";

const StatusMessages = ({ error, success }: StatusMessagesProps) => {
	if (!error && !success) return null;
	return (
		<>
			{error && (
				<div className="links-form-status links-form-status-error">
					<p className="links-form-status-row links-form-status-error-text">
						<CircleAlert className="links-form-status-icon" />
						{error}
					</p>
				</div>
			)}
			{success && (
				<div className="links-form-status links-form-status-success">
					<p className="links-form-status-row links-form-status-success-text">
						<CircleCheckBig className="links-form-status-icon" />
						{success}
					</p>
				</div>
			)}
		</>
	);
};

export default StatusMessages;
