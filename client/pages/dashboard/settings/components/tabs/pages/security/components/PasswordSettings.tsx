import { LockKeyhole } from "lucide-react";
import { Button, Input } from "@/components";
import { SectionTitle } from "@/pages/dashboard/settings/components/SectionTitle";
import { __ } from "@/i18n";
import { cn } from "@/shared/formatting";
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
		<section className="settings-section">
			<div className="settings-section-copy settings-section-heading">
				<SectionTitle icon={LockKeyhole}>{__("Password")}</SectionTitle>
				<p className="settings-group-description">
					{__(
						"Use a strong, unique password to protect your account."
					)}
				</p>
			</div>

			<div className="settings-grid settings-password-fields">
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
					autoComplete="new-password"
					value={securityForm.newPassword}
					onChange={(event) =>
						updateSecurityForm("newPassword", event.target.value)
					}
				/>
				<Input
					label={__("Confirm New Password")}
					type="password"
					autoComplete="new-password"
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
		</section>
	);
}

export default PasswordSettings;
