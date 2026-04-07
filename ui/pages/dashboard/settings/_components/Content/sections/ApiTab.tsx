import { Button } from '@/components/ui';
import {
	Plus,
	Trash2,
	Info,
	Key,
	LoaderCircle,
	ExternalLink,
	BookOpen,
	Copy,
	Link2,
} from 'lucide-react';
import { __ } from '@/i18n';
import type { ApiTabProps } from './types';

function ApiTab({
	user,
	baseApiUrl,
	copyToClipboard,
	isGeneratingKey,
	isDeletingKey,
	onDeleteKey,
	setShowCreateModal,
}: ApiTabProps) {
	return (
		<div className="space-y-5">
			{baseApiUrl && (
				<div className="rounded-lg border border-accent/20 bg-surface p-5">
					<div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
						<div className="space-y-3">
							<div className="flex items-center gap-2 text-heading">
								<div className="flex h-9 w-9 items-center justify-center rounded-lg bg-accent/12 text-accent">
									<Link2 size={18} />
								</div>
								<div className="space-y-1">
									<p className="text-xs font-medium uppercase tracking-[0.18em] text-text-muted">
										{__('Connection')}
									</p>
									<h2 className="text-base font-semibold">
										{__('Base API URL')}
									</h2>
								</div>
							</div>
							<p className="max-w-2xl text-sm text-text-muted">
								{__(
									'Use this endpoint with WordPress, browser extensions, scripts, and other API clients.'
								)}
							</p>
						</div>
						<Button
							size="sm"
							variant="secondary"
							onClick={() =>
								copyToClipboard(
									baseApiUrl,
									__('Base API URL copied to clipboard')
								)
							}
						>
							<Copy size={14} className="mr-2" />
							{__('Copy API URL')}
						</Button>
					</div>
					<div className="mt-4 rounded-xl border border-accent/15 bg-surface/90 px-4 py-3 shadow-sm">
						<p className="font-mono text-sm break-all text-heading">
							{baseApiUrl}
						</p>
					</div>
				</div>
			)}

			<div className="bg-surface border border-stroke rounded-lg p-5">
				<div className="flex items-center justify-between mb-5">
					<h2 className="text-base font-semibold text-heading">
						API Keys
					</h2>
					<Button
						size="sm"
						onClick={() => setShowCreateModal(true)}
						disabled={isGeneratingKey}
					>
						{isGeneratingKey ? (
							<LoaderCircle
								size={16}
								className="mr-2 animate-spin"
							/>
						) : (
							<Plus size={16} className="mr-2" />
						)}
						{__('Create New Key')}
					</Button>
				</div>

				{user?.apiKeys && user.apiKeys.length > 0 ? (
					<div className="space-y-3">
						{user.apiKeys.map((key) => (
							<div
								key={key.id}
								className="flex items-center justify-between p-4 border border-stroke rounded-lg hover:border-accent/50 transition-colors"
							>
								<div className="flex-1 min-w-0">
									<div className="flex items-center gap-2 mb-1">
										<p className="font-medium text-sm text-heading">
											{key.label || __('API Key')}
										</p>
										<span className="text-xs px-2 py-0.5 bg-emerald-500/10 dark:bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 rounded">
											{__('Active')}
										</span>
									</div>
									<div className="flex items-center gap-2">
										<p className="text-xs text-text-muted font-mono truncate">
											{key.maskedKey || '••••••••'}
										</p>
									</div>
									<p className="text-xs text-text-muted mt-1">
										{__('Created:')}{' '}
										{key.createdAt
											? new Date(
													key.createdAt
												).toLocaleDateString()
											: __('Unknown')}
									</p>
								</div>
								<div className="flex items-center gap-1 ml-4">
									<button
										type="button"
										className="p-2 text-text-muted hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
										aria-label={__('Delete')}
										onClick={() => onDeleteKey(key)}
										disabled={isDeletingKey}
										title={__('Delete API Key')}
									>
										<Trash2 size={16} />
									</button>
								</div>
							</div>
						))}
					</div>
				) : (
					<div className="text-center py-8 bg-surface-alt rounded-lg border border-dashed border-stroke">
						<Key
							size={32}
							className="mx-auto text-text-muted mb-3"
						/>
						<h3 className="text-sm font-medium text-heading mb-1">
							{__('No API Keys Generated')}
						</h3>
						<p className="text-xs text-text-muted mb-4">
							{__(
								'Generate an API key to access endpoints programmatically.'
							)}
						</p>
						<Button
							size="sm"
							onClick={() => setShowCreateModal(true)}
							disabled={isGeneratingKey}
						>
							{__('Create New Key')}
						</Button>
					</div>
				)}
			</div>

			<div className="bg-blue-500/10 dark:bg-blue-500/20 border border-blue-500/20 dark:border-blue-500/30 rounded-lg p-4">
				<div className="flex items-start gap-3">
					<Info
						size={18}
						className="text-blue-600 dark:text-blue-400 mt-0.5"
					/>
					<div>
						<p className="font-medium text-sm text-blue-700 dark:text-blue-300 mb-1">
							{__('Keep your API keys secure')}
						</p>
						<p className="text-xs text-blue-700 dark:text-blue-300">
							{__(
								'PeakURL only shows the full token once, at creation time. Store it in a password manager or secret store, and revoke it immediately if you think it has been exposed.'
							)}
						</p>
					</div>
				</div>
			</div>

			<div className="bg-surface border border-stroke rounded-lg p-5">
				<div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
					<div className="space-y-1">
						<div className="flex items-center gap-2 text-heading">
							<BookOpen size={18} className="text-accent" />
							<h3 className="text-sm font-semibold">
								{__('API documentation')}
							</h3>
						</div>
						<p className="text-sm text-text-muted max-w-2xl">
							{__(
								'Use the public docs for authentication, links, analytics, users, webhooks, and system endpoints.'
							)}
						</p>
					</div>
					<a
						href="https://peakurl.org/docs/api"
						target="_blank"
						rel="noreferrer"
					>
						<Button size="sm" variant="secondary">
							{__('API Overview')}
							<ExternalLink size={14} className="ml-2" />
						</Button>
					</a>
				</div>
			</div>
		</div>
	);
}

export default ApiTab;
