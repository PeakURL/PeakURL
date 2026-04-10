import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { X, Trash2, AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import { useBulkDeleteUrlMutation } from '@/store/slices/api';
import { __, sprintf } from '@/i18n';
import { getDocumentDirection } from '@/i18n/direction';
import { getErrorMessage } from '@/utils';
import type { BulkDeleteModalProps } from './types';

function BulkDeleteModal({
	open,
	setOpen,
	selectedIds,
	onSuccess,
}: BulkDeleteModalProps) {
	const direction = getDocumentDirection();
	const [error, setError] = useState('');
	const [bulkDeleteUrl, { isLoading }] = useBulkDeleteUrlMutation();

	const handleDelete = async () => {
		setError('');

		try {
			await bulkDeleteUrl(selectedIds).unwrap();
			setOpen(false);
			if (onSuccess) onSuccess();
		} catch (err) {
			setError(getErrorMessage(err, __('Failed to delete links')));
		}
	};

	if (!selectedIds || selectedIds.length === 0) return null;

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
							<div className="links-modal-title-icon links-bulk-delete-modal-title-icon">
								<AlertTriangle className="links-bulk-delete-modal-title-icon-svg" />
							</div>
							{__('Delete Links')}
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

						<p className="links-bulk-delete-modal-copy">
							{sprintf(
								__(
									'Are you sure you want to delete %s selected link(s)? This action cannot be undone.'
								),
								String(selectedIds.length)
							)}
						</p>

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
										{sprintf(
											__('Delete (%s)'),
											String(selectedIds.length)
										)}
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

export default BulkDeleteModal;
