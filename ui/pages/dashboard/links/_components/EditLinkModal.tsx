import type { SubmitEvent } from 'react';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { X, Save } from 'lucide-react';
import { useState } from 'react';
import { ReadOnlyValueBlock, Select } from '@/components/ui';
import { useUpdateUrlMutation } from '@/store/slices/api';
import { __ } from '@/i18n';
import { isDocumentRtl, resolveFieldDirection } from '@/i18n/direction';
import {
	buildShortUrl,
	getErrorMessage,
	normalizeLinkTitle,
	getLocalDateTimeValue,
	isFutureLocalDateTime,
	toIsoFromLocalDateTime,
	toLocalDateTimeValue,
} from '@/utils';
import type {
	EditLinkModalProps,
	LinkStatus,
	UpdateUrlPayload,
} from './types';
import type { SelectOption } from '@/components/ui';

function EditLinkModal({ open, setOpen, link }: EditLinkModalProps) {
	const pageDirection = isDocumentRtl() ? 'rtl' : 'ltr';
	const getInitialTitle = () => normalizeLinkTitle(link?.title);
	const getInitialStatus = (): LinkStatus => link?.status || 'active';
	const getInitialExpiresAt = () => toLocalDateTimeValue(link?.expiresAt);
	const hasExistingPassword = Boolean(link?.hasPassword);
	const statusOptions: SelectOption<LinkStatus>[] = [
		{ value: 'active', label: __('Active') },
		{ value: 'inactive', label: __('Inactive') },
		{ value: 'expired', label: __('Expired') },
	];

	const [title, setTitle] = useState(getInitialTitle);
	const [status, setStatus] = useState<LinkStatus>(getInitialStatus);
	const [password, setPassword] = useState('');
	const [clearPassword, setClearPassword] = useState(false);
	const [expiresAt, setExpiresAt] = useState(getInitialExpiresAt);
	const [error, setError] = useState('');

	const [updateUrl, { isLoading }] = useUpdateUrlMutation();
	const shortUrl = link ? buildShortUrl(link) : '';

	const handleClose = () => setOpen(false);

	const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		setError('');

		if (!link) {
			return;
		}

		if (expiresAt && !isFutureLocalDateTime(expiresAt)) {
			setError(__('Expiration time must be in the future.'));
			return;
		}

		try {
			const payload: UpdateUrlPayload = {
				id: link.id,
				title: title.trim() || undefined,
				status,
				expiresAt: expiresAt ? toIsoFromLocalDateTime(expiresAt) : null,
			};

			if (clearPassword) {
				payload.clearPassword = true;
			} else if (password.trim()) {
				payload.password = password.trim();
			}

			await updateUrl({
				...payload,
			}).unwrap();

			handleClose();
		} catch (error) {
			setError(getErrorMessage(error, __('Failed to update link')));
		}
	};

	if (!link) return null;

	return (
		<Dialog open={open} onClose={handleClose} className="relative z-50">
			<div className="fixed inset-0 bg-black/30" aria-hidden="true" />

			<div className="fixed inset-0 flex items-center justify-center p-4">
				<DialogPanel
					className="text-inline-start mx-auto w-full max-w-lg rounded-lg bg-surface shadow-xl"
				>
					{/* Header */}
					<div className="flex items-center justify-between p-6 border-b border-stroke">
						<DialogTitle className="text-lg font-semibold text-heading">
							{__('Edit Link')}
						</DialogTitle>
						<button
							onClick={handleClose}
							className="rounded-lg text-text-muted hover:text-heading hover:bg-surface-alt p-2 transition-all"
						>
							<X className="w-5 h-5" />
						</button>
					</div>

					{/* Content */}
					<form onSubmit={handleSubmit} className="p-6 space-y-4">
						{error && (
							<div className="p-3 bg-red-500/10 border border-red-500/20 rounded-lg">
								<p className="text-sm text-red-600 dark:text-red-400">
									{error}
								</p>
							</div>
						)}

						{/* Short URL (Read-only) */}
						<div>
							<label className="block text-sm font-medium text-heading mb-2">
								{__('Short URL')}
							</label>
							<div className="bg-surface-alt border border-stroke rounded-lg px-3 py-2">
								<ReadOnlyValueBlock
									value={shortUrl}
									className="border-0 bg-transparent p-0"
									valueClassName="text-text-muted"
								/>
							</div>
						</div>

						{/* Destination URL (Read-only) */}
						<div>
							<label className="block text-sm font-medium text-heading mb-2">
								{__('Destination URL')}
							</label>
							<div className="bg-surface-alt border border-stroke rounded-lg px-3 py-2">
								<ReadOnlyValueBlock
									value={link.destinationUrl}
									className="border-0 bg-transparent p-0"
									monospace={false}
									valueClassName="text-text-muted"
								/>
							</div>
						</div>

						{/* Title */}
						<div>
							<label
								htmlFor="title"
								className="block text-sm font-medium text-heading mb-2"
							>
								{__('Title (Optional)')}
							</label>
							<input
								type="text"
								id="title"
								dir={resolveFieldDirection({
									value: title,
									fallbackDirection: pageDirection,
								})}
								value={title}
								onChange={(e) => setTitle(e.target.value)}
								placeholder={__('Enter a title for this link')}
								className="text-inline-start w-full rounded-lg border border-stroke bg-surface-alt px-3 py-2 text-sm text-heading outline-none transition-all focus:border-accent focus:ring-2 focus:ring-accent"
							/>
						</div>

						{/* Password */}
						<div>
							<label
								htmlFor="password"
								className="block text-sm font-medium text-heading mb-2"
							>
								{__('Password Protection (Optional)')}
							</label>
							<input
								type="password"
								id="password"
								dir={resolveFieldDirection({
									value: password,
									fallbackDirection: pageDirection,
								})}
								value={password}
								disabled={clearPassword}
								onChange={(e) => setPassword(e.target.value)}
								placeholder={
									hasExistingPassword
										? __(
												'Enter a new password to replace the current one'
											)
										: __(
												'Set a password to protect this link'
											)
								}
								className="text-inline-start w-full rounded-lg border border-stroke bg-surface-alt px-3 py-2 text-sm text-heading outline-none transition-all focus:border-accent focus:ring-2 focus:ring-accent"
							/>
							{hasExistingPassword && (
								<div className="mt-2 space-y-2">
									<p className="text-xs text-text-muted">
										{__(
											'Leave this blank to keep the current password.'
										)}
									</p>
									<label className="flex items-center gap-2 text-sm text-heading">
										<input
											type="checkbox"
											checked={clearPassword}
											onChange={(e) => {
												setClearPassword(
													e.target.checked
												);
												if (e.target.checked) {
													setPassword('');
												}
											}}
											className="rounded border-stroke text-accent focus:ring-accent focus:ring-2"
										/>
										{__('Remove password protection')}
									</label>
								</div>
							)}
						</div>

						{/* Expiration Date */}
						<div>
							<label
								htmlFor="expiresAt"
								className="block text-sm font-medium text-heading mb-2"
							>
								{__('Expiration Date (Optional)')}
							</label>
							<input
								type="datetime-local"
								id="expiresAt"
								dir={resolveFieldDirection({
									value: expiresAt,
									fallbackDirection: pageDirection,
									valueDirection: 'ltr',
								})}
								value={expiresAt}
								onChange={(e) => setExpiresAt(e.target.value)}
								min={getLocalDateTimeValue()}
								step="60"
								className="text-inline-start w-full rounded-lg border border-stroke bg-surface-alt px-3 py-2 text-sm text-heading outline-none transition-all focus:border-accent focus:ring-2 focus:ring-accent"
							/>
						</div>

						{/* Status */}
						<div>
							<label
								htmlFor="status"
								className="block text-sm font-medium text-heading mb-2"
							>
								{__('Status')}
							</label>
							<Select
								id="status"
								value={status}
								onChange={setStatus}
								options={statusOptions}
								ariaLabel={__('Link status')}
								buttonClassName="rounded-lg bg-surface-alt px-3 py-2"
							/>
						</div>

						{/* Action Buttons */}
						<div className="flex gap-3 pt-4">
							<button
								type="button"
								onClick={handleClose}
								className="flex-1 px-4 py-2.5 bg-surface border border-stroke hover:bg-surface-alt text-heading rounded-lg transition-all font-medium"
							>
								{__('Cancel')}
							</button>
							<button
								type="submit"
								disabled={isLoading}
								className="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 bg-accent hover:bg-accent/90 disabled:bg-gray-400 text-white rounded-lg transition-all font-medium"
							>
								{isLoading ? (
									<>
										<div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white"></div>
										{__('Saving...')}
									</>
								) : (
									<>
										<Save className="w-4 h-4" />
										{__('Save Changes')}
									</>
								)}
							</button>
						</div>
					</form>
				</DialogPanel>
			</div>
		</Dialog>
	);
}

export default EditLinkModal;
