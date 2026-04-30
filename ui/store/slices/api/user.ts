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
import type { ProfileUser } from "@/pages/dashboard/settings/_components/tabs/types";

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
				url: "auth/register",
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
				url: "auth/verify-email",
				method: "POST",
				body,
			}),
		}),
		resendVerificationEmail: build.mutation<
			ApiDataResponse<UnknownBodyPayload>,
			UnknownBodyPayload
		>({
			query: (body) => ({
				url: "auth/resend-verification",
				method: "POST",
				body,
			}),
		}),
		login: build.mutation<LoginResponse, CredentialLoginPayload>({
			query: (body) => ({
				url: "auth/login",
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
				url: "auth/login/verify",
				method: "POST",
				body,
			}),
			invalidatesTags: userProfileTags,
		}),
		logout: build.mutation<LogoutResponse, void>({
			query: () => ({
				url: "auth/logout",
				method: "POST",
			}),
			invalidatesTags: loggedOutTags,
		}),
		getUserProfile: build.query<ApiDataResponse<ProfileUser>, void>({
			query: () => "users/me",
			providesTags: USER_PROFILE_TAGS,
		}),
		updateUserProfile: build.mutation<
			ApiDataResponse<ProfileUser>,
			UnknownBodyPayload
		>({
			query: (body) => ({
				url: "users/me",
				method: "PUT",
				body,
			}),
			invalidatesTags: PROFILE_TAGS,
		}),
		authCheck: build.query<AuthCheckResponse, void>({
			query: () => "users/me",
			providesTags: USER_PROFILE_TAGS,
		}),
		forgotPassword: build.mutation<
			ApiDataResponse<UnknownBodyPayload>,
			ForgotPasswordPayload
		>({
			query: (body) => ({
				url: "auth/forgot-password",
				method: "POST",
				body,
			}),
		}),
		checkPasswordResetToken: build.query<
			ApiDataResponse<PasswordResetTokenStatus>,
			string
		>({
			query: (token) =>
				`auth/reset-password/${encodeURIComponent(token)}`,
		}),
		resetPassword: build.mutation<
			ApiDataResponse<UnknownBodyPayload>,
			ResetPasswordPayload
		>({
			query: ({ token, ...body }) => ({
				url: `auth/reset-password/${encodeURIComponent(token)}`,
				method: "POST",
				body,
			}),
		}),
		getAllUsers: build.query<ApiDataResponse<UserSummary[]>, void>({
			query: () => "users",
			providesTags: USER_LIST_TAGS,
		}),
		createUser: build.mutation<
			ApiDataResponse<UserSummary>,
			UserDialogPayload
		>({
			query: (body) => ({
				url: "users",
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
				url: `users/${currentUsername || username}`,
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
				url: `users/${username}`,
				method: "DELETE",
			}),
			invalidatesTags: USER_LIST_TAGS,
		}),
		generateApiKey: build.mutation<
			GenerateApiKeyResponse,
			GenerateApiKeyPayload
		>({
			query: (body) => ({
				url: "auth/api-key",
				method: "POST",
				body,
			}),
			invalidatesTags: PROFILE_TAGS,
		}),
		deleteApiKey: build.mutation<void, string>({
			query: (id) => ({
				url: `auth/api-key/${id}`,
				method: "DELETE",
			}),
			invalidatesTags: PROFILE_TAGS,
		}),
		getSecuritySettings: build.query<SecuritySettingsResponse, void>({
			query: () => "auth/security",
			providesTags: SECURITY_TAGS,
		}),
		startTwoFactorSetup: build.mutation<TwoFactorSetupResponse, void>({
			query: () => ({
				url: "auth/security/two-factor/setup",
				method: "POST",
			}),
			invalidatesTags: SECURITY_TAGS,
		}),
		verifyTwoFactor: build.mutation<
			BackupCodesResponse,
			VerifyTwoFactorPayload
		>({
			query: (body) => ({
				url: "auth/security/two-factor/verify",
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
				url: "auth/security/two-factor/disable",
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
				url: "auth/security/two-factor/backup-codes",
				method: "POST",
				body,
			}),
			invalidatesTags: SECURITY_TAGS,
		}),
		downloadBackupCodes: build.mutation<string, CurrentPasswordPayload>({
			query: (body) => ({
				url: "auth/security/backup-codes/download",
				method: "POST",
				body,
				responseHandler: (response: Response) => response.text(),
			}),
		}),
		revokeSession: build.mutation<void, string>({
			query: (sessionId) => ({
				url: `auth/security/sessions/${sessionId}`,
				method: "DELETE",
			}),
			invalidatesTags: SECURITY_TAGS,
		}),
		revokeOtherSessions: build.mutation<RevokeOtherSessionsResponse, void>({
			query: () => ({
				url: "auth/security/sessions",
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
