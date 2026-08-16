import { Button, Input } from "@/components";
import { __ } from "@/i18n";
import { cn } from "@/utils";
import type { SecurityFormState } from "../../types";
import type { PasswordSettingsProps } from "../types";

/**
 * Renders the password update form inside the security settings tab.
 */
function PasswordSettings({
	securityForm,
	setSecurityForm,
	onSubmit,
	isUpdating,
	isRtl,
}: PasswordSettingsProps) {
	const updateSecurityForm = (
		field: keyof SecurityFormState,
		value: string
	) => {
		setSecurityForm({
			...securityForm,
			[field]: value,
		});
	};

	return (
		<fieldset className="settings-fieldset">
			<legend className="settings-legend">{__("Password")}</legend>
			<hr className="settings-separator" />
			<div className="flex flex-col gap-5 max-w-md">
				<Input
					label={__("Current Password")}
					type="password"
					value={securityForm.currentPassword}
					autoComplete="current-password"
					onChange={(event) =>
						updateSecurityForm(
							"currentPassword",
							event.target.value
						)
					}
				/>
				<Input
					label={__("New Password")}
					type="password"
					value={securityForm.newPassword}
					onChange={(event) =>
						updateSecurityForm("newPassword", event.target.value)
					}
				/>
				<Input
					label={__("Confirm New Password")}
					type="password"
					value={securityForm.confirmPassword}
					onChange={(event) =>
						updateSecurityForm(
							"confirmPassword",
							event.target.value
						)
					}
				/>
			</div>
			<div
				className={cn(
					"settings-security-password-actions",
					isRtl
						? "settings-security-password-actions-start"
						: "settings-security-password-actions-end"
				)}
			>
				<Button size="sm" onClick={onSubmit} disabled={isUpdating}>
					{isUpdating ? __("Updating...") : __("Update Password")}
				</Button>
			</div>
		</fieldset>
	);
}

export default PasswordSettings;
