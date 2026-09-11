import { createApi, fetchBaseQuery } from "@reduxjs/toolkit/query/react";
import { API_CLIENT_BASE_URL } from "@/constants";
import { API_TAG_TYPES } from "./types";

/**
 * Shared RTK Query API instance for dashboard requests.
 *
 * Individual domain slices inject their endpoints into this base API so the
 * store, middleware, and tag configuration remain centralized.
 */
const baseApi = createApi({
	reducerPath: "api",
	baseQuery: fetchBaseQuery({
		baseUrl: API_CLIENT_BASE_URL,
		credentials: "include",
	}),
	tagTypes: API_TAG_TYPES,
	endpoints: () => ({}),
});

export default baseApi;
