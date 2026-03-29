import baseApi from './base';

export const webhookApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		getWebhooks: build.query({
			query: () => 'webhooks',
			transformResponse: (response) => response.data ?? [],
			providesTags: ['Webhooks'],
		}),
		createWebhook: build.mutation({
			query: (body) => ({
				url: 'webhooks',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['Webhooks'],
		}),
		deleteWebhook: build.mutation({
			query: (id) => ({
				url: `webhooks/${id}`,
				method: 'DELETE',
			}),
			invalidatesTags: ['Webhooks'],
		}),
	}),
});

export const {
	useGetWebhooksQuery,
	useCreateWebhookMutation,
	useDeleteWebhookMutation,
} = webhookApi;
