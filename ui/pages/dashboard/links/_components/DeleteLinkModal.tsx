// @ts-nocheck

import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { X, Trash2, AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import { useDeleteUrlMutation } from '@/store/slices/api/urls';
import { buildShortUrl } from '@/utils';
import { __ } from '@/i18n';

function DeleteLinkModal({ open, setOpen, link }) {
	const [error, setError] = useState('');
	const [deleteUrl, { isLoading }] = useDeleteUrlMutation();
	const shortUrl = link ? buildShortUrl(link) : '';

	const handleDelete = async () => {
		setError('');

		try {
			await deleteUrl(link.id).unwrap();
			setOpen(false);
		} catch (err) {
			setError(err?.data?.message || __('Failed to delete link'));
		}
	};

	if (!link) return null;

	return (
		<Dialog open={open} onClose={setOpen} className="relative z-50">
			<div className="fixed inset-0 bg-black/30" aria-hidden="true" />

			<div className="fixed inset-0 flex items-center justify-center p-4">
				<DialogPanel className="mx-auto max-w-md w-full bg-surface rounded-lg shadow-xl">
					{/* Header */}
					<div className="flex items-center justify-between p-6 border-b border-stroke">
						<DialogTitle className="text-lg font-semibold text-heading flex items-center gap-2">
							<div className="w-8 h-8 rounded-lg bg-red-500/10 flex items-center justify-center">
								<AlertTriangle className="w-4 h-4 text-red-600 dark:text-red-400" />
							</div>
							{__('Delete Link')}
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
							{__(
								'Are you sure you want to delete this link? This action cannot be undone.'
							)}
						</p>

						{/* Link Info */}
						<div className="bg-surface-alt border border-stroke rounded-lg p-4 space-y-2">
							<div>
								<p className="text-xs font-medium text-text-muted mb-1">
									{__('Short URL')}
								</p>
								<code className="text-sm text-accent font-mono">
									{shortUrl}
								</code>
							</div>
							<div>
								<p className="text-xs font-medium text-text-muted mb-1">
									{__('Destination')}
								</p>
								<p className="text-sm text-heading break-all">
									{link.destinationUrl}
								</p>
							</div>
							{(link.clicks > 0 || link.uniqueClicks > 0) && (
								<div className="flex gap-4 pt-2 border-t border-stroke">
									<div>
										<p className="text-xs text-text-muted">
											{__('Total Clicks')}
										</p>
										<p className="text-sm font-semibold text-heading">
											{link.clicks || 0}
										</p>
									</div>
									<div>
										<p className="text-xs text-text-muted">
											{__('Unique Visitors')}
										</p>
										<p className="text-sm font-semibold text-heading">
											{link.uniqueClicks || 0}
										</p>
									</div>
								</div>
							)}
						</div>

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
										{__('Delete')}
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

export default DeleteLinkModal;
