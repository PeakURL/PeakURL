import { API_ROUTES } from "@/api";
import baseApi from "./base";
import type {
	ApiDataResponse,
	CreateWebhookPayload,
	CreatedWebhook,
	WebhookSummary,
} from "./types";

/**
 * RTK Query endpoints used by the integrations webhook settings UI.
 */
export const webhookApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		getWebhooks: build.query<WebhookSummary[], void>({
			query: () => API_ROUTES.webhooks.index,
			transformResponse: (response: ApiDataResponse<WebhookSummary[]>) =>
				response.data ?? [],
			providesTags: ["Webhooks"],
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
			invalidatesTags: ["Webhooks"],
		}),
		deleteWebhook: build.mutation<void, string>({
			query: (id) => ({
				url: API_ROUTES.webhooks.byId(id),
				method: "DELETE",
			}),
			invalidatesTags: ["Webhooks"],
		}),
	}),
});

export const {
	useGetWebhooksQuery,
	useCreateWebhookMutation,
	useDeleteWebhookMutation,
} = webhookApi;
