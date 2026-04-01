// @ts-nocheck
'use client';

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
} from '@/store/slices/api/webhook';

const EVENT_OPTIONS = [
	{ id: 'link.created', label: 'Link Created' },
	{ id: 'link.clicked', label: 'Link Clicked' },
	{ id: 'link.updated', label: 'Link Updated' },
	{ id: 'link.deleted', label: 'Link Deleted' },
];

function IntegrationsTab({ notification }) {
	const { data: webhooks = [], isLoading, error } = useGetWebhooksQuery();
	const [createWebhook, { isLoading: isCreating }] =
		useCreateWebhookMutation();
	const [deleteWebhook, { isLoading: isDeleting }] =
		useDeleteWebhookMutation();

	const [form, setForm] = useState({ url: '', events: ['link.clicked'] });
	const [createdWebhook, setCreatedWebhook] = useState(null);
	const [webhookPendingDelete, setWebhookPendingDelete] = useState(null);

	const canCreate = useMemo(() => {
		return form.url.trim().length > 0 && form.events.length > 0;
	}, [form.url, form.events]);

	const toggleEvent = (eventId) => {
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
				'Error',
				'Enter a URL and select at least one event.'
			);
			return;
		}

		try {
			const result = await createWebhook({
				url: form.url.trim(),
				events: form.events,
			}).unwrap();
			notification?.success?.('Success', 'Webhook created successfully');
			setForm({ url: '', events: ['link.clicked'] });
			setCreatedWebhook(result?.data || null);
		} catch (err) {
			notification?.error?.(
				'Error',
				err?.data?.message || 'Failed to create webhook'
			);
		}
	};

	const handleDelete = async (id) => {
		if (!id) return;

		try {
			await deleteWebhook(id).unwrap();
			notification?.success?.('Success', 'Webhook deleted');
			setWebhookPendingDelete(null);
		} catch (err) {
			notification?.error?.(
				'Error',
				err?.data?.message || 'Failed to delete webhook'
			);
		}
	};

	const copyToClipboard = async (text, label = 'Copied') => {
		if (!text) return;
		try {
			await navigator.clipboard.writeText(text);
			notification?.success?.(label, 'Copied to clipboard');
		} catch (err) {
			notification?.error?.('Error', 'Failed to copy');
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
							Integrations
						</h2>
						<p className="text-sm text-muted mt-0.5">
							Connect PeakURL to your automations with outbound
							webhooks for link activity.
						</p>
					</div>
				</div>
			</div>

			<div className="bg-surface border border-(--color-stroke) rounded-lg p-5">
				<div className="mb-5 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
					<div>
						<h3 className="text-base font-semibold text-heading">
							Webhooks
						</h3>
						<p className="mt-1 max-w-2xl text-sm leading-6 text-text-muted">
							PeakURL sends signed POST requests to your endpoint
							when selected link events happen.
						</p>
					</div>
					<a
						href="https://peakurl.org/docs/api/webhooks"
						target="_blank"
						rel="noreferrer"
						className="inline-flex items-center gap-2 text-sm font-medium text-accent hover:underline"
					>
						Webhook docs
						<ExternalLink size={14} />
					</a>
				</div>

				<div className="bg-surface-alt rounded-lg p-4 mb-6 border border-(--color-stroke)">
					<h4 className="text-sm font-semibold text-heading mb-3">
						Add New Webhook
					</h4>
					<p className="mb-4 text-sm leading-6 text-text-muted">
						Choose which events should trigger a delivery, then save
						the signing secret somewhere secure when it is shown.
					</p>

					<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
						<div>
							<label className="block text-sm font-medium text-muted mb-1">
								Endpoint URL
							</label>
							<div className="relative">
								<Link2
									className="absolute left-3 top-1/2 -translate-y-1/2 text-muted"
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
									className="w-full pl-10 pr-3 py-2 bg-surface border border-stroke rounded-lg text-sm text-heading focus:outline-none focus:ring-2 focus:ring-accent"
								/>
							</div>
							<p className="mt-2 text-xs leading-5 text-text-muted">
								Use a public HTTPS endpoint that can accept POST
								requests, such as a Zapier catch hook, an n8n
								webhook URL, or your own API route like
								<code className="mx-1 rounded bg-surface px-1.5 py-0.5 text-[11px]">
									https://example.com/api/webhooks/peakurl
								</code>
								.
							</p>
						</div>

						<div>
							<label className="block text-sm font-medium text-muted mb-1">
								Events
							</label>
							<div className="grid grid-cols-2 gap-2">
								{EVENT_OPTIONS.map((event) => (
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
							{isCreating ? 'Creating...' : (
								<>
									<Plus size={16} className="mr-2" />
									Create Webhook
								</>
							)}
						</Button>
					</div>
				</div>

				{isLoading ? (
					<div className="text-sm text-muted">Loading webhooks…</div>
				) : error ? (
					<div className="text-sm text-red-600 dark:text-red-400">
						{error?.data?.message || 'Failed to load webhooks'}
					</div>
				) : webhooks.length === 0 ? (
					<div className="text-center py-8 bg-surface-alt rounded-lg border border-dashed border-(--color-stroke)">
						<WebhookIcon className="w-8 h-8 mx-auto text-muted mb-3" />
						<h4 className="text-sm font-medium text-heading mb-1">
							No Webhooks Configured
						</h4>
						<p className="text-xs text-muted">
							Add a webhook to receive link events in real time.
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
													Inactive
												</span>
											)}
										</div>

										<div className="mt-3 flex items-center gap-2">
											<span className="text-xs text-muted font-mono truncate">
												{wh.secretHint || 'Signing secret stored'}
											</span>
										</div>

										{wh.createdAt && (
											<p className="text-xs text-muted mt-2">
												Created:{' '}
												{new Date(
													wh.createdAt
												).toLocaleDateString()}
											</p>
										)}
									</div>

									<button
										className="p-2 text-muted hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
										aria-label="Delete webhook"
										onClick={() => setWebhookPendingDelete(wh)}
										disabled={isDeleting}
										title="Delete webhook"
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
				title="Copy Your Webhook Secret"
				size="md"
			>
				<div className="space-y-5">
					<div className="rounded-xl border border-blue-500/20 bg-blue-500/10 px-4 py-3">
						<p className="text-sm font-medium text-blue-700 dark:text-blue-300">
							This signing secret will not be shown again.
						</p>
						<p className="mt-1 text-xs leading-5 text-blue-700 dark:text-blue-300">
							Store it in your automation tool or secret manager before closing this window.
						</p>
					</div>

					<div className="rounded-lg border border-stroke bg-surface-alt p-4">
						<p className="text-xs font-semibold uppercase tracking-[0.14em] text-text-muted">
							Endpoint URL
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
									'Secret copied'
								)
							}
							className="absolute right-2 top-2 rounded bg-surface p-1.5 text-text-muted shadow-sm transition-all hover:text-heading hover:shadow"
							title="Copy to clipboard"
						>
							<Copy size={14} />
						</button>
					</div>

					<p className="text-sm text-text-muted">
						If this secret is ever exposed, delete the webhook and create a new one.
					</p>

					<div className="flex justify-end gap-2">
						<Button
							variant="secondary"
							onClick={() =>
								copyToClipboard(
									createdWebhook?.secret,
									'Secret copied'
								)
							}
						>
							<Copy size={16} className="mr-2" />
							Copy Secret
						</Button>
						<Button onClick={() => setCreatedWebhook(null)}>
							I&apos;ve Stored It
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
				title="Delete webhook"
				description={
					webhookPendingDelete
						? `Delete the webhook for ${webhookPendingDelete.url}? PeakURL will stop sending signed event requests to this endpoint immediately.`
						: ''
				}
				confirmText="Delete webhook"
				cancelText="Keep webhook"
				confirmVariant="danger"
				onConfirm={() => handleDelete(webhookPendingDelete?.id)}
				loading={isDeleting}
			/>
		</div>
	);
}

export default IntegrationsTab;
