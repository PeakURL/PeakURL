import {
	Dialog,
	DialogPanel,
	TabGroup,
	TabPanel,
	TabPanels,
} from "@headlessui/react";
import { Image, Link2, Shield } from "lucide-react";

import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";

import type { EditableLink } from "../../types";
import Footer from "./Footer";
import Header from "./Header";
import LinkDetailsTab from "./LinkDetailsTab";
import ProtectionTab from "./ProtectionTab";
import SocialPreviewTab from "./SocialPreviewTab";
import TabList from "./TabList";
import { useEditLinkForm } from "./useEditLinkForm";

interface FormProps {
	open: boolean;
	setOpen: (open: boolean) => void;
	link: EditableLink;
}

function Form({ open, setOpen, link }: FormProps) {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? "rtl" : "ltr";
	const {
		clearPassword,
		error,
		expiresAt,
		fileInputRef,
		destinationUrl,
		handleClose,
		handleRemoveSocialImage,
		handleSocialImageChange,
		handleSocialImageUrlChange,
		handleSubmit,
		hasExistingPassword,
		isLoading,
		password,
		selectedTab,
		setClearPassword,
		setDestinationUrl,
		setExpiresAt,
		setPassword,
		setSelectedTab,
		setSocialDescription,
		setSocialTitle,
		setStatus,
		setTitle,
		shortUrl,
		showSocialImageRemove,
		socialDescription,
		socialImageFile,
		socialImageUrl,
		socialPreviewUrl,
		socialTitle,
		status,
		statusOptions,
		title,
	} = useEditLinkForm(link, setOpen);

	const tabs = [
		{ name: __("Link Details"), icon: Link2 },
		{ name: __("Social Preview"), icon: Image },
		{ name: __("Protection"), icon: Shield },
	];
	return (
		<Dialog open={open} onClose={handleClose} className="relative z-50">
			<div className="links-modal-backdrop" aria-hidden="true" />

			<div className="fixed inset-0 overflow-hidden">
				<div className="absolute inset-0 overflow-hidden">
					<div
						className={`links-edit-drawer-layout ${
							isRtl
								? "links-edit-drawer-layout-rtl"
								: "links-edit-drawer-layout-ltr"
						}`}
					>
						<DialogPanel
							dir={direction}
							transition
							className={`links-edit-drawer-panel ${
								isRtl
									? "data-closed:-translate-x-full"
									: "data-closed:translate-x-full"
							}`}
						>
							<form
								onSubmit={handleSubmit}
								className="links-edit-drawer-form"
							>
								<Header onClose={handleClose} />

								<div className="links-edit-drawer-content py-6">
									{error && (
										<div className="links-modal-alert links-modal-alert-error mb-4">
											<p className="links-modal-alert-error-text">
												{error}
											</p>
										</div>
									)}

									<TabGroup
										selectedIndex={selectedTab}
										onChange={setSelectedTab}
									>
										<TabList tabs={tabs} />

										<TabPanels>
											<TabPanel className="links-edit-drawer-stack focus:outline-none">
												<LinkDetailsTab
													shortUrl={shortUrl}
													destinationUrl={
														destinationUrl
													}
													setDestinationUrl={
														setDestinationUrl
													}
													title={title}
													setTitle={setTitle}
													expiresAt={expiresAt}
													setExpiresAt={setExpiresAt}
													status={status}
													setStatus={setStatus}
													statusOptions={
														statusOptions
													}
												/>
											</TabPanel>

											<TabPanel className="links-edit-drawer-stack focus:outline-none">
												<SocialPreviewTab
													fileInputRef={fileInputRef}
													socialTitle={socialTitle}
													setSocialTitle={
														setSocialTitle
													}
													socialDescription={
														socialDescription
													}
													setSocialDescription={
														setSocialDescription
													}
													socialImageFile={
														socialImageFile
													}
													socialImageUrl={
														socialImageUrl
													}
													onSocialImageUrlChange={
														handleSocialImageUrlChange
													}
													socialPreviewUrl={
														socialPreviewUrl
													}
													showSocialImageRemove={
														showSocialImageRemove
													}
													onSocialImageChange={
														handleSocialImageChange
													}
													onRemoveSocialImage={
														handleRemoveSocialImage
													}
												/>
											</TabPanel>

											<TabPanel className="links-edit-drawer-stack focus:outline-none">
												<ProtectionTab
													password={password}
													setPassword={setPassword}
													clearPassword={
														clearPassword
													}
													setClearPassword={
														setClearPassword
													}
													hasExistingPassword={
														hasExistingPassword
													}
												/>
											</TabPanel>
										</TabPanels>
									</TabGroup>
								</div>

								<Footer
									isLoading={isLoading}
									onCancel={handleClose}
								/>
							</form>
						</DialogPanel>
					</div>
				</div>
			</div>
		</Dialog>
	);
}

export default Form;
