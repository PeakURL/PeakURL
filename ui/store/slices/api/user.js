import baseApi from './base';

export const userApi = baseApi.injectEndpoints({
	endpoints: (build) => ({
		register: build.mutation({
			query: (body) => ({
				url: 'auth/register',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['Users'],
		}),
		verifyEmail: build.mutation({
			query: (body) => ({
				url: 'auth/verify-email',
				method: 'POST',
				body,
			}),
		}),
		resendVerificationEmail: build.mutation({
			query: (body) => ({
				url: 'auth/resend-verification',
				method: 'POST',
				body,
			}),
		}),
		login: build.mutation({
			query: (body) => ({
				url: 'auth/login',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['AuthSession', 'Profile'],
		}),
		verifyTwoFactorLogin: build.mutation({
			query: (body) => ({
				url: 'auth/login/verify',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['AuthSession', 'Profile'],
		}),
		logout: build.mutation({
			query: () => ({
				url: 'auth/logout',
				method: 'POST',
			}),
			invalidatesTags: ['AuthSession', 'Profile'],
		}),
		getUserProfile: build.query({
			query: () => 'users/me',
			providesTags: ['Profile'],
		}),
		updateUserProfile: build.mutation({
			query: (body) => ({
				url: 'users/me',
				method: 'PUT',
				body,
			}),
			invalidatesTags: ['Profile'],
		}),
		authCheck: build.query({
			query: () => 'users/me',
			providesTags: ['AuthSession'],
			retry: 0,
		}),
		forgotPassword: build.mutation({
			query: (body) => ({
				url: 'auth/forgot-password',
				method: 'POST',
				body,
			}),
		}),
		resetPassword: build.mutation({
			query: ({ token, ...body }) => ({
				url: `auth/reset-password/${token}`,
				method: 'POST',
				body,
			}),
		}),
		getAllUsers: build.query({
			query: () => 'users',
			providesTags: ['Users'],
		}),
		createUser: build.mutation({
			query: (body) => ({
				url: 'users',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['Users'],
		}),
		updateUser: build.mutation({
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
		deleteUser: build.mutation({
			query: (username) => ({
				url: `users/${username}`,
				method: 'DELETE',
			}),
			invalidatesTags: ['Users'],
		}),
		generateApiKey: build.mutation({
			query: (body) => ({
				url: 'auth/api-key',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['Profile'],
		}),
		deleteApiKey: build.mutation({
			query: (id) => ({
				url: `auth/api-key/${id}`,
				method: 'DELETE',
			}),
			invalidatesTags: ['Profile'],
		}),
		getSecuritySettings: build.query({
			query: () => 'auth/security',
			providesTags: ['Security'],
		}),
		startTwoFactorSetup: build.mutation({
			query: () => ({
				url: 'auth/security/two-factor/setup',
				method: 'POST',
			}),
			invalidatesTags: ['Security'],
		}),
		verifyTwoFactor: build.mutation({
			query: (body) => ({
				url: 'auth/security/two-factor/verify',
				method: 'POST',
				body,
			}),
			invalidatesTags: ['Security'],
		}),
		disableTwoFactor: build.mutation({
			query: () => ({
				url: 'auth/security/two-factor/disable',
				method: 'POST',
			}),
			invalidatesTags: ['Security'],
		}),
		regenerateBackupCodes: build.mutation({
			query: () => ({
				url: 'auth/security/two-factor/backup-codes',
				method: 'POST',
			}),
			invalidatesTags: ['Security'],
		}),
		downloadBackupCodes: build.query({
			query: () => ({
				url: 'auth/security/backup-codes/download',
				method: 'GET',
				responseHandler: (response) => response.text(),
			}),
			providesTags: ['Security'],
		}),
		revokeSession: build.mutation({
			query: (sessionId) => ({
				url: `auth/security/sessions/${sessionId}`,
				method: 'DELETE',
			}),
			invalidatesTags: ['Security'],
		}),
		revokeOtherSessions: build.mutation({
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
	useDownloadBackupCodesQuery,
	useRevokeSessionMutation,
	useRevokeOtherSessionsMutation,
	useLazyDownloadBackupCodesQuery,
} = userApi;

export const { forgotPassword, resetPassword } = userApi.endpoints;
