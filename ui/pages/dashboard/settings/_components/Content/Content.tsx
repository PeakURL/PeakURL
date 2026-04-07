import { useState } from 'react';
import { PEAKURL_BASENAME } from '@constants';
import { ConfirmDialog } from '@/components/ui';
import {
	useGetUserProfileQuery,
	useUpdateUserProfileMutation,
	useGenerateApiKeyMutation,
	useDeleteApiKeyMutation,
} from '@/store/slices/api';
import {
	useGetGeneralSettingsQuery,
	useSaveGeneralSettingsMutation,
	useGetGeoipStatusQuery,
	useGetMailStatusQuery,
	useSaveGeoipConfigurationMutation,
	useSaveMailConfigurationMutation,
	useDownloadGeoipDatabaseMutation,
	useGetUpdateStatusQuery,
	useCheckForUpdatesMutation,
	useApplyUpdateMutation,
	useReinstallUpdateMutation,
	useUpgradeDatabaseSchemaMutation,
} from '@/store/slices/api';
import { useNotification, IntegrationsTab } from '@/components';
import { __, sprintf } from '@/i18n';
import {
	copyToClipboard as writeToClipboard,
	extractErrorMessage,
	getErrorMessage,
} from '@/utils';
import type {
	ApiKeySummary,
	ContentProps,
	GeneralFormPayload,
	GeneralFormState,
	GeoipConfigurationPayload,
	MailConfigurationPayload,
	ProfileUser,
	ReleaseAction,
} from './types';
import type { SecurityFormState } from './sections/types';
import GeneralTab from './sections/GeneralTab';
import SecurityTab from './sections/SecurityTab';
import ApiTab from './sections/ApiTab';
import ApiKeyModals from './sections/ApiKeyModals';
import LocationDataTab from './sections/LocationDataTab';
import EmailDeliveryTab from './sections/EmailDeliveryTab';
import UpdatesTab from './sections/UpdatesTab';

const buildGeneralForm = (user?: ProfileUser | null): GeneralFormState => ({
	firstName: user?.firstName || '',
	lastName: user?.lastName || '',
	username: user?.username || '',
	email: user?.email || '',
	phoneNumber: user?.phoneNumber || '',
	company: user?.company || '',
	jobTitle: user?.jobTitle || '',
	bio: user?.bio || '',
});

const resolveBaseApiUrl = (
	user?: ProfileUser | null,
	fallbackBaseApiUrl: string | null | undefined = ''
): string => {
	if (fallbackBaseApiUrl) {
		return fallbackBaseApiUrl;
	}

	if (user?.baseApiUrl) {
		return user.baseApiUrl;
	}

	if (user?.siteUrl) {
		return `${String(user.siteUrl).replace(/\/+$/, '')}/api/v1`;
	}

	return '';
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
		skip: activeTab !== 'general',
	});
	const [saveGeneralSettings, { isLoading: isSavingGeneralSettings }] =
		useSaveGeneralSettingsMutation();
	const {
		data: geoipStatusResponse,
		error: geoipError,
		isLoading: isLoadingGeoipStatus,
		isFetching: isFetchingGeoipStatus,
	} = useGetGeoipStatusQuery(undefined, {
		skip: activeTab !== 'location',
	});
	const [saveGeoipConfiguration, { isLoading: isSavingGeoipConfiguration }] =
		useSaveGeoipConfigurationMutation();
	const {
		data: mailStatusResponse,
		error: mailError,
		isLoading: isLoadingMailStatus,
	} = useGetMailStatusQuery(undefined, {
		skip: activeTab !== 'email',
	});
	const [saveMailConfiguration, { isLoading: isSavingMailConfiguration }] =
		useSaveMailConfigurationMutation();
	const [downloadGeoipDatabase, { isLoading: isDownloadingGeoipDatabase }] =
		useDownloadGeoipDatabaseMutation();
	const {
		data: updateStatus,
		error: updateError,
		isLoading: isLoadingUpdateStatus,
		isFetching: isFetchingUpdateStatus,
	} = useGetUpdateStatusQuery(undefined, {
		skip: activeTab !== 'updates',
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
		currentPassword: '',
		newPassword: '',
		confirmPassword: '',
	});

	const [newApiKey, setNewApiKey] = useState('');
	const [showKeyModal, setShowKeyModal] = useState(false);
	const [showCreateModal, setShowCreateModal] = useState(false);
	const [apiKeyPendingDelete, setApiKeyPendingDelete] =
		useState<ApiKeySummary | null>(null);
	const [pendingReleaseAction, setPendingReleaseAction] =
		useState<ReleaseAction | null>(null);
	const [keyLabel, setKeyLabel] = useState('');
	const [newApiBaseUrl, setNewApiBaseUrl] = useState('');

	const handleGeneralSubmit = async (generalForm: GeneralFormPayload) => {
		const { siteLanguage: nextSiteLanguage, ...profileForm } =
			generalForm || {};
		const currentSiteLanguage = generalSettingsResponse?.data?.siteLanguage;
		const shouldSaveLanguage =
			!!nextSiteLanguage &&
			generalSettingsResponse?.data?.canManageSiteSettings &&
			nextSiteLanguage !== currentSiteLanguage;

		try {
			await updateProfile(profileForm).unwrap();
		} catch (err) {
			notification.error(
				__('Error'),
				getErrorMessage(err, __('Failed to update profile'))
			);
			return;
		}

		if (shouldSaveLanguage) {
			try {
				await saveGeneralSettings({
					siteLanguage: nextSiteLanguage,
				}).unwrap();
				notification.success(
					__('Language updated'),
					__('PeakURL is reloading the dashboard now.')
				);
				window.setTimeout(() => {
					window.location.reload();
				}, 350);
				return;
			} catch (err) {
				notification.error(
					__('Save failed'),
					getErrorMessage(
						err,
						__('Failed to update the site language')
					)
				);
				return;
			}
		}

		notification.success(
			__('Success'),
			__('Profile updated successfully')
		);
	};

	const handleSecuritySubmit = async () => {
		if (!securityForm.currentPassword) {
			notification.error(
				__('Error'),
				__('Enter your current password')
			);
			return;
		}

		if (securityForm.newPassword !== securityForm.confirmPassword) {
			notification.error(
				__('Error'),
				__('Passwords do not match')
			);
			return;
		}
		if (securityForm.newPassword.length < 8) {
			notification.error(
				__('Error'),
				__('Password must be at least 8 characters')
			);
			return;
		}

		try {
			await updateProfile({
				currentPassword: securityForm.currentPassword,
				password: securityForm.newPassword,
			}).unwrap();
			notification.success(
				__('Success'),
				__('Password updated successfully')
			);
			setSecurityForm({
				currentPassword: '',
				newPassword: '',
				confirmPassword: '',
			});
		} catch (err) {
			notification.error(
				__('Error'),
				getErrorMessage(err, __('Failed to update password'))
			);
		}
	};

	const handleCreateKey = async () => {
		try {
			const result = await generateApiKey({ label: keyLabel }).unwrap();
			const plainTextKey = result?.data?.apiKey || '';
			const baseApiUrl = resolveBaseApiUrl(user, result?.data?.baseApiUrl);

			if (!plainTextKey) {
				notification.error(
					__('Key created without visible token'),
					__(
						'PeakURL created the API key, but the one-time token was not returned. Revoke it and create a new key.'
					)
				);
				setShowCreateModal(false);
				setKeyLabel('');
				return;
			}

			setNewApiKey(plainTextKey);
			setNewApiBaseUrl(baseApiUrl);
			setShowCreateModal(false);
			setShowKeyModal(true);
			setKeyLabel('');
			notification.success(
				__('API key created'),
				__('Copy the token now. PeakURL will not show it again.')
			);
		} catch (err) {
			notification.error(
				__('Error'),
				getErrorMessage(err, __('Failed to generate API key'))
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
				__('Success'),
				__('API key deleted successfully')
			);
		} catch (err) {
			notification.error(
				__('Error'),
				getErrorMessage(err, __('Failed to delete API key'))
			);
		}
	};

	const copyToClipboard = async (
		text: string,
		message: string = __('API key copied to clipboard')
	): Promise<void> => {
		try {
			await writeToClipboard(text);
			notification.success(__('Copied'), message);
		} catch {
			notification.error(
				__('Copy failed'),
				__('PeakURL could not copy that value to the clipboard.')
			);
		}
	};

	const handleCloseKeyModal = () => {
		setShowKeyModal(false);
		setNewApiKey('');
		setNewApiBaseUrl('');
	};

	const handleCheckForUpdates = async () => {
		try {
			const result = await checkForUpdates(undefined).unwrap();
			const latestVersion = result?.data?.latestVersion;
			const currentVersion = result?.data?.currentVersion;

			if (result?.data?.updateAvailable && latestVersion) {
				notification.success(
					__('Update available'),
					sprintf(
						__('PeakURL %s is ready to install.'),
						latestVersion
					)
				);
				return;
			}

			notification.success(
				__('Up to date'),
				currentVersion
					? sprintf(
						__('PeakURL %s is already on the latest release.'),
						currentVersion
					)
					: __('PeakURL is already on the latest release.')
			);
		} catch (err) {
			notification.error(
				__('Update check failed'),
				getErrorMessage(
					err,
					__('PeakURL could not reach the update service.')
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
				__('Saved'),
				__('Location data settings were updated successfully.')
			);
			return result?.data || undefined;
		} catch (err) {
			notification.error(
				__('Save failed'),
				getErrorMessage(
					err,
					__('PeakURL could not save the MaxMind settings.')
				)
			);
			throw err;
		}
	};

	const handleDownloadGeoipDatabase = async () => {
		try {
			await downloadGeoipDatabase(undefined).unwrap();
			notification.success(
				__('Database updated'),
				__('PeakURL downloaded the latest GeoLite2 City database.')
			);
		} catch (err) {
			notification.error(
				__('Download failed'),
				getErrorMessage(
					err,
					__('PeakURL could not download the GeoLite2 database.')
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
				__('Saved'),
				__('Email configuration was updated successfully.')
			);
		} catch (err) {
			notification.error(
				__('Save failed'),
				getErrorMessage(
					err,
					__('PeakURL could not save the email configuration.')
				)
			);
			throw err;
		}
	};

	const runReleaseInstall = async (action: ReleaseAction) => {
		const isReinstall = action === 'reinstall';
		const installRelease = isReinstall ? reinstallUpdate : applyUpdate;

		try {
			const result = await installRelease(undefined).unwrap();
			const appliedVersion = result?.data?.currentVersion;
			setPendingReleaseAction(null);

			notification.success(
				isReinstall ? __('Release reinstalled') : __('Update installed'),
				appliedVersion
					? sprintf(
						isReinstall
							? __(
								'PeakURL %s was reinstalled successfully. Redirecting now.'
							)
							: __(
								'PeakURL %s was installed successfully. Redirecting now.'
							),
						appliedVersion
					)
					: isReinstall
						? __('PeakURL was reinstalled successfully. Redirecting now.')
						: __('PeakURL was installed successfully. Redirecting now.')
			);

			window.setTimeout(() => {
				window.location.assign(
					`${PEAKURL_BASENAME || ''}/dashboard/about?source=${isReinstall ? 'reinstall' : 'update'}`
				);
			}, 1500);
		} catch (err) {
			notification.error(
				isReinstall ? __('Reinstall failed') : __('Update failed'),
				getErrorMessage(
					err,
					isReinstall
						? __('PeakURL could not reinstall the latest release.')
						: __('PeakURL could not apply the update.')
				)
			);
		}
	};

	const handleApplyUpdate = async () => runReleaseInstall('install');

	const handleReinstallUpdate = async () => runReleaseInstall('reinstall');

	const handleUpgradeDatabase = async () => {
		try {
			const result = await upgradeDatabaseSchema(undefined).unwrap();
			const issuesCount = Number(result?.data?.issuesCount || 0);

			notification.success(
				__('Database updated'),
				issuesCount > 0
					? __(
						'PeakURL repaired the database schema. Review any remaining warnings below.'
					  )
					: __('The database schema is now current.')
			);
		} catch (err) {
			notification.error(
				__('Database upgrade failed'),
				getErrorMessage(
					err,
					__('PeakURL could not repair the database schema.')
				)
			);
		}
	};

	return (
		<div className="space-y-5">
			{activeTab === 'general' && (
				<GeneralTab
					key={`${user?._id || user?.id || user?.username || 'user'}-${user?.updatedAt || 'initial'}`}
					initialForm={buildGeneralForm(user)}
					onSubmit={handleGeneralSubmit}
					isUpdating={isUpdating || isSavingGeneralSettings}
					siteSettings={generalSettingsResponse?.data || null}
					isLoadingSiteSettings={isLoadingGeneralSettings}
				/>
			)}

			{activeTab === 'security' && (
				<SecurityTab
					securityForm={securityForm}
					setSecurityForm={setSecurityForm}
					onSubmit={handleSecuritySubmit}
					isUpdating={isUpdating}
					notification={notification}
				/>
			)}

			{activeTab === 'api' && (
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

			{activeTab === 'integrations' && (
				<IntegrationsTab notification={notification} />
			)}

			{activeTab === 'email' && (
				<EmailDeliveryTab
					key={JSON.stringify(mailStatusResponse?.data || {})}
					status={mailStatusResponse?.data || null}
					errorMessage={extractErrorMessage(mailError)}
					isLoading={isLoadingMailStatus}
					isSaving={isSavingMailConfiguration}
					onSave={handleSaveMailConfiguration}
				/>
			)}

			{activeTab === 'location' && (
				<LocationDataTab
					status={geoipStatusResponse?.data || null}
					errorMessage={extractErrorMessage(geoipError)}
					isLoading={isLoadingGeoipStatus || isFetchingGeoipStatus}
					isSaving={isSavingGeoipConfiguration}
					isDownloading={isDownloadingGeoipDatabase}
					onSave={handleSaveGeoipConfiguration}
					onDownload={handleDownloadGeoipDatabase}
				/>
			)}

			{activeTab === 'updates' && (
				<UpdatesTab
					status={updateStatusData}
					errorMessage={extractErrorMessage(updateError)}
					isLoading={isLoadingUpdateStatus || isFetchingUpdateStatus}
					isChecking={isCheckingForUpdates}
					isApplying={isApplyingUpdate}
					isReinstalling={isReinstallingUpdate}
					isRepairing={isUpgradingDatabaseSchema}
					onCheck={handleCheckForUpdates}
					onApply={() => setPendingReleaseAction('install')}
					onReinstall={() => setPendingReleaseAction('reinstall')}
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
				title={__('Delete API key')}
				description={
					apiKeyPendingDelete
						? sprintf(
							__(
								'Delete %s? Any scripts, automations, or extensions using it will stop working immediately.'
							),
							apiKeyPendingDelete.label ||
								apiKeyPendingDelete.maskedKey ||
								__('this API key')
						)
						: ''
				}
				confirmText={__('Delete key')}
				cancelText={__('Keep key')}
				confirmVariant="danger"
				onConfirm={handleDeleteKey}
				loading={isDeletingKey}
			/>
			<ConfirmDialog
				open={Boolean(pendingReleaseAction)}
				onClose={() => {
					if (!isInstallingRelease) {
						setPendingReleaseAction(null);
					}
				}}
				title={sprintf(
					pendingReleaseAction === 'reinstall'
						? __('Reinstall PeakURL %s?')
						: __('Install PeakURL %s?'),
					updateStatusData?.latestVersion ||
						updateStatusData?.currentVersion ||
						__('update')
				)}
					description={
						pendingReleaseAction === 'reinstall'
							? __(
								'PeakURL will download the latest release package again, replace managed application files, and reload the dashboard when the reinstall completes.\n\nUse this when you need to restore packaged files on a release install.'
							)
							: __(
								'PeakURL will download the latest release package, replace managed application files, and reload the dashboard when the update completes.\n\nOnly continue on packaged release installs.'
						)
				}
				confirmText={
					pendingReleaseAction === 'reinstall'
						? __('Reinstall latest version')
						: __('Install update')
				}
				cancelText={__('Cancel')}
				onConfirm={
					pendingReleaseAction === 'reinstall'
						? handleReinstallUpdate
						: handleApplyUpdate
				}
				loading={isInstallingRelease}
			/>
		</div>
	);
};

export default Content;
