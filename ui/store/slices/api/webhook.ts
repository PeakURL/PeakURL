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
			query: () => "webhooks",
			transformResponse: (response: ApiDataResponse<WebhookSummary[]>) =>
				response.data ?? [],
			providesTags: ["Webhooks"],
		}),
		createWebhook: build.mutation<
			ApiDataResponse<CreatedWebhook>,
			CreateWebhookPayload
		>({
			query: (body) => ({
				url: "webhooks",
				method: "POST",
				body,
			}),
			invalidatesTags: ["Webhooks"],
		}),
		deleteWebhook: build.mutation<void, string>({
			query: (id) => ({
				url: `webhooks/${id}`,
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
