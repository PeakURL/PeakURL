import { API_ROUTES } from "@/api";

import baseApi from "./base";
import type {
	ApiDataResponse,
	ClearWebhookDeliveriesParams,
	ClearWebhookDeliveriesResponse,
	CreateWebhookPayload,
	CreatedWebhook,
	DeleteWebhookDeliveryParams,
	DeleteWebhookDeliveryResponse,
	GetWebhookDeliveriesParams,
	RetryWebhookDeliveryParams,
	RotateSecretResult,
	TestWebhookPayload,
	UpdateWebhookPayload,
	WebhookDeliveriesResponse,
	WebhookDeliveryItem,
	WebhookEventCatalogItem,
	WebhookSummary,
	WebhookTestResult,
} from "./types";

const WEBHOOK_TAGS = ["Webhooks"] as const;
const DELIVERY_TAGS = ["Webhooks", "WebhookDeliveries"] as const;

interface SavedRetentionGuard {
	retentionDays: number;
	savedAtSeq: number;
}

interface StoreScope {
	sequenceCounter: number;
	inFlightRequests: Map<string, number>;
	savedGuards: Map<string, SavedRetentionGuard>;
}

const storeScopes = new WeakMap<object, StoreScope>();
const requestToScopeMap = new Map<string, StoreScope>();

function getStoreScope(getState: () => unknown): StoreScope {
	let scope = storeScopes.get(getState);
	if (!scope) {
		scope = {
			sequenceCounter: 0,
			inFlightRequests: new Map<string, number>(),
			savedGuards: new Map<string, SavedRetentionGuard>(),
		};
		storeScopes.set(getState, scope);
	}

	// Verify cache existence in RTK Query state using the typed query selector.
	// If getWebhooks is uninitialized and has no in-flight requests, clear obsolete guards
	// so a reset cache cannot inherit stale retention guards.
	const rootState = getState();
	if (rootState && typeof rootState === "object") {
		const queryState = webhookApi.endpoints.getWebhooks.select()(
			rootState as never
		);
		if (
			queryState.isUninitialized &&
			scope.inFlightRequests.size === 0 &&
			scope.savedGuards.size > 0
		) {
			scope.savedGuards.clear();
			scope.sequenceCounter = 0;
		}
	}

	return scope;
}

function cleanUpCompletedGuards(scope: StoreScope): void {
	if (scope.savedGuards.size === 0) {
		return;
	}
	for (const [webhookId, guard] of scope.savedGuards) {
		let hasOlderInFlight = false;
		for (const inFlightSeq of scope.inFlightRequests.values()) {
			if (inFlightSeq < guard.savedAtSeq) {
				hasOlderInFlight = true;
				break;
			}
		}
		if (!hasOlderInFlight) {
			scope.savedGuards.delete(webhookId);
		}
	}
}

function applyRetentionGuards(
	scope: StoreScope,
	reqSeq: number | undefined,
	items: WebhookSummary[]
): WebhookSummary[] {
	if (!scope.savedGuards.size || reqSeq === undefined) {
		return items;
	}

	return items.map((item) => {
		const guard = scope.savedGuards.get(item.id);
		if (guard && reqSeq < guard.savedAtSeq) {
			return {
				...item,
				deliveryRetentionDays: guard.retentionDays,
			};
		}
		return item;
	});
}

/**
 * RTK Query endpoints used by the integrations webhook settings UI.
 */
export const webhookApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		getWebhooks: build.query<WebhookSummary[], void>({
			query: () => API_ROUTES.webhooks.index,
			transformResponse: (response: ApiDataResponse<WebhookSummary[]>) =>
				response.data ?? [],
			providesTags: WEBHOOK_TAGS,
			async onQueryStarted(
				_arg,
				{ getState, requestId, queryFulfilled, updateCachedData }
			) {
				const scope = getStoreScope(getState);
				const reqSeq = ++scope.sequenceCounter;
				scope.inFlightRequests.set(requestId, reqSeq);
				requestToScopeMap.set(requestId, scope);

				try {
					await queryFulfilled;

					updateCachedData((draft) => {
						// Remove obsolete saved guards for webhooks that were deleted/omitted on the server
						const incomingIds = new Set(
							draft.map((item) => item.id)
						);
						for (const [id] of scope.savedGuards) {
							if (!incomingIds.has(id)) {
								scope.savedGuards.delete(id);
							}
						}

						// Guard both first responses (where merge is skipped by RTK Query)
						// and merged responses
						for (const item of draft) {
							const guard = scope.savedGuards.get(item.id);
							if (guard && reqSeq < guard.savedAtSeq) {
								item.deliveryRetentionDays =
									guard.retentionDays;
							}
						}
					});
				} catch {
					// Query failed or was aborted
				} finally {
					scope.inFlightRequests.delete(requestId);
					cleanUpCompletedGuards(scope);
					requestToScopeMap.delete(requestId);
				}
			},
			merge: (_currentCache, responseData, { requestId }) => {
				const scope = requestToScopeMap.get(requestId);
				if (!scope) {
					return responseData;
				}

				const reqSeq = scope.inFlightRequests.get(requestId);

				// Remove obsolete saved guards for webhooks that were deleted/omitted on the server
				const incomingIds = new Set(
					responseData.map((item) => item.id)
				);
				for (const [id] of scope.savedGuards) {
					if (!incomingIds.has(id)) {
						scope.savedGuards.delete(id);
					}
				}

				return applyRetentionGuards(scope, reqSeq, responseData);
			},
		}),
		getWebhookEvents: build.query<WebhookEventCatalogItem[], void>({
			query: () => API_ROUTES.webhooks.events,
			transformResponse: (
				response: ApiDataResponse<WebhookEventCatalogItem[]>
			) => response.data ?? [],
		}),
		createWebhook: build.mutation<
			ApiDataResponse<CreatedWebhook>,
			CreateWebhookPayload
		>({
			query: (body) => ({
				url: API_ROUTES.webhooks.index,
				method: "POST",
				body,
			}),
			invalidatesTags: WEBHOOK_TAGS,
		}),
		updateWebhook: build.mutation<
			ApiDataResponse<WebhookSummary>,
			UpdateWebhookPayload
		>({
			queryFn: async ({ id, ...body }, api, _extraOptions, baseQuery) => {
				const result = await baseQuery({
					url: API_ROUTES.webhooks.byId(id),
					method: "PUT",
					body,
				});

				if (result.error) {
					return { error: result.error };
				}

				const response = result.data as ApiDataResponse<WebhookSummary>;
				const confirmedRetention =
					response.data?.deliveryRetentionDays ??
					body.deliveryRetentionDays;

				if (undefined !== confirmedRetention) {
					const scope = getStoreScope(api.getState);
					const saveSeq = ++scope.sequenceCounter;
					scope.savedGuards.set(id, {
						retentionDays: confirmedRetention,
						savedAtSeq: saveSeq,
					});
					cleanUpCompletedGuards(scope);
				}

				return { data: response };
			},
			async onQueryStarted({ id }, { dispatch, queryFulfilled }) {
				try {
					const { data: response } = await queryFulfilled;
					const updated = response.data;
					if (updated) {
						dispatch(
							webhookApi.util.updateQueryData(
								"getWebhooks",
								undefined,
								(draft) => {
									const index = draft.findIndex(
										(item) => item.id === id
									);
									if (index !== -1) {
										draft[index] = {
											...draft[index],
											...updated,
										};
									}
								}
							)
						);
					}
				} catch {
					// Ignore mutation failure
				}
			},
			invalidatesTags: (_result, error) => (error ? [] : DELIVERY_TAGS),
		}),
		rotateWebhookSecret: build.mutation<
			ApiDataResponse<RotateSecretResult>,
			string
		>({
			query: (id) => ({
				url: API_ROUTES.webhooks.rotateSecret(id),
				method: "POST",
			}),
			invalidatesTags: WEBHOOK_TAGS,
		}),
		testWebhook: build.mutation<
			ApiDataResponse<WebhookTestResult>,
			TestWebhookPayload | string
		>({
			query: (arg) => {
				const body = typeof arg === "string" ? { id: arg } : arg;
				return {
					url: API_ROUTES.webhooks.test,
					method: "POST",
					body,
				};
			},
			invalidatesTags: WEBHOOK_TAGS,
		}),
		getWebhookDeliveries: build.query<
			WebhookDeliveriesResponse,
			GetWebhookDeliveriesParams
		>({
			query: ({ id, page = 1, perPage = 15 }) => ({
				url: API_ROUTES.webhooks.deliveries(id),
				params: {
					page,
					per_page: perPage,
				},
			}),
			transformResponse: (
				response: ApiDataResponse<WebhookDeliveriesResponse>
			) =>
				response.data ?? {
					items: [],
					meta: { page: 1, perPage: 15, total: 0, totalPages: 0 },
				},
			providesTags: ["WebhookDeliveries"],
		}),
		retryWebhookDelivery: build.mutation<
			ApiDataResponse<WebhookDeliveryItem>,
			RetryWebhookDeliveryParams
		>({
			query: ({ id, deliveryId }) => ({
				url: API_ROUTES.webhooks.retryDelivery(id, deliveryId),
				method: "POST",
			}),
			invalidatesTags: DELIVERY_TAGS,
		}),
		deleteWebhookDelivery: build.mutation<
			ApiDataResponse<DeleteWebhookDeliveryResponse>,
			DeleteWebhookDeliveryParams
		>({
			query: ({ id, deliveryId }) => ({
				url: API_ROUTES.webhooks.deliveryById(id, deliveryId),
				method: "DELETE",
			}),
			invalidatesTags: DELIVERY_TAGS,
		}),
		clearWebhookDeliveries: build.mutation<
			ApiDataResponse<ClearWebhookDeliveriesResponse>,
			ClearWebhookDeliveriesParams
		>({
			query: ({ id }) => ({
				url: API_ROUTES.webhooks.clearDeliveries(id),
				method: "DELETE",
			}),
			invalidatesTags: DELIVERY_TAGS,
		}),
		deleteWebhook: build.mutation<void, string>({
			query: (id) => ({
				url: API_ROUTES.webhooks.byId(id),
				method: "DELETE",
			}),
			async onQueryStarted(id, { getState, queryFulfilled }) {
				try {
					await queryFulfilled;
					const scope = getStoreScope(getState);
					scope.savedGuards.delete(id);
				} catch {
					// Ignore mutation failure
				}
			},
			invalidatesTags: DELIVERY_TAGS,
		}),
	}),
});

export const {
	useGetWebhooksQuery,
	useGetWebhookEventsQuery,
	useCreateWebhookMutation,
	useUpdateWebhookMutation,
	useRotateWebhookSecretMutation,
	useTestWebhookMutation,
	useGetWebhookDeliveriesQuery,
	useRetryWebhookDeliveryMutation,
	useDeleteWebhookDeliveryMutation,
	useClearWebhookDeliveriesMutation,
	useDeleteWebhookMutation,
} = webhookApi;
