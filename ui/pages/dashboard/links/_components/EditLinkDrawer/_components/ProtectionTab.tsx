import { Input } from "@/components";
import { __ } from "@/i18n";

interface ProtectionTabProps {
	password: string;
	setPassword: (value: string) => void;
	clearPassword: boolean;
	setClearPassword: (value: boolean) => void;
	hasExistingPassword: boolean;
}

function ProtectionTab({
	password,
	setPassword,
	clearPassword,
	setClearPassword,
	hasExistingPassword,
}: ProtectionTabProps) {
	return (
		<>
			<Input
				label={__("Password Protection (Optional)")}
				type="password"
				value={password}
				disabled={clearPassword}
				onChange={(event) => setPassword(event.target.value)}
				placeholder={
					hasExistingPassword
						? __(
								"Enter a new password to replace the current one"
							)
						: __("Set a password to protect this link")
				}
				className="form-control-surface-alt form-control-compact form-control-strong-focus"
			/>
			{hasExistingPassword && (
				<div className="links-edit-drawer-password-options">
					<p className="links-edit-drawer-help">
						{__("Leave this blank to keep the current password.")}
					</p>
					<label className="links-edit-drawer-checkbox-label">
						<input
							type="checkbox"
							checked={clearPassword}
							onChange={(event) => {
								const shouldClearPassword =
									event.target.checked;

								setClearPassword(shouldClearPassword);

								if (shouldClearPassword) {
									setPassword("");
								}
							}}
							className="links-checkbox"
						/>
						{__("Remove password protection")}
					</label>
				</div>
			)}
		</>
	);
}

export default ProtectionTab;
