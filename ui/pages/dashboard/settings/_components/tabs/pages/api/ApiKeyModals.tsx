import { Modal, Button, Input, ReadOnlyValueBlock } from '@/components/ui';
import { Copy } from 'lucide-react';
import { __ } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import { cn } from '@/utils';
import type { ApiKeyModalsProps } from '../types';

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
				<div className="settings-api-modal-content">
					<div className="settings-api-modal-section">
						<p className="settings-api-modal-label">
							{__(
								'Create a dedicated token for scripts, automations, or external tools.'
							)}
						</p>
						<p className="settings-api-modal-text">
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
						className={cn(
							'settings-api-modal-actions',
							isRtl
								? 'settings-api-modal-actions-start'
								: 'settings-api-modal-actions-end'
						)}
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
				<div className="settings-api-modal-content">
					<div className="settings-api-modal-copy-notice">
						<p className="settings-api-modal-copy-title">
							{__('This token will not be shown again.')}
						</p>
						<p className="settings-api-modal-copy-text">
							{__(
								'Store it in a password manager, environment file, or secret store before closing this window.'
							)}
						</p>
					</div>
					<ReadOnlyValueBlock
						value={newApiKey}
						onCopy={() => copyToClipboard(newApiKey)}
						copyButtonLabel={__('Copy to clipboard')}
					/>
					{baseApiUrl && (
						<div className="settings-api-modal-section">
							<p className="settings-api-modal-label">
								{__('Base API URL')}
							</p>
							<ReadOnlyValueBlock
								value={baseApiUrl}
								onCopy={() =>
									copyToClipboard(
										baseApiUrl,
										__('Base API URL copied to clipboard')
									)
								}
								copyButtonLabel={__('Copy to clipboard')}
							/>
							<p className="settings-api-modal-hint">
								{__(
									'Use this API URL with integrations that need both the endpoint and the token.'
								)}
							</p>
						</div>
					)}
					<p className="settings-api-modal-text">
						{__(
							'If this key is ever exposed, revoke it from the API Keys list and create a replacement.'
						)}
					</p>
					<div
						className={cn(
							'settings-api-modal-actions',
							isRtl
								? 'settings-api-modal-actions-start'
								: 'settings-api-modal-actions-end'
						)}
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
