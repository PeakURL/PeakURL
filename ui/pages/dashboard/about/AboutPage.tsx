// @ts-nocheck
import {
	Link2,
	Zap,
	Shield,
	Globe,
	BarChart3,
	Code,
	Lock,
	Heart,
	ExternalLink,
	Sparkles,
	Server,
	Database,
	Monitor,
	Palette,
	ChevronRight,
	BookOpen,
	Coffee,
} from 'lucide-react';
import { Link, useSearchParams } from 'react-router-dom';
import { PEAKURL_VERSION, PEAKURL_NAME } from '@/constants';
import { BrandLockup, Logo } from '@/components';
import { __ } from '@/i18n';

/* ─── Helpers ─────────────────────────────────────────────── */
const SectionTitle = ({ children, subtitle }: { children: React.ReactNode; subtitle?: string }) => (
	<div className="text-center mb-10">
		<h2 className="text-2xl sm:text-3xl font-bold text-heading">{children}</h2>
		{subtitle && <p className="mt-2 text-text-muted max-w-2xl mx-auto">{subtitle}</p>}
	</div>
);

const Divider = () => (
	<div className="my-16 flex items-center gap-4">
		<div className="flex-1 h-px bg-stroke" />
		<Logo size="sm" className="opacity-30" />
		<div className="flex-1 h-px bg-stroke" />
	</div>
);

const getLandingMeta = () => ({
	install: {
		eyebrow: __('Setup Complete'),
		title: __('PeakURL is ready to use.'),
		description:
			__(
				'Your administrator account is active and the dashboard is ready. Use these next steps to finish the initial configuration and start publishing links.'
			),
		actions: [
			{ label: __('Create your first short link'), to: '/dashboard/links' },
			{ label: __('Review general settings'), to: '/dashboard/settings/general' },
			{ label: __('Connect location data'), to: '/dashboard/settings/location' },
		],
	},
	update: {
		eyebrow: __('Update Complete'),
		title: __('PeakURL has been updated successfully.'),
		description:
			__(
				'The latest release is now running. Review the overview below, then confirm key services like email delivery, location data, and updates are configured the way you expect.'
			),
		actions: [
			{ label: __('Check email configuration'), to: '/dashboard/settings/email' },
			{ label: __('Verify update status'), to: '/dashboard/settings/updates' },
			{ label: __('Open all links'), to: '/dashboard/links' },
		],
	},
});

const LandingBanner = ({ source }) => {
	const meta = getLandingMeta()[source];

	if (!meta) {
		return null;
	}

	return (
		<div className="mb-8 rounded-2xl border border-accent/20 bg-accent/5 p-6">
			<p className="text-xs font-semibold uppercase tracking-[0.18em] text-accent">
				{meta.eyebrow}
			</p>
			<h2 className="mt-3 text-2xl font-semibold text-heading">
				{meta.title}
			</h2>
			<p className="mt-3 max-w-3xl text-sm leading-6 text-text-muted">
				{meta.description}
			</p>
			<div className="mt-5 flex flex-wrap gap-3">
				{meta.actions.map((action) => (
					<Link
						key={action.to}
						to={action.to}
						className="inline-flex items-center gap-2 rounded-lg border border-stroke bg-surface px-4 py-2 text-sm font-medium text-heading transition-colors hover:border-accent/30 hover:text-accent"
					>
						{action.label}
						<ChevronRight size={15} />
					</Link>
				))}
			</div>
		</div>
	);
};

/* ─── Feature cards ───────────────────────────────────────── */
const getFeatures = () => [
	{
		icon: Link2,
		title: __('Branded Short Links'),
		description:
			__(
				'Create clean short links with custom aliases, generated codes, QR output, and redirect settings that stay easy to manage.'
			),
	},
	{
		icon: BarChart3,
		title: __('Click Analytics'),
		description:
			__(
				'Review clicks, unique visitors, referrers, device breakdowns, and traffic trends from the same dashboard used to publish links.'
			),
	},
	{
		icon: Globe,
		title: __('Local Location Data'),
		description:
			__(
				'Resolve visitor locations from a local MaxMind GeoLite2 database so analytics stay under your control.'
			),
	},
	{
		icon: Shield,
		title: __('Access And Security'),
		description:
			__(
				'Run with admin and editor roles, session controls, two-factor authentication, backup codes, and API keys.'
			),
	},
	{
		icon: Code,
		title: __('Operations And Integrations'),
		description:
			__(
				'Use API keys, webhooks, import tools, and the built-in updater baseline to operate the service over time.'
			),
	},
	{
		icon: Server,
		title: __('Portable Self-Hosting'),
		description:
			__(
				'Deploy on your own domain, keep runtime configuration in your own environment, and preserve user-owned content across updates.'
			),
	},
];

const FeatureCard = ({ icon: Icon, title, description }) => (
	<div className="group relative bg-surface border border-stroke rounded-xl p-6 transition-all duration-200 hover:border-accent/30">
		<div className="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-accent/10 mb-4">
			<Icon size={20} className="text-accent" />
		</div>
		<h3 className="text-base font-semibold text-heading mb-1.5">{title}</h3>
		<p className="text-sm text-text-muted leading-relaxed">{description}</p>
	</div>
);

/* ─── Freedom cards ───────────────────────────────────────── */
const getFreedoms = () => [
	{
		number: '0',
		title: __('Use'),
		description: __('Run PeakURL for any purpose, personal projects, business, education, or anything in between.'),
	},
	{
		number: '1',
		title: __('Study'),
		description: __('Explore the source code, understand how it works, and modify it to suit your exact needs.'),
	},
	{
		number: '2',
		title: __('Share'),
		description: __('Redistribute copies freely so others can benefit from the same powerful link management platform.'),
	},
	{
		number: '3',
		title: __('Improve'),
		description: __('Enhance PeakURL and share your improvements, contributing back to the open-source community.'),
	},
];

/* ─── System info rows ────────────────────────────────────── */
const SystemInfoRow = ({ icon: Icon, label, value }) => (
	<div className="flex items-center justify-between py-3 border-b border-stroke/60 last:border-0">
		<div className="flex items-center gap-3">
			<Icon size={16} className="text-text-muted" />
			<span className="text-sm text-text-muted">{label}</span>
		</div>
		<span className="text-sm font-medium text-heading">{value}</span>
	</div>
);

/* ═══════════════════════════════════════════════════════════ */
function AboutPage() {
	const [searchParams] = useSearchParams();
	const source = searchParams.get('source');
	const features = getFeatures();
	const freedoms = getFreedoms();

	return (
		<div className="pb-12">
			{/* ─── Hero (full-width breakout) ─────────────────── */}
			<section className="relative overflow-hidden bg-slate-950 -mx-4 sm:-mx-6 -mt-4 sm:-mt-6 px-4 sm:px-6 py-12 sm:py-16 text-white mb-12">
				{/* Subtle glow */}
				<div className="absolute inset-0 bg-[radial-gradient(ellipse_at_center,rgba(99,102,241,0.08),transparent_70%)] pointer-events-none" />

				<div className="relative z-10 text-center max-w-3xl mx-auto">
					<div className="inline-flex items-center gap-2 bg-slate-800/80 border border-slate-700/60 rounded-full px-4 py-1.5 mb-6 text-sm font-medium text-slate-300">
						<Sparkles size={14} className="text-indigo-400" />
						{__('Version')} {PEAKURL_VERSION}
					</div>

					<div className="flex justify-center mb-6">
						<div className="w-16 h-16 bg-slate-800/80 border border-slate-700/50 rounded-2xl flex items-center justify-center">
							<Link2 size={32} className="text-indigo-400" />
						</div>
					</div>

					<h1 className="text-3xl sm:text-5xl font-extrabold tracking-tight mb-4 text-white">
						{__('Welcome to')} {PEAKURL_NAME}
					</h1>
					<p className="text-lg sm:text-xl text-slate-400 max-w-2xl mx-auto leading-relaxed">
						{__(
							'PeakURL gives you a focused workspace for publishing short links, measuring engagement, and running branded link infrastructure on your own stack.'
						)}
					</p>

					<div className="mt-8 flex flex-wrap justify-center gap-3">
						<a
							href="https://peakurl.org"
							target="_blank"
							rel="noreferrer"
							className="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-500 text-white font-semibold px-5 py-2.5 rounded-lg text-sm transition-colors"
						>
							<BookOpen size={16} />
							{__('Documentation')}
						</a>
						<a
							href="https://github.com/PeakURL/PeakURL"
							target="_blank"
							rel="noreferrer"
							className="inline-flex items-center gap-2 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 font-semibold px-5 py-2.5 rounded-lg text-sm transition-colors"
						>
							<svg
								viewBox="0 0 24 24"
								width={16}
								height={16}
								fill="currentColor"
								aria-hidden="true"
							>
								<path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12" />
							</svg>
							{__('View on GitHub')}
						</a>
					</div>
				</div>
			</section>

			<div className="max-w-5xl mx-auto space-y-0">
				<LandingBanner source={source} />

				{/* ─── Features ───────────────────────────────────── */}
				<section>
					<SectionTitle subtitle={__('Everything you need to publish short links, review traffic, and operate the service from one focused dashboard.')}>
						{__("What's Inside")}
					</SectionTitle>

					<div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
						{features.map((f) => (
							<FeatureCard key={f.title} {...f} />
						))}
					</div>
				</section>

				<Divider />

				{/* ─── Mission ────────────────────────────────────── */}
				<section>
				<SectionTitle subtitle={__('PeakURL is built to stay understandable in day-to-day use and dependable over time.')}>
					{__('Product Principles')}
				</SectionTitle>

					<div className="grid grid-cols-1 md:grid-cols-3 gap-6">
						<div className="bg-surface border border-stroke rounded-xl p-6 text-center">
							<div className="w-12 h-12 mx-auto mb-4 rounded-lg bg-accent/10 flex items-center justify-center">
								<Monitor size={22} className="text-accent" />
							</div>
							<h3 className="text-base font-semibold text-heading mb-2">
								{__('Clear By Default')}
							</h3>
							<p className="text-sm text-text-muted leading-relaxed">
								{__(
									'The dashboard should stay direct and readable so routine work like publishing links, reviewing traffic, and managing users does not feel heavy.'
								)}
							</p>
						</div>
						<div className="bg-surface border border-stroke rounded-xl p-6 text-center">
							<div className="w-12 h-12 mx-auto mb-4 rounded-lg bg-accent/10 flex items-center justify-center">
								<Code size={22} className="text-accent" />
							</div>
							<h3 className="text-base font-semibold text-heading mb-2">
								{__('Portable To Run')}
							</h3>
							<p className="text-sm text-text-muted leading-relaxed">
								{__(
									'The application should install cleanly on common PHP and MySQL hosting without depending on a managed SaaS control plane.'
								)}
							</p>
						</div>
						<div className="bg-surface border border-stroke rounded-xl p-6 text-center">
							<div className="w-12 h-12 mx-auto mb-4 rounded-lg bg-accent/10 flex items-center justify-center">
								<Heart size={22} className="text-accent" />
							</div>
							<h3 className="text-base font-semibold text-heading mb-2">
								{__('Open And Inspectable')}
							</h3>
							<p className="text-sm text-text-muted leading-relaxed">
								{__(
									'The codebase stays auditable and adaptable so operators can understand what they run, extend it carefully, and keep long-term control.'
								)}
							</p>
						</div>
					</div>
				</section>

				<Divider />

				{/* ─── Four Freedoms ──────────────────────────────── */}
				<section>
				<SectionTitle subtitle={__('PeakURL is released under the MIT License so it can stay practical to adopt, inspect, modify, and distribute.')}>
					{__('Open Source Terms')}
				</SectionTitle>

					<div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
						{freedoms.map((f) => (
							<div
								key={f.number}
								className="group relative bg-surface border border-stroke rounded-xl p-6 flex gap-5 items-start transition-all duration-200 hover:border-accent/30"
							>
								<div className="shrink-0 w-10 h-10 rounded-lg bg-accent/10 text-accent flex items-center justify-center font-bold text-lg">
									{f.number}
								</div>
								<div>
									<h3 className="text-base font-semibold text-heading mb-1">
										{__('Freedom to')} {f.title}
									</h3>
									<p className="text-sm text-text-muted leading-relaxed">
										{f.description}
									</p>
								</div>
							</div>
						))}
					</div>

					<div className="mt-8 rounded-2xl border border-accent/20 bg-accent/5 p-6">
						<div className="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
							<div className="max-w-2xl">
								<p className="text-xs font-semibold uppercase tracking-[0.18em] text-accent">
									{__('Support PeakURL')}
								</p>
								<h3 className="mt-2 text-xl font-semibold text-heading">
									{__('If PeakURL is useful to you, help keep it practical and actively maintained.')}
								</h3>
								<p className="mt-2 text-sm leading-6 text-text-muted">
									{__(
										'Sponsorships and small contributions help fund releases, documentation, infrastructure, and the maintenance work that keeps the project moving.'
									)}
								</p>
							</div>
							<div className="flex flex-wrap gap-3">
								<a
									href="https://peakurl.org/sponsor"
									target="_blank"
									rel="noreferrer"
									className="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-95"
								>
									<Heart size={16} />
									{__('Sponsor PeakURL')}
									<ExternalLink size={14} />
								</a>
								<a
									href="https://buymeacoffee.com/PeakURL"
									target="_blank"
									rel="noreferrer"
									className="inline-flex items-center gap-2 rounded-lg border border-stroke bg-surface px-4 py-2.5 text-sm font-semibold text-heading transition-colors hover:border-accent/30 hover:text-accent"
								>
									<Coffee size={16} />
									{__('Buy Me a Coffee')}
									<ExternalLink size={14} />
								</a>
							</div>
						</div>
					</div>
				</section>

				<Divider />

				{/* ─── System At A Glance ─────────────────────────── */}
				<section>
				<SectionTitle subtitle={__('A quick overview of the installation you are running right now.')}>
					{__('Current Installation')}
				</SectionTitle>

					<div className="max-w-lg mx-auto bg-surface border border-stroke rounded-xl p-6">
						<SystemInfoRow
							icon={Sparkles}
							label={__('Version')}
							value={PEAKURL_VERSION}
						/>
						<SystemInfoRow
							icon={Palette}
							label={__('Dashboard')}
							value="Vite + React"
						/>
						<SystemInfoRow
							icon={Server}
							label={__('Runtime')}
							value="PHP 7.4+"
						/>
						<SystemInfoRow
							icon={Database}
							label={__('Database')}
							value="MySQL / MariaDB"
						/>
						<SystemInfoRow
							icon={Lock}
							label={__('Authentication')}
							value="Session + API Key"
						/>
						<SystemInfoRow
							icon={Globe}
							label={__('GeoIP')}
							value="MaxMind GeoLite2"
						/>
					</div>
				</section>

				<Divider />

				{/* ─── Footer tagline ─────────────────────────────── */}
			<section className="text-center pt-4 pb-2">
				<div className="mb-3 flex justify-center">
					<BrandLockup to="/dashboard" size="md" />
				</div>
				<p className="text-sm italic text-text-muted tracking-wide">
					{__('Self-hosted link management with clear ownership and a focused dashboard.')}
				</p>
					<div className="mt-6 flex flex-wrap items-center justify-center gap-4 text-xs text-text-muted">
						<a
							href="https://peakurl.org"
							target="_blank"
							rel="noreferrer"
							className="inline-flex items-center gap-1 hover:text-accent transition-colors"
						>
							{__('Website')} <ExternalLink size={10} />
						</a>
						<span className="opacity-30">•</span>
						<a
							href="https://github.com/Abd-Ur-Rehman/PeakURL"
							target="_blank"
							rel="noreferrer"
							className="inline-flex items-center gap-1 hover:text-accent transition-colors"
						>
							GitHub <ExternalLink size={10} />
						</a>
						<span className="opacity-30">•</span>
						<a
							href="https://peakurl.org/release-notes"
							target="_blank"
							rel="noreferrer"
							className="inline-flex items-center gap-1 hover:text-accent transition-colors"
						>
							{__('Release Notes')} <ExternalLink size={10} />
						</a>
					</div>
				</section>
			</div>
		</div>
	);
}

export default AboutPage;
