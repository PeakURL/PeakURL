import { Plus, Shield } from "lucide-react";

import { Button, PageHeader, Skeleton } from "@/components";
import { __ } from "@/i18n";

import type { UsersOverviewProps } from "../../types";

export function UsersOverview({
	items,
	isLoading,
	onAddUserClick,
	onManageRolesClick,
}: UsersOverviewProps) {
	return (
		<div className="space-y-5">
			<PageHeader
				title={__("Users")}
				description={__(
					"Manage user accounts and access permissions. Administrators have complete access to system settings, while Editors manage short links."
				)}
				actions={
					<div className="users-page-hero-actions">
						{onManageRolesClick ? (
							<Button
								variant="outline"
								onClick={onManageRolesClick}
								disabled={isLoading}
								className="users-page-hero-action-button"
							>
								<Shield size={16} />
								<span>{__("Manage Roles & Capabilities")}</span>
								<span className="users-page-roles-badge-new">
									{__("New")}
								</span>
							</Button>
						) : null}
						<Button
							onClick={onAddUserClick}
							disabled={isLoading}
							className="users-page-hero-action-button"
						>
							<Plus size={16} />
							<span>{__("Add User")}</span>
						</Button>
					</div>
				}
			/>

			<div className="users-page-overview" aria-busy={isLoading}>
				<div className="users-page-overview-grid">
					{items.map((item) => {
						const Icon = item.icon;
						return (
							<div
								key={item.key}
								className="users-page-overview-item"
							>
								<div className="users-page-overview-header">
									{isLoading ? (
										<>
											<div
												className="users-page-overview-copy"
												aria-hidden="true"
											>
												<Skeleton className="users-skeleton-stat-label" />
												<Skeleton className="users-skeleton-stat-value" />
											</div>
											<Skeleton
												className="users-page-overview-icon"
												aria-hidden="true"
											/>
										</>
									) : (
										<>
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
										</>
									)}
								</div>
								{isLoading ? (
									<Skeleton
										className="users-skeleton-stat-note"
										aria-hidden="true"
									/>
								) : (
									<p className="users-page-overview-note">
										{item.note}
									</p>
								)}
							</div>
						);
					})}
				</div>
			</div>
		</div>
	);
}
