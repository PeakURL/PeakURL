// @ts-nocheck
import { Button } from '@/components/ui';
import { Plus, Copy, Trash2, Info, Key, LoaderCircle } from 'lucide-react';

function ApiTab({
	user,
	isGeneratingKey,
	isDeletingKey,
	onDeleteKey,
	setShowCreateModal,
	copyToClipboard,
}) {
	return (
		<div className="space-y-5">
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
							<LoaderCircle size={16} className="mr-2 animate-spin" />
						) : (
							<Plus size={16} className="mr-2" />
						)}
						Create New Key
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
											{key.label || 'API Key'}
										</p>
										<span className="text-xs px-2 py-0.5 bg-emerald-500/10 dark:bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 rounded">
											Active
										</span>
									</div>
									<div className="flex items-center gap-2">
										<p className="text-xs text-text-muted font-mono truncate">
											{key.key
												? key.key.substring(0, 10) +
													'•'.repeat(20)
												: '••••••••••••••••••••••••••••••••'}
										</p>
										{key.key && (
											<button
												onClick={() =>
													copyToClipboard(key.key)
												}
												className="p-1 text-text-muted hover:text-heading"
												title="Copy full key"
											>
												<Copy size={12} />
											</button>
										)}
									</div>
									<p className="text-xs text-text-muted mt-1">
										Created:{' '}
										{new Date(
											key.createdAt
										).toLocaleDateString()}
									</p>
								</div>
								<div className="flex items-center gap-1 ml-4">
									<button
										className="p-2 text-text-muted hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
										aria-label="Delete"
										onClick={() => onDeleteKey(key.id)}
										disabled={isDeletingKey}
										title="Delete API Key"
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
							No API Keys Generated
						</h3>
						<p className="text-xs text-text-muted mb-4">
							Generate an API key to access endpoints
							programmatically.
						</p>
						<Button
							size="sm"
							onClick={() => setShowCreateModal(true)}
							disabled={isGeneratingKey}
						>
							Create New Key
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
							Keep your API keys secure
						</p>
						<p className="text-xs text-blue-700 dark:text-blue-300">
							Never share your API keys publicly or commit them to
							version control. If a key is compromised, revoke it
							immediately and generate a new one.
						</p>
					</div>
				</div>
			</div>
		</div>
	);
}

export default ApiTab;
