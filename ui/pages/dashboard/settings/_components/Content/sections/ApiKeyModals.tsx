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
				title="Create New API Key"
				size="sm"
			>
				<div className="space-y-4">
					<Input
						label="Label (Optional)"
						placeholder="e.g. Production Key"
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
							{isGeneratingKey ? 'Creating...' : 'Create Key'}
						</Button>
					</div>
				</div>
			</Modal>

			<Modal
				isOpen={showKeyModal}
				onClose={() => setShowKeyModal(false)}
				title="Your API Key"
				size="md"
			>
				<div className="space-y-4">
					<p className="text-sm text-text-muted">
						Please copy your API key now. You won&apos;t be able to
						see it again!
					</p>
					<div className="relative">
						<pre className="p-3 bg-surface-alt rounded-lg text-sm font-mono break-all border border-stroke">
							{newApiKey}
						</pre>
						<button
							onClick={() => copyToClipboard(newApiKey)}
							className="absolute top-2 right-2 p-1.5 text-text-muted hover:text-heading bg-surface rounded shadow-sm hover:shadow transition-all"
							title="Copy to clipboard"
						>
							<Copy size={14} />
						</button>
					</div>
					<div className="flex justify-end">
						<Button onClick={() => setShowKeyModal(false)}>
							I&apos;ve copied it
						</Button>
					</div>
				</div>
			</Modal>
		</>
	);
}

export default ApiKeyModals;
