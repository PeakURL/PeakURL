import {
	Link2,
	Shield,
	Globe,
	BarChart3,
	Code,
	Heart,
	ExternalLink,
	Sparkles,
	Server,
	Monitor,
	ChevronRight,
	BookOpen,
	SquareTerminal,
	TextAlignJustify,
} from "lucide-react";
import { Link, useSearchParams } from "react-router";
import { PEAKURL_VERSION, PEAKURL_NAME } from "@/constants";
import { BrandLockup, Logo } from "@/components";
import { formatRelativeTime } from "@/utils";
import { __, sprintf } from "@/i18n";
import { useGetReleaseNotesQuery } from "@/store/slices/api/system";
import { Sponsors } from "./Sponsors";
import type {
	AboutIconProps,
	AddOnLink,
	Feature,
	Freedom,
	LandingBannerProps,
	LandingMetaEntry,
	LandingSource,
	SectionTitleProps,
} from "./types";

/* ─── Helpers ─────────────────────────────────────────────── */
const SectionTitle = ({ children, subtitle }: SectionTitleProps) => (
	<div className="about-page-section-heading">
		<h2 className="about-page-section-title">{children}</h2>
		{subtitle && <p className="about-page-section-summary">{subtitle}</p>}
	</div>
);

const Divider = () => (
	<div className="about-page-divider">
		<div className="about-page-divider-line" />
		<Logo size="sm" className="about-page-divider-logo" />
		<div className="about-page-divider-line" />
	</div>
);

const WordPressIcon = ({ className = "" }: AboutIconProps) => (
	<svg
		viewBox="0 0 24 24"
		aria-hidden="true"
		className={className}
		fill="none"
	>
		<path
			fill="#21759B"
			d="M21.469 6.825c.84 1.537 1.318 3.3 1.318 5.175 0 3.979-2.156 7.456-5.363 9.325l3.295-9.527c.615-1.54.82-2.771.82-3.864 0-.405-.026-.78-.07-1.11m-7.981.105c.647-.03 1.232-.105 1.232-.105.582-.075.514-.93-.067-.899 0 0-1.755.135-2.88.135-1.064 0-2.85-.15-2.85-.15-.585-.03-.661.855-.075.885 0 0 .54.061 1.125.09l1.68 4.605-2.37 7.08L5.354 6.9c.649-.03 1.234-.1-1.234-.1.585-.075.516-.93-.065-.896 0 0-1.746.138-2.874.138-.2 0-.438-.008-.69-.015C4.911 3.15 8.235 1.215 12 1.215c2.809 0 5.365 1.072 7.286 2.833-.046-.003-.091-.009-.141-.009-1.06 0-1.812.923-1.812 1.914 0 .89.513 1.643 1.06 2.531.411.72.89 1.643.89 2.977 0 .915-.354 1.994-.821 3.479l-1.075 3.585-3.9-11.61.001.014zM12 22.784c-1.059 0-2.081-.153-3.048-.437l3.237-9.406 3.315 9.087c.024.053.05.101.078.149-1.12.393-2.325.609-3.582.609M1.211 12c0-1.564.336-3.05.935-4.39L7.29 21.709C3.694 19.96 1.212 16.271 1.211 12M12 0C5.385 0 0 5.385 0 12s5.385 12 12 12 12-5.385 12-12S18.615 0 12 0"
		/>
	</svg>
);

const DockerIcon = ({ className = "" }: AboutIconProps) => (
	<svg
		viewBox="0 0 439 309"
		aria-hidden="true"
		className={className}
		fill="none"
	>
		<path
			fill="currentColor"
			d="M379.6,111.7c-2.3-16.7-11.5-31.2-28.1-44.3l-9.6-6.5l-6.4,9.7c-8.2,12.5-12.3,29.9-11,46.6
	c0.6,5.8,2.5,16.4,8.4,25.5c-5.9,3.3-17.6,7.7-33.2,7.4H1.7l-0.6,3.5c-2.8,16.7-2.8,69,30.7,109.1c25.5,30.5,63.6,46,113.4,46
	c108,0,187.8-50.3,225.3-141.9c14.7,0.3,46.4,0.1,62.7-31.4c0.4-0.7,1.4-2.6,4.2-8.6l1.6-3.3l-9.1-6.2
	C419.9,110.8,397.2,108.3,379.6,111.7L379.6,111.7z M240,0h-45.3v41.7H240V0z M240,50.1h-45.3v41.7H240V50.1z M186.4,50.1h-45.3
	v41.7h45.3V50.1z M132.9,50.1H87.6v41.7h45.3V50.1z M79.3,100.2H34v41.7h45.3V100.2z M132.9,100.2H87.6v41.7h45.3V100.2z
	 M186.4,100.2h-45.3v41.7h45.3V100.2z M240,100.2h-45.3v41.7H240V100.2z M293.6,100.2h-45.3v41.7h45.3V100.2z"
		/>
	</svg>
);

const ChromeIcon = ({ className = "" }: AboutIconProps) => (
	<svg
		viewBox="0 0 24 24"
		aria-hidden="true"
		className={className}
		fill="none"
	>
		<path
			fill="#4285F4"
			d="M12 0C8.21 0 4.831 1.757 2.632 4.501l3.953 6.848A5.454 5.454 0 0 1 12 6.545h10.691A12 12 0 0 0 12 0zM1.931 5.47A11.943 11.943 0 0 0 0 12c0 6.012 4.42 10.991 10.189 11.864l3.953-6.847a5.45 5.45 0 0 1-6.865-2.29zm13.342 2.166a5.446 5.446 0 0 1 1.45 7.09l.002.001h-.002l-5.344 9.257c.206.01.413.016.621.016 6.627 0 12-5.373 12-12 0-1.54-.29-3.011-.818-4.364zM12 16.364a4.364 4.364 0 1 1 0-8.728 4.364 4.364 0 0 1 0 8.728Z"
		/>
	</svg>
);

const FirefoxIcon = ({ className = "" }: AboutIconProps) => (
	<svg
		viewBox="0 0 24 24"
		aria-hidden="true"
		className={className}
		fill="none"
	>
		<path
			fill="#FF7139"
			d="M8.824 7.287c.008 0 .004 0 0 0zm-2.8-1.4c.006 0 .003 0 0 0zm16.754 2.161c-.505-1.215-1.53-2.528-2.333-2.943.654 1.283 1.033 2.57 1.177 3.53l.002.02c-1.314-3.278-3.544-4.6-5.366-7.477-.091-.147-.184-.292-.273-.446a3.545 3.545 0 01-.13-.24 2.118 2.118 0 01-.172-.46.03.03 0 00-.027-.03.038.038 0 00-.021 0l-.006.001a.037.037 0 00-.01.005L15.624 0c-2.585 1.515-3.657 4.168-3.932 5.856a6.197 6.197 0 00-2.305.587.297.297 0 00-.147.37c.057.162.24.24.396.17a5.622 5.622 0 012.008-.523l.067-.005a5.847 5.847 0 011.957.222l.095.03a5.816 5.816 0 01.616.228c.08.036.16.073.238.112l.107.055a5.835 5.835 0 01.368.211 5.953 5.953 0 012.034 2.104c-.62-.437-1.733-.868-2.803-.681 4.183 2.09 3.06 9.292-2.737 9.02a5.164 5.164 0 01-1.513-.292 4.42 4.42 0 01-.538-.232c-1.42-.735-2.593-2.121-2.74-3.806 0 0 .537-2 3.845-2 .357 0 1.38-.998 1.398-1.287-.005-.095-2.029-.9-2.817-1.677-.422-.416-.622-.616-.8-.767a3.47 3.47 0 00-.301-.227 5.388 5.388 0 01-.032-2.842c-1.195.544-2.124 1.403-2.8 2.163h-.006c-.46-.584-.428-2.51-.402-2.913-.006-.025-.343.176-.389.206-.406.29-.787.616-1.136.974-.397.403-.76.839-1.085 1.303a9.816 9.816 0 00-1.562 3.52c-.003.013-.11.487-.19 1.073-.013.09-.026.181-.037.272a7.8 7.8 0 00-.069.667l-.002.034-.023.387-.001.06C.386 18.795 5.593 24 12.016 24c5.752 0 10.527-4.176 11.463-9.661.02-.149.035-.298.052-.448.232-1.994-.025-4.09-.753-5.844z"
		/>
	</svg>
);

const getAddOnLinks = (): AddOnLink[] => [
	{
		label: "WordPress",
		href: "https://go.peakurl.org/get-wordpress-plugin",
		icon: WordPressIcon,
	},
	{
		label: "Chrome",
		href: "https://go.peakurl.org/get-chrome-extension",
		icon: ChromeIcon,
	},
	{
		label: "Firefox",
		href: "https://go.peakurl.org/get-firefox-addon",
		icon: FirefoxIcon,
	},
	{
		label: "Docker",
		href: "https://go.peakurl.org/docker",
		icon: DockerIcon,
	},
	{
		label: "CLI",
		href: "https://go.peakurl.org/cli",
		icon: SquareTerminal,
	},
];

const getLandingMeta = (): Record<LandingSource, LandingMetaEntry> => ({
	install: {
		eyebrow: __("Setup Complete"),
		title: __("PeakURL is ready to use."),
		description: __(
			"Your administrator account is active and the dashboard is ready. Use these next steps to finish the initial configuration and start publishing links."
		),
		actions: [
			{
				label: __("Create your first short link"),
				to: "/dashboard/links",
			},
			{
				label: __("Review general settings"),
				to: "/dashboard/settings/general",
			},
			{
				label: __("Connect location data"),
				to: "/dashboard/settings/location",
			},
		],
	},
	update: {
		eyebrow: __("Update Complete"),
		title: __("PeakURL has been updated to the latest version."),
		description: __(
			"The latest version is now installed. Here's a look at what's new in this release."
		),
	},
	reinstall: {
		eyebrow: __("Reinstall Complete"),
		title: __("PeakURL has been reinstalled."),
		description: __(
			"The latest version has been reinstalled. Here's a look at what's included in this release."
		),
	},
});

const LANDING_SOURCES: LandingSource[] = ["install", "update", "reinstall"];

const isLandingSource = (source: string | null): source is LandingSource =>
	null !== source && LANDING_SOURCES.includes(source as LandingSource);

const LandingBanner = ({ source }: LandingBannerProps) => {
	const shouldFetch = source === "update" || source === "reinstall";
	const { data: releaseNotesData } = useGetReleaseNotesQuery(undefined, {
		skip: !shouldFetch,
	});

	if (!isLandingSource(source)) {
		return null;
	}

	const meta = getLandingMeta()[source];

	let releaseNote = null;
	if (releaseNotesData?.data?.releases?.length) {
		const releases = releaseNotesData.data.releases;
		releaseNote =
			releases.find((r) => r.version === PEAKURL_VERSION) || releases[0];
	}

	return (
		<>
			<div className="about-page-landing mb-8">
				<p className="about-page-landing-eyebrow">{meta.eyebrow}</p>
				<h2 className="about-page-landing-title">{meta.title}</h2>
				<p className="about-page-landing-copy">{meta.description}</p>

				{meta.actions && meta.actions.length > 0 && (
					<div className="about-page-landing-actions">
						{meta.actions.map((action) => (
							<Link
								key={action.to}
								to={action.to}
								className="about-page-landing-action"
							>
								{action.label}
								<ChevronRight size={15} />
							</Link>
						))}
					</div>
				)}
			</div>

			{releaseNote && (
				<div className="bg-surface border border-stroke rounded-xl p-6 text-left shadow-sm mb-12">
					<div className="flex items-center gap-2 text-accent font-semibold text-xs mb-3 uppercase tracking-wide">
						<Sparkles className="w-4 h-4" />
						<span>
							{__("Version")} {releaseNote.version}
						</span>
						{releaseNote.releaseDate && (
							<>
								<span className="text-stroke px-1">•</span>
								<span className="text-text-muted normal-case">
									{sprintf(
										__("Released %s"),
										formatRelativeTime(
											releaseNote.releaseDate
										)
									)}
								</span>
							</>
						)}
					</div>
					<h3 className="text-base font-semibold text-heading mb-2">
						{releaseNote.title}
					</h3>
					<p className="text-sm leading-relaxed text-text-muted mb-6">
						{releaseNote.summary}
					</p>

					<div className="space-y-3 mb-6 bg-background border border-stroke/50 rounded-lg p-5">
						{releaseNote.highlights.map((highlight, idx) => (
							<div
								key={idx}
								className="flex items-start gap-3 text-sm text-text-muted"
							>
								<span className="text-accent text-sm leading-relaxed">
									•
								</span>
								<span className="leading-relaxed">
									{highlight}
								</span>
							</div>
						))}
					</div>

					<div className="flex flex-wrap items-center gap-x-6 gap-y-3 pt-5 border-t border-stroke/50 text-sm">
						<a
							href="https://go.peakurl.org/release-notes"
							target="_blank"
							rel="noopener noreferrer"
							className="inline-flex items-center gap-1.5 font-medium text-accent hover:text-accent-hover transition-colors"
						>
							<BookOpen className="w-4 h-4" />
							{__("For all release notes see the main page")}
						</a>
						<a
							href="https://go.peakurl.org/release-notes-txt"
							target="_blank"
							rel="noopener noreferrer"
							className="inline-flex items-center gap-1.5 font-medium text-text-muted hover:text-text transition-colors"
						>
							<TextAlignJustify className="w-4 h-4" />
							{__("Plain text format")}
						</a>
					</div>
				</div>
			)}
		</>
	);
};

/* ─── Feature cards ───────────────────────────────────────── */
const getFeatures = (): Feature[] => [
	{
		icon: Link2,
		title: __("Branded Short Links"),
		description: __(
			"Create clean short links with custom aliases, generated codes, QR output, and redirect settings that stay easy to manage."
		),
	},
	{
		icon: BarChart3,
		title: __("Click Analytics"),
		description: __(
			"Review clicks, unique visitors, referrers, device breakdowns, and traffic trends from the same dashboard used to publish links."
		),
	},
	{
		icon: Globe,
		title: __("Local Location Data"),
		description: __(
			"Resolve visitor locations from a local MaxMind GeoLite2 database so analytics stay under your control."
		),
	},
	{
		icon: Shield,
		title: __("Access And Security"),
		description: __(
			"Run with admin and editor roles, session controls, two-factor authentication, backup codes, and API keys."
		),
	},
	{
		icon: Code,
		title: __("Operations And Integrations"),
		description: __(
			"Use API keys, webhooks, import tools, and the built-in updater baseline to operate the service over time."
		),
	},
	{
		icon: Server,
		title: __("Portable Self-Hosting"),
		description: __(
			"Deploy on your own domain, keep runtime configuration in your own environment, and preserve user-owned content across updates."
		),
	},
];

const FeatureCard = ({ icon: Icon, title, description }: Feature) => (
	<div className="about-page-feature-card">
		<div className="about-page-feature-icon">
			<Icon size={20} className="text-accent" />
		</div>
		<h3 className="about-page-feature-title">{title}</h3>
		<p className="about-page-feature-copy">{description}</p>
	</div>
);

/* ─── Freedom cards ───────────────────────────────────────── */
const getFreedoms = (): Freedom[] => [
	{
		number: "0",
		title: __("Use"),
		description: __(
			"Run PeakURL for any purpose, personal projects, business, education, or anything in between."
		),
	},
	{
		number: "1",
		title: __("Study"),
		description: __(
			"Explore the source code, understand how it works, and modify it to suit your exact needs."
		),
	},
	{
		number: "2",
		title: __("Share"),
		description: __(
			"Redistribute copies freely so others can benefit from the same powerful link management platform."
		),
	},
	{
		number: "3",
		title: __("Improve"),
		description: __(
			"Enhance PeakURL and share your improvements, contributing back to the open-source community."
		),
	},
];

/* ═══════════════════════════════════════════════════════════ */
function AboutPage() {
	const [searchParams] = useSearchParams();
	const source = searchParams.get("source");
	const features = getFeatures();
	const freedoms = getFreedoms();
	const addOnLinks = getAddOnLinks();

	return (
		<div className="about-page">
			{/* ─── Hero (full-width breakout) ─────────────────── */}
			<section className="about-page-hero">
				{/* Subtle glow */}
				<div className="about-page-hero-glow" />

				<div className="about-page-hero-content">
					<div className="about-page-hero-version">
						<Sparkles
							size={14}
							className="about-page-hero-version-icon"
						/>
						{__("Version")} {PEAKURL_VERSION}
					</div>

					<div className="about-page-hero-mark">
						<div className="about-page-hero-mark-panel">
							<Link2
								size={32}
								className="about-page-hero-mark-icon"
							/>
						</div>
					</div>

					<h1 className="about-page-hero-title">
						{sprintf(__("Welcome to %s"), PEAKURL_NAME)}
					</h1>
					<p className="about-page-hero-summary">
						{__(
							"PeakURL gives you a focused workspace for publishing short links, measuring engagement, and running branded link infrastructure on your own stack."
						)}
					</p>

					<div className="about-page-hero-actions">
						<a
							href="https://peakurl.org/docs?utm_source=dashboard&utm_medium=about_page&utm_campaign=docs_link"
							target="_blank"
							rel="noreferrer"
							className="about-page-hero-link about-page-hero-link-primary"
						>
							<BookOpen size={16} />
							{__("Documentation")}
						</a>
						<a
							href="https://go.peakurl.org/repo"
							target="_blank"
							rel="noreferrer"
							className="about-page-hero-link about-page-hero-link-secondary"
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
							{__("View on GitHub")}
						</a>
					</div>

					<div className="about-page-hero-addons">
						{addOnLinks.map((item) => {
							const Icon = item.icon;

							return (
								<a
									key={item.label}
									href={item.href}
									target="_blank"
									rel="noreferrer"
									className="about-page-hero-addon"
								>
									<span className="about-page-hero-addon-icon">
										<Icon className="h-5 w-5" />
									</span>
									<span>{item.label}</span>
									<ExternalLink
										size={13}
										className="about-page-hero-addon-link-icon"
									/>
								</a>
							);
						})}
					</div>
				</div>
			</section>

			<div className="about-page-body">
				<LandingBanner source={source} />

				{/* ─── Features ───────────────────────────────────── */}
				<section>
					<SectionTitle
						subtitle={__(
							"Everything you need to publish short links, review traffic, and operate the service from one focused dashboard."
						)}
					>
						{__("What's Inside")}
					</SectionTitle>

					<div className="about-page-features-grid">
						{features.map((f) => (
							<FeatureCard key={f.title} {...f} />
						))}
					</div>
				</section>

				<Divider />

				{/* ─── Mission ────────────────────────────────────── */}
				<section>
					<SectionTitle
						subtitle={__(
							"PeakURL is built to stay understandable in day-to-day use and dependable over time."
						)}
					>
						{__("Product Principles")}
					</SectionTitle>

					<div className="about-page-principles-grid">
						<div className="about-page-principle-card">
							<div className="about-page-principle-icon">
								<Monitor size={22} className="text-accent" />
							</div>
							<h3 className="about-page-principle-title">
								{__("Clear By Default")}
							</h3>
							<p className="about-page-principle-copy">
								{__(
									"The dashboard should stay direct and readable so routine work like publishing links, reviewing traffic, and managing users does not feel heavy."
								)}
							</p>
						</div>
						<div className="about-page-principle-card">
							<div className="about-page-principle-icon">
								<Code size={22} className="text-accent" />
							</div>
							<h3 className="about-page-principle-title">
								{__("Portable To Run")}
							</h3>
							<p className="about-page-principle-copy">
								{__(
									"The application should install cleanly on common PHP and MySQL hosting without depending on a managed SaaS control plane."
								)}
							</p>
						</div>
						<div className="about-page-principle-card">
							<div className="about-page-principle-icon">
								<Heart size={22} className="text-accent" />
							</div>
							<h3 className="about-page-principle-title">
								{__("Open And Inspectable")}
							</h3>
							<p className="about-page-principle-copy">
								{__(
									"The codebase stays auditable and adaptable so operators can understand what they run, extend it carefully, and keep long-term control."
								)}
							</p>
						</div>
					</div>
				</section>

				<Divider />

				{/* ─── Four Freedoms ──────────────────────────────── */}
				<section>
					<SectionTitle
						subtitle={__(
							"PeakURL is released under the MIT License so it can stay practical to adopt, inspect, modify, and distribute."
						)}
					>
						{__("Open Source Terms")}
					</SectionTitle>

					<div className="about-page-freedoms-grid">
						{freedoms.map((f) => (
							<div
								key={f.number}
								className="about-page-freedom-card"
							>
								<div className="about-page-freedom-number">
									{f.number}
								</div>
								<div className="about-page-freedom-copy">
									<h3 className="about-page-freedom-title">
										{__("Freedom to")} {f.title}
									</h3>
									<p className="about-page-freedom-description">
										{f.description}
									</p>
								</div>
							</div>
						))}
					</div>
				</section>

				<Divider />

				<Sponsors />

				<Divider />

				{/* ─── Footer tagline ─────────────────────────────── */}
				<section className="about-page-footer">
					<div className="about-page-footer-brand">
						<BrandLockup to="/dashboard" size="md" />
					</div>
					<p className="about-page-footer-tagline">
						{__(
							"Self-hosted link management with clear ownership and a focused dashboard."
						)}
					</p>
					<div className="about-page-footer-links">
						<a
							href="https://peakurl.org?utm_source=dashboard&utm_medium=about_page&utm_campaign=website_link"
							target="_blank"
							rel="noreferrer"
							className="about-page-footer-link"
						>
							{__("Website")} <ExternalLink size={10} />
						</a>
						<span className="about-page-footer-separator">•</span>
						<a
							href="https://go.peakurl.org/repo"
							target="_blank"
							rel="noreferrer"
							className="about-page-footer-link"
						>
							GitHub <ExternalLink size={10} />
						</a>
						<span className="about-page-footer-separator">•</span>
						<a
							href="https://peakurl.org/release-notes?utm_source=dashboard&utm_medium=about_page&utm_campaign=release_notes_link"
							target="_blank"
							rel="noreferrer"
							className="about-page-footer-link"
						>
							{__("Release Notes")} <ExternalLink size={10} />
						</a>
					</div>
				</section>
			</div>
		</div>
	);
}

export default AboutPage;
