import {
	Menu as MenuIcon,
	ChevronDown,
	User,
	Settings,
	LogOut,
} from 'lucide-react';
import {
	useGetUserProfileQuery,
	useLogoutMutation,
} from '@/store/slices/api';
import { Avatar, ThemeToggle } from '@/components';
import { useNavigate } from 'react-router-dom';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { isDocumentRtl } from '@/i18n/direction';
import { __ } from '@/i18n';
import { DashboardSearch } from './DashboardSearch';
import type { DashboardAppBarProps } from './types';

export const DashboardAppBar = ({
	onMobileMenuToggle,
}: DashboardAppBarProps) => {
	const isRtl = isDocumentRtl();
	const { data: userData } = useGetUserProfileQuery(undefined);
	const user = userData?.data;
	const [logout, { isLoading: isLoggingOut }] = useLogoutMutation();
	const navigate = useNavigate();
	const basePath = '/dashboard';

	const getRoleLabel = (role?: string | null) => {
		if (role === 'admin') return __('Admin');
		if (role === 'editor') return __('Editor');
		return __('User');
	};

	const handleLogout = async () => {
		try {
			await logout(undefined).unwrap();
			navigate('/login', { replace: true });
		} catch (error) {
			console.error('Logout failed:', error);
		}
	};

	return (
		<div className="sticky top-0 z-30 bg-surface border-b border-stroke">
			<div className="flex h-16 items-center justify-between gap-2 px-3 sm:gap-4 sm:px-4">
				{/* Mobile menu button */}
				<button
					onClick={onMobileMenuToggle}
					className="rounded-lg p-2 transition-colors hover:bg-surface-alt lg:hidden"
				>
					<MenuIcon size={20} className="text-heading" />
				</button>

				{/* Search bar */}
				<DashboardSearch />

				{/* Right section */}
				<div className="flex shrink-0 items-center gap-1.5 sm:gap-2">
					{/* Theme toggle */}
					<ThemeToggle />

					{/* User dropdown */}
					<Menu as="div" className="relative">
						<MenuButton className="flex items-center gap-2 rounded-lg px-1.5 py-2 transition-colors hover:bg-surface-alt sm:px-3">
							<Avatar
								size="sm"
								email={user?.email}
								firstName={user?.firstName}
								lastName={user?.lastName}
								fallbackName={user?.username || __('Admin')}
							/>
							<div
								className={`hidden sm:block ${
									isRtl ? 'text-right' : 'text-left'
								}`}
							>
								<div className="text-sm font-semibold text-heading">
									{user
										? `${user.firstName} ${user.lastName}`
										: __('Admin Account')}
								</div>
								<div className="text-xs text-text-muted">
									{user
										? getRoleLabel(user.role)
										: __('Admin')}
								</div>
							</div>
							<ChevronDown
								size={16}
								className="text-text-muted hidden sm:block"
							/>
						</MenuButton>

						<MenuItems
							className={`absolute mt-2 z-50 w-56 rounded-lg border border-stroke bg-surface shadow-lg focus:outline-none ${
								isRtl
									? 'left-0 origin-top-left'
									: 'right-0 origin-top-right'
							}`}
						>
							<div className="p-1">
								<MenuItem>
									{({ focus }) => (
										<button
											onClick={() =>
												navigate(
													`${basePath}/settings/general`
												)
											}
											className={`${
												focus ? 'bg-surface-alt' : ''
											} group flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-heading transition-colors`}
										>
											<User
												size={16}
												className="text-text-muted"
											/>
											{__('Profile')}
										</button>
									)}
								</MenuItem>
								<MenuItem>
									{({ focus }) => (
										<button
											onClick={() =>
												navigate(`${basePath}/settings`)
											}
											className={`${
												focus ? 'bg-surface-alt' : ''
											} group flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-heading transition-colors`}
										>
											<Settings
												size={16}
												className="text-text-muted"
											/>
											{__('Settings')}
										</button>
									)}
								</MenuItem>
								<div className="my-1 h-px bg-stroke" />
								<MenuItem>
									{({ focus }) => (
										<button
											onClick={handleLogout}
											disabled={isLoggingOut}
											className={`${
												focus ? 'bg-error/10' : ''
											} group flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-error transition-colors disabled:opacity-50`}
										>
											<LogOut size={16} />
											{isLoggingOut
												? __('Logging out...')
												: __('Logout')}
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
