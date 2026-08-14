import {
	API_ROUTES,
	buildApiRouteWithQuery,
	createApiQueryParams,
} from "@/api";

import baseApi from "./base";
import { createFormData } from "./formData";
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

type CreateUrlRequestBody =
	Omit<CreateUrlPayload, "socialImageFile"> | FormData;

type UpdateUrlRequestBody =
	| Omit<UpdateUrlPayload, "id" | "socialImageFile" | "removeSocialImage">
	| FormData;

/**
 * Return a request body for creating a link.
 */
function createUrlRequestBody(payload: CreateUrlPayload): CreateUrlRequestBody {
	const { socialImageFile, ...body } = payload;

	if (!socialImageFile) {
		return body;
	}

	return createFormData({
		...body,
		socialImage: socialImageFile,
	});
}

/**
 * Return the request body and method mode for updating a link.
 */
function buildUpdateUrlRequest({
	socialImageFile,
	removeSocialImage,
	...body
}: Omit<UpdateUrlPayload, "id">): {
	hasMultipartUpdate: boolean;
	requestBody: UpdateUrlRequestBody;
} {
	const hasMultipartUpdate =
		Boolean(socialImageFile) || Boolean(removeSocialImage);

	if (!hasMultipartUpdate) {
		return { hasMultipartUpdate, requestBody: body };
	}

	const requestBody = createFormData({
		...body,
		socialImage: socialImageFile || undefined,
		removeSocialImage: removeSocialImage ? "1" : undefined,
	});

	return { hasMultipartUpdate, requestBody };
}

/**
 * Return the links-list route with stable query parameters.
 */
function getUrlsRoute({
	page = 1,
	limit = 25,
	sortBy = "createdAt",
	sortOrder = "desc",
	search = "",
	range,
	from,
	to,
}: GetUrlsQueryArgs = {}): string {
	const params = createApiQueryParams({
		page,
		limit,
		sortBy,
		sortOrder,
		search: search || undefined,
	});

	if (range === "custom") {
		const hasValidFrom = typeof from === "string" && from.trim().length > 0;
		const hasValidTo = typeof to === "string" && to.trim().length > 0;

		if (hasValidFrom && hasValidTo) {
			params.set("range", range);
			params.set("from", from);
			params.set("to", to);
		}
	} else if (range) {
		params.set("range", range);
	}

	return buildApiRouteWithQuery(API_ROUTES.urls.index, params);
}

/**
 * Return the links-export route with stable query parameters.
 */
function getUrlsExportRoute({
	sortBy = "createdAt",
	sortOrder = "desc",
	search = "",
}: GetUrlsExportQueryArgs = {}): string {
	const params = createApiQueryParams({
		sortBy,
		sortOrder,
		search: search || undefined,
	});

	return buildApiRouteWithQuery(API_ROUTES.urls.export, params);
}

/**
 * RTK Query endpoints for managing short links.
 */
export const urlsApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		getUrls: build.query<UrlsListResponse, GetUrlsQueryArgs | void>({
			query: (args) => getUrlsRoute(args || {}),
			providesTags: (result) => {
				const items = result?.data?.items || result?.items || [];

				return [URL_LIST_TAG, ...items.map((url) => urlTag(url.id))];
			},
		}),
		getUrl: build.query<UrlResponse, string>({
			query: (id) => API_ROUTES.urls.byId(id),
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
			query: (args) => getUrlsExportRoute(args || {}),
		}),
		createUrl: build.mutation<CreateUrlResponse, CreateUrlPayload>({
			query: (body) => ({
				url: API_ROUTES.urls.index,
				method: "POST",
				body: createUrlRequestBody(body),
			}),
			invalidatesTags: URL_LIST_CHANGE_TAGS,
		}),
		bulkCreateUrl: build.mutation<
			BulkCreateResponse,
			BulkCreateUrlsPayload
		>({
			query: (body) => ({
				url: API_ROUTES.urls.bulk,
				method: "POST",
				body,
			}),
			invalidatesTags: URL_LIST_CHANGE_TAGS,
		}),
		updateUrl: build.mutation<UrlResponse, UpdateUrlPayload>({
			query: ({ id, ...payload }) => {
				const { hasMultipartUpdate, requestBody } =
					buildUpdateUrlRequest(payload);

				return {
					url: API_ROUTES.urls.byId(id),
					method: hasMultipartUpdate ? "POST" : "PUT",
					body: requestBody,
				};
			},
			invalidatesTags: (_result, _error, { id }) => [
				urlTag(id),
				URL_LIST_TAG,
				"Analytics",
			],
		}),
		deleteUrl: build.mutation<void, string>({
			query: (id) => ({
				url: API_ROUTES.urls.byId(id),
				method: "DELETE",
			}),
			invalidatesTags: URL_LIST_CHANGE_TAGS,
		}),
		bulkDeleteUrl: build.mutation<void, string[]>({
			query: (ids) => ({
				url: API_ROUTES.urls.bulk,
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
