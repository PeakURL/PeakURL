import { Modal, Button, Input } from '@/components/ui';
import { Copy } from 'lucide-react';
import { __ } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import type { ApiKeyModalsProps } from './types';

function ApiKeyModals({
	showCreateModal,
	setShowCreateModal,
	showKeyModal,
	setShowKeyModal,
	keyLabel,
	setKeyLabel,
	newApiKey,
	baseApiUrl,
	onCreateKey,
	copyToClipboard,
	isGeneratingKey,
}: ApiKeyModalsProps) {
	const isRtl = isDocumentRtl();
	return (
		<>
			<Modal
				isOpen={showCreateModal}
				onClose={() => setShowCreateModal(false)}
				title={__('Create API Key')}
				size="sm"
			>
				<div className="space-y-5">
					<div className="space-y-1">
						<p className="text-sm font-medium text-heading">
							{__(
								'Create a dedicated token for scripts, automations, or external tools.'
							)}
						</p>
						<p className="text-sm text-text-muted">
							{__(
								'You can give it a label now so it is easier to recognize later in your API key list.'
							)}
						</p>
					</div>
					<Input
						label={__('Label (Optional)')}
						placeholder={__('e.g. Production automation')}
						value={keyLabel}
						onChange={(event) => setKeyLabel(event.target.value)}
					/>
					<div
						className={`flex gap-2 ${
							isRtl ? 'justify-start' : 'justify-end'
						}`}
					>
						<Button
							variant="secondary"
							onClick={() => setShowCreateModal(false)}
						>
							{__('Cancel')}
						</Button>
						<Button
							onClick={onCreateKey}
							disabled={isGeneratingKey}
						>
							{isGeneratingKey
								? __('Creating...')
								: __('Create API Key')}
						</Button>
					</div>
				</div>
			</Modal>

			<Modal
				isOpen={showKeyModal}
				onClose={() => setShowKeyModal(false)}
				title={__('Copy Your API Key')}
				size="md"
			>
				<div className="space-y-5">
					<div className="rounded-xl border border-blue-500/20 bg-blue-500/10 px-4 py-3">
						<p className="text-sm font-medium text-blue-700 dark:text-blue-300">
							{__('This token will not be shown again.')}
						</p>
						<p className="mt-1 text-xs leading-5 text-blue-700 dark:text-blue-300">
							{__(
								'Store it in a password manager, environment file, or secret store before closing this window.'
							)}
						</p>
					</div>
					<div className="relative">
						<pre
							className="ltr-literal-value p-3 bg-surface-alt rounded-lg text-sm font-mono break-all border border-stroke"
						>
							{newApiKey}
						</pre>
						<button
							type="button"
							onClick={() => copyToClipboard(newApiKey)}
							className="logical-inset-inline-end-2 absolute top-2 rounded bg-surface p-1.5 text-text-muted shadow-sm transition-all hover:text-heading hover:shadow"
							title={__('Copy to clipboard')}
						>
							<Copy size={14} />
						</button>
					</div>
					{baseApiUrl && (
						<div className="space-y-2">
							<p className="text-sm font-medium text-heading">
								{__('Base API URL')}
							</p>
							<div className="relative">
								<pre
									className="ltr-literal-value p-3 bg-surface-alt rounded-lg text-sm font-mono break-all border border-stroke"
								>
									{baseApiUrl}
								</pre>
								<button
									type="button"
									onClick={() =>
										copyToClipboard(
											baseApiUrl,
											__(
												'Base API URL copied to clipboard'
											)
										)
									}
									className="logical-inset-inline-end-2 absolute top-2 rounded bg-surface p-1.5 text-text-muted shadow-sm transition-all hover:text-heading hover:shadow"
									title={__('Copy to clipboard')}
								>
									<Copy size={14} />
								</button>
							</div>
							<p className="text-xs text-text-muted">
								{__(
									'Use this API URL with integrations that need both the endpoint and the token.'
								)}
							</p>
						</div>
					)}
					<p className="text-sm text-text-muted">
						{__(
							'If this key is ever exposed, revoke it from the API Keys list and create a replacement.'
						)}
					</p>
					<div
						className={`flex gap-2 ${
							isRtl ? 'justify-start' : 'justify-end'
						}`}
					>
						<Button
							variant="secondary"
							icon={Copy}
							onClick={() => copyToClipboard(newApiKey)}
						>
							{__('Copy Key')}
						</Button>
						<Button onClick={() => setShowKeyModal(false)}>
							{__("I've Stored It")}
						</Button>
					</div>
				</div>
			</Modal>
		</>
	);
}

export default ApiKeyModals;
