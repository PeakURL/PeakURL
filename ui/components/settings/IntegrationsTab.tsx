import { useMemo, useState } from 'react';
import { Button, ConfirmDialog, Modal } from '@/components/ui';
import {
	Copy,
	ExternalLink,
	Plus,
	Trash2,
	Webhook as WebhookIcon,
	Link2,
} from 'lucide-react';
import {
	useCreateWebhookMutation,
	useDeleteWebhookMutation,
	useGetWebhooksQuery,
} from '@/store/slices/api';
import { isDocumentRtl } from '@/i18n/direction';
import { __, sprintf } from '@/i18n';
import { copyToClipboard as writeToClipboard, getErrorMessage } from '@/utils';
import type {
	CreatedWebhook,
	IntegrationsTabProps,
	WebhookEventOption,
	WebhookFormState,
	WebhookSummary,
} from './types';

const getEventOptions = (): WebhookEventOption[] => [
	{ id: 'link.created', label: __('Link Created') },
	{ id: 'link.clicked', label: __('Link Clicked') },
	{ id: 'link.updated', label: __('Link Updated') },
	{ id: 'link.deleted', label: __('Link Deleted') },
];

function IntegrationsTab({ notification }: IntegrationsTabProps) {
	const isRtl = isDocumentRtl();
	const eventOptions = getEventOptions();
	const {
		data: webhookData,
		isLoading,
		error,
	} = useGetWebhooksQuery(undefined);
	const [createWebhook, { isLoading: isCreating }] =
		useCreateWebhookMutation();
	const [deleteWebhook, { isLoading: isDeleting }] =
		useDeleteWebhookMutation();
	const webhooks = webhookData || [];

	const [form, setForm] = useState<WebhookFormState>({
		url: '',
		events: ['link.clicked'],
	});
	const [createdWebhook, setCreatedWebhook] = useState<CreatedWebhook | null>(
		null
	);
	const [webhookPendingDelete, setWebhookPendingDelete] =
		useState<WebhookSummary | null>(null);

	const canCreate = useMemo(() => {
		return form.url.trim().length > 0 && form.events.length > 0;
	}, [form.url, form.events]);

	const toggleEvent = (eventId: string) => {
		setForm((prev) => {
			const has = prev.events.includes(eventId);
			const nextEvents = has
				? prev.events.filter((e) => e !== eventId)
				: [...prev.events, eventId];
			return { ...prev, events: nextEvents };
		});
	};

	const handleCreate = async () => {
		if (!canCreate) {
			notification?.error?.(
				__('Error'),
				__('Enter a URL and select at least one event.')
			);
			return;
		}

		try {
			const result = await createWebhook({
				url: form.url.trim(),
				events: form.events,
			}).unwrap();
			notification?.success?.(
				__('Success'),
				__('Webhook created successfully')
			);
			setForm({ url: '', events: ['link.clicked'] });
			setCreatedWebhook(result?.data || null);
		} catch (err) {
			notification?.error?.(
				__('Error'),
				getErrorMessage(err, __('Failed to create webhook'))
			);
		}
	};

	const handleDelete = async (id?: string) => {
		if (!id) return;

		try {
			await deleteWebhook(id).unwrap();
			notification?.success?.(__('Success'), __('Webhook deleted'));
			setWebhookPendingDelete(null);
		} catch (err) {
			notification?.error?.(
				__('Error'),
				getErrorMessage(err, __('Failed to delete webhook'))
			);
		}
	};

	const copyToClipboard = async (
		text?: string | null,
		label: string = __('Copied')
	) => {
		if (!text) return;
		try {
			await writeToClipboard(text);
			notification?.success?.(label, __('Copied to clipboard'));
		} catch (err) {
			notification?.error?.(__('Error'), __('Failed to copy'));
		}
	};

	return (
		<div className="space-y-5">
			<div className="bg-surface border border-(--color-stroke) rounded-lg p-5">
				<div className="flex items-center gap-3">
					<div className="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center">
						<WebhookIcon className="w-5 h-5 text-primary-600 dark:text-primary-400" />
					</div>
					<div>
						<h2 className="text-base font-semibold text-heading">
							{__('Integrations')}
						</h2>
						<p className="text-sm text-muted mt-0.5">
							{__(
								'Connect PeakURL to your automations with outbound webhooks for link activity.'
							)}
						</p>
					</div>
				</div>
			</div>

			<div className="bg-surface border border-(--color-stroke) rounded-lg p-5">
				<div className="mb-5 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
					<div>
						<h3 className="text-base font-semibold text-heading">
							{__('Webhooks')}
						</h3>
						<p className="mt-1 max-w-2xl text-sm leading-6 text-text-muted">
							{__(
								'PeakURL sends signed POST requests to your endpoint when selected link events happen.'
							)}
						</p>
					</div>
					<a
						href="https://peakurl.org/docs/integrations"
						target="_blank"
						rel="noreferrer"
						className="inline-flex items-center gap-2 text-sm font-medium text-accent hover:underline"
					>
						{__('Webhook docs')}
						<ExternalLink size={14} />
					</a>
				</div>

				<div className="bg-surface-alt rounded-lg p-4 mb-6 border border-(--color-stroke)">
					<h4 className="text-sm font-semibold text-heading mb-3">
						{__('Add New Webhook')}
					</h4>
					<p className="mb-4 text-sm leading-6 text-text-muted">
						{__(
							'Choose which events should trigger a delivery, then save the signing secret somewhere secure when it is shown.'
						)}
					</p>

					<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
						<div>
							<label className="block text-sm font-medium text-muted mb-1">
								{__('Endpoint URL')}
							</label>
							<div className="relative">
								<Link2
									className={`absolute top-1/2 -translate-y-1/2 text-muted ${
										isRtl ? 'right-3' : 'left-3'
									}`}
									size={16}
								/>
								<input
									type="url"
									placeholder="https://hooks.zapier.com/hooks/catch/123456/peakurl"
									value={form.url}
									onChange={(e) =>
										setForm((prev) => ({
											...prev,
											url: e.target.value,
										}))
									}
									className={`w-full bg-surface border border-stroke rounded-lg py-2 text-sm text-heading focus:outline-none focus:ring-2 focus:ring-accent ${
										isRtl
											? 'pr-10 pl-3 text-right'
											: 'pl-10 pr-3 text-left'
									}`}
								/>
							</div>
							<p className="mt-2 text-xs leading-5 text-text-muted">
								{__(
									'Use a public HTTPS endpoint that can accept POST requests, such as a Zapier catch hook, an n8n webhook URL, or your own API route like'
								)}
								<code className="mx-1 rounded bg-surface px-1.5 py-0.5 text-[11px]">
									https://example.com/api/webhooks/peakurl
								</code>
								.
							</p>
						</div>

						<div>
							<label className="block text-sm font-medium text-muted mb-1">
								{__('Events')}
							</label>
							<div className="grid grid-cols-2 gap-2">
								{eventOptions.map((event) => (
									<label
										key={event.id}
										className="flex items-center gap-2 text-sm text-muted cursor-pointer select-none"
									>
										<input
											type="checkbox"
											checked={form.events.includes(
												event.id
											)}
											onChange={() =>
												toggleEvent(event.id)
											}
										/>
										<span>{event.label}</span>
									</label>
								))}
							</div>
						</div>
					</div>

					<div className="flex justify-end mt-4">
						<Button
							size="sm"
							onClick={handleCreate}
							disabled={!canCreate || isCreating}
						>
							{isCreating ? (
								__('Creating...')
							) : (
								<>
									<Plus size={16} className="mr-2" />
									{__('Create Webhook')}
								</>
							)}
						</Button>
					</div>
				</div>

				{isLoading ? (
					<div className="text-sm text-muted">
						{__('Loading webhooks…')}
					</div>
				) : error ? (
					<div className="text-sm text-red-600 dark:text-red-400">
						{getErrorMessage(error, __('Failed to load webhooks'))}
					</div>
				) : webhooks.length === 0 ? (
					<div className="text-center py-8 bg-surface-alt rounded-lg border border-dashed border-(--color-stroke)">
						<WebhookIcon className="w-8 h-8 mx-auto text-muted mb-3" />
						<h4 className="text-sm font-medium text-heading mb-1">
							{__('No Webhooks Configured')}
						</h4>
						<p className="text-xs text-muted">
							{__(
								'Add a webhook to receive link events in real time.'
							)}
						</p>
					</div>
				) : (
					<div className="space-y-3">
						{webhooks.map((wh) => (
							<div
								key={wh.id}
								className="p-4 border border-(--color-stroke) rounded-lg"
							>
								<div className="flex items-start justify-between gap-4">
									<div className="min-w-0 flex-1">
										<p className="text-sm font-medium text-heading truncate">
											{wh.url}
										</p>
										<div className="flex flex-wrap gap-1.5 mt-2">
											{(wh.events || []).map((evt) => (
												<span
													key={evt}
													className="text-xs px-2 py-0.5 rounded bg-accent/10 dark:bg-accent/20 text-accent"
												>
													{evt}
												</span>
											))}
											{!wh.isActive && (
												<span className="text-xs px-2 py-0.5 rounded bg-surface-alt text-text-muted">
													{__('Inactive')}
												</span>
											)}
										</div>

										<div className="mt-3 flex items-center gap-2">
											<span className="text-xs text-muted font-mono truncate">
												{wh.secretHint ||
													__('Signing secret stored')}
											</span>
										</div>

										{wh.createdAt && (
											<p className="text-xs text-muted mt-2">
												{__('Created:')}{' '}
												{new Date(
													wh.createdAt
												).toLocaleDateString()}
											</p>
										)}
									</div>

									<button
										className="p-2 text-muted hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
										aria-label={__('Delete webhook')}
										onClick={() =>
											setWebhookPendingDelete(wh)
										}
										disabled={isDeleting}
										title={__('Delete webhook')}
									>
										<Trash2 size={18} />
									</button>
								</div>
							</div>
						))}
					</div>
				)}
			</div>

			<Modal
				isOpen={Boolean(createdWebhook?.secret)}
				onClose={() => setCreatedWebhook(null)}
				title={__('Copy Your Webhook Secret')}
				size="md"
			>
				<div className="space-y-5">
					<div className="rounded-xl border border-blue-500/20 bg-blue-500/10 px-4 py-3">
						<p className="text-sm font-medium text-blue-700 dark:text-blue-300">
							{__('This signing secret will not be shown again.')}
						</p>
						<p className="mt-1 text-xs leading-5 text-blue-700 dark:text-blue-300">
							{__(
								'Store it in your automation tool or secret manager before closing this window.'
							)}
						</p>
					</div>

					<div className="rounded-lg border border-stroke bg-surface-alt p-4">
						<p className="text-xs font-semibold uppercase tracking-[0.14em] text-text-muted">
							{__('Endpoint URL')}
						</p>
						<p className="mt-2 text-sm font-medium text-heading break-all">
							{createdWebhook?.url}
						</p>
					</div>

					<div className="relative">
						<pre className="break-all rounded-lg border border-stroke bg-surface-alt p-3 text-sm font-mono">
							{createdWebhook?.secret}
						</pre>
						<button
							type="button"
							onClick={() =>
								copyToClipboard(
									createdWebhook?.secret,
									__('Secret copied')
								)
							}
							className="absolute right-2 top-2 rounded bg-surface p-1.5 text-text-muted shadow-sm transition-all hover:text-heading hover:shadow"
							title={__('Copy to clipboard')}
						>
							<Copy size={14} />
						</button>
					</div>

					<p className="text-sm text-text-muted">
						{__(
							'If this secret is ever exposed, delete the webhook and create a new one.'
						)}
					</p>

					<div className="flex justify-end gap-2">
						<Button
							variant="secondary"
							onClick={() =>
								copyToClipboard(
									createdWebhook?.secret,
									__('Secret copied')
								)
							}
						>
							<Copy size={16} className="mr-2" />
							{__('Copy Secret')}
						</Button>
						<Button onClick={() => setCreatedWebhook(null)}>
							{__("I've Stored It")}
						</Button>
					</div>
				</div>
			</Modal>

			<ConfirmDialog
				open={Boolean(webhookPendingDelete)}
				onClose={() => {
					if (!isDeleting) {
						setWebhookPendingDelete(null);
					}
				}}
				title={__('Delete webhook')}
				description={
					webhookPendingDelete
						? sprintf(
								__(
									'Delete the webhook for %s? PeakURL will stop sending signed event requests to this endpoint immediately.'
								),
								webhookPendingDelete.url
							)
						: ''
				}
				confirmText={__('Delete webhook')}
				cancelText={__('Keep webhook')}
				confirmVariant="danger"
				onConfirm={() => handleDelete(webhookPendingDelete?.id)}
				loading={isDeleting}
			/>
		</div>
	);
}

export default IntegrationsTab;
