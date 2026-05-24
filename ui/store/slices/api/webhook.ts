import { API_ROUTES } from "@/api";

import baseApi from "./base";
import type {
	ApiDataResponse,
	CreateWebhookPayload,
	CreatedWebhook,
	WebhookSummary,
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
	useCreateWebhookMutation,
	useDeleteWebhookMutation,
} = webhookApi;
