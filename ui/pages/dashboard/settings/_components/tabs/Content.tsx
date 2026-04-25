import { useEffect, useRef, useState } from "react";
import { PEAKURL_BASENAME } from "@constants";
import { ConfirmDialog, useNotification } from "@/components";
import {
	useGetUserProfileQuery,
	useUpdateUserProfileMutation,
	useGenerateApiKeyMutation,
	useDeleteApiKeyMutation,
} from "@/store/slices/api";
import {
	useGetGeneralSettingsQuery,
	useSaveGeneralSettingsMutation,
	useGetGeoipStatusQuery,
	useGetMailStatusQuery,
	useSaveGeoipConfigurationMutation,
	useSaveMailConfigurationMutation,
	useSendTestEmailMutation,
	useDownloadGeoipDatabaseMutation,
	useGetUpdateStatusQuery,
	useCheckForUpdatesMutation,
	useApplyUpdateMutation,
	useReinstallUpdateMutation,
	useUpgradeDatabaseSchemaMutation,
} from "@/store/slices/api";
import { __, applyDocumentFavicon, sprintf } from "@/i18n";
import {
	copyToClipboard as writeToClipboard,
	extractErrorMessage,
	getErrorMessage,
} from "@/utils";
import type {
	ApiKeySummary,
	ContentProps,
	GeneralFormPayload,
	GeneralFormState,
	GeoipConfigurationPayload,
	MailConfigurationPayload,
	ProfileUser,
	ReleaseAction,
} from "./types";
import type {
	ReleaseInstallStage,
	ReleaseInstallProgressState,
	SecurityFormState,
} from "./pages/types";
import {
	ApiKeyModals,
	ApiTab,
	EmailDeliveryTab,
	GeneralTab,
	IntegrationsTab,
	LocationDataTab,
	ReleaseInstallProgress,
	SecurityTab,
	UpdatesTab,
} from "./pages";
import SettingsSkeleton from "../SettingsSkeleton";

const buildGeneralForm = (user?: ProfileUser | null): GeneralFormState => ({
	firstName: user?.firstName || "",
	lastName: user?.lastName || "",
	username: user?.username || "",
	email: user?.email || "",
	phoneNumber: user?.phoneNumber || "",
	company: user?.company || "",
	jobTitle: user?.jobTitle || "",
	bio: user?.bio || "",
});

const profileFormKeys: Array<keyof GeneralFormState> = [
	"firstName",
	"lastName",
	"username",
	"email",
	"phoneNumber",
	"company",
	"jobTitle",
	"bio",
];

const hasProfileChanges = (
	user: ProfileUser | null | undefined,
	profileForm: GeneralFormState
): boolean => {
	const currentProfile = buildGeneralForm(user);

	return profileFormKeys.some(
		(key) => currentProfile[key] !== profileForm[key]
	);
};

const resolveBaseApiUrl = (
	user?: ProfileUser | null,
	fallbackBaseApiUrl: string | null | undefined = ""
): string => {
	if (fallbackBaseApiUrl) {
		return fallbackBaseApiUrl;
	}

	if (user?.baseApiUrl) {
		return user.baseApiUrl;
	}

	if (user?.siteUrl) {
		return `${String(user.siteUrl).replace(/\/+$/, "")}/api/v1`;
	}

	return "";
};

const releaseInstallStageOrder: ReleaseInstallStage[] = [
	"preparing",
	"downloading",
	"installing",
	"finishing",
];

const releaseInstallRedirectDelayMs = 2400;

const getReleaseInstallTitle = (action: ReleaseAction): string =>
	action === "reinstall"
		? __("Restoring the latest version")
		: __("Installing the latest version");

const getReleaseInstallStepLabel = (
	action: ReleaseAction,
	stage: ReleaseInstallStage
): string => {
	if ("preparing" === stage) {
		return __("Getting ready");
	}

	if ("downloading" === stage) {
		return __("Downloading update");
	}

	if ("installing" === stage) {
		return action === "reinstall"
			? __("Restoring included files")
			: __("Installing update");
	}

	return __("Finishing up");
};

const getReleaseInstallActiveStageIndex = (
	progress: ReleaseInstallProgressState | null
): number => {
	const currentStageIndex =
		progress?.steps.findIndex(({ state }) => "current" === state) ?? -1;

	if (currentStageIndex >= 0) {
		return currentStageIndex;
	}

	const completedStepCount =
		progress?.steps.filter(({ state }) => "complete" === state).length ?? 0;

	return Math.min(
		Math.max(completedStepCount, 0),
		releaseInstallStageOrder.length - 1
	);
};

const buildReleaseInstallProgressState = (
	action: ReleaseAction,
	stage: ReleaseInstallStage
): ReleaseInstallProgressState => {
	const currentStepIndex = releaseInstallStageOrder.indexOf(stage);

	return {
		title: getReleaseInstallTitle(action),
		description:
			stage === "preparing"
				? __("PeakURL is getting everything ready.")
				: stage === "downloading"
					? __("PeakURL is downloading the update.")
					: stage === "installing"
						? __(
								"PeakURL is applying the included files and content updates."
							)
						: __(
								"PeakURL is finishing up and getting the dashboard ready."
							),
		steps: releaseInstallStageOrder.map((step, index) => ({
			id: step,
			label: getReleaseInstallStepLabel(action, step),
			state:
				index < currentStepIndex
					? "complete"
					: index === currentStepIndex
						? "current"
						: "upcoming",
		})),
	};
};

const buildCompletedReleaseInstallProgressState = (
	action: ReleaseAction,
	appliedVersion?: string | null
): ReleaseInstallProgressState => {
	const isReinstall = action === "reinstall";

	return {
		title: getReleaseInstallTitle(action),
		description: appliedVersion
			? sprintf(
					isReinstall
						? __("PeakURL %s has been reinstalled.")
						: __("PeakURL %s is now installed."),
					appliedVersion
				)
			: isReinstall
				? __("The latest version has been reinstalled.")
				: __("The latest version is now installed."),
		steps: releaseInstallStageOrder.map((step) => ({
			id: step,
			label: getReleaseInstallStepLabel(action, step),
			state: "complete",
		})),
	};
};

const Content = ({ activeTab }: ContentProps) => {
	const notification = useNotification();
	const { data: userData } = useGetUserProfileQuery(undefined);
	const [updateProfile, { isLoading: isUpdating }] =
		useUpdateUserProfileMutation();
	const [generateApiKey, { isLoading: isGeneratingKey }] =
		useGenerateApiKeyMutation();
	const [deleteApiKey, { isLoading: isDeletingKey }] =
		useDeleteApiKeyMutation();
	const {
		data: generalSettingsResponse,
		isLoading: isLoadingGeneralSettings,
	} = useGetGeneralSettingsQuery(undefined, {
		skip: activeTab !== "general",
	});
	const [saveGeneralSettings, { isLoading: isSavingGeneralSettings }] =
		useSaveGeneralSettingsMutation();
	const {
		data: geoipStatusResponse,
		error: geoipError,
		isLoading: isLoadingGeoipStatus,
		isFetching: isFetchingGeoipStatus,
	} = useGetGeoipStatusQuery(undefined, {
		skip: activeTab !== "location",
	});
	const [saveGeoipConfiguration, { isLoading: isSavingGeoipConfiguration }] =
		useSaveGeoipConfigurationMutation();
	const {
		data: mailStatusResponse,
		error: mailError,
		isLoading: isLoadingMailStatus,
	} = useGetMailStatusQuery(undefined, {
		skip: activeTab !== "email",
	});
	const [saveMailConfiguration, { isLoading: isSavingMailConfiguration }] =
		useSaveMailConfigurationMutation();
	const [sendTestEmail, { isLoading: isSendingTestEmail }] =
		useSendTestEmailMutation();
	const [downloadGeoipDatabase, { isLoading: isDownloadingGeoipDatabase }] =
		useDownloadGeoipDatabaseMutation();
	const {
		data: updateStatus,
		error: updateError,
		isLoading: isLoadingUpdateStatus,
		isFetching: isFetchingUpdateStatus,
	} = useGetUpdateStatusQuery(undefined, {
		skip: activeTab !== "updates",
	});
	const [checkForUpdates, { isLoading: isCheckingForUpdates }] =
		useCheckForUpdatesMutation();
	const [applyUpdate, { isLoading: isApplyingUpdate }] =
		useApplyUpdateMutation();
	const [reinstallUpdate, { isLoading: isReinstallingUpdate }] =
		useReinstallUpdateMutation();
	const [upgradeDatabaseSchema, { isLoading: isUpgradingDatabaseSchema }] =
		useUpgradeDatabaseSchemaMutation();
	const updateStatusData = updateStatus?.data || null;
	const isInstallingRelease = isApplyingUpdate || isReinstallingUpdate;

	const user = userData?.data || null;

	const [securityForm, setSecurityForm] = useState<SecurityFormState>({
		currentPassword: "",
		newPassword: "",
		confirmPassword: "",
	});

	const [newApiKey, setNewApiKey] = useState("");
	const [showKeyModal, setShowKeyModal] = useState(false);
	const [showCreateModal, setShowCreateModal] = useState(false);
	const [apiKeyPendingDelete, setApiKeyPendingDelete] =
		useState<ApiKeySummary | null>(null);
	const [pendingReleaseAction, setPendingReleaseAction] =
		useState<ReleaseAction | null>(null);
	const [activeReleaseInstallAction, setActiveReleaseInstallAction] =
		useState<ReleaseAction | null>(null);
	const [releaseInstallProgress, setReleaseInstallProgress] =
		useState<ReleaseInstallProgressState | null>(null);
	const [keyLabel, setKeyLabel] = useState("");
	const [newApiBaseUrl, setNewApiBaseUrl] = useState("");
	const releaseInstallProgressTimerIds = useRef<number[]>([]);
	const releaseInstallProgressStateRef =
		useRef<ReleaseInstallProgressState | null>(null);
	const releaseInstallRedirectTimerId = useRef<number | null>(null);

	const setReleaseInstallProgressState = (
		progress: ReleaseInstallProgressState | null
	) => {
		releaseInstallProgressStateRef.current = progress;
		setReleaseInstallProgress(progress);
	};

	const clearReleaseInstallProgressTimers = () => {
		releaseInstallProgressTimerIds.current.forEach((timerId) => {
			window.clearTimeout(timerId);
		});
		releaseInstallProgressTimerIds.current = [];
	};

	const clearReleaseInstallRedirectTimer = () => {
		if (null !== releaseInstallRedirectTimerId.current) {
			window.clearTimeout(releaseInstallRedirectTimerId.current);
			releaseInstallRedirectTimerId.current = null;
		}
	};

	const startReleaseInstallProgress = (action: ReleaseAction) => {
		clearReleaseInstallProgressTimers();
		clearReleaseInstallRedirectTimer();
		setActiveReleaseInstallAction(action);
		setReleaseInstallProgressState(
			buildReleaseInstallProgressState(action, "preparing")
		);

		const stageTransitions: Array<{
			afterMs: number;
			stage: ReleaseInstallStage;
		}> = [
			{ afterMs: 700, stage: "downloading" },
			{ afterMs: 1900, stage: "installing" },
			{ afterMs: 3600, stage: "finishing" },
		];

		releaseInstallProgressTimerIds.current = stageTransitions.map(
			({ afterMs, stage }) =>
				window.setTimeout(() => {
					setReleaseInstallProgressState(
						buildReleaseInstallProgressState(action, stage)
					);
				}, afterMs)
		);
	};

	const startReleaseInstallCompletion = (
		action: ReleaseAction,
		appliedVersion?: string | null,
		onReachFinishingStage?: (() => void) | null
	) => {
		const activeStageIndex = getReleaseInstallActiveStageIndex(
			releaseInstallProgressStateRef.current
		);
		const remainingStageSequence = releaseInstallStageOrder.slice(
			activeStageIndex + 1
		);
		const completionTransitionCount = remainingStageSequence.length + 1;
		const completionSegmentDuration =
			releaseInstallRedirectDelayMs / (completionTransitionCount + 1);
		const finishingStageOffset =
			remainingStageSequence.indexOf("finishing");
		const finishingStageDelay =
			-1 === finishingStageOffset
				? 0
				: completionSegmentDuration * (finishingStageOffset + 1);

		clearReleaseInstallProgressTimers();
		clearReleaseInstallRedirectTimer();

		releaseInstallProgressTimerIds.current = [
			...remainingStageSequence.map((stage, index) =>
				window.setTimeout(
					() => {
						setReleaseInstallProgressState(
							buildReleaseInstallProgressState(action, stage)
						);
					},
					completionSegmentDuration * (index + 1)
				)
			),
			...(onReachFinishingStage && finishingStageDelay > 0
				? [
						window.setTimeout(() => {
							onReachFinishingStage();
						}, finishingStageDelay),
					]
				: []),
			window.setTimeout(() => {
				setReleaseInstallProgressState(
					buildCompletedReleaseInstallProgressState(
						action,
						appliedVersion
					)
				);
			}, completionSegmentDuration * completionTransitionCount),
		];

		if (onReachFinishingStage && 0 === finishingStageDelay) {
			onReachFinishingStage();
		}

		releaseInstallRedirectTimerId.current = window.setTimeout(() => {
			setPendingReleaseAction(null);
			setActiveReleaseInstallAction(null);
			setReleaseInstallProgressState(null);
			window.location.assign(
				`${PEAKURL_BASENAME || ""}/dashboard/about?source=${action === "reinstall" ? "reinstall" : "update"}`
			);
		}, releaseInstallRedirectDelayMs);
	};

	useEffect(() => {
		return () => {
			clearReleaseInstallProgressTimers();
			clearReleaseInstallRedirectTimer();
		};
	}, []);

	const handleGeneralSubmit = async (generalForm: GeneralFormPayload) => {
		const {
			siteName: nextSiteName,
			siteLanguage: nextSiteLanguage,
			faviconFile,
			removeFavicon,
			...profileForm
		} = generalForm || {};
		const currentSiteName = (
			generalSettingsResponse?.data?.siteName || ""
		).trim();
		const currentSiteLanguage = generalSettingsResponse?.data?.siteLanguage;
		const shouldSaveProfile = hasProfileChanges(user, profileForm);
		const shouldSaveSiteName =
			(generalSettingsResponse?.data?.canManageSiteSettings ?? false) &&
			nextSiteName.trim() !== currentSiteName;
		const shouldSaveLanguage =
			!!nextSiteLanguage &&
			generalSettingsResponse?.data?.canManageSiteSettings &&
			nextSiteLanguage !== currentSiteLanguage;
		const shouldSaveGeneralSettings =
			shouldSaveSiteName ||
			shouldSaveLanguage ||
			Boolean(faviconFile) ||
			Boolean(removeFavicon);

		if (!shouldSaveProfile && !shouldSaveGeneralSettings) {
			return;
		}

		if (shouldSaveProfile) {
			try {
				await updateProfile(profileForm).unwrap();
			} catch (err) {
				notification.error(
					__("Error"),
					getErrorMessage(err, __("Failed to update profile"))
				);
				return;
			}
		}

		if (shouldSaveGeneralSettings) {
			try {
				const response = await saveGeneralSettings({
					siteName: nextSiteName,
					siteLanguage: nextSiteLanguage,
					faviconFile,
					removeFavicon,
				}).unwrap();

				if (response?.data?.siteName) {
					window.__PEAKURL_SITE_NAME__ = response.data.siteName;
				}

				if (response?.data?.favicon) {
					window.__PEAKURL_FAVICON__ = response.data.favicon;
					applyDocumentFavicon(response.data.favicon);
				}

				if (shouldSaveLanguage) {
					notification.success(
						__("Language updated"),
						__("PeakURL is reloading the dashboard now.")
					);
					window.setTimeout(() => {
						window.location.reload();
					}, 350);
					return;
				}

				notification.success(
					__("Success"),
					shouldSaveProfile
						? __(
								"Profile and general settings updated successfully"
							)
						: __("General settings updated successfully")
				);
				return;
			} catch (err) {
				notification.error(
					__("Save failed"),
					getErrorMessage(
						err,
						__("Failed to update the general settings")
					)
				);
				return;
			}
		}

		notification.success(__("Success"), __("Profile updated successfully"));
	};

	const handleSecuritySubmit = async () => {
		if (!securityForm.currentPassword) {
			notification.error(__("Error"), __("Enter your current password"));
			return;
		}

		if (securityForm.newPassword !== securityForm.confirmPassword) {
			notification.error(__("Error"), __("Passwords do not match"));
			return;
		}
		if (securityForm.newPassword.length < 8) {
			notification.error(
				__("Error"),
				__("Password must be at least 8 characters")
			);
			return;
		}

		try {
			await updateProfile({
				currentPassword: securityForm.currentPassword,
				password: securityForm.newPassword,
			}).unwrap();
			notification.success(
				__("Success"),
				__("Password updated successfully")
			);
			setSecurityForm({
				currentPassword: "",
				newPassword: "",
				confirmPassword: "",
			});
		} catch (err) {
			notification.error(
				__("Error"),
				getErrorMessage(err, __("Failed to update password"))
			);
		}
	};

	const handleCreateKey = async () => {
		try {
			const result = await generateApiKey({ label: keyLabel }).unwrap();
			const plainTextKey = result?.data?.apiKey || "";
			const baseApiUrl = resolveBaseApiUrl(
				user,
				result?.data?.baseApiUrl
			);

			if (!plainTextKey) {
				notification.error(
					__("Key created without visible token"),
					__(
						"PeakURL created the API key, but the one-time token was not returned. Revoke it and create a new key."
					)
				);
				setShowCreateModal(false);
				setKeyLabel("");
				return;
			}

			setNewApiKey(plainTextKey);
			setNewApiBaseUrl(baseApiUrl);
			setShowCreateModal(false);
			setShowKeyModal(true);
			setKeyLabel("");
			notification.success(
				__("API key created"),
				__("Copy the token now. PeakURL will not show it again.")
			);
		} catch (err) {
			notification.error(
				__("Error"),
				getErrorMessage(err, __("Failed to generate API key"))
			);
		}
	};

	const handleDeleteKey = async () => {
		if (!apiKeyPendingDelete?.id) {
			return;
		}

		try {
			await deleteApiKey(apiKeyPendingDelete.id).unwrap();
			setApiKeyPendingDelete(null);
			notification.success(
				__("Success"),
				__("API key deleted successfully")
			);
		} catch (err) {
			notification.error(
				__("Error"),
				getErrorMessage(err, __("Failed to delete API key"))
			);
		}
	};

	const copyToClipboard = async (
		text: string,
		message: string = __("API key copied to clipboard")
	): Promise<void> => {
		try {
			await writeToClipboard(text);
			notification.success(__("Copied"), message);
		} catch {
			notification.error(
				__("Copy failed"),
				__("PeakURL could not copy that value to the clipboard.")
			);
		}
	};

	const handleCloseKeyModal = () => {
		setShowKeyModal(false);
		setNewApiKey("");
		setNewApiBaseUrl("");
	};

	const handleCheckForUpdates = async () => {
		try {
			const result = await checkForUpdates(undefined).unwrap();
			const latestVersion = result?.data?.latestVersion;
			const currentVersion = result?.data?.currentVersion;

			if (result?.data?.updateAvailable && latestVersion) {
				notification.success(
					__("Update available"),
					sprintf(
						__("PeakURL %s is ready to install."),
						latestVersion
					)
				);
				return;
			}

			notification.success(
				__("Up to date"),
				currentVersion
					? sprintf(
							__("PeakURL %s is already on the latest version."),
							currentVersion
						)
					: __("PeakURL is already on the latest version.")
			);
		} catch (err) {
			notification.error(
				__("Update check failed"),
				getErrorMessage(
					err,
					__("PeakURL could not reach the update service.")
				)
			);
		}
	};

	const handleSaveGeoipConfiguration = async (
		values: GeoipConfigurationPayload
	) => {
		try {
			const result = await saveGeoipConfiguration(values).unwrap();
			notification.success(
				__("Saved"),
				__("Location data settings were updated successfully.")
			);
			return result?.data || undefined;
		} catch (err) {
			notification.error(
				__("Save failed"),
				getErrorMessage(
					err,
					__("PeakURL could not save the MaxMind settings.")
				)
			);
			throw err;
		}
	};

	const handleDownloadGeoipDatabase = async () => {
		try {
			await downloadGeoipDatabase(undefined).unwrap();
			notification.success(
				__("Database updated"),
				__("PeakURL downloaded the latest GeoLite2 City database.")
			);
		} catch (err) {
			notification.error(
				__("Download failed"),
				getErrorMessage(
					err,
					__("PeakURL could not download the GeoLite2 database.")
				)
			);
		}
	};

	const handleSaveMailConfiguration = async (
		values: MailConfigurationPayload
	) => {
		try {
			await saveMailConfiguration(values).unwrap();
			notification.success(
				__("Saved"),
				__("Email configuration was updated successfully.")
			);
		} catch (err) {
			notification.error(
				__("Save failed"),
				getErrorMessage(
					err,
					__("PeakURL could not save the email configuration.")
				)
			);
			throw err;
		}
	};

	const handleSendTestEmail = async () => {
		try {
			const result = await sendTestEmail(undefined).unwrap();
			const recipient = result?.data?.recipient;

			notification.success(
				__("Test email sent"),
				recipient
					? sprintf(
							__("PeakURL sent a test email to %s."),
							recipient
						)
					: __("PeakURL sent a test email to your account email.")
			);
		} catch (err) {
			notification.error(
				__("Test email failed"),
				getErrorMessage(
					err,
					__("PeakURL could not send the test email.")
				)
			);
		}
	};

	if (
		isLoadingGeneralSettings ||
		isLoadingGeoipStatus ||
		isLoadingMailStatus ||
		isLoadingUpdateStatus
	) {
		return (
			<div className="settings-content">
				<SettingsSkeleton />
			</div>
		);
	}

	const runReleaseInstall = async (action: ReleaseAction) => {
		const isReinstall = action === "reinstall";
		const installRelease = isReinstall ? reinstallUpdate : applyUpdate;
		startReleaseInstallProgress(action);

		try {
			const result = await installRelease(undefined).unwrap();
			const appliedVersion = result?.data?.currentVersion;
			startReleaseInstallCompletion(action, appliedVersion, () => {
				notification.success(
					isReinstall
						? __("Release reinstalled")
						: __("Update installed"),
					appliedVersion
						? sprintf(
								isReinstall
									? __("PeakURL %s has been reinstalled.")
									: __("PeakURL %s is now installed."),
								appliedVersion
							)
						: isReinstall
							? __("The latest version has been reinstalled.")
							: __("The latest version is now installed.")
				);
			});
		} catch (err) {
			notification.error(
				isReinstall ? __("Reinstall failed") : __("Update failed"),
				getErrorMessage(
					err,
					isReinstall
						? __("PeakURL could not reinstall the latest version.")
						: __("PeakURL could not apply the update.")
				)
			);
			clearReleaseInstallProgressTimers();
			clearReleaseInstallRedirectTimer();
			setActiveReleaseInstallAction(null);
			setReleaseInstallProgressState(null);
		}
	};

	const handleApplyUpdate = async () => {
		setPendingReleaseAction(null);
		await runReleaseInstall("install");
	};

	const handleReinstallUpdate = async () => {
		setPendingReleaseAction(null);
		await runReleaseInstall("reinstall");
	};

	const handleUpgradeDatabase = async () => {
		try {
			const result = await upgradeDatabaseSchema(undefined).unwrap();
			const issuesCount = Number(result?.data?.issuesCount || 0);

			notification.success(
				__("Database updated"),
				issuesCount > 0
					? __(
							"PeakURL repaired the database schema. Review any remaining warnings below."
						)
					: __("The database schema is now current.")
			);
		} catch (err) {
			notification.error(
				__("Database upgrade failed"),
				getErrorMessage(
					err,
					__("PeakURL could not repair the database schema.")
				)
			);
		}
	};

	return (
		<div className="settings-content">
			{activeTab === "general" && (
				<GeneralTab
					key={`${user?._id || user?.id || user?.username || "user"}-${user?.updatedAt || "initial"}`}
					initialForm={buildGeneralForm(user)}
					onSubmit={handleGeneralSubmit}
					isUpdating={isUpdating || isSavingGeneralSettings}
					siteSettings={generalSettingsResponse?.data || null}
					isLoadingSiteSettings={isLoadingGeneralSettings}
				/>
			)}

			{activeTab === "security" && (
				<SecurityTab
					securityForm={securityForm}
					setSecurityForm={setSecurityForm}
					onSubmit={handleSecuritySubmit}
					isUpdating={isUpdating}
					notification={notification}
				/>
			)}

			{activeTab === "api" && (
				<ApiTab
					user={user}
					baseApiUrl={resolveBaseApiUrl(user)}
					copyToClipboard={copyToClipboard}
					isGeneratingKey={isGeneratingKey}
					isDeletingKey={isDeletingKey}
					onDeleteKey={(key) => setApiKeyPendingDelete(key)}
					setShowCreateModal={(open) => setShowCreateModal(open)}
				/>
			)}

			{activeTab === "integrations" && (
				<IntegrationsTab notification={notification} />
			)}

			{activeTab === "email" && (
				<EmailDeliveryTab
					key={JSON.stringify(mailStatusResponse?.data || {})}
					status={mailStatusResponse?.data || null}
					errorMessage={extractErrorMessage(mailError)}
					isLoading={isLoadingMailStatus}
					isSaving={isSavingMailConfiguration}
					isTesting={isSendingTestEmail}
					onSave={handleSaveMailConfiguration}
					onSendTest={handleSendTestEmail}
				/>
			)}

			{activeTab === "location" && (
				<LocationDataTab
					status={geoipStatusResponse?.data || null}
					errorMessage={extractErrorMessage(geoipError)}
					isLoading={isFetchingGeoipStatus}
					isSaving={isSavingGeoipConfiguration}
					isDownloading={isDownloadingGeoipDatabase}
					onSave={handleSaveGeoipConfiguration}
					onDownload={handleDownloadGeoipDatabase}
				/>
			)}

			{activeTab === "updates" && (
				<UpdatesTab
					status={updateStatusData}
					errorMessage={extractErrorMessage(updateError)}
					releaseInstallProgress={releaseInstallProgress}
					isLoading={isFetchingUpdateStatus}
					isChecking={isCheckingForUpdates}
					isApplying={isApplyingUpdate}
					isReinstalling={isReinstallingUpdate}
					isRepairing={isUpgradingDatabaseSchema}
					onCheck={handleCheckForUpdates}
					onApply={() => setPendingReleaseAction("install")}
					onReinstall={() => setPendingReleaseAction("reinstall")}
					onRepair={handleUpgradeDatabase}
				/>
			)}

			<ApiKeyModals
				showCreateModal={showCreateModal}
				setShowCreateModal={setShowCreateModal}
				showKeyModal={showKeyModal}
				setShowKeyModal={handleCloseKeyModal}
				keyLabel={keyLabel}
				setKeyLabel={setKeyLabel}
				newApiKey={newApiKey}
				baseApiUrl={newApiBaseUrl || resolveBaseApiUrl(user)}
				onCreateKey={handleCreateKey}
				copyToClipboard={copyToClipboard}
				isGeneratingKey={isGeneratingKey}
			/>
			<ConfirmDialog
				open={Boolean(apiKeyPendingDelete)}
				onClose={() => {
					if (!isDeletingKey) {
						setApiKeyPendingDelete(null);
					}
				}}
				title={__("Delete API key")}
				description={
					apiKeyPendingDelete
						? sprintf(
								__(
									"Delete %s? Any scripts, automations, or extensions using it will stop working immediately."
								),
								apiKeyPendingDelete.label ||
									apiKeyPendingDelete.maskedKey ||
									__("this API key")
							)
						: ""
				}
				confirmText={__("Delete key")}
				cancelText={__("Keep key")}
				confirmVariant="danger"
				onConfirm={handleDeleteKey}
				loading={isDeletingKey}
			/>
			<ConfirmDialog
				open={Boolean(pendingReleaseAction)}
				onClose={() => {
					if (!activeReleaseInstallAction) {
						setPendingReleaseAction(null);
						setReleaseInstallProgressState(null);
					}
				}}
				title={sprintf(
					pendingReleaseAction === "reinstall"
						? __("Reinstall PeakURL %s?")
						: __("Install PeakURL %s?"),
					updateStatusData?.latestVersion ||
						updateStatusData?.currentVersion ||
						__("update")
				)}
				description={
					releaseInstallProgress
						? ""
						: pendingReleaseAction === "reinstall"
							? __(
									"PeakURL will restore the latest version and refresh the included files."
								)
							: __(
									"PeakURL will install the latest version and refresh the included files."
								)
				}
				confirmText={
					pendingReleaseAction === "reinstall"
						? __("Reinstall latest version")
						: __("Install update")
				}
				cancelText={__("Cancel")}
				onConfirm={
					pendingReleaseAction === "reinstall"
						? handleReinstallUpdate
						: handleApplyUpdate
				}
				loading={isInstallingRelease}
			/>
			<ConfirmDialog
				open={Boolean(
					activeReleaseInstallAction && releaseInstallProgress
				)}
				onClose={() => {}}
				title=""
				onConfirm={() => {}}
				hideActions
			>
				{releaseInstallProgress ? (
					<ReleaseInstallProgress
						progress={releaseInstallProgress}
						compact
					/>
				) : null}
			</ConfirmDialog>
		</div>
	);
};

export default Content;
