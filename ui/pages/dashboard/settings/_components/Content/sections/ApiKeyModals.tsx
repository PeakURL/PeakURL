// @ts-nocheck
import { Modal, Button, Input } from '@/components/ui';
import { Copy } from 'lucide-react';

function ApiKeyModals({
	showCreateModal,
	setShowCreateModal,
	showKeyModal,
	setShowKeyModal,
	keyLabel,
	setKeyLabel,
	newApiKey,
	onCreateKey,
	copyToClipboard,
	isGeneratingKey,
}) {
	return (
		<>
			<Modal
				isOpen={showCreateModal}
				onClose={() => setShowCreateModal(false)}
				title="Create API Key"
				size="sm"
			>
				<div className="space-y-5">
					<div className="space-y-1">
						<p className="text-sm font-medium text-heading">
							Create a dedicated token for scripts, automations, or
							external tools.
						</p>
						<p className="text-sm text-text-muted">
							You can give it a label now so it is easier to
							recognize later in your API key list.
						</p>
					</div>
					<Input
						label="Label (Optional)"
						placeholder="e.g. Production automation"
						value={keyLabel}
						onChange={(e) => setKeyLabel(e.target.value)}
					/>
					<div className="flex justify-end gap-2">
						<Button
							variant="secondary"
							onClick={() => setShowCreateModal(false)}
						>
							Cancel
						</Button>
						<Button
							onClick={onCreateKey}
							disabled={isGeneratingKey}
						>
							{isGeneratingKey ? 'Creating...' : 'Create API Key'}
						</Button>
					</div>
				</div>
			</Modal>

			<Modal
				isOpen={showKeyModal}
				onClose={setShowKeyModal}
				title="Copy Your API Key"
				size="md"
			>
				<div className="space-y-5">
					<div className="rounded-xl border border-blue-500/20 bg-blue-500/10 px-4 py-3">
						<p className="text-sm font-medium text-blue-700 dark:text-blue-300">
							This token will not be shown again.
						</p>
						<p className="mt-1 text-xs leading-5 text-blue-700 dark:text-blue-300">
							Store it in a password manager, environment file, or
							secret store before closing this window.
						</p>
					</div>
					<div className="relative">
						<pre className="p-3 bg-surface-alt rounded-lg text-sm font-mono break-all border border-stroke">
							{newApiKey}
						</pre>
						<button
							type="button"
							onClick={() => copyToClipboard(newApiKey)}
							className="absolute top-2 right-2 p-1.5 text-text-muted hover:text-heading bg-surface rounded shadow-sm hover:shadow transition-all"
							title="Copy to clipboard"
						>
							<Copy size={14} />
						</button>
					</div>
					<p className="text-sm text-text-muted">
						If this key is ever exposed, revoke it from the API Keys
						list and create a replacement.
					</p>
					<div className="flex justify-end gap-2">
						<Button
							variant="secondary"
							onClick={() => copyToClipboard(newApiKey)}
						>
							<Copy size={16} className="mr-2" />
							Copy Key
						</Button>
						<Button onClick={setShowKeyModal}>
							I&apos;ve Stored It
						</Button>
					</div>
				</div>
			</Modal>
		</>
	);
}

export default ApiKeyModals;
