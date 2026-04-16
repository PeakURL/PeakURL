import type {
	FeatureRoadmapCardProps,
	PluginTabItem,
	TabId,
	ViewMode,
} from './types';
import { useState } from 'react';
import {
	Plug,
	ShieldCheck,
	Sparkles,
	TrendingUp,
	Grid3X3,
	LayoutList,
	Wrench,
	Rocket,
	Code2,
	FolderTree,
	Eye,
	RefreshCw,
	ExternalLink,
	Bell,
} from 'lucide-react';
import { useAdminAccess } from '@/hooks';
import { InstalledPluginsTable, PluginCard, PluginTabs } from './_components';
import {
	BROWSE_CARDS,
	INSTALLED_CARDS,
	FEATURED_CARDS,
	POPULAR_CARDS,
} from './pluginData';
import { __ } from '@/i18n';
import { cn } from '@/utils';
import { PLUGINS_WAITLIST_URL } from '@constants';

function PluginsPage() {
	const { canManageUsers, isLoading } = useAdminAccess();
	const [activeTab, setActiveTab] = useState<TabId>('browse');
	const [viewMode, setViewMode] = useState<ViewMode>('grid');

	const tabs: PluginTabItem[] = [
		{
			id: 'installed',
			label: __('Installed'),
			count: INSTALLED_CARDS.length,
		},
		{ id: 'browse', label: __('Browse All'), count: BROWSE_CARDS.length },
		{ id: 'featured', label: __('Featured') },
		{ id: 'popular', label: __('Popular') },
	];

	const activeCards = (() => {
		switch (activeTab) {
			case 'installed':
				return INSTALLED_CARDS;
			case 'featured':
				return FEATURED_CARDS;
			case 'popular':
				return POPULAR_CARDS;
			default:
				return BROWSE_CARDS;
		}
	})();

	const getViewButtonClassName = (mode: ViewMode) =>
		cn(
			'plugins-page-view-button',
			`plugins-page-view-button-${mode}`,
			viewMode === mode
				? 'plugins-page-view-button-active'
				: 'plugins-page-view-button-inactive'
		);

	/* ─── Loading ─── */
	if (isLoading) {
		return (
			<div className="plugins-page-loading">
				<div className="plugins-page-loading-spinner" />
			</div>
		);
	}

	/* ─── Non-admin gate ─── */
	if (!canManageUsers) {
		return (
			<div className="plugins-page-gate">
				<div className="plugins-page-gate-icon">
					<ShieldCheck size={28} />
				</div>
				<h2 className="plugins-page-gate-title">
					{__('Admin access required')}
				</h2>
				<p className="plugins-page-gate-copy">
					{__(
						'Only admin accounts will be able to install, update, and manage plugins.'
					)}
				</p>
			</div>
		);
	}

	return (
		<div className="plugins-page">
			{/* ════════════════════════════════════
			    PAGE HEADER
			   ════════════════════════════════════ */}
			<div className="plugins-page-header">
				<div className="plugins-page-header-layout">
					<div className="plugins-page-header-copy">
						<div className="plugins-page-header-icon-row">
							<div className="plugins-page-header-icon">
								<Plug size={21} />
							</div>
						</div>
						<h1 className="plugins-page-header-title">
							{__('Plugins')}
						</h1>
						<p className="plugins-page-header-summary">
							{__(
								'Extend PeakURL with plugins, install from the library, upload custom packages, and manage everything from one place.'
							)}
						</p>
					</div>

					{/* Waitlist CTA */}
					<div className="plugins-page-header-waitlist">
						<div className="plugins-page-header-waitlist-inner">
							<div className="plugins-page-header-waitlist-badge-row">
								<span className="plugins-page-header-waitlist-badge">
									<Sparkles
										size={9}
										className="plugins-page-header-waitlist-badge-icon"
									/>
									{__('Coming Soon')}
								</span>
							</div>
							<p className="plugins-page-header-waitlist-copy">
								{__(
									'Be the first to know when the plugin library launches.'
								)}
							</p>
							<a
								href={PLUGINS_WAITLIST_URL}
								target="_blank"
								rel="noreferrer"
								className="plugins-page-header-waitlist-button group"
							>
								<Bell
									size={15}
									className="plugins-page-header-waitlist-button-icon group-hover:rotate-12"
								/>
								{__('Join the Waitlist')}
								<ExternalLink
									size={13}
									className="plugins-page-header-waitlist-button-link"
								/>
							</a>
						</div>
					</div>
				</div>
			</div>

			{/* ════════════════════════════════════
			    TAB BAR + VIEW TOGGLE
			   ════════════════════════════════════ */}
			<div className="plugins-page-toolbar">
				<div className="plugins-page-toolbar-inner">
					<PluginTabs
						activeTab={activeTab}
						onTabChange={setActiveTab}
						tabs={tabs}
					/>
					{activeTab !== 'installed' && (
						<div className="plugins-page-toolbar-controls">
							{/* Disabled search placeholder */}
							<div className="plugins-page-search">
								<div className="plugins-page-search-field">
									{__('Search plugins…')}
								</div>
								<div className="plugins-page-search-icon">
									<svg
										className="h-4 w-4"
										fill="none"
										viewBox="0 0 24 24"
										stroke="currentColor"
										strokeWidth={2}
									>
										<circle cx="11" cy="11" r="8" />
										<path d="m21 21-4.3-4.3" />
									</svg>
								</div>
							</div>
							<div className="plugins-page-view-toggle">
								<button
									onClick={() => setViewMode('grid')}
									className={getViewButtonClassName('grid')}
								>
									<Grid3X3 size={15} />
								</button>
								<button
									onClick={() => setViewMode('list')}
									className={getViewButtonClassName('list')}
								>
									<LayoutList size={15} />
								</button>
							</div>
						</div>
					)}
				</div>
			</div>

			{/* ════════════════════════════════════
			    CONTENT AREA — BLURRED CARDS
			   ════════════════════════════════════ */}
			<div className="plugins-page-content">
				{/* ── Installed tab: table ── */}
				{activeTab === 'installed' && (
					<div className="plugins-page-content-panel">
						<InstalledPluginsTable plugins={activeCards} />
					</div>
				)}

				{/* ── Browse / Featured / Popular: card grid ── */}
				{activeTab !== 'installed' && (
					<div className="plugins-page-content-panel">
						{/* Tab context banner */}
						{activeTab === 'featured' && (
							<div className="plugins-page-banner plugins-page-banner-featured">
								<Sparkles
									size={18}
									className="plugins-page-banner-icon plugins-page-banner-icon-featured"
								/>
								<div>
									<h3 className="plugins-page-banner-title">
										{__('Featured Plugins')}
									</h3>
									<p className="plugins-page-banner-copy">
										{__(
											'Hand-picked extensions recommended by the PeakURL team.'
										)}
									</p>
								</div>
							</div>
						)}
						{activeTab === 'popular' && (
							<div className="plugins-page-banner plugins-page-banner-popular">
								<TrendingUp
									size={18}
									className="plugins-page-banner-icon plugins-page-banner-icon-popular"
								/>
								<div>
									<h3 className="plugins-page-banner-title">
										{__('Most Popular')}
									</h3>
									<p className="plugins-page-banner-copy">
										{__(
											'Extensions with the highest community adoption.'
										)}
									</p>
								</div>
							</div>
						)}

						{/* Card grid (or list table) */}
						{viewMode === 'grid' ? (
							<div className="plugins-page-grid">
								{activeCards.map((card) => (
									<PluginCard key={card.id} plugin={card} />
								))}
							</div>
						) : (
							<InstalledPluginsTable plugins={activeCards} />
						)}
					</div>
				)}

				{/* ── Floating "under construction" overlay ── */}
				<div className="plugins-page-content-overlay" />
			</div>

			{/* ════════════════════════════════════
			    WORK IN PROGRESS HERO
			   ════════════════════════════════════ */}
			<div className="plugins-page-roadmap">
				<div className="plugins-page-roadmap-inner">
					{/* Background decoration */}
					<div className="plugins-page-roadmap-glow-end" />
					<div className="plugins-page-roadmap-glow-start" />

					<div className="plugins-page-roadmap-copy">
						<div className="plugins-page-roadmap-icon">
							<Wrench size={24} />
						</div>
						<h2 className="plugins-page-roadmap-title">
							{__('Plugin System Under Development')}
						</h2>
						<p className="plugins-page-roadmap-description">
							{__(
								"We're building a full plugin ecosystem for PeakURL. Install extensions, manage updates, and customise your experience from the dashboard. Here's what's coming:"
							)}
						</p>
					</div>

					{/* Feature roadmap cards */}
					<div className="plugins-page-roadmap-grid">
						<FeatureRoadmapCard
							icon={Rocket}
							title={__('One-Click Installs')}
							description={__(
								'Browse the plugin library and install ZIP packages straight from the dashboard with compatibility checks.'
							)}
							gradient="from-blue-500/10 to-indigo-500/10"
						/>
						<FeatureRoadmapCard
							icon={Eye}
							title={__('Activate & Manage')}
							description={__(
								'Review plugin details, enable or disable features, and access per-plugin settings screens.'
							)}
							gradient="from-emerald-500/10 to-teal-500/10"
						/>
						<FeatureRoadmapCard
							icon={RefreshCw}
							title={__('Safe Updates')}
							description={__(
								'Managed update checks from trusted manifests with rollback guardrails and clear notices.'
							)}
							gradient="from-orange-500/10 to-red-500/10"
						/>
						<FeatureRoadmapCard
							icon={FolderTree}
							title={__('Persistent Storage')}
							description={__(
								'Plugins live in content/plugins so core releases never wipe your extensions.'
							)}
							gradient="from-violet-500/10 to-purple-500/10"
						/>
						<FeatureRoadmapCard
							icon={Code2}
							title={__('Extension APIs')}
							description={__(
								'Documented hooks for settings screens, link actions, analytics widgets, and integrations.'
							)}
							gradient="from-pink-500/10 to-rose-500/10"
						/>
						<FeatureRoadmapCard
							icon={ShieldCheck}
							title={__('Admin Review Flow')}
							description={__(
								'Every install surfaces version, author, homepage, and compatibility notes before activation.'
							)}
							gradient="from-cyan-500/10 to-sky-500/10"
						/>
					</div>
				</div>
			</div>
		</div>
	);
}

/* ─── Small roadmap feature card ─── */
function FeatureRoadmapCard({
	icon: Icon,
	title,
	description,
	gradient,
}: FeatureRoadmapCardProps) {
	return (
		<div
			className={cn(
				'plugins-page-roadmap-card',
				'bg-linear-to-br',
				gradient
			)}
		>
			<div className="plugins-page-roadmap-card-icon">
				<Icon size={17} />
			</div>
			<h3 className="plugins-page-roadmap-card-title">{title}</h3>
			<p className="plugins-page-roadmap-card-copy">{description}</p>
		</div>
	);
}

export default PluginsPage;
