import { API_ROUTES } from "@/api";
import baseApi from "./base";
import type {
	ApiDataResponse,
	AuthCheckResponse,
	BackupCodesResponse,
	CredentialLoginPayload,
	CurrentPasswordPayload,
	ForgotPasswordPayload,
	GenerateApiKeyPayload,
	GenerateApiKeyResponse,
	LoginResponse,
	LogoutResponse,
	PasswordResetTokenStatus,
	ResetPasswordPayload,
	RevokeOtherSessionsResponse,
	SecuritySettingsResponse,
	TwoFactorSetupResponse,
	UpdateUserPayload,
	UserDialogPayload,
	UserSummary,
	VerifyTwoFactorPayload,
} from "./types";
import type { ProfileUser } from "@/pages/dashboard/settings/_components/tabs";

/**
 * Generic object payload used by auth endpoints that do not yet have a stable
 * request contract in the React layer.
 */
type UnknownBodyPayload = Record<string, unknown>;

type SessionUserResponse = {
	data?: ProfileUser | null;
	user?: ProfileUser | null;
};

const USER_PROFILE_TAGS = ["AuthSession", "Profile"] as const;
const USER_LIST_TAGS = ["Users"] as const;
const PROFILE_TAGS = ["Profile"] as const;
const SECURITY_TAGS = ["Security"] as const;

const userProfileTags = (result?: LoginResponse) =>
	result?.data?.user ? USER_PROFILE_TAGS : [];

const loggedOutTags = (result?: LogoutResponse) =>
	result?.data?.loggedOut ? USER_PROFILE_TAGS : [];

export const selectSessionUser = (
	response?: SessionUserResponse | null
): ProfileUser | null => response?.data ?? response?.user ?? null;

/**
 * RTK Query endpoints for authentication, profile, and user management.
 */
export const userApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		register: build.mutation<
			ApiDataResponse<UnknownBodyPayload>,
			UnknownBodyPayload
		>({
			query: (body) => ({
				url: API_ROUTES.auth.register,
				method: "POST",
				body,
			}),
			invalidatesTags: USER_LIST_TAGS,
		}),
		verifyEmail: build.mutation<
			ApiDataResponse<UnknownBodyPayload>,
			UnknownBodyPayload
		>({
			query: (body) => ({
				url: API_ROUTES.auth.verifyEmail,
				method: "POST",
				body,
			}),
		}),
		resendVerificationEmail: build.mutation<
			ApiDataResponse<UnknownBodyPayload>,
			UnknownBodyPayload
		>({
			query: (body) => ({
				url: API_ROUTES.auth.resendVerification,
				method: "POST",
				body,
			}),
		}),
		login: build.mutation<LoginResponse, CredentialLoginPayload>({
			query: (body) => ({
				url: API_ROUTES.auth.login,
				method: "POST",
				body,
			}),
			invalidatesTags: userProfileTags,
		}),
		verifyTwoFactorLogin: build.mutation<
			LoginResponse,
			CredentialLoginPayload
		>({
			query: (body) => ({
				url: API_ROUTES.auth.loginVerify,
				method: "POST",
				body,
			}),
			invalidatesTags: userProfileTags,
		}),
		logout: build.mutation<LogoutResponse, void>({
			query: () => ({
				url: API_ROUTES.auth.logout,
				method: "POST",
			}),
			invalidatesTags: loggedOutTags,
		}),
		getUserProfile: build.query<ApiDataResponse<ProfileUser>, void>({
			query: () => API_ROUTES.users.me,
			providesTags: USER_PROFILE_TAGS,
		}),
		updateUserProfile: build.mutation<
			ApiDataResponse<ProfileUser>,
			UnknownBodyPayload
		>({
			query: (body) => ({
				url: API_ROUTES.users.me,
				method: "PUT",
				body,
			}),
			invalidatesTags: PROFILE_TAGS,
		}),
		authCheck: build.query<AuthCheckResponse, void>({
			query: () => API_ROUTES.users.me,
			providesTags: USER_PROFILE_TAGS,
		}),
		forgotPassword: build.mutation<
			ApiDataResponse<UnknownBodyPayload>,
			ForgotPasswordPayload
		>({
			query: (body) => ({
				url: API_ROUTES.auth.forgotPassword,
				method: "POST",
				body,
			}),
		}),
		checkPasswordResetToken: build.query<
			ApiDataResponse<PasswordResetTokenStatus>,
			string
		>({
			query: (token) => API_ROUTES.auth.resetPassword(token),
		}),
		resetPassword: build.mutation<
			ApiDataResponse<UnknownBodyPayload>,
			ResetPasswordPayload
		>({
			query: ({ token, ...body }) => ({
				url: API_ROUTES.auth.resetPassword(token),
				method: "POST",
				body,
			}),
		}),
		getAllUsers: build.query<ApiDataResponse<UserSummary[]>, void>({
			query: () => API_ROUTES.users.index,
			providesTags: USER_LIST_TAGS,
		}),
		createUser: build.mutation<
			ApiDataResponse<UserSummary>,
			UserDialogPayload
		>({
			query: (body) => ({
				url: API_ROUTES.users.index,
				method: "POST",
				body,
			}),
			invalidatesTags: USER_LIST_TAGS,
		}),
		updateUser: build.mutation<
			ApiDataResponse<UserSummary>,
			UpdateUserPayload
		>({
			query: ({ currentUsername, username, ...body }) => ({
				url: API_ROUTES.users.byUsername(currentUsername || username),
				method: "PUT",
				body: {
					username,
					...body,
				},
			}),
			invalidatesTags: USER_LIST_TAGS,
		}),
		deleteUser: build.mutation<void, string>({
			query: (username) => ({
				url: API_ROUTES.users.byUsername(username),
				method: "DELETE",
			}),
			invalidatesTags: USER_LIST_TAGS,
		}),
		generateApiKey: build.mutation<
			GenerateApiKeyResponse,
			GenerateApiKeyPayload
		>({
			query: (body) => ({
				url: API_ROUTES.auth.apiKey,
				method: "POST",
				body,
			}),
			invalidatesTags: PROFILE_TAGS,
		}),
		deleteApiKey: build.mutation<void, string>({
			query: (id) => ({
				url: API_ROUTES.auth.apiKeyById(id),
				method: "DELETE",
			}),
			invalidatesTags: PROFILE_TAGS,
		}),
		getSecuritySettings: build.query<SecuritySettingsResponse, void>({
			query: () => API_ROUTES.auth.security,
			providesTags: SECURITY_TAGS,
		}),
		startTwoFactorSetup: build.mutation<TwoFactorSetupResponse, void>({
			query: () => ({
				url: API_ROUTES.auth.twoFactorSetup,
				method: "POST",
			}),
			invalidatesTags: SECURITY_TAGS,
		}),
		verifyTwoFactor: build.mutation<
			BackupCodesResponse,
			VerifyTwoFactorPayload
		>({
			query: (body) => ({
				url: API_ROUTES.auth.twoFactorVerify,
				method: "POST",
				body,
			}),
			invalidatesTags: SECURITY_TAGS,
		}),
		disableTwoFactor: build.mutation<
			ApiDataResponse<UnknownBodyPayload>,
			CurrentPasswordPayload
		>({
			query: (body) => ({
				url: API_ROUTES.auth.twoFactorDisable,
				method: "POST",
				body,
			}),
			invalidatesTags: SECURITY_TAGS,
		}),
		regenerateBackupCodes: build.mutation<
			BackupCodesResponse,
			CurrentPasswordPayload
		>({
			query: (body) => ({
				url: API_ROUTES.auth.twoFactorBackupCodes,
				method: "POST",
				body,
			}),
			invalidatesTags: SECURITY_TAGS,
		}),
		downloadBackupCodes: build.mutation<string, CurrentPasswordPayload>({
			query: (body) => ({
				url: API_ROUTES.auth.securityBackupCodesDownload,
				method: "POST",
				body,
				responseHandler: (response: Response) => response.text(),
			}),
		}),
		revokeSession: build.mutation<void, string>({
			query: (sessionId) => ({
				url: API_ROUTES.auth.securitySession(sessionId),
				method: "DELETE",
			}),
			invalidatesTags: SECURITY_TAGS,
		}),
		revokeOtherSessions: build.mutation<RevokeOtherSessionsResponse, void>({
			query: () => ({
				url: API_ROUTES.auth.securitySessions,
				method: "DELETE",
			}),
			invalidatesTags: SECURITY_TAGS,
		}),
	}),
});

export const {
	useRegisterMutation,
	useVerifyEmailMutation,
	useResendVerificationEmailMutation,
	useLoginMutation,
	useVerifyTwoFactorLoginMutation,
	useLogoutMutation,
	useGetUserProfileQuery,
	useUpdateUserProfileMutation,
	useAuthCheckQuery,
	useForgotPasswordMutation,
	useCheckPasswordResetTokenQuery,
	useResetPasswordMutation,
	useGetAllUsersQuery,
	useCreateUserMutation,
	useUpdateUserMutation,
	useDeleteUserMutation,
	useGenerateApiKeyMutation,
	useDeleteApiKeyMutation,
	useGetSecuritySettingsQuery,
	useStartTwoFactorSetupMutation,
	useVerifyTwoFactorMutation,
	useDisableTwoFactorMutation,
	useRegenerateBackupCodesMutation,
	useDownloadBackupCodesMutation,
	useRevokeSessionMutation,
	useRevokeOtherSessionsMutation,
} = userApi;

export const { forgotPassword, checkPasswordResetToken, resetPassword } =
	userApi.endpoints;
