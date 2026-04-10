import { Button, ReadOnlyValueBlock } from '@/components';
import {
	Plus,
	Trash2,
	Info,
	Key,
	ExternalLink,
	BookOpen,
	Copy,
	Link2,
} from 'lucide-react';
import { __ } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import type { ApiTabProps } from '../types';

function ApiTab({
	user,
	baseApiUrl,
	copyToClipboard,
	isGeneratingKey,
	isDeletingKey,
	onDeleteKey,
	setShowCreateModal,
}: ApiTabProps) {
	const direction = isDocumentRtl() ? 'rtl' : 'ltr';
	return (
		<div className="settings-api">
			{baseApiUrl && (
				<div className="settings-api-endpoint-card">
					<div className="settings-api-endpoint-header">
						<div className="settings-api-endpoint-summary">
							<div className="settings-api-endpoint-title-row">
								<div className="settings-api-endpoint-icon">
									<Link2 size={18} />
								</div>
								<div className="settings-api-endpoint-copy">
									<p className="settings-api-endpoint-kicker">
										{__('Connection')}
									</p>
									<h2 className="settings-api-endpoint-title">
										{__('Base API URL')}
									</h2>
								</div>
							</div>
							<p className="settings-api-endpoint-description">
								{__(
									'Use this endpoint with WordPress, browser extensions, scripts, and other API clients.'
								)}
							</p>
						</div>
						<Button
							size="sm"
							variant="secondary"
							icon={Copy}
							className="settings-api-endpoint-copy-button"
							onClick={() =>
								copyToClipboard(
									baseApiUrl,
									__('Base API URL copied to clipboard')
								)
							}
						>
							{__('Copy API URL')}
						</Button>
					</div>
					<ReadOnlyValueBlock
						value={baseApiUrl}
						className="settings-api-endpoint-value"
					/>
				</div>
			)}

			<div className="settings-api-keys-card">
				<div className="settings-api-keys-header">
					<h2 className="settings-api-keys-title">
						{__('API Keys')}
					</h2>
					<Button
						size="sm"
						icon={Plus}
						loading={isGeneratingKey}
						onClick={() => setShowCreateModal(true)}
					>
						{__('Create New Key')}
					</Button>
				</div>

				{user?.apiKeys && user.apiKeys.length > 0 ? (
					<div className="settings-api-keys-list">
						{user.apiKeys.map((key) => (
							<div
								key={key.id}
								dir={direction}
								className="settings-api-key-row"
							>
								<div className="settings-api-key-details">
									<div className="settings-api-key-title-row">
										<p className="settings-api-key-title">
											{key.label || __('API Key')}
										</p>
										<span className="settings-api-key-status">
											{__('Active')}
										</span>
									</div>
									<div className="settings-api-key-value-row">
										<p className="settings-api-key-value preserve-ltr-value">
											{key.maskedKey || '••••••••'}
										</p>
									</div>
									<p className="settings-api-key-meta">
										{__('Created:')}{' '}
										<bdi className="preserve-ltr-value inline-block">
											{key.createdAt
												? new Date(
														key.createdAt
													).toLocaleDateString()
												: __('Unknown')}
										</bdi>
									</p>
								</div>
								<div className="settings-api-key-actions">
									<button
										type="button"
										className="settings-api-key-delete-button"
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
					<div className="settings-api-empty">
						<Key size={32} className="settings-api-empty-icon" />
						<h3 className="settings-api-empty-title">
							{__('No API Keys Generated')}
						</h3>
						<p className="settings-api-empty-text">
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

			<div className="settings-api-note">
				<div className="settings-api-note-layout">
					<Info size={18} className="settings-api-note-icon" />
					<div className="settings-api-note-content">
						<p className="settings-api-note-title">
							{__('Keep your API keys secure')}
						</p>
						<p className="settings-api-note-text">
							{__(
								'PeakURL only shows the full token once, at creation time. Store it in a password manager or secret store, and revoke it immediately if you think it has been exposed.'
							)}
						</p>
					</div>
				</div>
			</div>

			<div className="settings-api-docs-card">
				<div className="settings-api-docs-header">
					<div className="settings-api-docs-summary">
						<div className="settings-api-docs-title-row">
							<BookOpen size={18} className="settings-api-docs-icon" />
							<h3 className="settings-api-docs-title">
								{__('API documentation')}
							</h3>
						</div>
						<p className="settings-api-docs-description">
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
						<Button
							size="sm"
							variant="secondary"
							icon={ExternalLink}
							iconPosition="right"
						>
							{__('API Overview')}
						</Button>
					</a>
				</div>
			</div>
		</div>
	);
}

export default ApiTab;
