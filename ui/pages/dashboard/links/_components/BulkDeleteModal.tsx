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
			<div className="fixed inset-0 bg-black/30" aria-hidden="true" />

			<div className="fixed inset-0 flex items-center justify-center p-4">
				<DialogPanel
					dir={direction}
					className="text-inline-start mx-auto w-full max-w-md rounded-lg bg-surface shadow-xl"
				>
					{/* Header */}
					<div className="flex items-center justify-between p-6 border-b border-stroke">
						<DialogTitle className="text-lg font-semibold text-heading flex items-center gap-2">
							<div className="w-8 h-8 rounded-lg bg-red-500/10 flex items-center justify-center">
								<AlertTriangle className="w-4 h-4 text-red-600 dark:text-red-400" />
							</div>
							{__('Delete Links')}
						</DialogTitle>
						<button
							onClick={() => setOpen(false)}
							className="rounded-lg text-text-muted hover:text-heading hover:bg-surface-alt p-2 transition-all"
						>
							<X className="w-5 h-5" />
						</button>
					</div>

					{/* Content */}
					<div className="p-6 space-y-4">
						{error && (
							<div className="p-3 bg-red-500/10 border border-red-500/20 rounded-lg">
								<p className="text-sm text-red-600 dark:text-red-400">
									{error}
								</p>
							</div>
						)}

						<p className="text-sm text-text-muted">
							{sprintf(
								__(
									'Are you sure you want to delete %s selected link(s)? This action cannot be undone.'
								),
								String(selectedIds.length)
							)}
						</p>

						{/* Action Buttons */}
						<div className="flex gap-3 pt-2">
							<button
								type="button"
								onClick={() => setOpen(false)}
								disabled={isLoading}
								className="flex-1 px-4 py-2.5 bg-surface border border-stroke hover:bg-surface-alt text-heading rounded-lg transition-all font-medium disabled:opacity-50"
							>
								{__('Cancel')}
							</button>
							<button
								onClick={handleDelete}
								disabled={isLoading}
								className="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 bg-red-600 hover:bg-red-700 disabled:bg-gray-400 text-white rounded-lg transition-all font-medium"
							>
								{isLoading ? (
									<>
										<div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white"></div>
										{__('Deleting...')}
									</>
								) : (
									<>
										<Trash2 className="w-4 h-4" />
										{sprintf(
											__('Delete (%s)'),
											String(selectedIds.length)
										)}
									</>
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
