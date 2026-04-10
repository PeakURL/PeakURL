import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { X, Trash2, AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import { ReadOnlyValueBlock } from '@/components/ui';
import { useDeleteUrlMutation } from '@/store/slices/api';
import { buildShortUrl, getErrorMessage } from '@/utils';
import { __ } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import type { DeleteLinkModalProps } from './types';

function DeleteLinkModal({ open, setOpen, link }: DeleteLinkModalProps) {
	const direction = isDocumentRtl() ? 'rtl' : 'ltr';
	const [error, setError] = useState('');
	const [deleteUrl, { isLoading }] = useDeleteUrlMutation();
	const shortUrl = link ? buildShortUrl(link) : '';
	const totalClicks = Number(link?.clicks || 0);
	const uniqueClicks = Number(link?.uniqueClicks || 0);

	const handleDelete = async () => {
		if (!link) {
			return;
		}

		setError('');

		try {
			await deleteUrl(link.id).unwrap();
			setOpen(false);
		} catch (err) {
			setError(getErrorMessage(err, __('Failed to delete link')));
		}
	};

	if (!link) return null;

	return (
		<Dialog open={open} onClose={setOpen} className="relative z-50">
			<div className="links-modal-backdrop" aria-hidden="true" />

			<div className="links-modal-shell">
				<DialogPanel
					dir={direction}
					className="links-modal-panel links-modal-panel-medium"
				>
					{/* Header */}
					<div className="links-modal-header">
						<DialogTitle className="links-modal-title links-modal-title-with-icon">
							<div className="links-modal-title-icon links-delete-modal-title-icon">
								<AlertTriangle className="links-delete-modal-title-icon-svg" />
							</div>
							{__('Delete Link')}
						</DialogTitle>
						<button
							onClick={() => setOpen(false)}
							className="links-modal-close"
						>
							<X className="links-modal-close-icon" />
						</button>
					</div>

					{/* Content */}
					<div className="links-modal-content">
						{error && (
							<div className="links-modal-alert links-modal-alert-error">
								<p className="links-modal-alert-error-text">
									{error}
								</p>
							</div>
						)}

						<p className="links-delete-modal-copy">
							{__(
								'Are you sure you want to delete this link? This action cannot be undone.'
							)}
						</p>

						{/* Link Info */}
						<div className="links-delete-modal-summary">
							<div>
								<p className="links-delete-modal-summary-label">
									{__('Short URL')}
								</p>
								<ReadOnlyValueBlock
									value={shortUrl}
									className="links-readonly-reset"
									valueClassName="text-accent"
								/>
							</div>
							<div>
								<p className="links-delete-modal-summary-label">
									{__('Destination')}
								</p>
								<ReadOnlyValueBlock
									value={link.destinationUrl}
									className="links-readonly-reset"
									monospace={false}
									valueClassName="text-heading"
								/>
							</div>
							{(totalClicks > 0 || uniqueClicks > 0) && (
								<div className="links-delete-modal-metrics">
									<div>
										<p className="links-delete-modal-metric-label">
											{__('Total Clicks')}
										</p>
										<p className="links-delete-modal-metric-value">
											{totalClicks}
										</p>
									</div>
									<div>
										<p className="links-delete-modal-metric-label">
											{__('Unique Visitors')}
										</p>
										<p className="links-delete-modal-metric-value">
											{uniqueClicks}
										</p>
									</div>
								</div>
							)}
						</div>

						{/* Action Buttons */}
						<div className="links-modal-actions">
							<button
								type="button"
								onClick={() => setOpen(false)}
								disabled={isLoading}
								className="links-modal-button links-modal-button-secondary"
							>
								{__('Cancel')}
							</button>
							<button
								onClick={handleDelete}
								disabled={isLoading}
								className="links-modal-button links-modal-button-danger"
							>
								{isLoading ? (
									<span className="links-modal-button-content">
										<div className="links-modal-spinner"></div>
										{__('Deleting...')}
									</span>
								) : (
									<span className="links-modal-button-content">
										<Trash2 className="links-modal-button-icon" />
										{__('Delete')}
									</span>
								)}
							</button>
						</div>
					</div>
				</DialogPanel>
			</div>
		</Dialog>
	);
}

export default DeleteLinkModal;
