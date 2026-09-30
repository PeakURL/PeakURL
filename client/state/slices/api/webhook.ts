import { API_ROUTES } from "@/api";

import baseApi from "./base";
import type {
	ApiDataResponse,
	CreateWebhookPayload,
	CreatedWebhook,
	GetWebhookDeliveriesParams,
	RotateSecretResult,
	TestWebhookPayload,
	UpdateWebhookPayload,
	WebhookDeliveriesResponse,
	WebhookEventCatalogItem,
	WebhookSummary,
	WebhookTestResult,
} from "./types";

const WEBHOOK_TAGS = ["Webhooks"] as const;

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
			query: ({ id, ...body }) => ({
				url: API_ROUTES.webhooks.byId(id),
				method: "PUT",
				body,
			}),
			invalidatesTags: WEBHOOK_TAGS,
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
		}),
		deleteWebhook: build.mutation<void, string>({
			query: (id) => ({
				url: API_ROUTES.webhooks.byId(id),
				method: "DELETE",
			}),
			invalidatesTags: WEBHOOK_TAGS,
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
	useDeleteWebhookMutation,
} = webhookApi;
