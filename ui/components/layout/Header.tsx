// @ts-nocheck
'use client';
import { useState, useEffect } from 'react';
import { ArrowRight } from 'lucide-react';
import { useLocation } from 'react-router-dom';
import { ThemeToggle } from '@/components';
import { PEAKURL_URL } from '@/constants';

export const Header = () => {
	const [mobileMenuState, setMobileMenuState] = useState({
		pathname: '',
		open: false,
	});
	const [scrolled, setScrolled] = useState(false);
	const location = useLocation();
	const pathname = location.pathname;
	const mobileMenuOpen =
		mobileMenuState.pathname === pathname && mobileMenuState.open;

	useEffect(() => {
		const handleScroll = () => {
			setScrolled(window.scrollY > 20);
		};
		window.addEventListener('scroll', handleScroll);
		return () => window.removeEventListener('scroll', handleScroll);
	}, []);

	const closeMobileMenu = () => {
		setMobileMenuState({ pathname, open: false });
	};

	const navigation = [
		{ name: 'Home', href: PEAKURL_URL },
		{ name: 'Features', href: `${PEAKURL_URL}/features` },
		{ name: 'Pricing', href: `${PEAKURL_URL}/pricing` },
		{ name: 'About', href: `${PEAKURL_URL}/about` },
		{ name: 'Contact', href: `${PEAKURL_URL}/contact` },
	];

	const isActive = (href) => {
		// For absolute URLs, extract the path
		if (href.startsWith('http')) {
			const url = new URL(href);
			const path = url.pathname;
			if (path === '/') return pathname === '/';
			return pathname.startsWith(path);
		}
		// For relative paths
		if (href === '/') return pathname === '/';
		return pathname.startsWith(href);
	};

	return (
		<>
			<header
				className={`fixed top-0 left-0 right-0 z-50 transition-all duration-300 ${
					scrolled ? 'bg-surface shadow-md' : 'bg-surface/80'
				} border-b border-stroke`}
			>
				<nav className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
					<div className="flex items-center justify-between h-16 lg:h-20">
						{/* Logo */}
						<a
							href={PEAKURL_URL}
							className="flex items-center gap-2 sm:gap-3 group"
						>
							<div className="relative w-9 h-9 sm:w-10 sm:h-10">
								<div className="absolute inset-0 bg-linear-to-br from-primary-600 to-purple-600 rounded-xl shadow-lg shadow-primary-500/30 group-hover:shadow-primary-500/50 transition-all duration-300 group-hover:scale-105"></div>
								<div className="relative w-full h-full flex items-center justify-center">
									<svg
										xmlns="http://www.w3.org/2000/svg"
										viewBox="0 0 24 24"
										fill="none"
										stroke="currentColor"
										strokeWidth="2.5"
										strokeLinecap="round"
										strokeLinejoin="round"
										className="w-5 h-5 sm:w-6 sm:h-6 text-white"
									>
										<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
										<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
									</svg>
								</div>
							</div>
							<div className="flex flex-col">
								<span className="text-xl sm:text-2xl font-bold tracking-tight text-heading group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
									PeakURL
								</span>
								<span className="text-[10px] sm:text-xs text-text-muted -mt-1 hidden sm:block">
									Link Management
								</span>
							</div>
						</a>

						{/* Desktop Navigation */}
						<div className="hidden lg:flex items-center gap-2">
							{navigation.map((item) => (
								<a
									key={item.name}
									href={item.href}
									className={`relative px-5 py-2.5 text-sm font-semibold rounded-lg transition-all duration-200 ${
										isActive(item.href)
											? 'text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-900/20'
											: 'text-heading hover:text-heading hover:bg-surface-alt'
									}`}
								>
									{item.name}
								</a>
							))}
						</div>

						{/* CTA Buttons - Desktop */}
						<div className="hidden lg:flex items-center gap-3">
							<ThemeToggle />
							<a
								href={`${PEAKURL_URL}/login`}
								className="px-5 py-2.5 text-sm font-semibold text-heading hover:text-heading transition-colors"
							>
								Log in
							</a>
							<a
								href={`${PEAKURL_URL}/signup`}
								className="group relative px-5 py-2.5 bg-primary-600 hover:bg-primary-700 text-white rounded-xl font-semibold text-sm transition-all duration-300 shadow-lg shadow-primary-500/25 hover:shadow-primary-500/40 hover:-translate-y-0.5"
							>
								<span className="flex items-center gap-2">
									Get Started
									<ArrowRight className="w-4 h-4 group-hover:translate-x-0.5 transition-transform" />
								</span>
							</a>
						</div>

						{/* Mobile menu button */}
						<div className="lg:hidden flex items-center gap-2">
							<ThemeToggle />
							<button
								type="button"
								className="relative p-2 rounded-xl text-heading hover:bg-surface-alt transition-all active:scale-95"
								onClick={() =>
									setMobileMenuState({
										pathname,
										open: !mobileMenuOpen,
									})
								}
								aria-label="Toggle menu"
							>
								<div className="relative w-6 h-6 flex flex-col items-center justify-center">
									<span
										className={`absolute w-6 h-0.5 bg-current rounded-full transition-all duration-300 ease-in-out ${
											mobileMenuOpen
												? 'rotate-45'
												: '-translate-y-2'
										}`}
									></span>
									<span
										className={`absolute w-6 h-0.5 bg-current rounded-full transition-all duration-300 ease-in-out ${
											mobileMenuOpen
												? 'opacity-0'
												: 'opacity-100'
										}`}
									></span>
									<span
										className={`absolute w-6 h-0.5 bg-current rounded-full transition-all duration-300 ease-in-out ${
											mobileMenuOpen
												? '-rotate-45'
												: 'translate-y-2'
										}`}
									></span>
								</div>
							</button>
						</div>
					</div>
				</nav>
			</header>

			{/* Mobile menu overlay */}
			<div
				className={`fixed inset-0 z-40 lg:hidden transition-all duration-300 ${
					mobileMenuOpen
						? 'opacity-100 pointer-events-auto'
						: 'opacity-0 pointer-events-none'
				}`}
			>
				{/* Backdrop */}
				<div
					className="absolute inset-0 bg-gray-900/60"
					onClick={closeMobileMenu}
				></div>

				{/* Menu Panel */}
				<div
					className={`absolute top-16 lg:top-20 right-0 bottom-0 w-full sm:w-80 bg-surface shadow-2xl transition-transform duration-300 ${
						mobileMenuOpen ? 'translate-x-0' : 'translate-x-full'
					}`}
				>
					<div className="h-full overflow-y-auto overscroll-contain">
						{/* Navigation Links */}
						<div className="p-6 space-y-2">
							{navigation.map((item) => (
								<a
									key={item.name}
									href={item.href}
									onClick={closeMobileMenu}
									className={`block px-5 py-3.5 rounded-xl font-semibold transition-all duration-200 ${
										isActive(item.href)
											? 'bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400'
											: 'text-heading hover:bg-surface-alt active:scale-[0.98]'
									}`}
								>
									{item.name}
								</a>
							))}
						</div>

						{/* Divider */}
						<div className="mx-6 border-t border-stroke"></div>

						{/* Auth Buttons */}
						<div className="p-6 space-y-3">
							<a
								href={`${PEAKURL_URL}/login`}
								onClick={closeMobileMenu}
								className="block text-center px-6 py-3.5 text-base font-semibold text-heading bg-surface-alt hover:bg-stroke rounded-xl transition-all active:scale-98"
							>
								Log in
							</a>
							<a
								href={`${PEAKURL_URL}/signup`}
								onClick={closeMobileMenu}
								className="group block text-center px-6 py-3.5 bg-linear-to-r from-primary-600 to-purple-600 hover:from-primary-700 hover:to-purple-700 text-white rounded-xl font-semibold text-base transition-all shadow-lg shadow-primary-500/30 hover:shadow-primary-500/50 active:scale-98"
							>
								<span className="flex items-center justify-center gap-2">
									Get Started Free
									<ArrowRight className="w-5 h-5 group-hover:translate-x-1 transition-transform" />
								</span>
							</a>
							<p className="text-center text-xs text-gray-500 dark:text-gray-400 pt-2">
								Free 14-day trial • No credit card required
							</p>
						</div>

						{/* Bottom section with extra info */}
						<div className="absolute bottom-0 left-0 right-0 p-6 bg-linear-to-t from-gray-50 dark:from-gray-900 to-transparent">
							<div className="text-center space-y-2">
								<p className="text-xs font-medium text-gray-600 dark:text-gray-400">
									Trusted by 50,000+ teams worldwide
								</p>
								<div className="flex items-center justify-center gap-4 text-xs text-gray-500 dark:text-gray-500">
									<span>99.9% Uptime</span>
									<span>•</span>
									<span>SSL Encrypted</span>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</>
	);
};
