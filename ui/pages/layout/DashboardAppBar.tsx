// @ts-nocheck
'use client';

import {
	Search,
	Menu as MenuIcon,
	ChevronDown,
	User,
	Settings,
	Info,
	Heart,
	Coffee,
	ExternalLink,
	LogOut,
} from 'lucide-react';
import {
	useGetUserProfileQuery,
	useLogoutMutation,
} from '@/store/slices/api/user';
import { Avatar, ThemeToggle } from '@/components';
import { useNavigate } from 'react-router-dom';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';

export const DashboardAppBar = ({ onMobileMenuToggle }) => {
	const { data: userData } = useGetUserProfileQuery();
	const user = userData?.data;
	const [logout, { isLoading: isLoggingOut }] = useLogoutMutation();
	const navigate = useNavigate();
	const basePath = '/dashboard';

	const getRoleLabel = (role) => {
		if (role === 'admin') return 'Admin';
		if (role === 'editor') return 'Editor';
		return 'User';
	};

	const handleLogout = async () => {
		try {
			await logout().unwrap();
			navigate('/login', { replace: true });
		} catch (error) {
			console.error('Logout failed:', error);
		}
	};

	const externalMenuItemClass = (focus) =>
		`${focus ? 'bg-surface-alt' : ''} group flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-heading transition-colors`;

	return (
		<div className="sticky top-0 z-30 bg-surface border-b border-stroke">
			<div className="flex items-center justify-between h-16 px-4 gap-4">
				{/* Mobile menu button */}
				<button
					onClick={onMobileMenuToggle}
					className="lg:hidden p-2 rounded-lg hover:bg-surface-alt transition-colors"
				>
					<MenuIcon size={20} className="text-heading" />
				</button>

				{/* Search bar */}
				<div className="flex-1 max-w-xl">
					<div className="relative">
						<Search
							size={18}
							className="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted"
						/>
						<input
							type="text"
							placeholder="Search links, settings..."
							className="w-full pl-10 pr-4 py-2 bg-bg border border-stroke rounded-lg text-sm text-heading placeholder:text-text-muted focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
						/>
					</div>
				</div>

				{/* Right section */}
				<div className="flex items-center gap-2">
					{/* Theme toggle */}
					<ThemeToggle />

					{/* User dropdown */}
					<Menu as="div" className="relative">
						<MenuButton className="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-surface-alt transition-colors">
							<Avatar
								size="sm"
								email={user?.email}
								firstName={user?.firstName}
								lastName={user?.lastName}
								fallbackName={user?.username || 'Admin'}
							/>
							<div className="hidden sm:block text-left">
								<div className="text-sm font-semibold text-heading">
									{user
										? `${user.firstName} ${user.lastName}`
										: 'Admin Account'}
								</div>
								<div className="text-xs text-text-muted">
									{user ? getRoleLabel(user.role) : 'Admin'}
								</div>
							</div>
							<ChevronDown
								size={16}
								className="text-text-muted hidden sm:block"
							/>
						</MenuButton>

						<MenuItems className="absolute right-0 mt-2 w-56 origin-top-right bg-surface border border-stroke rounded-lg shadow-lg focus:outline-none z-50">
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
											Profile
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
											Settings
										</button>
									)}
								</MenuItem>
								<MenuItem>
									{({ focus }) => (
										<button
											onClick={() =>
												navigate(`${basePath}/about`)
											}
											className={`${
												focus ? 'bg-surface-alt' : ''
											} group flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-heading transition-colors`}
										>
											<Info
												size={16}
												className="text-text-muted"
											/>
											About
										</button>
									)}
								</MenuItem>
								<MenuItem>
									{({ focus }) => (
										<a
											href="https://peakurl.org/sponsor"
											target="_blank"
											rel="noreferrer"
											className={externalMenuItemClass(focus)}
										>
											<Heart
												size={16}
												className="text-text-muted"
											/>
											Sponsor
											<ExternalLink
												size={14}
												className="ml-auto text-text-muted"
											/>
										</a>
									)}
								</MenuItem>
								<MenuItem>
									{({ focus }) => (
										<a
											href="https://buymeacoffee.com/PeakURL"
											target="_blank"
											rel="noreferrer"
											className={externalMenuItemClass(focus)}
										>
											<Coffee
												size={16}
												className="text-text-muted"
											/>
											Buy Me a Coffee
											<ExternalLink
												size={14}
												className="ml-auto text-text-muted"
											/>
										</a>
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
												? 'Logging out...'
												: 'Logout'}
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
