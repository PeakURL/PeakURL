// @ts-nocheck
'use client';

import { useMemo, useState } from 'react';
import { Button } from '@/components/ui';
import {
	Copy,
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

const maskSecret = (secret) => {
	if (!secret) return '••••••••••••••••••••••••';
	const visible = secret.slice(0, 10);
	return `${visible}${'•'.repeat(18)}`;
};

function IntegrationsTab({ notification }) {
	const { data: webhooks = [], isLoading, error } = useGetWebhooksQuery();
	const [createWebhook, { isLoading: isCreating }] =
		useCreateWebhookMutation();
	const [deleteWebhook, { isLoading: isDeleting }] =
		useDeleteWebhookMutation();

	const [form, setForm] = useState({ url: '', events: ['link.clicked'] });

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
			await createWebhook({
				url: form.url.trim(),
				events: form.events,
			}).unwrap();
			notification?.success?.('Success', 'Webhook created successfully');
			setForm({ url: '', events: ['link.clicked'] });
		} catch (err) {
			notification?.error?.(
				'Error',
				err?.data?.message || 'Failed to create webhook'
			);
		}
	};

	const handleDelete = async (id) => {
		if (!id) return;
		if (!window.confirm('Delete this webhook?')) return;

		try {
			await deleteWebhook(id).unwrap();
			notification?.success?.('Success', 'Webhook deleted');
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
							Configure webhooks to receive real-time events.
						</p>
					</div>
				</div>
			</div>

			<div className="bg-surface border border-(--color-stroke) rounded-lg p-5">
				<div className="flex items-center justify-between mb-5">
					<h3 className="text-base font-semibold text-heading">
						Webhooks
					</h3>
				</div>

				{/* Create Webhook */}
				<div className="bg-surface-alt rounded-lg p-4 mb-6 border border-(--color-stroke)">
					<h4 className="text-sm font-semibold text-heading mb-3">
						Add New Webhook
					</h4>

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
									placeholder="https://your-app.com/webhook"
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
							{isCreating ? (
								'Creating...'
							) : (
								<>
									<Plus size={16} className="mr-2" />
									Create Webhook
								</>
							)}
						</Button>
					</div>
				</div>

				{/* Webhook List */}
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
												{maskSecret(wh.secret)}
											</span>
											{wh.secret && (
												<button
													onClick={() =>
														copyToClipboard(
															wh.secret,
															'Secret copied'
														)
													}
													className="p-1 text-muted hover:text-heading"
													title="Copy signing secret"
												>
													<Copy size={14} />
												</button>
											)}
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
										onClick={() => handleDelete(wh.id)}
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
		</div>
	);
}

export default IntegrationsTab;
