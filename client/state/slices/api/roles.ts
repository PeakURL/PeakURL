import { API_ROUTES, mapApiRoleDefinition } from "@/api";
import type {
	ApiRoleDefinition,
	RoleDefinition,
	CreateRolePayload,
	DeleteRolePayload,
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

		createRole: builder.mutation<RoleDefinition, CreateRolePayload>({
			query: (body) => ({
				url: API_ROUTES.roles.create,
				method: "POST",
				body,
			}),
			transformResponse: (response: ApiDataResponse<ApiRoleDefinition>) =>
				mapApiRoleDefinition(response.data),
			invalidatesTags: (_result, error) =>
				error ? [] : ROLES_CHANGE_TAGS,
		}),

		updateRoleCapabilities: builder.mutation<
			RoleDefinition,
			UpdateRoleCapabilitiesPayload
		>({
			query: ({ role, ...body }) => ({
				url: API_ROUTES.roles.byRole(role),
				method: "PUT",
				body,
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

		deleteRole: builder.mutation<
			{ success?: boolean; id?: string },
			DeleteRolePayload
		>({
			query: ({ role }) => ({
				url: API_ROUTES.roles.delete(role),
				method: "DELETE",
			}),
			transformResponse: (
				response: ApiDataResponse<{ success?: boolean; id?: string }>
			) => response.data ?? { success: true },
			invalidatesTags: (_result, error) =>
				error ? [] : ROLES_CHANGE_TAGS,
		}),
	}),
});

export const {
	useGetRolesQuery,
	useCreateRoleMutation,
	useUpdateRoleCapabilitiesMutation,
	useResetRoleCapabilitiesMutation,
	useDeleteRoleMutation,
} = rolesApi;
