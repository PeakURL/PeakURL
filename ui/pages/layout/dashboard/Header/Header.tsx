import {
	Menu as MenuIcon,
	ChevronDown,
	Clock3,
	User,
	Settings,
	LogOut,
} from "lucide-react";
import { useLogoutMutation } from "@/store/slices/api";
import { authApi } from "@/store/slices";
import { Avatar, ThemeToggle } from "@/components";
import { useNavigate } from "react-router-dom";
import { Menu, MenuButton, MenuItem, MenuItems } from "@headlessui/react";
import { getDocumentDirection } from "@/i18n/direction";
import { __ } from "@/i18n";
import { cn } from "@/utils";
import { Search } from "../Search";
import type { HeaderProps } from "../types";

export const Header = ({ onMobileMenuToggle }: HeaderProps) => {
	const direction = getDocumentDirection();
	const { useAuthCheckQuery } = authApi;
	const { data: sessionData } = useAuthCheckQuery(undefined);
	const user = sessionData?.data ?? sessionData?.user;
	const [logout, { isLoading: isLoggingOut }] = useLogoutMutation();
	const navigate = useNavigate();
	const basePath = "/dashboard";

	const getRoleLabel = (role?: string | null) => {
		if (role === "admin") return __("Admin");
		if (role === "editor") return __("Editor");
		return __("User");
	};

	const handleLogout = async () => {
		try {
			await logout(undefined).unwrap();
			navigate("/login", { replace: true });
		} catch (error) {
			console.error("Logout failed:", error);
		}
	};

	return (
		<div className="dashboard-header">
			<div className="dashboard-header-inner">
				<button
					onClick={onMobileMenuToggle}
					className="dashboard-header-menu-button"
				>
					<MenuIcon
						size={20}
						className="dashboard-header-menu-icon"
					/>
				</button>

				<Search />

				<div className="dashboard-header-actions">
					<ThemeToggle />

					<Menu as="div" className="dashboard-header-user-menu">
						<MenuButton className="dashboard-header-user-trigger">
							<Avatar
								size="sm"
								email={user?.email}
								firstName={user?.firstName}
								lastName={user?.lastName}
								fallbackName={user?.username || __("Admin")}
							/>
							<div className="dashboard-header-user-details">
								<div className="dashboard-header-user-name">
									{user
										? `${user.firstName} ${user.lastName}`
										: __("Admin Account")}
								</div>
								<div className="dashboard-header-user-role">
									{user
										? getRoleLabel(user.role)
										: __("Admin")}
								</div>
							</div>
							<ChevronDown
								size={16}
								className="dashboard-header-user-caret"
							/>
						</MenuButton>

						<MenuItems
							dir={direction}
							className="dashboard-header-user-panel"
						>
							<div className="dashboard-header-user-panel-inner">
								<MenuItem>
									{({ focus }) => (
										<button
											onClick={() =>
												navigate(`${basePath}/activity`)
											}
											className={cn(
												"dashboard-header-user-item",
												focus &&
													"dashboard-header-user-item-active"
											)}
										>
											<Clock3
												size={16}
												className="dashboard-header-user-item-icon"
											/>
											{__("Activity")}
										</button>
									)}
								</MenuItem>
								<MenuItem>
									{({ focus }) => (
										<button
											onClick={() =>
												navigate(
													`${basePath}/settings/general`
												)
											}
											className={cn(
												"dashboard-header-user-item",
												focus &&
													"dashboard-header-user-item-active"
											)}
										>
											<User
												size={16}
												className="dashboard-header-user-item-icon"
											/>
											{__("Profile")}
										</button>
									)}
								</MenuItem>
								<MenuItem>
									{({ focus }) => (
										<button
											onClick={() =>
												navigate(`${basePath}/settings`)
											}
											className={cn(
												"dashboard-header-user-item",
												focus &&
													"dashboard-header-user-item-active"
											)}
										>
											<Settings
												size={16}
												className="dashboard-header-user-item-icon"
											/>
											{__("Settings")}
										</button>
									)}
								</MenuItem>
								<div className="dashboard-header-user-divider" />
								<MenuItem>
									{({ focus }) => (
										<button
											onClick={handleLogout}
											disabled={isLoggingOut}
											className={cn(
												"dashboard-header-user-item",
												"dashboard-header-user-item-danger",
												focus &&
													"dashboard-header-user-item-danger-active"
											)}
										>
											<LogOut size={16} />
											{isLoggingOut
												? __("Logging out...")
												: __("Logout")}
										</button>
									)}
								</MenuItem>
							</div>
						</MenuItems>
					</Menu>
				</div>
			</div>
		</div>
	);
};

export default Header;
