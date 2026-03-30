// @ts-nocheck
import { useState } from 'react';
import { PEAKURL_BASENAME } from '@constants';
import { ConfirmDialog } from '@/components/ui';
import {
	useGetUserProfileQuery,
	useUpdateUserProfileMutation,
	useGenerateApiKeyMutation,
	useDeleteApiKeyMutation,
} from '@/store/slices/api/user';
import {
	useGetGeoipStatusQuery,
	useGetMailStatusQuery,
	useSaveGeoipConfigurationMutation,
	useSaveMailConfigurationMutation,
	useDownloadGeoipDatabaseMutation,
	useGetUpdateStatusQuery,
	useCheckForUpdatesMutation,
	useApplyUpdateMutation,
} from '@/store/slices/api/system';
import { useNotification, IntegrationsTab } from '@/components';
import GeneralTab from './sections/GeneralTab';
import SecurityTab from './sections/SecurityTab';
import ApiTab from './sections/ApiTab';
import ApiKeyModals from './sections/ApiKeyModals';
import LocationDataTab from './sections/LocationDataTab';
import EmailDeliveryTab from './sections/EmailDeliveryTab';
import UpdatesTab from './sections/UpdatesTab';

const buildGeneralForm = (user) => ({
	firstName: user?.firstName || '',
	lastName: user?.lastName || '',
	username: user?.username || '',
	email: user?.email || '',
	phoneNumber: user?.phoneNumber || '',
	company: user?.company || '',
	jobTitle: user?.jobTitle || '',
	bio: user?.bio || '',
});

const Content = ({ activeTab }) => {
	const notification = useNotification();
	const { data: userData } = useGetUserProfileQuery();
	const [updateProfile, { isLoading: isUpdating }] =
		useUpdateUserProfileMutation();
	const [generateApiKey, { isLoading: isGeneratingKey }] =
		useGenerateApiKeyMutation();
	const [deleteApiKey, { isLoading: isDeletingKey }] =
		useDeleteApiKeyMutation();
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

	const user = userData?.data;

	const [securityForm, setSecurityForm] = useState({
		currentPassword: '',
		newPassword: '',
		confirmPassword: '',
	});

	const [newApiKey, setNewApiKey] = useState(null);
	const [showKeyModal, setShowKeyModal] = useState(false);
	const [showCreateModal, setShowCreateModal] = useState(false);
	const [showUpdateConfirm, setShowUpdateConfirm] = useState(false);
	const [keyLabel, setKeyLabel] = useState('');

	const handleGeneralSubmit = async (generalForm) => {
		try {
			await updateProfile(generalForm).unwrap();
			notification.success('Success', 'Profile updated successfully');
		} catch (err) {
			notification.error(
				'Error',
				err?.data?.message || 'Failed to update profile'
			);
		}
	};

	const handleSecuritySubmit = async () => {
		if (securityForm.newPassword !== securityForm.confirmPassword) {
			notification.error('Error', 'Passwords do not match');
			return;
		}
		if (securityForm.newPassword.length < 8) {
			notification.error(
				'Error',
				'Password must be at least 8 characters'
			);
			return;
		}

		try {
			await updateProfile({
				password: securityForm.newPassword,
			}).unwrap();
			notification.success('Success', 'Password updated successfully');
			setSecurityForm({
				currentPassword: '',
				newPassword: '',
				confirmPassword: '',
			});
		} catch (err) {
			notification.error(
				'Error',
				err?.data?.message || 'Failed to update password'
			);
		}
	};

	const handleCreateKey = async () => {
		try {
			const result = await generateApiKey({ label: keyLabel }).unwrap();
			setNewApiKey(result.data.apiKey);
			setShowCreateModal(false);
			setShowKeyModal(true);
			setKeyLabel('');
			notification.success('Success', 'API Key generated successfully');
		} catch (err) {
			notification.error(
				'Error',
				err?.data?.message || 'Failed to generate API key'
			);
		}
	};

	const handleDeleteKey = async (id) => {
		if (
			!window.confirm(
				'Are you sure you want to delete this API key? Any applications using it will stop working.'
			)
		) {
			return;
		}

		try {
			await deleteApiKey(id).unwrap();
			notification.success('Success', 'API Key deleted successfully');
		} catch (err) {
			notification.error(
				'Error',
				err?.data?.message || 'Failed to delete API key'
			);
		}
	};

	const copyToClipboard = (text) => {
		navigator.clipboard.writeText(text);
		notification.success('Copied', 'API Key copied to clipboard');
	};

	const handleCheckForUpdates = async () => {
		try {
			const result = await checkForUpdates().unwrap();
			const latestVersion = result?.data?.latestVersion;
			const currentVersion = result?.data?.currentVersion;

			if (result?.data?.updateAvailable && latestVersion) {
				notification.success(
					'Update available',
					`PeakURL ${latestVersion} is ready to install.`
				);
				return;
			}

			notification.success(
				'Up to date',
				currentVersion
					? `PeakURL ${currentVersion} is already current.`
					: 'PeakURL is already current.'
			);
		} catch (err) {
			notification.error(
				'Update check failed',
				err?.data?.message || 'PeakURL could not reach the update service.'
			);
		}
	};

	const handleSaveGeoipConfiguration = async (values) => {
		try {
			const result = await saveGeoipConfiguration(values).unwrap();
			notification.success(
				'Saved',
				'Location data settings were updated successfully.'
			);
			return result?.data || null;
		} catch (err) {
			notification.error(
				'Save failed',
				err?.data?.message ||
					'PeakURL could not save the MaxMind settings.'
			);
			throw err;
		}
	};

	const handleDownloadGeoipDatabase = async () => {
		try {
			await downloadGeoipDatabase().unwrap();
			notification.success(
				'Database updated',
				'PeakURL downloaded the latest GeoLite2 City database.'
			);
		} catch (err) {
			notification.error(
				'Download failed',
				err?.data?.message ||
					'PeakURL could not download the GeoLite2 database.'
			);
		}
	};

	const handleSaveMailConfiguration = async (values) => {
		try {
			await saveMailConfiguration(values).unwrap();
			notification.success(
				'Saved',
				'Email configuration was updated successfully.'
			);
		} catch (err) {
			notification.error(
				'Save failed',
				err?.data?.message ||
					'PeakURL could not save the email configuration.'
			);
			throw err;
		}
	};

	const handleApplyUpdate = async () => {
		try {
			const result = await applyUpdate().unwrap();
			const appliedVersion = result?.data?.currentVersion;
			setShowUpdateConfirm(false);

			notification.success(
				'Update installed',
				appliedVersion
					? `PeakURL ${appliedVersion} was installed successfully. Redirecting now.`
					: 'PeakURL was installed successfully. Redirecting now.'
			);

			window.setTimeout(() => {
				window.location.assign(
					`${PEAKURL_BASENAME || ''}/dashboard/about?source=update`
				);
			}, 1500);
		} catch (err) {
			notification.error(
				'Update failed',
				err?.data?.message || 'PeakURL could not apply the update.'
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
					isUpdating={isUpdating}
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
					isGeneratingKey={isGeneratingKey}
					isDeletingKey={isDeletingKey}
					onDeleteKey={handleDeleteKey}
					setShowCreateModal={setShowCreateModal}
					copyToClipboard={copyToClipboard}
				/>
			)}

			{activeTab === 'integrations' && (
				<IntegrationsTab notification={notification} />
			)}

			{activeTab === 'email' && (
				<EmailDeliveryTab
					key={JSON.stringify(mailStatusResponse?.data || {})}
					status={mailStatusResponse?.data || null}
					errorMessage={mailError?.data?.message || null}
					isLoading={isLoadingMailStatus}
					isSaving={isSavingMailConfiguration}
					onSave={handleSaveMailConfiguration}
				/>
			)}

			{activeTab === 'location' && (
				<LocationDataTab
					status={geoipStatusResponse?.data || null}
					errorMessage={geoipError?.data?.message || null}
					isLoading={isLoadingGeoipStatus || isFetchingGeoipStatus}
					isSaving={isSavingGeoipConfiguration}
					isDownloading={isDownloadingGeoipDatabase}
					onSave={handleSaveGeoipConfiguration}
					onDownload={handleDownloadGeoipDatabase}
				/>
			)}

			{activeTab === 'updates' && (
				<UpdatesTab
					status={updateStatus?.data || null}
					errorMessage={updateError?.data?.message || null}
					isLoading={isLoadingUpdateStatus || isFetchingUpdateStatus}
					isChecking={isCheckingForUpdates}
					isApplying={isApplyingUpdate}
					onCheck={handleCheckForUpdates}
					onApply={() => setShowUpdateConfirm(true)}
				/>
			)}

			<ApiKeyModals
				showCreateModal={showCreateModal}
				setShowCreateModal={setShowCreateModal}
				showKeyModal={showKeyModal}
				setShowKeyModal={setShowKeyModal}
				keyLabel={keyLabel}
				setKeyLabel={setKeyLabel}
				newApiKey={newApiKey}
				onCreateKey={handleCreateKey}
				copyToClipboard={copyToClipboard}
				isGeneratingKey={isGeneratingKey}
			/>
			<ConfirmDialog
				open={showUpdateConfirm}
				onClose={() => {
					if (!isApplyingUpdate) {
						setShowUpdateConfirm(false);
					}
				}}
				title={`Install PeakURL ${updateStatus?.data?.latestVersion || 'update'}?`}
				description={
					'PeakURL will download the latest release package, replace managed application files, and reload the dashboard when the update completes.\n\nOnly continue on packaged release installs.'
				}
				confirmText="Install update"
				cancelText="Cancel"
				onConfirm={handleApplyUpdate}
				loading={isApplyingUpdate}
			/>
			<notification.NotificationContainer />
		</div>
	);
};

export default Content;
