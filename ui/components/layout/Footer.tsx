// @ts-nocheck
'use client';
import {
	Link as LinkIcon,
	Send,
	BriefcaseBusiness,
	Code2,
	Mail,
} from 'lucide-react';

export const Footer = () => {
	const footerLinks = {
		product: [
			{ name: 'Features', href: '/features' },
			{ name: 'Pricing', href: '/pricing' },
			{ name: 'Use Cases', href: '/features#use-cases' },
			{ name: 'API', href: '/features#api' },
		],
		company: [
			{ name: 'About', href: '/about' },
			{ name: 'Blog', href: '/blog' },
			{ name: 'Careers', href: '/about#careers' },
			{ name: 'Contact', href: '/contact' },
		],
		resources: [
			{ name: 'Documentation', href: '/docs' },
			{ name: 'Support', href: '/contact' },
			{ name: 'Status', href: '/status' },
			{ name: 'Changelog', href: '/blog#changelog' },
		],
		legal: [
			{ name: 'Privacy', href: '/privacy' },
			{ name: 'Terms', href: '/terms' },
			{ name: 'Security', href: '/privacy#security' },
			{ name: 'Cookies', href: '/privacy#cookies' },
		],
	};

	const socialLinks = [
		{ name: 'Twitter', icon: Send, href: 'https://twitter.com' },
		{
			name: 'LinkedIn',
			icon: BriefcaseBusiness,
			href: 'https://linkedin.com',
		},
		{ name: 'GitHub', icon: Code2, href: 'https://github.com' },
		{ name: 'Email', icon: Mail, href: 'mailto:hello@peakurl.com' },
	];

	return (
		<footer className="relative py-16 border-t border-stroke bg-surface">
			<div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
				{/* Main Footer Content */}
				<div className="grid grid-cols-2 md:grid-cols-6 gap-8 mb-12">
					{/* Brand Column */}
					<div className="col-span-2">
						<a href="/" className="flex items-center gap-2 mb-4">
							<div className="w-8 h-8 bg-primary-600 rounded-lg flex items-center justify-center text-white font-bold shadow-lg shadow-primary-500/30">
								<LinkIcon className="w-5 h-5" />
							</div>
							<span className="text-xl font-bold text-heading">
								PeakURL
							</span>
						</a>
						<p className="text-sm text-text-muted mb-6 max-w-xs">
							The powerful link management platform for modern
							marketing teams. Shorten, track, and optimize your
							links.
						</p>
						{/* Social Links */}
						<div className="flex gap-4">
							{socialLinks.map((social) => (
								<a
									key={social.name}
									href={social.href}
									className="text-text-muted hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
									aria-label={social.name}
									target="_blank"
									rel="noopener noreferrer"
								>
									<social.icon className="w-5 h-5" />
								</a>
							))}
						</div>
					</div>

					{/* Product Links */}
					<div>
						<h3 className="text-sm font-semibold text-heading mb-4">
							Product
						</h3>
						<ul className="space-y-3">
							{footerLinks.product.map((link) => (
								<li key={link.name}>
									<a
										href={link.href}
										className="text-sm text-text-muted hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
									>
										{link.name}
									</a>
								</li>
							))}
						</ul>
					</div>

					{/* Company Links */}
					<div>
						<h3 className="text-sm font-semibold text-heading mb-4">
							Company
						</h3>
						<ul className="space-y-3">
							{footerLinks.company.map((link) => (
								<li key={link.name}>
									<a
										href={link.href}
										className="text-sm text-text-muted hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
									>
										{link.name}
									</a>
								</li>
							))}
						</ul>
					</div>

					{/* Resources Links */}
					<div>
						<h3 className="text-sm font-semibold text-heading mb-4">
							Resources
						</h3>
						<ul className="space-y-3">
							{footerLinks.resources.map((link) => (
								<li key={link.name}>
									<a
										href={link.href}
										className="text-sm text-text-muted hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
									>
										{link.name}
									</a>
								</li>
							))}
						</ul>
					</div>

					{/* Legal Links */}
					<div>
						<h3 className="text-sm font-semibold text-heading mb-4">
							Legal
						</h3>
						<ul className="space-y-3">
							{footerLinks.legal.map((link) => (
								<li key={link.name}>
									<a
										href={link.href}
										className="text-sm text-text-muted hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
									>
										{link.name}
									</a>
								</li>
							))}
						</ul>
					</div>
				</div>

				{/* Bottom Bar */}
				<div className="pt-8 border-t border-stroke">
					<div className="flex flex-col md:flex-row justify-between items-center gap-4">
						<p className="text-sm text-text-muted">
							© {new Date().getFullYear()} PeakURL. All rights
							reserved.
						</p>
						<p className="text-sm text-text-muted">
							Made with ❤️ for better link management
						</p>
					</div>
				</div>
			</div>
		</footer>
	);
};
