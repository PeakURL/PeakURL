import type { SubmitEvent } from 'react';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { X, Save } from 'lucide-react';
import { useState } from 'react';
import { ReadOnlyValueBlock, Select } from '@/components/ui';
import { useUpdateUrlMutation } from '@/store/slices/api';
import { __ } from '@/i18n';
import { getFieldDirection, isDocumentRtl } from '@/i18n/direction';
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
			<div className="links-modal-backdrop" aria-hidden="true" />

			<div className="links-modal-shell">
				<DialogPanel className="links-modal-panel links-modal-panel-large">
					{/* Header */}
					<div className="links-modal-header">
						<DialogTitle className="links-modal-title">
							{__('Edit Link')}
						</DialogTitle>
						<button
							onClick={handleClose}
							className="links-modal-close"
						>
							<X className="links-modal-close-icon" />
						</button>
					</div>

					{/* Content */}
					<form onSubmit={handleSubmit} className="links-modal-form">
						{error && (
							<div className="links-modal-alert links-modal-alert-error">
								<p className="links-modal-alert-error-text">
									{error}
								</p>
							</div>
						)}

						{/* Short URL (Read-only) */}
						<div>
							<label className="links-modal-field-label">
								{__('Short URL')}
							</label>
							<div className="links-modal-static-field">
								<ReadOnlyValueBlock
									value={shortUrl}
									className="links-readonly-reset"
									valueClassName="text-text-muted"
								/>
							</div>
						</div>

						{/* Destination URL (Read-only) */}
						<div>
							<label className="links-modal-field-label">
								{__('Destination URL')}
							</label>
							<div className="links-modal-static-field">
								<ReadOnlyValueBlock
									value={link.destinationUrl}
									className="links-readonly-reset"
									monospace={false}
									valueClassName="text-text-muted"
								/>
							</div>
						</div>

						{/* Title */}
						<div>
							<label htmlFor="title" className="links-modal-field-label">
								{__('Title (Optional)')}
							</label>
							<input
								type="text"
								id="title"
								dir={getFieldDirection({
									fallbackDirection: pageDirection,
								})}
								value={title}
								onChange={(e) => setTitle(e.target.value)}
								placeholder={__('Enter a title for this link')}
								className="links-edit-modal-input"
							/>
						</div>

						{/* Password */}
						<div>
							<label htmlFor="password" className="links-modal-field-label">
								{__('Password Protection (Optional)')}
							</label>
							<input
								type="password"
								id="password"
								dir={getFieldDirection({
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
								className="links-edit-modal-input"
							/>
							{hasExistingPassword && (
								<div className="links-edit-modal-password-options">
									<p className="links-edit-modal-help">
										{__(
											'Leave this blank to keep the current password.'
										)}
									</p>
									<label className="links-edit-modal-checkbox-label">
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
											className="links-checkbox"
										/>
										{__('Remove password protection')}
									</label>
								</div>
							)}
						</div>

						{/* Expiration Date */}
						<div>
							<label htmlFor="expiresAt" className="links-modal-field-label">
								{__('Expiration Date (Optional)')}
							</label>
							<input
								type="datetime-local"
								id="expiresAt"
								dir={getFieldDirection({
									fallbackDirection: pageDirection,
									valueDirection: 'ltr',
								})}
								value={expiresAt}
								onChange={(e) => setExpiresAt(e.target.value)}
								min={getLocalDateTimeValue()}
								step="60"
								className="links-edit-modal-input"
							/>
						</div>

						{/* Status */}
						<div>
							<label htmlFor="status" className="links-modal-field-label">
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
						<div className="links-modal-actions-spacious">
							<button
								type="button"
								onClick={handleClose}
								className="links-modal-button links-modal-button-secondary"
							>
								{__('Cancel')}
							</button>
							<button
								type="submit"
								disabled={isLoading}
								className="links-modal-button links-modal-button-primary"
							>
								{isLoading ? (
									<span className="links-modal-button-content">
										<div className="links-modal-spinner"></div>
										{__('Saving...')}
									</span>
								) : (
									<span className="links-modal-button-content">
										<Save className="links-modal-button-icon" />
										{__('Save Changes')}
									</span>
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
