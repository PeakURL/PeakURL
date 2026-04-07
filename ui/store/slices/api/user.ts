import baseApi from './base';
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
	ResetPasswordPayload,
	RevokeOtherSessionsResponse,
	SecuritySettingsResponse,
	TwoFactorSetupResponse,
	UpdateUserPayload,
	UserDialogPayload,
	UserSummary,
	VerifyTwoFactorPayload,
} from './types';
import type { ProfileUser } from '@/pages/dashboard/settings/_components/Content/types';

/**
 * Generic object payload used by auth endpoints that do not yet have a stable
 * request contract in the React layer.
 */
type UnknownBodyPayload = Record<string, unknown>;

/**
 * RTK Query endpoints for authentication, profile, and user management.
 */
export const userApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		register: build.mutation<ApiDataResponse<UnknownBodyPayload>, UnknownBodyPayload>({
			query: (body) => ({
				url: 'auth/register',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['Users'],
		}),
		verifyEmail: build.mutation<ApiDataResponse<UnknownBodyPayload>, UnknownBodyPayload>({
			query: (body) => ({
				url: 'auth/verify-email',
				method: 'POST',
				body,
			}),
		}),
		resendVerificationEmail: build.mutation<
			ApiDataResponse<UnknownBodyPayload>,
			UnknownBodyPayload
		>({
			query: (body) => ({
				url: 'auth/resend-verification',
				method: 'POST',
				body,
			}),
		}),
		login: build.mutation<LoginResponse, CredentialLoginPayload>({
			query: (body) => ({
				url: 'auth/login',
				method: 'POST',
				body,
			}),
			invalidatesTags: (result) =>
				result?.data?.user ? ['AuthSession', 'Profile'] : [],
		}),
		verifyTwoFactorLogin: build.mutation<
			LoginResponse,
			CredentialLoginPayload
		>({
			query: (body) => ({
				url: 'auth/login/verify',
				method: 'POST',
				body,
			}),
			invalidatesTags: (result) =>
				result?.data?.user ? ['AuthSession', 'Profile'] : [],
		}),
		logout: build.mutation<LogoutResponse, void>({
			query: () => ({
				url: 'auth/logout',
				method: 'POST',
			}),
			invalidatesTags: (result) =>
				result?.data?.loggedOut ? ['AuthSession', 'Profile'] : [],
		}),
		getUserProfile: build.query<ApiDataResponse<ProfileUser>, void>({
			query: () => 'users/me',
			providesTags: ['Profile'],
		}),
		updateUserProfile: build.mutation<
			ApiDataResponse<ProfileUser>,
			UnknownBodyPayload
		>({
			query: (body) => ({
				url: 'users/me',
				method: 'PUT',
				body,
			}),
			invalidatesTags: ['Profile'],
		}),
		authCheck: build.query<AuthCheckResponse, void>({
			query: () => 'users/me',
			providesTags: ['AuthSession'],
		}),
		forgotPassword: build.mutation<
			ApiDataResponse<UnknownBodyPayload>,
			ForgotPasswordPayload
		>({
			query: (body) => ({
				url: 'auth/forgot-password',
				method: 'POST',
				body,
			}),
		}),
		resetPassword: build.mutation<
			ApiDataResponse<UnknownBodyPayload>,
			ResetPasswordPayload
		>({
			query: ({ token, ...body }) => ({
				url: `auth/reset-password/${token}`,
				method: 'POST',
				body,
			}),
		}),
		getAllUsers: build.query<ApiDataResponse<UserSummary[]>, void>({
			query: () => 'users',
			providesTags: ['Users'],
		}),
		createUser: build.mutation<ApiDataResponse<UserSummary>, UserDialogPayload>({
			query: (body) => ({
				url: 'users',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['Users'],
		}),
		updateUser: build.mutation<ApiDataResponse<UserSummary>, UpdateUserPayload>({
			query: ({ currentUsername, username, ...body }) => ({
				url: `users/${currentUsername || username}`,
				method: 'PUT',
				body: {
					username,
					...body,
				},
			}),
			invalidatesTags: ['Users'],
		}),
		deleteUser: build.mutation<void, string>({
			query: (username) => ({
				url: `users/${username}`,
				method: 'DELETE',
			}),
			invalidatesTags: ['Users'],
		}),
		generateApiKey: build.mutation<
			GenerateApiKeyResponse,
			GenerateApiKeyPayload
		>({
			query: (body) => ({
				url: 'auth/api-key',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['Profile'],
		}),
		deleteApiKey: build.mutation<void, string>({
			query: (id) => ({
				url: `auth/api-key/${id}`,
				method: 'DELETE',
			}),
			invalidatesTags: ['Profile'],
		}),
		getSecuritySettings: build.query<SecuritySettingsResponse, void>({
			query: () => 'auth/security',
			providesTags: ['Security'],
		}),
		startTwoFactorSetup: build.mutation<TwoFactorSetupResponse, void>({
			query: () => ({
				url: 'auth/security/two-factor/setup',
				method: 'POST',
			}),
			invalidatesTags: ['Security'],
		}),
		verifyTwoFactor: build.mutation<
			BackupCodesResponse,
			VerifyTwoFactorPayload
		>({
			query: (body) => ({
				url: 'auth/security/two-factor/verify',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['Security'],
		}),
		disableTwoFactor: build.mutation<
			ApiDataResponse<UnknownBodyPayload>,
			CurrentPasswordPayload
		>({
			query: (body) => ({
				url: 'auth/security/two-factor/disable',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['Security'],
		}),
		regenerateBackupCodes: build.mutation<
			BackupCodesResponse,
			CurrentPasswordPayload
		>({
			query: (body) => ({
				url: 'auth/security/two-factor/backup-codes',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['Security'],
		}),
		downloadBackupCodes: build.mutation<string, CurrentPasswordPayload>({
			query: (body) => ({
				url: 'auth/security/backup-codes/download',
				method: 'POST',
				body,
				responseHandler: (response: Response) => response.text(),
			}),
		}),
		revokeSession: build.mutation<void, string>({
			query: (sessionId) => ({
				url: `auth/security/sessions/${sessionId}`,
				method: 'DELETE',
			}),
			invalidatesTags: ['Security'],
		}),
		revokeOtherSessions: build.mutation<RevokeOtherSessionsResponse, void>({
			query: () => ({
				url: 'auth/security/sessions',
				method: 'DELETE',
			}),
			invalidatesTags: ['Security'],
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

export const { forgotPassword, resetPassword } = userApi.endpoints;
