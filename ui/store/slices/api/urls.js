import baseApi from './base';

export const urlsApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		getUrls: build.query({
			query: ({
				page = 1,
				limit = 25,
				sortBy = 'createdAt',
				sortOrder = 'desc',
				search = '',
			} = {}) => {
				const params = new URLSearchParams();
				params.set('page', String(page));
				params.set('limit', String(limit));
				params.set('sortBy', sortBy);
				params.set('sortOrder', sortOrder);
				if (search) params.set('search', search);

				// Updated endpoint to 'urls'
				return `urls?${params.toString()}`;
			},
			providesTags: (result) => {
				// Handle response format: { data: { items: [...] } } or { items: [...] }
				const items = result?.data?.items || result?.items || [];

				return [
					{ type: 'Urls', id: 'LIST' },
					...items.map((url) => ({ type: 'Urls', id: url.id })),
				];
			},
		}),
		getUrl: build.query({
			query: (id) => `urls/${id}`,
			providesTags: (_res, _err, id) => [{ type: 'Urls', id }],
		}),
		getUrlsExport: build.query({
			query: ({
				sortBy = 'createdAt',
				sortOrder = 'desc',
				search = '',
			} = {}) => {
				const params = new URLSearchParams();
				params.set('sortBy', sortBy);
				params.set('sortOrder', sortOrder);
				if (search) params.set('search', search);

				return `urls/export?${params.toString()}`;
			},
		}),
		createUrl: build.mutation({
			query: (body) => ({ url: 'urls', method: 'POST', body }),
			invalidatesTags: [{ type: 'Urls', id: 'LIST' }, 'Analytics'],
		}),
		bulkCreateUrl: build.mutation({
			query: (body) => ({ url: 'urls/bulk', method: 'POST', body }),
			invalidatesTags: [{ type: 'Urls', id: 'LIST' }, 'Analytics'],
		}),
		updateUrl: build.mutation({
			query: ({ id, ...body }) => ({
				url: `urls/${id}`,
				method: 'PUT',
				body,
			}),
			invalidatesTags: (_res, _err, { id }) => [
				{ type: 'Urls', id },
				{ type: 'Urls', id: 'LIST' },
				'Analytics',
			],
		}),
		deleteUrl: build.mutation({
			query: (id) => ({ url: `urls/${id}`, method: 'DELETE' }),
			invalidatesTags: [{ type: 'Urls', id: 'LIST' }, 'Analytics'],
		}),
		bulkDeleteUrl: build.mutation({
			query: (ids) => ({
				url: 'urls/bulk',
				method: 'DELETE',
				body: { ids },
			}),
			invalidatesTags: [{ type: 'Urls', id: 'LIST' }, 'Analytics'],
		}),
	}),
});

export const {
	useGetUrlsQuery,
	useGetUrlQuery,
	useLazyGetUrlsExportQuery,
	useCreateUrlMutation,
	useBulkCreateUrlMutation,
	useUpdateUrlMutation,
	useDeleteUrlMutation,
	useBulkDeleteUrlMutation,
} = urlsApi;
