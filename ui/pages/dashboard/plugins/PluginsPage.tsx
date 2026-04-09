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
import { isDocumentRtl } from '@/i18n/direction';
import { __ } from '@/i18n';
import { PLUGINS_WAITLIST_URL } from '@constants';

function PluginsPage() {
	const isRtl = isDocumentRtl();
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

	/* ─── Loading ─── */
	if (isLoading) {
		return (
			<div className="rounded-lg border border-stroke bg-surface p-10 text-center">
				<div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-accent" />
			</div>
		);
	}

	/* ─── Non-admin gate ─── */
	if (!canManageUsers) {
		return (
			<div className="rounded-xl border border-stroke bg-surface p-10 text-center">
				<div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-500/10 text-amber-600">
					<ShieldCheck size={28} />
				</div>
				<h2 className="text-xl font-semibold text-heading">
					{__('Admin access required')}
				</h2>
				<p className="mt-2 text-sm text-text-muted">
					{__(
						'Only admin accounts will be able to install, update, and manage plugins.'
					)}
				</p>
			</div>
		);
	}

	return (
		<div className="space-y-0 pb-8">
			{/* ════════════════════════════════════
			    PAGE HEADER
			   ════════════════════════════════════ */}
			<div className="relative overflow-hidden rounded-t-2xl border border-stroke bg-surface px-6 py-6 shadow-sm sm:px-8">
				{/* Decorative blobs */}
				<div className="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-accent/5 blur-3xl" />
				<div className="pointer-events-none absolute -left-16 bottom-0 h-48 w-48 rounded-full bg-purple-500/5 blur-3xl" />

				<div className="relative flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
					<div className="max-w-3xl">
						<div className="mb-3 flex flex-wrap items-center gap-3">
							<div className="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-accent/10 text-accent">
								<Plug size={21} />
							</div>
						</div>
						<h1 className="text-2xl font-semibold tracking-tight text-heading sm:text-3xl">
							{__('Plugins')}
						</h1>
						<p className="mt-2 max-w-2xl text-sm leading-relaxed text-text-muted sm:text-base">
							{__(
								'Extend PeakURL with plugins, install from the library, upload custom packages, and manage everything from one place.'
							)}
						</p>
					</div>

					{/* Waitlist CTA */}
					<div className="relative overflow-hidden rounded-2xl border border-accent/20 bg-surface p-5 sm:min-w-65">
						<div className="pointer-events-none absolute -right-8 -top-8 h-32 w-32 rounded-full" />
						<div className="relative">
							<div className="mb-2 flex items-center gap-2">
								<span className="inline-flex items-center gap-1.5 rounded-full bg-accent/15 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-widest text-accent">
									<Sparkles size={9} />
									{__('Coming Soon')}
								</span>
							</div>
							<p className="mb-4 text-sm font-medium leading-snug text-heading">
								{__(
									'Be the first to know when the plugin library launches.'
								)}
							</p>
							<a
								href={PLUGINS_WAITLIST_URL}
								target="_blank"
								rel="noreferrer"
								className="group inline-flex items-center gap-2 rounded-xl bg-accent px-5 py-2.5 text-sm font-semibold text-white shadow-md transition-all hover:bg-accent/90 hover:shadow-lg hover:-translate-y-0.5 active:translate-y-0 active:shadow-sm"
							>
								<Bell
									size={15}
									className="transition-transform group-hover:rotate-12"
								/>
								{__('Join the Waitlist')}
								<ExternalLink
									size={13}
									className="opacity-60"
								/>
							</a>
						</div>
					</div>
				</div>
			</div>

			{/* ════════════════════════════════════
			    TAB BAR + VIEW TOGGLE
			   ════════════════════════════════════ */}
			<div className="overflow-hidden border-x border-stroke bg-surface shadow-sm">
				<div className="flex flex-col gap-3 px-6 sm:flex-row sm:items-center sm:justify-between sm:px-8">
					<PluginTabs
						activeTab={activeTab}
						onTabChange={setActiveTab}
						tabs={tabs}
					/>
					{activeTab !== 'installed' && (
						<div className="flex items-center gap-2 pb-3 sm:pb-0">
							{/* Disabled search placeholder */}
							<div className="relative max-w-55 flex-1">
								<div
									className="field-with-inline-start-icon text-inline-start pointer-events-none w-full rounded-lg border border-stroke bg-surface-alt/50 py-2 text-sm text-text-muted/40"
								>
									{__('Search plugins…')}
								</div>
								<div
									className="inline-start-icon-slot pointer-events-none absolute inset-y-0 flex items-center text-text-muted/40"
								>
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
							<div className="flex rounded-lg border border-stroke">
								<button
									onClick={() => setViewMode('grid')}
									className={`flex h-9 w-9 items-center justify-center transition-colors ${
										isRtl ? 'rounded-r-lg' : 'rounded-l-lg'
									} ${
										viewMode === 'grid'
											? 'bg-accent/10 text-accent'
											: 'text-text-muted hover:bg-surface-alt'
									}`}
								>
									<Grid3X3 size={15} />
								</button>
								<button
									onClick={() => setViewMode('list')}
									className={`flex h-9 w-9 items-center justify-center transition-colors ${
										isRtl
											? 'rounded-l-lg border-r'
											: 'rounded-r-lg border-l'
									} border-stroke ${
										viewMode === 'list'
											? 'bg-accent/10 text-accent'
											: 'text-text-muted hover:bg-surface-alt'
									}`}
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
			<div className="relative overflow-hidden rounded-b-2xl border-x border-b border-stroke bg-surface shadow-sm">
				{/* ── Installed tab: table ── */}
				{activeTab === 'installed' && (
					<div className="px-6 pb-6 pt-5 sm:px-8">
						<InstalledPluginsTable plugins={activeCards} />
					</div>
				)}

				{/* ── Browse / Featured / Popular: card grid ── */}
				{activeTab !== 'installed' && (
					<div className="px-6 pb-6 pt-5 sm:px-8">
						{/* Tab context banner */}
						{activeTab === 'featured' && (
							<div className="mb-5 flex items-center gap-3 rounded-xl bg-linear-to-r from-accent/10 via-purple-500/10 to-pink-500/10 px-5 py-4">
								<Sparkles
									size={18}
									className="shrink-0 text-accent"
								/>
								<div>
									<h3 className="text-sm font-semibold text-heading">
										{__('Featured Plugins')}
									</h3>
									<p className="text-xs text-text-muted">
										{__(
											'Hand-picked extensions recommended by the PeakURL team.'
										)}
									</p>
								</div>
							</div>
						)}
						{activeTab === 'popular' && (
							<div className="mb-5 flex items-center gap-3 rounded-xl bg-linear-to-r from-orange-500/10 via-red-500/10 to-pink-500/10 px-5 py-4">
								<TrendingUp
									size={18}
									className="shrink-0 text-orange-500"
								/>
								<div>
									<h3 className="text-sm font-semibold text-heading">
										{__('Most Popular')}
									</h3>
									<p className="text-xs text-text-muted">
										{__(
											'Extensions with the highest community adoption.'
										)}
									</p>
								</div>
							</div>
						)}

						{/* Card grid (or list table) */}
						{viewMode === 'grid' ? (
							<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
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
				<div className="pointer-events-none absolute inset-x-0 bottom-0 h-44 bg-linear-to-t from-surface via-surface/80 to-transparent" />
			</div>

			{/* ════════════════════════════════════
			    WORK IN PROGRESS HERO
			   ════════════════════════════════════ */}
			<div className="mt-5 overflow-hidden rounded-2xl border border-stroke bg-surface shadow-sm">
				<div className="relative px-6 py-8 sm:px-10 sm:py-10">
					{/* Background decoration */}
					<div className="pointer-events-none absolute -right-20 -top-20 h-72 w-72 rounded-full bg-accent/5 blur-3xl" />
					<div className="pointer-events-none absolute -left-12 bottom-0 h-48 w-48 rounded-full bg-purple-500/5 blur-3xl" />

					<div className="relative mx-auto max-w-2xl text-center">
						<div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-accent/10 text-accent">
							<Wrench size={24} />
						</div>
						<h2 className="text-xl font-semibold tracking-tight text-heading sm:text-2xl">
							{__('Plugin System Under Development')}
						</h2>
						<p className="mx-auto mt-3 max-w-lg text-sm leading-relaxed text-text-muted">
							{__(
								"We're building a full plugin ecosystem for PeakURL. Install extensions, manage updates, and customise your experience from the dashboard. Here's what's coming:"
							)}
						</p>
					</div>

					{/* Feature roadmap cards */}
					<div className="relative mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
			className={`rounded-xl border border-stroke bg-linear-to-br ${gradient} p-5 transition-shadow hover:shadow-sm`}
		>
			<div className="mb-3 flex h-9 w-9 items-center justify-center rounded-xl bg-surface text-accent shadow-sm">
				<Icon size={17} />
			</div>
			<h3 className="text-sm font-semibold text-heading">{title}</h3>
			<p className="mt-1.5 text-xs leading-relaxed text-text-muted">
				{description}
			</p>
		</div>
	);
}

export default PluginsPage;
