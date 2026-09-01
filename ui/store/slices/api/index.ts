/**
 * Dashboard RTK Query API exports.
 *
 * Domain slices stay in separate files, while app code imports generated hooks
 * and shared DTO types from this single API barrel.
 */

export { analyticsApi } from "./analytics";
export {
	useBulkDeleteActivityLogsMutation,
	useClearActivityLogsMutation,
	useDeleteActivityLogMutation,
	useGetActivityQuery,
	useGetActivityHistoryQuery,
	useGetAnalyticsQuery,
	useGetRecentClicksQuery,
	useGetLinkLocationQuery,
	useGetLinkStatsQuery,
	useRestoreActivityLinkMutation,
} from "./analytics";
export { default as baseApi } from "./base";
export { systemApi } from "./system";
export {
	useApplyUpdateMutation,
	useCheckForUpdatesMutation,
	useDownloadGeoipDatabaseMutation,
	useGetAdminNoticesQuery,
	useGetGeneralSettingsQuery,
	useGetGeoipStatusQuery,
	useGetCaptchaStatusQuery,
	useGetMailStatusQuery,
	useGetSystemStatusQuery,
	useGetUpdateStatusQuery,
	useReinstallUpdateMutation,
	useSaveGeneralSettingsMutation,
	useSaveGeoipConfigurationMutation,
	useSaveCaptchaConfigurationMutation,
	useSaveMailConfigurationMutation,
	useSendTestEmailMutation,
	useUpgradeDatabaseSchemaMutation,
} from "./system";
export * from "./types";
export { urlsApi } from "./urls";
export {
	useBulkCreateUrlMutation,
	useBulkDeleteUrlMutation,
	useBulkRestoreUrlsMutation,
	useClearUrlsMutation,
	useCreateUrlMutation,
	useDeleteUrlMutation,
	useEmptyTrashMutation,
	useGetUrlQuery,
	useGetUrlsQuery,
	useLazyGetUrlsExportQuery,
	useRestoreUrlMutation,
	useUpdateUrlMutation,
} from "./urls";
export { userApi } from "./user";
export {
	selectSessionUser,
	useAuthCheckQuery,
	useCheckPasswordResetTokenQuery,
	useCreateUserMutation,
	useDeleteApiKeyMutation,
	useDeleteUserMutation,
	useDisableTwoFactorMutation,
	useDownloadBackupCodesMutation,
	useForgotPasswordMutation,
	useGenerateApiKeyMutation,
	useGetAllUsersQuery,
	useGetSecuritySettingsQuery,
	useGetUserProfileQuery,
	useLoginMutation,
	useLogoutMutation,
	useRegenerateBackupCodesMutation,
	useResetPasswordMutation,
	useRevokeOtherSessionsMutation,
	useRevokeSessionMutation,
	useResendVerificationEmailMutation,
	useRegisterMutation,
	useStartTwoFactorSetupMutation,
	useUpdateUserMutation,
	useUpdateUserProfileMutation,
	useVerifyEmailMutation,
	useVerifyTwoFactorLoginMutation,
	useVerifyTwoFactorMutation,
} from "./user";
export { webhookApi } from "./webhook";
export {
	useCreateWebhookMutation,
	useDeleteWebhookMutation,
	useGetWebhooksQuery,
} from "./webhook";
