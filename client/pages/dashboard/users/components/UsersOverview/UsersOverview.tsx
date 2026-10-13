import { Plus, Shield, UserRound } from "lucide-react";

import { Button } from "@/components";
import { __ } from "@/i18n";

import type { UsersOverviewProps } from "../../types";

export function UsersOverview({
	items,
	onAddUserClick,
	onManageRolesClick,
}: UsersOverviewProps) {
	return (
		<div className="space-y-4">
			<div className="users-page-hero">
				<div className="users-page-hero-copy">
					<div className="users-page-hero-badge">
						<UserRound size={13} />
						<span>{__("User Management")}</span>
					</div>
					<h1 className="users-page-title">{__("Users")}</h1>
					<p className="users-page-summary">
						{__(
							"Manage user accounts and access permissions. Administrators have complete access to system settings, while Editors manage short links."
						)}
					</p>
				</div>
				<div className="users-page-hero-actions">
					{onManageRolesClick ? (
						<Button
							variant="outline"
							onClick={onManageRolesClick}
							className="users-page-hero-action-button"
						>
							<Shield size={16} />
							<span>{__("Manage Roles & Capabilities")}</span>
						</Button>
					) : null}
					<Button
						onClick={onAddUserClick}
						className="users-page-hero-action-button"
					>
						<Plus size={16} />
						<span>{__("Add User")}</span>
					</Button>
				</div>
			</div>

			<div className="users-page-overview">
				<div className="users-page-overview-grid">
					{items.map((item) => {
						const Icon = item.icon;
						return (
							<div
								key={item.key}
								className="users-page-overview-item"
							>
								<div className="users-page-overview-header">
									<div className="users-page-overview-copy">
										<p className="users-page-overview-title">
											{item.label}
										</p>
										<p
											className={
												item.isTextValue
													? "users-page-overview-value-text"
													: "users-page-overview-value"
											}
											title={
												item.isTextValue
													? String(item.value)
													: undefined
											}
										>
											{item.value}
										</p>
									</div>
									<div
										className={`users-page-overview-icon users-page-overview-icon-${item.iconTone}`}
									>
										<Icon className="users-page-overview-icon-glyph" />
									</div>
								</div>
								<p className="users-page-overview-note">
									{item.note}
								</p>
							</div>
						);
					})}
				</div>
			</div>
		</div>
	);
}
