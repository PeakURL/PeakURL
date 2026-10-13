import { API_ROUTES, mapApiRoleDefinition } from "@/api";
import type {
	ApiRoleDefinition,
	RoleDefinition,
	ResetRoleCapabilitiesPayload,
	UpdateRoleCapabilitiesPayload,
} from "@/api";

import baseApi from "./base";
import type { ApiDataResponse } from "./types";

const ROLES_TAGS = ["Roles"] as const;
const ROLES_CHANGE_TAGS = ["Roles", "AuthSession", "Profile", "Users"] as const;

export const rolesApi = baseApi.injectEndpoints({
	endpoints: (builder) => ({
		getRoles: builder.query<RoleDefinition[], void>({
			query: () => ({
				url: API_ROUTES.roles.index,
				method: "GET",
			}),
			transformResponse: (
				response: ApiDataResponse<ApiRoleDefinition[]>
			) => (response.data ?? []).map(mapApiRoleDefinition),
			providesTags: ROLES_TAGS,
		}),

		updateRoleCapabilities: builder.mutation<
			RoleDefinition,
			UpdateRoleCapabilitiesPayload
		>({
			query: ({ role, capabilities }) => ({
				url: API_ROUTES.roles.byRole(role),
				method: "PUT",
				body: { capabilities },
			}),
			transformResponse: (response: ApiDataResponse<ApiRoleDefinition>) =>
				mapApiRoleDefinition(response.data),
			invalidatesTags: (_result, error) =>
				error ? [] : ROLES_CHANGE_TAGS,
		}),

		resetRoleCapabilities: builder.mutation<
			RoleDefinition,
			ResetRoleCapabilitiesPayload
		>({
			query: ({ role }) => ({
				url: API_ROUTES.roles.reset(role),
				method: "POST",
			}),
			transformResponse: (response: ApiDataResponse<ApiRoleDefinition>) =>
				mapApiRoleDefinition(response.data),
			invalidatesTags: (_result, error) =>
				error ? [] : ROLES_CHANGE_TAGS,
		}),
	}),
});

export const {
	useGetRolesQuery,
	useUpdateRoleCapabilitiesMutation,
	useResetRoleCapabilitiesMutation,
} = rolesApi;
