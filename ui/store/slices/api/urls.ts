import baseApi from "./base";
import type {
	BulkCreateResponse,
	BulkCreateUrlsPayload,
	CreateUrlPayload,
	CreateUrlResponse,
	GetUrlsExportQueryArgs,
	GetUrlsQueryArgs,
	UpdateUrlPayload,
	UrlExportResponse,
	UrlResponse,
	UrlsListResponse,
} from "./types";

/**
 * Builds a stable query string for the links list endpoint.
 */
function buildUrlsQueryString({
	page = 1,
	limit = 25,
	sortBy = "createdAt",
	sortOrder = "desc",
	search = "",
}: GetUrlsQueryArgs = {}): string {
	const params = new URLSearchParams();
	params.set("page", String(page));
	params.set("limit", String(limit));
	params.set("sortBy", sortBy);
	params.set("sortOrder", sortOrder);

	if (search) {
		params.set("search", search);
	}

	return `urls?${params.toString()}`;
}

/**
 * Builds a stable query string for the export lookup endpoint.
 */
function buildUrlsExportQueryString({
	sortBy = "createdAt",
	sortOrder = "desc",
	search = "",
}: GetUrlsExportQueryArgs = {}): string {
	const params = new URLSearchParams();
	params.set("sortBy", sortBy);
	params.set("sortOrder", sortOrder);

	if (search) {
		params.set("search", search);
	}

	return `urls/export?${params.toString()}`;
}

/**
 * RTK Query endpoints for managing short links.
 */
export const urlsApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		getUrls: build.query<UrlsListResponse, GetUrlsQueryArgs | void>({
			query: (args) => buildUrlsQueryString(args || {}),
			providesTags: (result) => {
				const items = result?.data?.items || result?.items || [];

				return [
					{ type: "Urls" as const, id: "LIST" },
					...items.map((url) => ({
						type: "Urls" as const,
						id: url.id,
					})),
				];
			},
		}),
		getUrl: build.query<UrlResponse, string>({
			query: (id) => `urls/${id}`,
			providesTags: (result, _error, id) => {
				const tags = [{ type: "Urls" as const, id }];
				const resolvedId = result?.data?.id;

				if (resolvedId) {
					tags.push({ type: "Urls" as const, id: resolvedId });
				}

				return tags;
			},
		}),
		getUrlsExport: build.query<
			UrlExportResponse,
			GetUrlsExportQueryArgs | void
		>({
			query: (args) => buildUrlsExportQueryString(args || {}),
		}),
		createUrl: build.mutation<CreateUrlResponse, CreateUrlPayload>({
			query: (body) => ({ url: "urls", method: "POST", body }),
			invalidatesTags: [{ type: "Urls", id: "LIST" }, "Analytics"],
		}),
		bulkCreateUrl: build.mutation<
			BulkCreateResponse,
			BulkCreateUrlsPayload
		>({
			query: (body) => ({ url: "urls/bulk", method: "POST", body }),
			invalidatesTags: [{ type: "Urls", id: "LIST" }, "Analytics"],
		}),
		updateUrl: build.mutation<UrlResponse, UpdateUrlPayload>({
			query: ({ id, ...body }) => ({
				url: `urls/${id}`,
				method: "PUT",
				body,
			}),
			invalidatesTags: (_result, _error, { id }) => [
				{ type: "Urls", id },
				{ type: "Urls", id: "LIST" },
				"Analytics",
			],
		}),
		deleteUrl: build.mutation<void, string>({
			query: (id) => ({ url: `urls/${id}`, method: "DELETE" }),
			invalidatesTags: [{ type: "Urls", id: "LIST" }, "Analytics"],
		}),
		bulkDeleteUrl: build.mutation<void, string[]>({
			query: (ids) => ({
				url: "urls/bulk",
				method: "DELETE",
				body: { ids },
			}),
			invalidatesTags: [{ type: "Urls", id: "LIST" }, "Analytics"],
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
