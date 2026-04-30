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

const urlTag = (id: string) => ({ type: "Urls" as const, id });

const URL_LIST_TAG = urlTag("LIST");
const URL_LIST_CHANGE_TAGS = [URL_LIST_TAG, "Analytics"] as const;

/**
 * Returns a stable query string for the links list endpoint.
 */
function serializeUrlsQuery({
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
 * Returns a stable query string for the export lookup endpoint.
 */
function serializeUrlsExportQuery({
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
			query: (args) => serializeUrlsQuery(args || {}),
			providesTags: (result) => {
				const items = result?.data?.items || result?.items || [];

				return [
					URL_LIST_TAG,
					...items.map((url) => urlTag(url.id)),
				];
			},
		}),
		getUrl: build.query<UrlResponse, string>({
			query: (id) => `urls/${id}`,
			providesTags: (result, _error, id) => {
				const tags = [urlTag(id)];
				const createdId = result?.data?.id;

				if (createdId) {
					tags.push(urlTag(createdId));
				}

				return tags;
			},
		}),
		getUrlsExport: build.query<
			UrlExportResponse,
			GetUrlsExportQueryArgs | void
		>({
			query: (args) => serializeUrlsExportQuery(args || {}),
		}),
		createUrl: build.mutation<CreateUrlResponse, CreateUrlPayload>({
			query: (body) => ({ url: "urls", method: "POST", body }),
			invalidatesTags: URL_LIST_CHANGE_TAGS,
		}),
		bulkCreateUrl: build.mutation<
			BulkCreateResponse,
			BulkCreateUrlsPayload
		>({
			query: (body) => ({ url: "urls/bulk", method: "POST", body }),
			invalidatesTags: URL_LIST_CHANGE_TAGS,
		}),
		updateUrl: build.mutation<UrlResponse, UpdateUrlPayload>({
			query: ({ id, ...body }) => ({
				url: `urls/${id}`,
				method: "PUT",
				body,
			}),
			invalidatesTags: (_result, _error, { id }) => [
				urlTag(id),
				URL_LIST_TAG,
				"Analytics",
			],
		}),
		deleteUrl: build.mutation<void, string>({
			query: (id) => ({ url: `urls/${id}`, method: "DELETE" }),
			invalidatesTags: URL_LIST_CHANGE_TAGS,
		}),
		bulkDeleteUrl: build.mutation<void, string[]>({
			query: (ids) => ({
				url: "urls/bulk",
				method: "DELETE",
				body: { ids },
			}),
			invalidatesTags: URL_LIST_CHANGE_TAGS,
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
